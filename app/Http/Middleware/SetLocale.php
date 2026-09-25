<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Symfony\Component\HttpFoundation\Response;

/**
 * Resolves the request's language and applies it, once, before anything renders.
 *
 * Priority, highest first:
 *
 *   1. the signed-in customer's saved locale
 *   2. the session
 *   3. the first-party cookie
 *   4. the browser's Accept-Language, on a first visit only
 *   5. config('localization.default')
 *
 * The account wins over the browser deliberately: someone who chose French on
 * this account should not be flipped back to English because they opened it on
 * a machine whose browser asks for English.
 *
 * Every value is checked against config('localization.supported') before it
 * reaches App::setLocale(). Nothing from the request is trusted - a locale is a
 * filesystem path segment inside the translator, so an unvalidated one is a
 * traversal primitive, not just a bad label.
 */
class SetLocale
{
    public function handle(Request $request, Closure $next): Response
    {
        // The admin panel is English only. Its copy is written in English
        // rather than translated, so a non-English locale changes nothing it
        // says - but it does set `dir` from the locale, and an RTL language
        // mirrors the whole layout: sidebar on the right, every row reversed,
        // around an interface whose text is still English.
        //
        // Set the locale for THIS request without resolving or storing one, so
        // an operator who was reading the storefront in another language keeps
        // that choice when they go back to it. Falling through to the code
        // below would write `en` into the session and silently reset them.
        if ($this->isAdminArea($request)) {
            App::setLocale($this->default());

            return $next($request);
        }

        $locale = $this->resolve($request);

        App::setLocale($locale);

        // Written back on every request, so a locale that arrived from the
        // account or the browser survives the next one without re-deriving it.
        if ($request->hasSession() && $request->session()->get('locale') !== $locale) {
            $request->session()->put('locale', $locale);
        }

        // Nothing to clean up after the translate widget: it is gone, and
        // every offered language now renders from its own lang/{code} files.
        // A stale googtrans cookie from before the removal is inert.
        return $next($request);
    }

    private function resolve(Request $request): string
    {
        $user = $request->user();

        if ($user && $this->supported($user->locale)) {
            return $user->locale;
        }

        if ($request->hasSession() && $this->supported($request->session()->get('locale'))) {
            return $request->session()->get('locale');
        }

        if ($this->supported($cookie = $request->cookie(config('localization.cookie.name')))) {
            return $cookie;
        }

        // First visit only - once anything above is set, the browser no longer
        // gets a say, or changing the language would not survive a page load.
        if ($browser = $this->fromAcceptLanguage($request)) {
            return $browser;
        }

        return $this->default();
    }

    /**
     * The best supported match from Accept-Language, honouring its q-weights.
     *
     * Region subtags are collapsed - fr-CA and fr-BE are French, and refusing
     * them because the tag is not exactly "fr" would send most French speakers
     * an English page.
     */
    private function fromAcceptLanguage(Request $request): ?string
    {
        $header = (string) $request->header('Accept-Language', '');

        if ($header === '') {
            return null;
        }

        $candidates = [];

        foreach (explode(',', $header) as $part) {
            $bits = explode(';q=', trim($part));
            $tag = strtolower(trim($bits[0]));

            if ($tag === '' || $tag === '*') {
                continue;
            }

            $candidates[substr($tag, 0, 2)] ??= isset($bits[1]) ? (float) $bits[1] : 1.0;
        }

        arsort($candidates);

        foreach (array_keys($candidates) as $tag) {
            if ($this->supported($tag)) {
                return $tag;
            }
        }

        return null;
    }

    private function supported(mixed $locale): bool
    {
        return is_string($locale)
            && array_key_exists($locale, (array) config('localization.supported', []));
    }

    private function default(): string
    {
        return (string) config('localization.default', config('app.fallback_locale', 'en'));
    }

    /**
     * Whether this request is for the admin panel.
     *
     * Matched on the URL rather than the admin guard: an operator can be signed
     * into the admin guard while reading the storefront, and that visit should
     * still honour their chosen language.
     */
    private function isAdminArea(Request $request): bool
    {
        return $request->is('admin', 'admin/*');
    }
}

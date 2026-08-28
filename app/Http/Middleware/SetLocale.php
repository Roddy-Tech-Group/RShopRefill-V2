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
        $locale = $this->resolve($request);

        App::setLocale($locale);

        // Written back on every request, so a locale that arrived from the
        // account or the browser survives the next one without re-deriving it.
        if ($request->hasSession() && $request->session()->get('locale') !== $locale) {
            $request->session()->put('locale', $locale);
        }

        // No googtrans housekeeping here. That cookie is the translate
        // engine's live language choice (partials/translate-engine), and a
        // middleware that cleared it would reset every visitor to English on
        // their next request.
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
}

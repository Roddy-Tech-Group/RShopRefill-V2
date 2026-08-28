<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Validation\Rule;

/**
 * Changes the interface language.
 *
 * POST, not GET: it mutates stored preferences, and a GET would let any page
 * flip a visitor's language with an <img> tag.
 *
 * Writes to all three places the middleware reads - session, cookie and (when
 * signed in) the account - so the choice survives a new session, a new browser
 * session, and a sign-in on another device respectively.
 */
class LocaleController extends Controller
{
    public function __invoke(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'locale' => ['required', 'string', Rule::in(array_keys(config('localization.supported')))],
        ]);

        $locale = $validated['locale'];

        $request->session()->put('locale', $locale);

        Cookie::queue(Cookie::make(
            config('localization.cookie.name'),
            $locale,
            (int) config('localization.cookie.minutes'),
            null,
            null,
            null,
            true,   // httpOnly - nothing client-side needs to read this
            false,
            'lax',
        ));

        if ($user = $request->user()) {
            $user->forceFill(['locale' => $locale])->save();
        }

        // back() only, never a URL from the request: a redirect target taken
        // from input is an open redirect, and this endpoint is reachable by any
        // visitor. back() falls through to the homepage when the referrer is
        // missing or off-site, which is exactly the required behaviour.
        return back();
    }
}

<?php

namespace App\Http\Middleware;

use App\Support\DisplayCurrency;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Works out which currency /v2 should show prices in.
 *
 * Every price we hold is USD, because USD is the settlement currency. The
 * storefront converts for display using DisplayCurrency, and this puts the app's
 * choice where that class looks, so the whole conversion chain is reused rather
 * than reimplemented.
 *
 * Why this is not just ResolveDisplayCurrency: that one reads a cookie, queues a
 * cookie and shares a Blade variable. A phone has no cookies and there is no
 * Blade. It also means the app states its currency EXPLICITLY on every request
 * rather than relying on server-held state, which is what you want from an API:
 * two devices signed into one account can be showing different currencies, and
 * neither surprises the other.
 *
 * The app learns the customer's saved preference from GET /v2/auth/me
 * (display_currency) and sends it back here. Nothing is sent means native
 * prices, which is USD for almost everything.
 */
class ResolveApiDisplayCurrency
{
    public function handle(Request $request, Closure $next): Response
    {
        $header = $request->header('X-Display-Currency');
        $query = $request->query('display_currency');

        // A query parameter can arrive as an array (?display_currency[]=USD), and
        // casting that to a string is a type error before validation ever runs.
        $requested = strtoupper(trim(match (true) {
            is_string($header) && $header !== '' => $header,
            is_string($query) => $query,
            default => '',
        }));

        $currency = preg_match('/^[A-Z]{3}$/', $requested) === 1 ? $requested : null;

        // The same request attribute DisplayCurrency::code() reads, so every
        // existing converter and rate memo works untouched.
        $request->attributes->set(DisplayCurrency::COOKIE, $currency);

        return $next($request);
    }
}

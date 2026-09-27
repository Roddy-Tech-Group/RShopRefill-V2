<?php

namespace App\Http\Middleware;

use App\Support\DisplayCurrency;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\View;
use Symfony\Component\HttpFoundation\Response;

class ResolveDisplayCurrency
{
    /**
     * Resolves the customer's chosen DISPLAY currency - the locale modal's
     * currency pane - and shares it with the request, mirroring how
     * ResolveRegion locks the shopping region.
     *
     * Resolution order:
     *   1. ?display_currency= on the request - a fresh switch (the locale
     *      modal navigates with this). Deliberately distinct from
     *      ?currency=, which the gift-cards listing keeps as its own
     *      catalogue FILTER: the display currency changes how a price is
     *      shown, never which products show.
     *   2. the `display_currency` cookie - the last chosen currency.
     *   3. nothing - prices stay in each product's native currency.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $fromUrl = strtoupper(trim((string) $request->query('display_currency', '')));
        $isSwitch = preg_match('/^[A-Z]{3}$/', $fromUrl) === 1;

        $currency = $isSwitch
            ? $fromUrl
            : strtoupper((string) $request->cookie(DisplayCurrency::COOKIE, ''));

        if (preg_match('/^[A-Z]{3}$/', $currency) !== 1) {
            $currency = null;
        }

        $request->attributes->set(DisplayCurrency::COOKIE, $currency);
        View::share('displayCurrency', $currency);

        $response = $next($request);

        // Persist a fresh switch so the choice survives subsequent requests.
        if ($isSwitch) {
            Cookie::queue(DisplayCurrency::COOKIE, $fromUrl, 60 * 24 * 365);
        }

        return $response;
    }
}

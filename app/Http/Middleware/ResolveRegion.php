<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\View;
use Symfony\Component\HttpFoundation\Response;

class ResolveRegion
{
    /**
     * Resolves the customer's shopping region (an ISO-3166 country code) and
     * locks the storefront catalog to it.
     *
     * Resolution order:
     *   1. ?country= on the request — a fresh region switch (locale modal /
     *      the gift-cards country picker navigate with this).
     *   2. the `region` cookie — the customer's last chosen region.
     *   3. US — the default for a brand-new visitor.
     *
     * The resolved region is shared to every view as $region and exposed on the
     * request; a fresh switch is persisted to the cookie for later requests.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $fromUrl = strtoupper(trim($request->queryString('country', '')));
        $isSwitch = preg_match('/^[A-Z]{2}$/', $fromUrl) === 1;

        $region = $isSwitch
            ? $fromUrl
            : strtoupper((string) $request->cookie('region', 'US'));

        if (preg_match('/^[A-Z]{2}$/', $region) !== 1) {
            $region = 'US';
        }

        View::share('region', $region);
        $request->attributes->set('region', $region);

        $response = $next($request);

        // Persist a fresh switch so the lock survives subsequent requests.
        if ($isSwitch) {
            Cookie::queue('region', $region, 60 * 24 * 365);
        }

        return $response;
    }
}

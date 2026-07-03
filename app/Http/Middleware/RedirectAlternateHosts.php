<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * 301-redirects onto the single canonical host, keeping the full path + query,
 * so every visitor and all search-ranking signals land on one URL.
 *
 * Two jobs:
 *  1. www/non-www: the canonical host's opposite sibling (derived from APP_URL,
 *     e.g. rshoprefill.com when canonical is www.rshoprefill.com) is folded onto
 *     the canonical. This kills the "Duplicate, Google chose a different
 *     canonical" reports caused by the site answering on both hosts, and matches
 *     the <link rel=canonical> (also built from APP_URL).
 *  2. Secondary domains: any host in app.alternate_hosts (e.g. a mistyped/plural
 *     rshoprefills.com once it's owned + pointed here) folds on too.
 *
 * Only ever fires for REAL domains and only for the exact sibling/listed hosts,
 * so local dev, the raw origin IP, health checks and preview hosts are untouched,
 * and the `$host === canonical` guard makes redirect loops impossible.
 */
class RedirectAlternateHosts
{
    public function handle(Request $request, Closure $next): Response
    {
        $canonicalHost = strtolower(trim((string) config('app.canonical_host')));

        $alternates = array_map(
            fn ($host) => strtolower(trim((string) $host)),
            (array) config('app.alternate_hosts', [])
        );

        // Auto-fold the www/non-www sibling of the canonical host - real domains
        // only, so localhost / IP origins never trigger a redirect.
        if ($this->isRealDomain($canonicalHost)) {
            $alternates[] = str_starts_with($canonicalHost, 'www.')
                ? substr($canonicalHost, 4)
                : 'www.'.$canonicalHost;
        }

        $alternates = array_values(array_unique(array_filter($alternates)));

        if ($canonicalHost === '' || $alternates === []) {
            return $next($request);
        }

        $host = strtolower($request->getHost());

        // The canonical host - and any host not on the alternate list - passes
        // straight through. No redirect, no loop.
        if ($host === $canonicalHost || ! in_array($host, $alternates, true)) {
            return $next($request);
        }

        // Rebuild on the canonical host over https, preserving path + query.
        $target = 'https://'.$canonicalHost.$request->getRequestUri();

        return redirect()->away($target, 301);
    }

    /**
     * A routable public domain (has a dot, no port, not a bare IP) - so the
     * www/non-www auto-fold never engages for localhost, *.test or origin IPs.
     */
    private function isRealDomain(string $host): bool
    {
        return $host !== ''
            && str_contains($host, '.')
            && ! str_contains($host, ':')
            && filter_var($host, FILTER_VALIDATE_IP) === false;
    }
}

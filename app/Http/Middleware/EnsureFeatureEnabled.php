<?php

namespace App\Http\Middleware;

use App\Support\FeatureFlag;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureFeatureEnabled
{
    /**
     * Handle an incoming request.
     *
     * Gates here guard features that are still being built, so the default is
     * off: a missing settings row must close the route rather than open it.
     * This mirrors the dashboard nav, which already passes an explicit `false`
     * to FeatureFlag::on(), and keeps the two from disagreeing.
     *
     * Responds 404 rather than 403 so an unreleased feature does not advertise
     * its own existence. Laravel renders that as JSON for API callers.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next, string $feature): Response
    {
        if (! FeatureFlag::on($feature, false)) {
            abort(404);
        }

        return $next($request);
    }
}

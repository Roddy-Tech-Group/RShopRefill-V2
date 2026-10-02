<?php

namespace App\Http\Middleware;

use App\Support\ApiV2Error;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Turns away app builds we no longer support.
 *
 * A mobile app cannot be recalled. Once a build is on someone's phone it stays
 * there until they choose to update, which many people never do, so a contract
 * change or a bad build has no remedy except refusing to serve it. Without this
 * the only options are supporting every build ever shipped forever, or breaking
 * customers in the field with no explanation.
 *
 * The app sends its build number in X-App-Build. A request with no build number
 * counts as build 0, which means raising the floor also turns away anything too
 * old to send the header. That is the intent: those are precisely the builds
 * being retired.
 *
 * Dormant until api.v2.minimum_app_build is raised above 0, so it ships safely
 * ahead of ever being needed.
 */
class EnsureSupportedAppBuild
{
    public function handle(Request $request, Closure $next): Response
    {
        $minimum = (int) config('api.v2.minimum_app_build', 0);

        if ($minimum <= 0) {
            return $next($request);
        }

        // Digits only. A plain (int) cast would read "1e3" as a thousand and a
        // twenty-digit string as PHP_INT_MAX, which passes any floor; anything that
        // is not a build number is treated as no build number.
        $raw = trim((string) $request->header('X-App-Build', ''));
        $build = ctype_digit($raw) && strlen($raw) <= 12 ? (int) $raw : 0;

        if ($build < $minimum) {
            // 426 Upgrade Required. Chosen over 403 so the app can tell "you
            // must update" apart from "you may not do that" without reading
            // the body, and over 400 so it is never retried as transient.
            return ApiV2Error::make(
                'upgrade_required',
                'Please update the app to continue.',
                426,
                ['minimum_build' => $minimum],
            );
        }

        return $next($request);
    }
}

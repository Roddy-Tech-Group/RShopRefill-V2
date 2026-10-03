<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Symfony\Component\HttpFoundation\Response;

/**
 * The /v2 group's general rate ceiling, placed so that it runs BEFORE
 * authentication.
 *
 * Laravel sorts middleware by a priority list, and on that list Authenticate
 * comes before ThrottleRequests. So `throttle:v2` in the group, however early it
 * was written, ran after `auth:sanctum`, and every request carrying a made-up
 * bearer token cost a personal_access_tokens lookup before the limiter was ever
 * consulted. A flood of garbage tokens was never throttled at all.
 *
 * Extending ThrottleRequests would not help: the sorter matches a middleware's
 * parents too, so a subclass inherits the parent's place in the queue. This class
 * therefore WRAPS the framework's throttle rather than extending it, which keeps
 * it off the priority list and leaves it exactly where the group declares it.
 * The limiter itself is the `v2` limiter in AppServiceProvider; nothing about
 * how limits are counted or reported changes.
 */
class ThrottleApiV2Group
{
    public function __construct(private ThrottleRequests $throttle) {}

    public function handle(Request $request, Closure $next): Response
    {
        return $this->throttle->handle($request, $next, 'v2');
    }
}

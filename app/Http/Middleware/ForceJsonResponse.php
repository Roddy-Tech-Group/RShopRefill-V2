<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Makes every /v2 request behave as a JSON request, whatever the client sent.
 *
 * Laravel decides how to render a validation error, a 401 or a 404 by looking
 * at the Accept header: HTML gets a redirect or an error page, JSON gets JSON.
 * A mobile app that forgets the header, or an HTTP library that quietly
 * rewrites it, would otherwise receive a redirect to the login PAGE where it
 * expected a 401, and report a confusing failure to the customer.
 *
 * /v2 has no HTML to serve, so there is nothing to negotiate.
 */
class ForceJsonResponse
{
    public function handle(Request $request, Closure $next): Response
    {
        $request->headers->set('Accept', 'application/json');

        return $next($request);
    }
}

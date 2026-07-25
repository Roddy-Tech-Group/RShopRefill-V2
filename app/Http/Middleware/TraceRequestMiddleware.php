<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class TraceRequestMiddleware
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $correlationId = $request->header('X-Correlation-ID', Str::uuid()->toString());

        Context::add('correlation_id', $correlationId);
        Context::add('request_id', Str::uuid()->toString());

        // Snapshot the caller for queued consumers: Context is dehydrated into
        // queued jobs/listeners, where Request::ip() resolves the WORKER's
        // context, not the customer's - that is how audit rows ended up with
        // the wrong IP. Hidden so it never leaks into log lines.
        Context::addHidden('request_ip', $request->ip());
        Context::addHidden('request_user_agent', $request->userAgent());

        $response = $next($request);

        $response->headers->set('X-Correlation-ID', $correlationId);

        return $response;
    }
}

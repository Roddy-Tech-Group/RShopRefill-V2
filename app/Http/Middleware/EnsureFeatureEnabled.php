<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use App\Support\FeatureFlag;

class EnsureFeatureEnabled
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next, string $feature): Response
    {
        if (!FeatureFlag::on($feature)) {
            return response()->json([
                'message' => 'This feature is currently disabled or undergoing maintenance.'
            ], 403);
        }

        return $next($request);
    }
}

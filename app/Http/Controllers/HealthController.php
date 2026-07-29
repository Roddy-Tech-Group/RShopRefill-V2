<?php

namespace App\Http\Controllers;

use App\Domain\Shared\Services\HealthCheckService;
use Illuminate\Http\JsonResponse;

/**
 * Public health endpoint for the external uptime probe.
 *
 * Returns 200 when the platform can actually serve customers and 503 when it
 * cannot, so a probe that only understands status codes still makes the right
 * call, while the JSON body tells a human which component failed.
 */
class HealthController extends Controller
{
    public function __invoke(HealthCheckService $health): JsonResponse
    {
        $result = $health->run();

        return response()
            ->json($result, $result['status'] === 'ok' ? 200 : 503)
            // Never let a CDN or browser serve a cached "everything is fine".
            ->header('Cache-Control', 'no-store, no-cache, must-revalidate');
    }
}

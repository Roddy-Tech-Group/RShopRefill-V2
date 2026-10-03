<?php

namespace App\Http\Controllers\Api\V2;

use Illuminate\Http\JsonResponse;

/**
 * What the app needs to know before it trusts anything else.
 *
 * Unauthenticated on purpose: an app that has been retired has to be able to
 * learn so without first proving who it is, and a customer whose sign-in is
 * failing needs a way to tell "the server is unreachable" apart from "my
 * password is wrong".
 */
class MetaController
{
    public function __invoke(): JsonResponse
    {
        return response()->json([
            'data' => [
                'api_version' => (string) config('api.v2.version'),
                'minimum_app_build' => (int) config('api.v2.minimum_app_build'),
                // Lets the app notice a badly wrong device clock, which
                // otherwise shows up as tokens that look expired the moment
                // they are issued and is impossible to diagnose from the
                // customer's side.
                'server_time' => now()->toIso8601ZuluString(),
            ],
        ]);
    }
}

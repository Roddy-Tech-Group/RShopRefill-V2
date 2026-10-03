<?php

namespace App\Http\Controllers\Api\V2\Auth;

use App\Domain\Security\Exceptions\RefreshTokenRejected;
use App\Domain\Security\Services\ApiTokenService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V2\RefreshTokenRequest;
use App\Support\ApiV2Error;
use Illuminate\Http\JsonResponse;

/**
 * Trading an expiring access token for a fresh one.
 *
 * Unauthenticated, and it has to be: the app calls this precisely BECAUSE its
 * access token has run out. The refresh token in the body is the credential.
 */
class TokenController extends Controller
{
    public function __construct(private ApiTokenService $tokens) {}

    public function refresh(RefreshTokenRequest $request): JsonResponse
    {
        try {
            $pair = $this->tokens->exchange(
                (string) $request->input('refresh_token'),
                (string) $request->header('X-Installation-ID'),
            );
        } catch (RefreshTokenRejected $e) {
            // Every one of these means "wipe what you have stored and show the
            // login screen", never "retry". The distinct `type` is there so a
            // support conversation and a log can tell an ordinary expiry apart
            // from a replay.
            return ApiV2Error::make($e->type, $e->getMessage(), 401);
        }

        // No user object here. The app already has it from signing in, and a
        // token refresh happening silently in the background is not the moment to
        // make it re-read a profile it did not ask for.
        return response()->json([
            'data' => [
                'status' => 'authenticated',
                'tokens' => $pair->toArray(),
            ],
        ]);
    }
}

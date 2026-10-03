<?php

namespace App\Http\Controllers\Api\V2\Auth;

use App\Domain\Security\Services\ApiTokenService;
use App\Http\Controllers\Controller;
use App\Http\Resources\V2\UserResource;
use App\Models\ExpoPushToken;
use App\Models\User;
use App\Support\ApiV2Error;
use App\Support\VerificationResendThrottle;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * What the signed-in app can do about its own session.
 */
class SessionController extends Controller
{
    public function __construct(private ApiTokenService $tokens) {}

    /** Who the token belongs to. The app's first call after launching. */
    public function me(Request $request): JsonResponse
    {
        return response()->json([
            'data' => ['user' => new UserResource($request->user())],
        ]);
    }

    /**
     * Sign out this phone. Both halves die: the access token presented, and the
     * refresh token that would otherwise mint a replacement.
     */
    public function logout(Request $request): JsonResponse
    {
        $token = $request->user()->currentAccessToken();

        if ($token instanceof PersonalAccessToken) {
            $this->tokens->revokeCurrentDevice($token);
        }

        // A signed-out phone must stop receiving pushes. Otherwise somebody's
        // wallet activity keeps appearing on a lock screen they no longer have an
        // account open on, which is both a surprise and a leak.
        $this->forgetPushToken($request);

        return response()->json(['data' => ['status' => 'signed_out']]);
    }

    /**
     * Sign out everywhere, this device included. What a customer reaches for
     * when they think somebody else is in their account.
     */
    public function logoutAll(Request $request): JsonResponse
    {
        $this->tokens->revokeEverything($request->user());

        // Every device, so no phone anywhere keeps pushing for this account.
        ExpoPushToken::where('user_id', $request->user()->id)->delete();

        return response()->json(['data' => ['status' => 'signed_out_everywhere']]);
    }

    /**
     * Stop pushing to the phone making this request.
     *
     * Keyed on the installation id the app reports. A build that sends none simply
     * has no token registered, so there is nothing to remove.
     */
    private function forgetPushToken(Request $request): void
    {
        $installationId = trim((string) $request->header('X-Installation-ID', ''));

        if ($installationId === '') {
            return;
        }

        ExpoPushToken::where('user_id', $request->user()->id)
            ->where('installation_id', $installationId)
            ->delete();
    }

    /**
     * Another copy of the email-verification link. The escalating schedule and
     * the flat daily budget are the same ones the website's resend button
     * honours, read from the same place.
     */
    public function resendEmailVerification(Request $request, VerificationResendThrottle $throttle): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        if ($user->hasVerifiedEmail()) {
            return response()->json(['data' => ['status' => 'already_verified']]);
        }

        $result = $throttle->attemptSend($user);

        if (! $result['sent']) {
            return ApiV2Error::make(
                $result['reason'] === 'budget' ? 'verification_limit_reached' : 'verification_cooldown',
                $result['reason'] === 'budget'
                    ? 'Too many verification emails requested. Please try again later.'
                    : 'Please wait before requesting another link.',
                429,
                ['retry_after' => $result['retry_after']],
            );
        }

        return response()->json(['data' => [
            'status' => 'sent',
            'retry_after' => $result['retry_after'],
        ]]);
    }
}

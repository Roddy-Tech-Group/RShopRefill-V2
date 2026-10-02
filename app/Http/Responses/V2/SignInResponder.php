<?php

namespace App\Http\Responses\V2;

use App\Domain\Security\DTOs\IssuedTokenPair;
use App\Domain\Security\Services\ApiTokenService;
use App\Domain\Security\Services\DeviceOtpService;
use App\Domain\Security\Services\LoginChallengeStore;
use App\Domain\Security\Services\LoginRiskEvaluator;
use App\Http\Resources\V2\UserResource;
use App\Models\User;
use App\Support\ApiV2Error;
use App\Support\FeatureFlag;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Turns a passed credential step into the one of two answers /v2 ever gives:
 * tokens, or a challenge.
 *
 * Registration, password login and Google sign-in all go through respond(), and
 * a passed device challenge through authenticated(), so the app has one shape to
 * read and one place decides which it gets. The DECISION itself is
 * LoginRiskEvaluator's, shared with the website; this only dresses it for the
 * wire.
 *
 * A challenged sign-in is handed a challenge token and NO access token. That is
 * deliberate: an unverified sign-in holding an API token would mean every future
 * /v2 route had to remember to exclude it, and the day somebody forgot, an
 * unverified device would be reading orders.
 */
class SignInResponder
{
    public function __construct(
        private LoginRiskEvaluator $riskEvaluator,
        private DeviceOtpService $otp,
        private LoginChallengeStore $challenges,
        private ApiTokenService $tokens,
    ) {}

    /**
     * The refusal an account's STATE earns regardless of how well it just proved
     * itself, or null when it may sign in.
     *
     * In one place, because every way in (password, Google, a passed challenge)
     * has to ask the same question, and the one that forgot would be the way a
     * banned customer got back in. EnsureApiAccountActive catches a token that
     * outlives a ban; this stops the token being issued at all.
     */
    public static function accountRefusal(User $user): ?JsonResponse
    {
        if ($user->banned_at !== null) {
            return ApiV2Error::make(
                'account_banned',
                'Your account has been banned. Please contact support.',
                403,
            );
        }

        if ($user->anonymised_at !== null) {
            return ApiV2Error::make('account_closed', 'This account has been closed.', 403);
        }

        return null;
    }

    public function respond(User $user, string $installationId, Request $request, int $status = 200): JsonResponse
    {
        if ($refusal = self::accountRefusal($user)) {
            return $refusal;
        }

        // The same kill switch the website honours. With device management off
        // there are no device rows, no new-device codes and no new-device hold
        // ANYWHERE: the phone must not keep enforcing a regime the browser has
        // stopped enforcing, or an admin flipping this off to unstick customers
        // would unstick only half of them.
        if (FeatureFlag::off('device_management')) {
            return $this->authenticated($user, $this->tokens->issuePair($user, $installationId), $status);
        }

        $outcome = $this->riskEvaluator->evaluate(
            $user,
            $installationId,
            (string) $request->ip(),
            $request->userAgent() ?? 'Unknown',
        );

        if (! $outcome->requiresOtp) {
            return $this->authenticated($user, $this->tokens->issuePair($user, $installationId), $status);
        }

        $this->otp->issueForLogin($user);

        $challenge = $this->challenges->create($user, $outcome->device, $installationId);

        return response()->json([
            'data' => [
                'status' => 'verification_required',
                'challenge_token' => $challenge->token,
                'challenge_expires_at' => $challenge->expiresAt->toIso8601ZuluString(),
                // Masked, because this screen is reachable from a device that has
                // not been trusted yet. It confirms where to look without printing
                // the address to whoever is holding the phone.
                'sent_to' => DeviceOtpService::maskedEmail((string) $user->email),
                'code_expires_in' => $this->otp->codeExpiresInSeconds($user),
                'resend_available_in' => $this->otp->cooldownSeconds($user),
            ],
        ], $status);
    }

    public function authenticated(User $user, IssuedTokenPair $pair, int $status = 200): JsonResponse
    {
        return response()->json([
            'data' => [
                'status' => 'authenticated',
                'tokens' => $pair->toArray(),
                'user' => new UserResource($user),
            ],
        ], $status);
    }
}

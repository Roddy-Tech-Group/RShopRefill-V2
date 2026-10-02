<?php

namespace App\Http\Controllers\Api\V2\Auth;

use App\Domain\Security\Enums\OtpDispatchResult;
use App\Domain\Security\Enums\OtpVerificationResult;
use App\Domain\Security\Services\ApiTokenService;
use App\Domain\Security\Services\DeviceOtpService;
use App\Domain\Security\Services\LoginChallengeStore;
use App\Domain\Security\Services\LoginRiskEvaluator;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V2\ResendChallengeRequest;
use App\Http\Requests\Api\V2\VerifyChallengeRequest;
use App\Http\Responses\V2\SignInResponder;
use App\Models\User;
use App\Support\ApiV2Error;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The emailed-code step, for a sign-in that needed verifying.
 *
 * The app arrives with the challenge token it was handed at login. It holds no
 * access token at this point and cannot reach anything else in the API, which is
 * the whole reason a challenge token exists rather than a restricted access one.
 */
class ChallengeController extends Controller
{
    public function __construct(
        private LoginChallengeStore $challenges,
        private DeviceOtpService $otp,
        private LoginRiskEvaluator $riskEvaluator,
        private ApiTokenService $tokens,
    ) {}

    public function verify(VerifyChallengeRequest $request, SignInResponder $responder): JsonResponse
    {
        $token = (string) $request->input('challenge_token');

        $payload = $this->challenges->find($token);

        if ($payload === null) {
            return $this->challengeGone();
        }

        // The device is taken from the CHALLENGE, never from the request, and the
        // request has to agree with it. A challenge token lifted off the wire is
        // therefore useless on another phone: it only finishes the sign-in that
        // started on the device that began it.
        if ((string) $payload['installation_id'] !== (string) $request->header('X-Installation-ID')) {
            return ApiV2Error::make(
                'challenge_device_mismatch',
                'Please sign in again on this device.',
                401,
            );
        }

        $user = User::find($payload['user_id']);

        if ($user === null) {
            $this->challenges->consume($token);

            return $this->challengeGone();
        }

        $result = $this->otp->verify($user, (string) $request->input('code'));

        if ($result === OtpVerificationResult::Burned) {
            // The code has been destroyed, so this attempt cannot be finished.
            // Signing in again is the only way forward, and saying so is kinder
            // than letting them keep typing into a dead challenge.
            $this->challenges->consume($token);

            return ApiV2Error::make(
                'code_burned',
                'Too many incorrect attempts. Please sign in again to get a new code.',
                401,
            );
        }

        if ($result === OtpVerificationResult::Invalid) {
            // 422, not 401: the sign-in is still alive and the app should let
            // them retype rather than throw them back to the login screen.
            return ApiV2Error::make('code_invalid', 'Invalid or expired code.', 422);
        }

        $this->challenges->consume($token);

        // The account may have been banned or closed in the minutes between the
        // password step and the code arriving. The code proves the inbox; it does
        // not reopen the account.
        if ($refusal = SignInResponder::accountRefusal($user)) {
            return $refusal;
        }

        // Trusts the device, sends the "new device signed in" mail, and records
        // the country as proved. Shared with the website's verification page.
        $this->riskEvaluator->markChallengePassed(
            $user,
            (int) $payload['device_id'],
            (string) $request->ip(),
        );

        return $responder->authenticated(
            $user,
            $this->tokens->issuePair($user, (string) $payload['installation_id']),
        );
    }

    public function resend(ResendChallengeRequest $request): JsonResponse
    {
        $payload = $this->challenges->find((string) $request->input('challenge_token'));

        if ($payload === null) {
            return $this->challengeGone();
        }

        // Same rule as verify: a challenge token lifted off the wire must not let
        // somebody else fill the customer's inbox with codes from another device.
        if ((string) $payload['installation_id'] !== (string) $request->header('X-Installation-ID')) {
            return ApiV2Error::make(
                'challenge_device_mismatch',
                'Please sign in again on this device.',
                401,
            );
        }

        $user = User::find($payload['user_id']);

        if ($user === null) {
            return $this->challengeGone();
        }

        return match ($this->otp->resend($user)) {
            OtpDispatchResult::Sent => response()->json(['data' => [
                'status' => 'sent',
                'sent_to' => DeviceOtpService::maskedEmail((string) $user->email),
                'code_expires_in' => $this->otp->codeExpiresInSeconds($user),
                'resend_available_in' => $this->otp->cooldownSeconds($user),
            ]]),

            OtpDispatchResult::CooldownActive => ApiV2Error::make(
                'resend_cooldown',
                'Please wait before requesting another code.',
                429,
                ['retry_after' => $this->otp->cooldownSeconds($user)],
            ),

            OtpDispatchResult::TooManyRequests => ApiV2Error::make(
                'resend_limit_reached',
                'Too many attempts. Please contact support.',
                429,
            ),
        };
    }

    /**
     * Same answer for an expired challenge, a forged token and a deleted
     * account: none of them tell the caller anything about the others.
     */
    private function challengeGone(): JsonResponse
    {
        return ApiV2Error::make(
            'challenge_expired',
            'That sign-in attempt has expired. Please sign in again.',
            401,
        );
    }
}

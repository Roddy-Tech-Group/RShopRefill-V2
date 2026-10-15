<?php

namespace App\Http\Controllers\Api\Mobile;

use App\Domain\Security\DTOs\LoginAssessment;
use App\Domain\Security\Services\DeviceOtpService;
use App\Domain\Security\Services\DeviceTrustManager;
use App\Domain\Security\Services\LoginRiskEvaluator;
use App\Domain\Security\Services\SessionSecurityManager;
use App\Models\PersonalAccessToken;
use App\Models\User;
use App\Support\FeatureFlag;
use Illuminate\Auth\Events\Login;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * How the mobile app finishes a sign-in, whichever door it came through (a
 * password, or an account just created): the phone is assessed as the website
 * assesses a browser, and the customer is either signed in with a token or
 * asked for the emailed device code first.
 *
 * The app also asks for the code while the account's email is unconfirmed, so
 * a new account proves its address before it is signed in. The code goes to
 * that address, so typing it back confirms it, as the website's link does
 * (MobileAuthController::verifyDevice).
 *
 * Each token belongs to one phone and is recorded as a session in the security
 * centre, so removing the device, "sign out everywhere", a ban or a password
 * change ends it (EnsureAppTokenIsValid). A sign-in waiting on its code is held
 * as a challenge, bound to the phone it began on.
 */
final class AppSignIn
{
    /** How long an app sign-in lasts: the life the security centre gives a session. */
    private const TOKEN_DAYS = 30;

    public function __construct(
        private readonly LoginRiskEvaluator $evaluator,
        private readonly SessionSecurityManager $sessions,
        private readonly DeviceOtpService $otp,
        private readonly DeviceTrustManager $devices,
    ) {}

    /**
     * The phone's installation ID, minted once by the app and sent on every
     * request. Without it there is nothing to bind a token to.
     */
    public static function installationId(Request $request): string
    {
        $id = (string) $request->header('X-Installation-ID', '');

        if (! Str::isUuid($id)) {
            throw ValidationException::withMessages([
                'device' => 'This app could not identify your phone. Please update the app and try again.',
            ]);
        }

        return $id;
    }

    /**
     * Signed in now, or the emailed code first: for a phone the account has not
     * seen, or for an email nobody has confirmed yet.
     *
     * @param  array<string, string|null>  $deviceData  What the app says about the phone (device_name, os)
     */
    public function begin(User $user, Request $request, array $deviceData): JsonResponse
    {
        $installationId = self::installationId($request);
        $assessment = $this->assess($user, $installationId, $request, ['device_type' => 'mobile'] + $deviceData);
        $confirmsEmail = ! $user->hasVerifiedEmail();

        if (! $assessment->needsOtp && ! $confirmsEmail) {
            return $this->complete($user, $assessment);
        }

        $challenge = Str::random(64);
        $pending = [
            'user_id' => $user->id,
            'device_id' => $assessment->device->id,
            'risk_level' => $assessment->riskLevel,
            'installation_id' => $installationId,
            // The address the code goes to, so only that address is confirmed by it.
            'confirms_email' => $confirmsEmail ? (string) $user->email : null,
        ];
        $this->hold($challenge, $pending);
        $this->otp->issue($user, self::codeContext($pending));

        return response()->json([
            'status' => 'verification_required',
            // What the app titles the code screen: "Confirm your email" or "Verify your device".
            'purpose' => $confirmsEmail ? 'email' : 'device',
            'challenge' => $challenge,
            'masked_email' => DeviceOtpService::maskEmail((string) $user->email),
            'expires_in' => $this->otp->secondsUntilExpiry($user),
            'resend_in' => $this->otp->secondsUntilResend($user),
        ], 202);
    }

    /**
     * What a held sign-in's code is for, as its email names it.
     *
     * @param  array<string, mixed>  $pending
     */
    public static function codeContext(array $pending): string
    {
        return ($pending['confirms_email'] ?? null) !== null ? 'email_verification' : 'login';
    }

    /** The token, recorded as a security centre session on the phone it belongs to. */
    public function complete(User $user, LoginAssessment $assessment): JsonResponse
    {
        $session = $this->sessions->createSession($user, $assessment->device, null, $assessment->riskLevel);
        $expiresAt = now()->addDays(self::TOKEN_DAYS);

        $issued = $user->createToken($assessment->device->device_name ?: 'RShopRefill app', ['*'], $expiresAt);

        /** @var PersonalAccessToken $token */
        $token = $issued->accessToken;
        $token->forceFill([
            'trusted_device_id' => $assessment->device->id,
            'user_session_id' => $session->id,
            'password_fingerprint' => PersonalAccessToken::fingerprint($user->password),
        ])->save();

        // What a browser sign-in sets off happens here too: a pending account
        // closure is cancelled, and a guest cart sent as X-Guest-Token joins the
        // account. The risk listener steps aside for this guard; the device was
        // assessed already.
        event(new Login('sanctum', $user, false));

        return response()->json([
            'status' => 'signed_in',
            'token' => $issued->plainTextToken,
            'expires_at' => $expiresAt->toIso8601String(),
            'user' => self::profile($user),
        ]);
    }

    /**
     * A challenged sign-in, still waiting for its code, asked for from the phone
     * it began on. Anything else reads as expired.
     *
     * @return array{0: User, 1: array<string, mixed>}
     */
    public function pending(Request $request, string $challenge): array
    {
        $pending = Cache::get($this->challengeKey($challenge));

        if (! is_array($pending)
            || ! hash_equals((string) ($pending['installation_id'] ?? ''), (string) $request->header('X-Installation-ID', ''))) {
            $this->expired();
        }

        $user = User::find($pending['user_id']);

        if ($user === null || $user->banned_at !== null || $user->anonymised_at !== null) {
            $this->expired();
        }

        return [$user, $pending];
    }

    /** @param array<string, mixed> $pending */
    public function hold(string $challenge, array $pending): void
    {
        Cache::put($this->challengeKey($challenge), $pending, now()->addMinutes(DeviceOtpService::TTL_MINUTES));
    }

    public function release(string $challenge): void
    {
        Cache::forget($this->challengeKey($challenge));
    }

    public function expired(): never
    {
        throw new HttpResponseException(response()->json([
            'message' => 'This sign-in has expired. Please sign in again.',
        ], 401));
    }

    /** @return array<string, mixed> */
    public static function profile(User $user): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'email_verified' => $user->email_verified_at !== null,
            // False only for an account Google created that has not yet given
            // the country, phone and gender sign-up asks for (MobileProfileController).
            'profile_completed' => ! $user->needsProfileCompletion(),
            'phone' => $user->phone,
            'country' => $user->country,
            'avatar_url' => $user->avatar_url,
        ];
    }

    /**
     * The web assessment, or, with device management switched off, the device
     * alone: a token still needs a phone to belong to, and with the feature off
     * nothing is held back or challenged.
     *
     * @param  array<string, string|null>  $deviceData
     */
    private function assess(User $user, string $installationId, Request $request, array $deviceData): LoginAssessment
    {
        $ip = (string) $request->ip();
        $userAgent = $request->userAgent() ?? 'Unknown';

        if (FeatureFlag::on('device_management')) {
            return $this->evaluator->assess($user, $installationId, $ip, $userAgent, $deviceData);
        }

        $device = $this->devices->registerOrUpdateDevice(
            $user,
            ['installation_id' => $installationId] + array_filter($deviceData),
            $ip,
            $userAgent
        );

        if (! $device->isTrusted()) {
            $this->devices->markAsTrusted($device);
        }

        return new LoginAssessment($device, 'LOW', false);
    }

    /** Only a digest of the challenge is stored, so the cache cannot be read back into a live one. */
    private function challengeKey(string $challenge): string
    {
        return 'mobile_login_challenge_'.hash('sha256', $challenge);
    }
}

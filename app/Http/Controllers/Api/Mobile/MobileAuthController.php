<?php

namespace App\Http\Controllers\Api\Mobile;

use App\Domain\Security\DTOs\LoginAssessment;
use App\Domain\Security\Enums\OtpCheck;
use App\Domain\Security\Enums\OtpResend;
use App\Domain\Security\Services\DeviceOtpService;
use App\Domain\Security\Services\DeviceTrustManager;
use App\Domain\Security\Services\LoginCountryGuard;
use App\Http\Controllers\Controller;
use App\Models\PersonalAccessToken;
use App\Models\TrustedDevice;
use App\Models\User;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Auth\Events\Verified;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Sign-in for the RShopRefill mobile app (rshoprefill-mobileapp), which cannot
 * hold the website's session cookie and signs in with a token instead.
 *
 * It is the website's sign-in, not a second one: the same password check and
 * five-try limit (shared with the web form, so the two cannot be played off
 * each other), the same device assessment and risk score, and the same emailed
 * device code (DeviceOtpService). How a sign-in finishes is AppSignIn, shared
 * with the app's sign-up. The same code also confirms an email nobody has
 * confirmed yet, which the app asks for before it signs anyone in.
 */
class MobileAuthController extends Controller
{
    public function __construct(
        private readonly AppSignIn $signIn,
        private readonly DeviceOtpService $otp,
        private readonly DeviceTrustManager $devices,
        private readonly LoginCountryGuard $countries,
        private readonly AppGoogleHandoff $google,
    ) {}

    /**
     * A Google sign-in the app began on the website, finished here: the code the
     * website handed back and the secret only the app that began it holds. The
     * account is then signed in on the phone's own terms, the device code
     * included, as a password sign-in is.
     */
    public function google(Request $request): JsonResponse
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'size:64'],
            'verifier' => ['required', 'string', 'min:43', 'max:128', 'regex:/^[A-Za-z0-9._~-]+$/'],
            'device_name' => ['nullable', 'string', 'max:100'],
            'os' => ['nullable', 'string', 'max:50'],
        ]);
        AppSignIn::installationId($request);

        $user = $this->google->redeem($data['code'], $data['verifier']);

        if ($user === null || $user->anonymised_at !== null) {
            throw ValidationException::withMessages(['code' => 'This Google sign-in has expired. Please try again.']);
        }

        if ($user->banned_at !== null) {
            return response()->json(['message' => 'Your account has been banned. Please contact support.'], 403);
        }

        return $this->signIn->begin($user, $request, [
            'device_name' => $data['device_name'] ?? null,
            'os' => $data['os'] ?? null,
        ]);
    }

    public function login(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'string', 'email'],
            'password' => ['required', 'string'],
            'device_name' => ['nullable', 'string', 'max:100'],
            'os' => ['nullable', 'string', 'max:50'],
        ]);
        AppSignIn::installationId($request);

        // The web form's key, so five wrong passwords lock out both.
        $throttleKey = Str::transliterate(Str::lower($data['email']).'|'.$request->ip());

        if (RateLimiter::tooManyAttempts($throttleKey, 5)) {
            event(new Lockout($request));
            $seconds = RateLimiter::availableIn($throttleKey);

            throw ValidationException::withMessages([
                'email' => __('auth.throttle', ['seconds' => $seconds, 'minutes' => ceil($seconds / 60)]),
            ])->status(429);
        }

        $guard = Auth::guard('web');

        if (! $guard->validate(['email' => $data['email'], 'password' => $data['password']])) {
            RateLimiter::hit($throttleKey);

            // A web attempt announces its own failure; validate() does not, so
            // the audit log would never see the app's.
            event(new Failed('sanctum', $guard->getLastAttempted(), ['email' => $data['email']]));

            throw ValidationException::withMessages(['email' => __('auth.failed')]);
        }

        RateLimiter::clear($throttleKey);

        /** @var User $user */
        $user = $guard->getLastAttempted();

        if ($user->banned_at !== null) {
            return response()->json(['message' => 'Your account has been banned. Please contact support.'], 403);
        }

        return $this->signIn->begin($user, $request, [
            'device_name' => $data['device_name'] ?? null,
            'os' => $data['os'] ?? null,
        ]);
    }

    public function verifyDevice(Request $request): JsonResponse
    {
        $data = $request->validate([
            'challenge' => ['required', 'string', 'size:64'],
            'code' => ['required', 'string', 'size:6'],
        ]);
        [$user, $pending] = $this->signIn->pending($request, $data['challenge']);

        $check = $this->otp->check($user, $data['code']);

        if ($check === OtpCheck::Burned) {
            throw ValidationException::withMessages(['code' => 'Too many incorrect attempts. Please request a new code.']);
        }

        if ($check === OtpCheck::Invalid) {
            throw ValidationException::withMessages(['code' => 'Invalid or expired code.']);
        }

        $this->signIn->release($data['challenge']);

        // The customer has proved they can receive mail for this account from
        // wherever they are: this country stops being challenged, as on the web.
        $this->countries->markProven($user, $request->ip());

        $device = TrustedDevice::query()->whereKey($pending['device_id'])->where('user_id', $user->id)->first();

        if ($device === null) {
            $this->signIn->expired();
        }

        $this->devices->trustAfterVerification($device);

        // The code went to the account's address, so typing it back proves the
        // address as the website's link does, and the welcome email follows
        // (Verified). Only while the address is still the one it was sent to.
        $sentTo = $pending['confirms_email'] ?? null;

        if ($sentTo !== null && hash_equals((string) $sentTo, (string) $user->email)
            && ! $user->hasVerifiedEmail() && $user->markEmailAsVerified()) {
            event(new Verified($user));
        }

        return $this->signIn->complete($user, new LoginAssessment($device->refresh(), (string) $pending['risk_level'], false));
    }

    public function resendCode(Request $request): JsonResponse
    {
        $data = $request->validate(['challenge' => ['required', 'string', 'size:64']]);
        [$user, $pending] = $this->signIn->pending($request, $data['challenge']);

        $outcome = $this->otp->resend($user, AppSignIn::codeContext($pending));

        if ($outcome === OtpResend::TooSoon) {
            return response()->json([
                'message' => 'Please wait before requesting another code.',
                'resend_in' => $this->otp->secondsUntilResend($user),
            ], 429);
        }

        if ($outcome === OtpResend::Exhausted) {
            return response()->json(['message' => 'Too many attempts. Please contact support.'], 429);
        }

        // A new code lives its own ten minutes, and the sign-in waits as long.
        $this->signIn->hold($data['challenge'], $pending);

        return response()->json([
            'message' => 'A new verification code has been sent to your email.',
            'expires_in' => $this->otp->secondsUntilExpiry($user),
            'resend_in' => $this->otp->secondsUntilResend($user),
        ]);
    }

    public function me(Request $request): JsonResponse
    {
        return response()->json(['user' => AppSignIn::profile($request->user())]);
    }

    public function logout(Request $request): Response
    {
        $token = $request->user()->currentAccessToken();

        if ($token instanceof PersonalAccessToken) {
            $session = $token->userSession;

            if ($session !== null && $session->revoked_at === null) {
                $session->forceFill(['revoked_at' => now()])->save();

                if ($token->device !== null && (int) $token->device->current_session_count > 0) {
                    $token->device->decrement('current_session_count');
                }
            }

            $token->delete();
        }

        return response()->noContent();
    }
}

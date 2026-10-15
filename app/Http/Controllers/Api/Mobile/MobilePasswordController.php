<?php

namespace App\Http\Controllers\Api\Mobile;

use App\Domain\Auth\Services\PasswordResetCode;
use App\Domain\Security\Enums\OtpCheck;
use App\Domain\Security\Enums\OtpResend;
use App\Domain\Security\Services\DeviceOtpService;
use App\Http\Controllers\Controller;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Auth\Events\Verified;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules;
use Illuminate\Validation\ValidationException;

/**
 * A forgotten password, reset in the mobile app: the website's reset by another
 * road. The website emails a link to its own page; the app emails a code
 * (PasswordResetCode), the code buys one reset on the phone that entered it,
 * and the new password is held to the website's rule and saved the website's
 * way, so every sign-in made with the old one ends, here and on the web.
 *
 * Nothing here says whether an address has an account, as the website's "A
 * reset link will be sent if the account exists" does not.
 */
class MobilePasswordController extends Controller
{
    public function __construct(
        private readonly PasswordResetCode $codes,
    ) {}

    /** A code to the address, the first or another one: the same answer either way. */
    public function forgot(Request $request): JsonResponse
    {
        AppSignIn::installationId($request);
        $email = (string) $request->validate(['email' => ['required', 'string', 'email', 'max:255']])['email'];

        $outcome = $this->codes->send($email);

        if ($outcome === OtpResend::TooSoon) {
            return response()->json([
                'message' => 'Please wait before requesting another code.',
                'resend_in' => $this->codes->secondsUntilNext($email),
            ], 429);
        }

        if ($outcome === OtpResend::Exhausted) {
            return response()->json(['message' => 'Too many codes requested today. Please try again tomorrow.'], 429);
        }

        return response()->json([
            'message' => 'If an account exists for this email, a 6-digit code is on its way.',
            'masked_email' => DeviceOtpService::maskEmail(Str::lower(trim($email))),
            'expires_in' => $this->codes->secondsUntilExpiry($email),
            'resend_in' => $this->codes->secondsUntilNext($email),
        ], 202);
    }

    /** The code, checked: right, it buys one reset on this phone. */
    public function verify(Request $request): JsonResponse
    {
        $installationId = AppSignIn::installationId($request);
        $data = $request->validate([
            'email' => ['required', 'string', 'email', 'max:255'],
            'code' => ['required', 'string', 'size:6'],
        ]);

        $check = $this->codes->check($data['email'], $data['code']);

        if ($check === OtpCheck::Burned) {
            throw ValidationException::withMessages(['code' => 'Too many incorrect attempts. Please request a new code.']);
        }

        $user = $check === OtpCheck::Valid ? PasswordResetCode::account($data['email']) : null;

        if ($user === null) {
            throw ValidationException::withMessages(['code' => 'Invalid or expired code.']);
        }

        // The code went to the account's address, so it proves the address as
        // the website's link does; an unconfirmed one is confirmed by it.
        if (! $user->hasVerifiedEmail() && $user->markEmailAsVerified()) {
            event(new Verified($user));
        }

        return response()->json(['ticket' => $this->codes->ticket($user, $installationId)]);
    }

    /** The new password, by the website's rule, saved the website's way. */
    public function reset(Request $request): JsonResponse
    {
        $installationId = AppSignIn::installationId($request);

        // The account the ticket is for, so the new password is also checked
        // against its name and email (HardToGuessPassword reads them from here).
        $holder = $this->codes->holder((string) $request->input('ticket'));

        $data = Validator::make(['email' => $holder?->email, 'name' => $holder?->name] + $request->all(), [
            'ticket' => ['required', 'string', 'size:64'],
            'password' => ['required', 'string', 'confirmed', Rules\Password::defaults()],
        ])->validate();

        $user = $this->codes->redeem($data['ticket'], $installationId);

        if ($user === null) {
            throw ValidationException::withMessages([
                'ticket' => 'This reset has expired. Please request a new code.',
            ]);
        }

        // As the website's reset-password: a new remember token signs out every
        // "remember me" browser, the new hash ends every app token
        // (EnsureAppTokenIsValid) and browser session, and PasswordReset is
        // audited. Any reset link the website emailed is spent with it.
        $user->forceFill([
            'password' => Hash::make($data['password']),
            'remember_token' => Str::random(60),
        ])->save();

        Password::broker()->deleteToken($user);
        event(new PasswordReset($user));

        return response()->json([
            'message' => __('passwords.reset'),
            'email' => $user->email,
        ]);
    }
}

<?php

namespace App\Http\Controllers\Api\V2\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V2\ForgotPasswordRequest;
use App\Http\Requests\Api\V2\ResetPasswordRequest;
use App\Models\User;
use App\Support\ApiV2Error;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;

/**
 * Forgotten passwords, from the app.
 *
 * Uses Laravel's password broker, which is the same broker and the same tokens
 * table the website uses, so a link requested in the app can be completed in the
 * browser and the other way round.
 *
 * A successful reset signs every device out, including this one. That is not a
 * convenience decision: the reason people reset a password is that somebody else
 * may have it, and leaving the thief's tokens alive would defeat the act. The
 * revocation is enforced on the User model itself, so it happens whichever route
 * writes the column.
 */
class PasswordController extends Controller
{
    public function forgot(ForgotPasswordRequest $request): JsonResponse
    {
        Password::sendResetLink(['email' => Str::lower((string) $request->input('email'))]);

        // Always the same answer, sent or not. Telling the caller whether an
        // address is registered would make this a customer-list oracle, and the
        // person who genuinely forgot learns nothing extra from the difference.
        return response()->json(['data' => [
            'status' => 'sent',
            'message' => 'A reset link will be sent if the account exists.',
        ]]);
    }

    public function reset(ResetPasswordRequest $request): JsonResponse
    {
        $status = Password::reset(
            [
                'email' => Str::lower((string) $request->input('email')),
                'password' => (string) $request->input('password'),
                'password_confirmation' => (string) $request->input('password_confirmation'),
                'token' => (string) $request->input('token'),
            ],
            function (User $user, string $password) {
                $user->forceFill([
                    'password' => Hash::make($password),
                    'remember_token' => Str::random(60),
                ])->save();

                event(new PasswordReset($user));
            },
        );

        if ($status !== Password::PasswordReset) {
            // One answer for an expired token, a wrong token and an unknown
            // address, for the same reason forgot() has one answer.
            return ApiV2Error::make(
                'reset_token_invalid',
                'This reset link is no longer valid. Please request a new one.',
                422,
            );
        }

        // No tokens are issued here. The customer signs in with the new password,
        // which is also the proof that the reset landed.
        return response()->json(['data' => [
            'status' => 'password_reset',
            'message' => 'Your password has been changed. Please sign in.',
        ]]);
    }
}

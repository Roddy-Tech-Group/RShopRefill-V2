<?php

namespace App\Http\Controllers\Api\V2\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V2\RegisterRequest;
use App\Http\Responses\V2\SignInResponder;
use App\Models\Referral;
use App\Models\User;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Propaganistas\LaravelPhone\PhoneNumber;

/**
 * Creating an account from the app.
 *
 * Mirrors the storefront register form step for step, including the pieces that
 * are easy to miss and expensive to leave out: the phone stored in E.164, the
 * two name fields folded back into users.name, profile_completed_at stamped so
 * the account never lands in the completion hole, referral attribution, and the
 * Registered event that creates the wallet and sends the verification mail.
 */
class RegisterController extends Controller
{
    public function __invoke(RegisterRequest $request, SignInResponder $responder): JsonResponse
    {
        $validated = $request->validated();

        // One line, one account. A unique rule on the national number could not
        // see that 0803... and +234803... are the same line, so the check is on
        // E.164, which is also the only format every gateway reads the same way.
        $phone = (new PhoneNumber($validated['phone'], [$validated['country']]))->formatE164();

        if (User::where('phone', $phone)->exists()) {
            throw ValidationException::withMessages(['phone' => __('auth.phone_taken')]);
        }

        $referralCode = trim((string) ($validated['referral'] ?? ''));

        // Consent is recorded by the act of registering, and the referral code is
        // not a users column. Neither may reach User::create().
        unset($validated['terms'], $validated['referral']);

        $validated['phone'] = $phone;

        // The split is a form concern only. users.name is still the single column
        // every screen reads to greet a customer.
        $validated['name'] = trim($validated['first_name'].' '.$validated['last_name']);
        unset($validated['first_name'], $validated['last_name']);

        $validated['password'] = Hash::make($validated['password']);

        // The app collects everything an account needs, so the signup is complete
        // on arrival and never meets the profile-completion step a Google
        // sign-in lands on.
        $validated['profile_completed_at'] = now();

        $user = User::create($validated);

        // Matches it against users.referral_code and, when it points at a
        // DIFFERENT real user, creates the row the reward engine reads once this
        // customer's first order completes. Silently no-ops on blank, no match,
        // or a self-referral.
        Referral::attribute($user, $referralCode);

        // Creates the wallet and sends the verification mail, same as the web.
        event(new Registered($user));

        // The web signs a new registrant in straight away, which fires Login and
        // writes the audit row for that first sign-in. The app's first sign-in
        // happens here too, so the same event is fired for the same listeners.
        event(new Login('sanctum', $user, false));

        // 201 either way: the account exists now, whether or not this first
        // device also has to prove itself with a code. It will not normally: the
        // account's first device is trusted at birth.
        return $responder->respond(
            $user,
            (string) $request->header('X-Installation-ID'),
            $request,
            201,
        );
    }
}

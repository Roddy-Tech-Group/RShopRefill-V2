<?php

namespace App\Http\Controllers\Api\Mobile;

use App\Domain\Auth\Services\CustomerRegistration;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\FeatureFlag;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Sign-up for the mobile app. It is the website's register wizard by another
 * road: CustomerRegistration holds the rules, the one-phone-one-account check
 * and the account itself. Where the web signs a new account straight in and
 * emails a link, the app emails a code, and the account is signed in on the
 * phone it was made on once the code is entered (AppSignIn), with its email
 * confirmed by it.
 */
class MobileRegistrationController extends Controller
{
    public function __construct(
        private readonly CustomerRegistration $registration,
        private readonly AppSignIn $signIn,
    ) {}

    /**
     * One page of the wizard, checked before the app moves on: an email that
     * is already registered is caught on the first page and a phone on the
     * second, so nobody learns it only after choosing a password.
     */
    public function check(Request $request): Response
    {
        $step = (int) $request->input('step');

        if ($step < 1 || $step >= CustomerRegistration::LAST_STEP) {
            throw ValidationException::withMessages(['step' => 'Unknown sign-up step.']);
        }

        if ($step === 1) {
            $this->refuseExistingEmail($request);
        }

        $data = $request->validate($this->registration->rulesForStep($step), $this->registration->messages());

        if ($step === 2) {
            $this->registration->ensurePhoneIsFree((string) $data['phone'], (string) $data['country']);
        }

        return response()->noContent();
    }

    public function register(Request $request): JsonResponse
    {
        // Closed here as the sign-up modal closes it, whatever the app shows.
        if (! FeatureFlag::on('signup')) {
            return response()->json(['message' => __('auth.registrations_closed')], 403);
        }

        AppSignIn::installationId($request);

        // The web form's key: five sign-ups a minute from one address, counted
        // across the website and the app together.
        $throttleKey = Str::transliterate('register|'.$request->ip());

        if (RateLimiter::tooManyAttempts($throttleKey, 5)) {
            event(new Lockout($request));
            $seconds = RateLimiter::availableIn($throttleKey);

            throw ValidationException::withMessages([
                'email' => __('auth.throttle', ['seconds' => $seconds, 'minutes' => ceil($seconds / 60)]),
            ])->status(429);
        }

        RateLimiter::hit($throttleKey);

        $this->refuseExistingEmail($request);

        $validated = $request->validate($this->registration->rules() + [
            // The app asks for this beside the terms (rshoprefill-mobileapp);
            // the Terms of Service already make 18 the minimum age.
            'adult' => ['accepted'],
            'referral' => ['nullable', 'string', 'max:64'],
            'device_name' => ['nullable', 'string', 'max:100'],
            'os' => ['nullable', 'string', 'max:50'],
        ], $this->registration->messages() + [
            'adult.accepted' => 'Please certify that you are 18 years of age or older to continue.',
        ]);

        $user = $this->registration->create(
            Arr::only($validated, array_keys($this->registration->rules())),
            $validated['referral'] ?? null,
            confirmsEmailByCode: true,
        );

        RateLimiter::clear($throttleKey);

        return $this->signIn->begin($user, $request, [
            'device_name' => $validated['device_name'] ?? null,
            'os' => $validated['os'] ?? null,
        ]);
    }

    /**
     * An email that already has an account is not a slip to correct on the
     * form: its owner belongs on Sign in. Answered as a conflict, apart from
     * field errors, so the app can take them straight there. The step check
     * already says whether an email is taken (as the web wizard does), so this
     * tells nobody anything new.
     */
    private function refuseExistingEmail(Request $request): void
    {
        $email = Str::lower(trim((string) $request->input('email')));

        if ($email !== '' && User::where('email', $email)->exists()) {
            throw new HttpResponseException(response()->json([
                'message' => 'An account with this email already exists. Sign in instead.',
                'account_exists' => true,
            ], 409));
        }
    }
}

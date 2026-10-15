<?php

namespace App\Domain\Auth\Services;

use App\Models\Referral;
use App\Models\User;
use App\Support\PhoneCountries;
use Illuminate\Auth\Events\Registered;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules;
use Illuminate\Validation\ValidationException;
use Propaganistas\LaravelPhone\PhoneNumber;
use Propaganistas\LaravelPhone\Rules\Phone;

/**
 * Creating a customer account, for every way one is created with a password:
 * the website's register wizard (livewire/auth/register) and the mobile app's
 * sign-up (Api\Mobile\MobileRegistrationController). One set of rules, one
 * phone check, one way the row is written, so the two can never disagree about
 * what a valid account is.
 *
 * What stays with each caller is what belongs to its surface: the website's
 * Turnstile, session and referral cookie; the app's token.
 */
final class CustomerRegistration
{
    /**
     * What each page of the form asks for. Presentation only: the final
     * submit validates everything, so skipping ahead gains nothing. The steps
     * are a way to present the fields without a wall of them, never the gate.
     */
    private const STEP_FIELDS = [
        1 => ['first_name', 'last_name', 'email'],
        2 => ['country', 'phone', 'gender'],
    ];

    public const LAST_STEP = 3;

    /**
     * The whole form's rules, in one place, so a step can borrow its slice and
     * the final submit can take the lot.
     *
     * max:100 a part, not 255: users.name holds both plus a space, and two
     * 255s would overflow the column at the insert rather than at the form.
     *
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'first_name' => ['required', 'string', 'max:100'],
            'last_name' => ['required', 'string', 'max:100'],
            'email' => ['required', 'string', 'lowercase', 'email', 'max:255', 'unique:'.User::class],
            'country' => ['required', 'string', Rule::in(PhoneCountries::isoCodes())],
            // The national number is validated against the chosen country's
            // numbering plan (libphonenumber). A trunk 0 the customer may type
            // is tolerated here and stripped when the number is stored.
            'phone' => ['required', 'string', 'max:32', (new Phone)->countryField('country')],
            'gender' => ['required', 'in:male,female,other'],
            'password' => ['required', 'string', 'confirmed', Rules\Password::defaults()],
            'terms' => ['accepted'],
        ];
    }

    /** @return array<string, array<int, mixed>> */
    public function rulesForStep(int $step): array
    {
        return array_intersect_key($this->rules(), array_flip(self::STEP_FIELDS[$step] ?? []));
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'terms.accepted' => 'Please accept the Terms of Service and Privacy Policy to continue.',
        ];
    }

    /**
     * One line, one account. A rule on the national number could not see that
     * 0803... and +234803... are the same line, so the check is on E.164.
     *
     * Checked when the phone page is left, as well as at the end, so a customer
     * who has signed up before learns it before choosing a password.
     */
    public function ensurePhoneIsFree(string $phone, string $country): void
    {
        if (User::where('phone', $this->phoneE164($phone, $country))->exists()) {
            throw ValidationException::withMessages([
                'phone' => __('auth.phone_taken'),
            ]);
        }
    }

    /**
     * The account, from input the full rules() have already passed.
     *
     * @param  array<string, mixed>  $validated
     */
    public function create(array $validated, ?string $referralCode): User
    {
        $this->ensurePhoneIsFree((string) $validated['phone'], (string) $validated['country']);

        // The number in E.164 (+2348031234567), the one format every gateway
        // and SMS provider reads the same way.
        $validated['phone'] = $this->phoneE164((string) $validated['phone'], (string) $validated['country']);

        // Consent is recorded by the act of registering; it is not a column on
        // users, so it must not reach User::create().
        unset($validated['terms']);

        // The split is a form concern only. users.name is still the single
        // column every screen reads to greet a customer, so the two fields are
        // joined back into it here rather than pushing the split onto the
        // schema and every caller of $user->name.
        $validated['name'] = trim($validated['first_name'].' '.$validated['last_name']);
        unset($validated['first_name'], $validated['last_name']);

        $validated['password'] = Hash::make($validated['password']);

        // The form collects everything an account needs, so the signup is
        // complete on arrival and never meets the profile-completion step that
        // a Google sign-in lands on.
        $validated['profile_completed_at'] = now();

        $user = User::create($validated);

        // Referral::attribute matches the code against users.referral_code
        // and, if it points at a DIFFERENT real user, creates the Referral row
        // the RewardEngine reads when this user's first order completes. It
        // silently no-ops on blank / no-match / self-referral.
        Referral::attribute($user, $referralCode);

        // The verification link goes out from here (MustVerifyEmail).
        event(new Registered($user));

        return $user;
    }

    private function phoneE164(string $phone, string $country): string
    {
        return (new PhoneNumber($phone, [$country]))->formatE164();
    }
}

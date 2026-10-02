<?php

namespace App\Http\Requests\Api\V2;

use App\Models\User;
use App\Support\PhoneCountries;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules;
use Propaganistas\LaravelPhone\Rules\Phone;

/**
 * Registration from the app.
 *
 * The rules are the storefront register form's rules, field for field. They are
 * not relaxed for the app: an account created on a phone has to be as complete
 * as one created in a browser, or it lands in the profile-completion hole that
 * Google sign-ups used to.
 *
 * max:100 on each name part, not 255: users.name holds both plus a space, and
 * two 255s would overflow the column at the insert rather than at the form.
 */
class RegisterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
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
            // Consent is recorded by the act of registering. Not a users column.
            'terms' => ['accepted'],
            'referral' => ['nullable', 'string', 'max:64'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'terms.accepted' => 'Please accept the Terms of Service and Privacy Policy to continue.',
        ];
    }
}

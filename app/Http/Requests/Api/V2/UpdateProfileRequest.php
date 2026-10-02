<?php

namespace App\Http\Requests\Api\V2;

use App\Support\PhoneCountries;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Propaganistas\LaravelPhone\Rules\Phone;

class UpdateProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Every field is `sometimes`, so the app can save one thing without needing to
     * resend the rest, and cannot wipe a field just by leaving it out.
     *
     * The EMAIL is absent on purpose. Changing it is changing the account, and on the
     * web that flow re-proves the password, notifies the old address and re-verifies
     * the new one. A thinner version here would be the weakest door into every
     * account we have.
     *
     * The phone is validated against the country's numbering plan, and a number given
     * in international form (+234...) is validated as such, matching how the
     * storefront's profile page treats it.
     *
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $phone = is_string($this->input('phone')) ? trim($this->input('phone')) : '';
        $international = str_starts_with($phone, '+');

        return [
            'name' => ['sometimes', 'required', 'string', 'max:255'],

            'phone' => [
                'sometimes',
                'nullable',
                'string',
                'max:32',
                $international ? (new Phone)->international() : (new Phone)->countryField('country'),
            ],

            // Needed to validate a national number, so it is required alongside
            // one. `sometimes` would skip every rule when the field is absent,
            // including the requirement itself, so it is only `sometimes` when no
            // national number is being sent.
            'country' => $phone !== '' && ! $international
                ? ['required', 'string', Rule::in(PhoneCountries::isoCodes())]
                : ['sometimes', 'nullable', 'string', Rule::in(PhoneCountries::isoCodes())],

            'gender' => ['sometimes', 'nullable', 'in:male,female,other'],

            // Which language mail and notifications render in.
            'locale' => ['sometimes', 'string', Rule::in(array_keys(config('localization.supported', ['en' => []])))],

            // Which currency the app shows prices in. Saved so a second device picks
            // up the same choice; the app still states it per request in
            // X-Display-Currency. Only a currency we can actually price in: any
            // three letters would be stored and then read back as a currency.
            'display_currency' => [
                'sometimes',
                'nullable',
                'string',
                'size:3',
                Rule::exists('currency_rates', 'code')->where('is_active', true)->where('type', 'fiat'),
            ],
        ];
    }
}

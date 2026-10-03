<?php

namespace App\Http\Requests\Api\V2;

use App\Domain\Payment\Support\SupportedPaymentMethods;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Authorising a payment session: which rail, and what that rail needs.
 *
 * The storefront controller this is delegated to validates these fields too, but
 * INSIDE a try/catch that turns a validation failure into a 400 reading "Payment
 * processing failed: The details.auth token field is required." Validated here
 * first, the app gets an ordinary 422 naming the field, and the delegate only ever
 * sees a request it can act on.
 *
 * Card details are never among these: a card is entered into the gateway's own
 * secure form, never sent to us.
 */
class AuthorisePaymentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('method'))) {
            $this->merge(['method' => strtolower(trim($this->input('method')))]);
        }
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'method' => ['required', 'string', Rule::in(SupportedPaymentMethods::allMethods())],

            'details' => ['sometimes', 'array'],

            // A wallet payment is authorised by the token from POST /transaction-pin/verify.
            'details.auth_token' => ['required_if:method,wallet', 'string', 'max:255'],

            // Mobile money needs the number the push goes to; the network is per rail.
            'details.phone_number' => ['required_if:method,mobile_money', 'string', 'regex:/^\+?[0-9 \-]{6,20}$/'],
            'details.network' => ['nullable', 'string', 'max:40'],

            // Crypto needs the coin the customer will pay in.
            'details.pay_currency' => ['required_if:method,crypto', 'string', 'max:20'],

            // A card issuer's follow-up (OTP, PIN) on a direct charge.
            'otp' => ['nullable', 'string', 'max:20'],
            'pin' => ['nullable', 'string', 'max:20'],
            'flw_ref' => ['nullable', 'string', 'max:255'],
        ];
    }
}

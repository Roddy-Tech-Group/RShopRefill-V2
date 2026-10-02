<?php

namespace App\Http\Requests\Api\V2;

use App\Domain\Payment\Support\SupportedPaymentMethods;
use App\Domain\Shared\Enums\Currency;
use App\Rules\ChargeableCurrency;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class PlaceOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        foreach (['currency', 'wallet_currency'] as $field) {
            if (is_string($this->input($field))) {
                $this->merge([$field => strtoupper(trim($this->input($field)))]);
            }
        }
    }

    /**
     * Mirrors the storefront's checkout inputs, which is deliberate: the same
     * CheckoutService runs behind both, and a field it expects but never receives
     * becomes a default silently applied to somebody's money.
     *
     * `cart_id` is required rather than inferred. The customer is paying for the
     * basket they were LOOKING at, and inferring "their active cart" would let a
     * second device's change, or a background sync, quietly alter what gets charged
     * between the review screen and the tap.
     *
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'cart_id' => ['required', 'uuid'],

            'payment_method' => ['required', 'string', Rule::in(['wallet', 'flutterwave', 'crypto'])],

            // The currency the customer is being charged in, which is not the same
            // as the one the app happens to be displaying prices in.
            //
            // It MUST be a currency with a live, active rate. The rate lookup behind
            // checkout falls back to the USD row for a code it does not know, so an
            // unknown or deactivated code would price a $5 cart as "5.00" of that
            // currency and hand the gateway that figure to charge. Three letters is
            // not a currency; a row in the active rate table is.
            'currency' => ['required', 'string', 'size:3', new ChargeableCurrency],

            // Which wallet to spend from, for a wallet payment across currencies.
            // A cash wallet: Rcoin is points and cannot settle an order on its own.
            'wallet_currency' => [
                'nullable',
                'string',
                'size:3',
                Rule::in(array_values(array_filter(
                    array_map(fn (Currency $currency) => $currency->value, Currency::cases()),
                    fn (string $code) => $code !== Currency::RCOIN->value,
                ))),
            ],

            // Where a gift card or voucher should be emailed, when it is not the
            // account's own address.
            'delivery_email' => ['nullable', 'email'],

            'coupon_code' => ['nullable', 'string', 'max:64'],

            'apply_rcoin' => ['nullable', 'boolean'],
        ];
    }

    /**
     * The currency has to accept the chosen rail, which is the check the website's
     * checkout form makes and the storefront's own API route forgot. Without it a
     * crypto order can be raised in a currency the crypto gateway does not price,
     * and a card order in one the card gateways refuse, leaving an order nobody can
     * pay.
     *
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($validator->errors()->hasAny(['currency', 'payment_method'])) {
                    return;
                }

                $currency = (string) $this->input('currency');
                $method = (string) $this->input('payment_method');

                // 'flutterwave' is the generic fiat rail; the specific method (card,
                // mobile money, transfer) is chosen at the pay step and checked again
                // there. Card is the one method every currency accepts, so that is
                // what the rail needs here: the same rule the storefront applies.
                $allowed = SupportedPaymentMethods::allows($currency, $method === 'flutterwave' ? 'card' : $method);

                if (! $allowed) {
                    $validator->errors()->add(
                        'payment_method',
                        "That payment method is not available for {$currency}.",
                    );
                }
            },
        ];
    }
}

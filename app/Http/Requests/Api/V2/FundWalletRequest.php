<?php

namespace App\Http\Requests\Api\V2;

use App\Domain\Shared\Enums\Currency;
use App\Rules\ChargeableCurrency;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class FundWalletRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        foreach (['currency', 'display_currency'] as $field) {
            if (is_string($this->input($field))) {
                $this->merge([$field => strtoupper(trim($this->input($field)))]);
            }
        }
    }

    /**
     * The amount is in the WALLET'S currency, which is the currency being added. A
     * customer topping up their naira wallet sends naira.
     *
     * The floor and ceiling are the wallet's own, per currency (Currency), checked
     * here so a 500-naira attempt is answered as a validation message naming the
     * minimum rather than reaching the service and coming back as a generic error.
     * The service checks them again; this is the same rule, answered earlier.
     *
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            // A CASH wallet. Rcoin is points, and the enum includes it.
            'currency' => ['required', 'string', Rule::in(self::cashCurrencies())],
            'amount' => ['required', 'numeric', 'decimal:0,4', 'gt:0'],

            // Which currency to quote the charge in, when the customer is paying for
            // this top-up from a card denominated in something else. Must carry a
            // live rate: the lookup behind funding falls back to the USD row for an
            // unknown code, which would quote a naira top-up at a fraction of its
            // value and credit the full amount.
            'display_currency' => ['nullable', 'string', 'size:3', new ChargeableCurrency],
        ];
    }

    /**
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($validator->errors()->hasAny(['currency', 'amount'])) {
                    return;
                }

                $currency = Currency::from((string) $this->input('currency'));
                $amount = (float) $this->input('amount');

                if ($amount < $currency->minimumFundingAmount()) {
                    $validator->errors()->add(
                        'amount',
                        'The minimum top-up is '.number_format($currency->minimumFundingAmount(), 2).' '.$currency->value.'.',
                    );
                }

                if ($amount > $currency->maximumFundingAmount()) {
                    $validator->errors()->add(
                        'amount',
                        'The maximum top-up per transaction is '.number_format($currency->maximumFundingAmount(), 2).' '.$currency->value.'.',
                    );
                }
            },
        ];
    }

    /**
     * @return list<string>
     */
    private static function cashCurrencies(): array
    {
        return array_values(array_filter(
            array_map(fn (Currency $currency) => $currency->value, Currency::cases()),
            fn (string $code) => $code !== Currency::RCOIN->value,
        ));
    }
}

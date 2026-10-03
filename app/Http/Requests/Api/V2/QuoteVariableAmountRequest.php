<?php

namespace App\Http\Requests\Api\V2;

use Illuminate\Foundation\Http\FormRequest;

class QuoteVariableAmountRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * The amount is in the VARIANT'S OWN currency: what the customer typed. A
     * Nigerian buying airtime enters 1000 meaning ₦1,000, and converting that to
     * the settlement currency is the server's job.
     *
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'variant_id' => ['required', 'integer', 'min:1'],
            // A plain decimal, bounded. `numeric` alone accepts "1e300".
            'amount' => ['required', 'numeric', 'decimal:0,4', 'gt:0', 'max:100000000'],
        ];
    }
}

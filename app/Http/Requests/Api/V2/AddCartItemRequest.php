<?php

namespace App\Http\Requests\Api\V2;

use Illuminate\Foundation\Http\FormRequest;

class AddCartItemRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * `amount` is only for VARIABLE products, and it is in the variant's own
     * currency: what the customer typed. A Nigerian buying airtime sends 1000
     * meaning ₦1,000. Converting to the settlement currency is the server's job, so
     * there is one rate and one rounding, and so the app cannot drift from us.
     *
     * The metadata rules mirror the storefront's exactly. They are not cosmetic:
     * a top-up with no recipient number cannot be delivered, and finding that out
     * after payment is how refunds happen.
     *
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'variant_id' => ['required', 'integer', 'exists:product_variants,id'],
            'quantity' => ['required', 'integer', 'min:1', 'max:100'],
            // A plain decimal, bounded. `numeric` alone accepts "1e300", which a
            // variant with no maximum would try to store and overflow on.
            'amount' => ['nullable', 'numeric', 'decimal:0,4', 'gt:0', 'max:100000000'],

            'metadata' => ['nullable', 'array'],
            'metadata.recipient_phone' => ['nullable', 'string', 'regex:/^\+?[0-9 \-]{6,20}$/'],
            'metadata.sender_phone' => ['nullable', 'string', 'regex:/^\+?[0-9 \-]{6,20}$/'],
            'metadata.delivery_email' => ['nullable', 'email'],
            'metadata.account_id' => ['nullable', 'string', 'regex:/^[A-Za-z0-9 \-]{4,30}$/'],
        ];
    }
}

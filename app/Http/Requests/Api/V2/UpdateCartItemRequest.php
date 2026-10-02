<?php

namespace App\Http\Requests\Api\V2;

use Illuminate\Foundation\Http\FormRequest;

class UpdateCartItemRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * An ABSOLUTE quantity, not a delta, which is what makes this endpoint safe to
     * retry without an idempotency key: setting it to 3 twice still means 3. Zero
     * removes the line.
     *
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'quantity' => ['required', 'integer', 'min:0', 'max:100'],
        ];
    }
}

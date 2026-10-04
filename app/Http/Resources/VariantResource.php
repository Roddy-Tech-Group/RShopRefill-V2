<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class VariantResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        // Staff-only fields. The SKU names the supplier ("AIRALO-..."), and
        // retail_price is the supplier's list price, not ours: neither belongs
        // in a customer's response. The admin catalogue API still gets both.
        $forStaff = $request->is('admin/*');

        return [
            'id' => $this->id,
            'sku' => $this->when($forStaff, fn () => $this->sku),
            'currency' => $this->currency,
            'face_value' => (float) $this->face_value,
            'retail_price' => $this->when($forStaff, fn () => (float) $this->retail_price),
            'is_variable' => $this->is_variable,
            'min_amount' => $this->min_amount ? (float) $this->min_amount : null,
            'max_amount' => $this->max_amount ? (float) $this->max_amount : null,
            'is_available' => $this->is_available,
        ];
    }
}

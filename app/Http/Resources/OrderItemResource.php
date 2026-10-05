<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class OrderItemResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'product_id' => $this->product_id,
            'product_name' => $this->product_snapshot['name'] ?? null,
            'product_logo' => $this->product_snapshot['logo_url'] ?? null,
            'quantity' => $this->quantity,
            'display_currency' => $this->display_currency,
            'display_amount' => (float) $this->display_amount,
            'subtotal_amount' => (float) $this->subtotal_amount,
            'fulfillment_status' => $this->fulfillment_status->value,
            'delivered_at' => $this->delivered_at?->toIso8601String(),
            'failed_at' => $this->failed_at?->toIso8601String(),

            // Deliver pins or eSIM profiles directly under a safe output block
            'delivery_details' => $this->when(
                $this->fulfillment_status->value === 'fulfilled',
                fn () => $this->extractDeliveryDetails()
            ),
        ];
    }

    private function extractDeliveryDetails(): array
    {
        $payload = $this->fulfillment_payload ?? [];

        return [
            'pins' => $payload['pins'] ?? [],
            'esim' => $payload['esim'] ?? null,
        ];
    }
}

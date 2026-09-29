<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class OrderResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $latestAttempt = $this->paymentAttempts->sortByDesc('created_at')->first();

        return [
            'id' => $this->id,
            'order_number' => $this->order_number,
            'display_currency' => $this->display_currency,
            'subtotal_amount' => (float) $this->subtotal_amount,
            // markup_amount is deliberately absent: it is our margin, not a
            // figure the customer pays or needs to see.
            'total_amount' => (float) $this->total_amount,
            'payment_method' => $this->payment_method,
            'payment_status' => $this->payment_status->value,
            'fulfillment_status' => $this->fulfillment_status->value,
            'order_status' => $this->order_status->value,
            'placed_at' => $this->placed_at?->toIso8601String(),
            'completed_at' => $this->completed_at?->toIso8601String(),
            'failed_at' => $this->failed_at?->toIso8601String(),
            'items' => OrderItemResource::collection($this->whenLoaded('items')),

            // Payment context
            'checkout_url' => $latestAttempt?->payment_url,
            'payment_expires_at' => $latestAttempt?->expires_at?->toIso8601String(),
        ];
    }
}

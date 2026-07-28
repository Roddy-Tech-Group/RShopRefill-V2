<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PaymentSessionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'provider' => $this->provider,
            'session_type' => $this->session_type,
            'status' => $this->status,
            'client_reference' => $this->client_reference,
            'amount' => (float) $this->amount,
            'currency' => $this->currency,
            'display_currency' => $this->display_currency,
            'payment_payload' => $this->payment_payload,
            'available_methods' => $this->getAvailableMethods(),
            'expires_at' => $this->expires_at?->toIso8601String(),
            'confirmed_at' => $this->confirmed_at?->toIso8601String(),
            'failed_at' => $this->failed_at?->toIso8601String(),
            'underpayment' => $this->underpaymentSummary(),
        ];
    }

    /**
     * Outstanding balance on a short crypto payment, or null when there isn't one.
     *
     * Surfaced on the session because the checkout has no other way to learn it:
     * the shortfall lives on the payment attempt, and a customer staring at a
     * spinner needs to be told they are short rather than left waiting for a
     * confirmation that will never come.
     *
     * @return array<string, mixed>|null
     */
    private function underpaymentSummary(): ?array
    {
        $attempt = $this->paymentAttempt;

        if (! $attempt || ! $attempt->isUnderpaid()) {
            return null;
        }

        return [
            'expected' => (string) $attempt->pay_amount,
            'received' => (string) $attempt->actually_paid,
            'shortfall' => (string) abs((float) $attempt->settlement_delta),
            'currency' => strtoupper((string) $attempt->pay_currency),
            'can_top_up' => $attempt->purchase_id !== null,
        ];
    }
}

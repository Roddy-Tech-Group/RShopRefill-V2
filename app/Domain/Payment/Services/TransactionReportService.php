<?php

namespace App\Domain\Payment\Services;

use App\Models\PaymentAttempt;
use Illuminate\Support\Str;

/**
 * Assembles the financial receipt for one payment attempt: the money facts
 * (amount, fees, settlement, references, timeline), who paid, and what it paid
 * for. Deliberately lean - orders have their own full report, so this one
 * points at the order instead of duplicating it. Read-only: assembling never
 * mutates; the CONTROLLER audits the act of generating.
 */
class TransactionReportService
{
    /**
     * @return array<string, mixed>
     */
    public function assemble(PaymentAttempt $attempt, string $generatedBy): array
    {
        $attempt->load(['user', 'order', 'paymentSession']);

        return [
            'report' => [
                'id' => (string) Str::uuid(),
                'generated_at' => now(),
                'generated_by' => $generatedBy,
            ],
            'transaction' => $this->transactionSection($attempt),
            'customer' => $this->customerSection($attempt),
            'order' => $this->orderSection($attempt),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function transactionSection(PaymentAttempt $attempt): array
    {
        $session = $attempt->paymentSession;

        return [
            'id' => $attempt->id,
            'gateway' => $attempt->gateway,
            'payment_method' => $attempt->payment_method,
            'gateway_reference' => $attempt->gateway_reference,
            'idempotency_key' => $attempt->idempotency_key,
            'status' => (string) ($attempt->payment_status->value ?? $attempt->payment_status),
            'amount' => (float) $attempt->amount,
            'currency' => $attempt->currency,
            'exchange_rate' => $attempt->exchange_rate_snapshot,
            'gateway_fee' => $attempt->gateway_fee,
            'settlement_delta' => $attempt->settlement_delta,
            'created_at' => $attempt->created_at,
            'confirmed_at' => $attempt->confirmed_at,
            'failed_at' => $attempt->failed_at,
            'session_id' => $session?->id,
            // Crypto settlement facts, when present.
            'pay_currency' => $attempt->pay_currency,
            'pay_amount' => $attempt->pay_amount,
            'actually_paid' => $attempt->actually_paid,
            'network' => $attempt->network,
            'outcome_amount' => $attempt->outcome_amount,
            'outcome_currency' => $attempt->outcome_currency,
            'context' => $attempt->order ? 'order' : 'wallet_funding',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function customerSection(PaymentAttempt $attempt): array
    {
        $user = $attempt->user;

        if (! $user) {
            return ['missing' => true];
        }

        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
        ];
    }

    /**
     * A pointer at the order, never a copy of it - the order report carries
     * the full story.
     *
     * @return array<string, mixed>|null
     */
    private function orderSection(PaymentAttempt $attempt): ?array
    {
        $order = $attempt->order;

        if (! $order) {
            return null;
        }

        return [
            'number' => $order->order_number,
            'total_usd' => $order->usdTotal(),
        ];
    }
}

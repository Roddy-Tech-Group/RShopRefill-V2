<?php

namespace App\Domain\Order\Services;

use App\Domain\Audit\Models\AuditLog;
use App\Domain\Kyc\Services\KycTierService;
use App\Models\CommunicationLog;
use App\Models\Order;
use App\Models\User;
use Illuminate\Support\Str;

/**
 * Assembles the complete evidence bundle for one order: everything an acquirer,
 * a card-scheme representment, or an internal investigation needs, drawn from
 * the immutable stores (orders, payment attempts, risk assessments + signals,
 * communication log, audit trail). Read-only: assembling a report never mutates
 * anything; the CONTROLLER audits the act of generating it.
 */
class OrderReportService
{
    public function __construct(private readonly KycTierService $tiers) {}

    /**
     * @return array<string, mixed>
     */
    public function assemble(Order $order, string $generatedBy): array
    {
        $order->load(['user', 'items.product', 'paymentAttempts.paymentSession']);
        $user = $order->user;

        return [
            'report' => [
                'id' => (string) Str::uuid(),
                'generated_at' => now(),
                'generated_by' => $generatedBy,
            ],
            'order' => $this->orderSection($order),
            'customer' => $this->customerSection($user),
            'items' => $this->itemsSection($order),
            'payments' => $this->paymentsSection($order),
            'communications' => $this->communicationsSection($order, $user),
            'audit' => $this->auditSection($order, $user),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function orderSection(Order $order): array
    {
        return [
            'number' => $order->order_number,
            'id' => $order->id,
            'placed_at' => $order->placed_at ?? $order->created_at,
            'order_status' => (string) ($order->order_status->value ?? $order->order_status),
            'payment_status' => (string) ($order->payment_status->value ?? $order->payment_status),
            'fulfillment_status' => (string) ($order->fulfillment_status->value ?? $order->fulfillment_status),
            'payment_method' => $order->payment_method,
            'display_currency' => $order->display_currency,
            'total_display' => (float) $order->total_amount,
            'total_usd' => $order->usdTotal(),
            'exchange_rate' => $order->exchangeRate(),
            'delivery_email' => $order->metadata['delivery_email'] ?? null,
            'device_ip' => $order->metadata['device_ip'] ?? null,
            'user_agent' => $order->metadata['user_agent'] ?? null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function customerSection(?User $user): array
    {
        if (! $user) {
            return ['missing' => true];
        }

        // The name on the ID when the customer is identity verified: a report
        // names who paid, whatever they chose to be called on the platform.
        $identity = $user->isKycVerified() ? $user->verifiedIdentity() : null;

        return [
            'id' => $user->id,
            'name' => $user->name,
            'legal_name' => $identity?->legal_name,
            'goes_by_another_name' => (bool) $identity?->legalNameDiffersFrom($user->name),
            'email' => $user->email,
            'email_verified_at' => $user->email_verified_at,
            'phone' => $user->phone,
            'phone_verified_at' => $user->phone_verified_at,
            'country' => $user->country,
            'registered_at' => $user->created_at,
            'is_guest' => (bool) $user->is_guest,
            'kyc_status' => $user->kyc_status,
            'kyc_tier' => $this->tiers->currentTier($user)->label(),
            'banned_at' => $user->banned_at,
            'suspended_at' => $user->suspended_at,
            'lifetime_volume_usd' => $this->tiers->lifetimeVolumeUsd($user),
        ];
    }

    /**
     * An item's subtotal is stored in the customer's display currency, so it
     * is converted with the order's own rate snapshot, the same way the admin
     * order page does. The customer's amount rides along when it differs from
     * USD and the order's pricing can be trusted.
     *
     * @return list<array<string, mixed>>
     */
    private function itemsSection(Order $order): array
    {
        $rate = $order->exchangeRate();
        $showCustomerAmount = ! $order->hasSuspectPricing();

        return $order->items->map(fn ($item) => [
            'name' => $item->product_snapshot['name'] ?? $item->product?->name ?? 'Item',
            'category' => $item->compliance_category->value ?? (string) $item->compliance_category,
            'quantity' => (int) $item->quantity,
            'subtotal_usd' => $rate
                ? round((float) $item->subtotal_amount / $rate, 4)
                : (float) $item->subtotal_amount,
            'subtotal_display' => (float) $item->subtotal_amount,
            'display_currency' => $showCustomerAmount && strtoupper((string) $item->display_currency) !== 'USD'
                ? strtoupper((string) $item->display_currency)
                : null,
            'provider' => $item->provider_name,
            'fulfillment_status' => (string) ($item->fulfillment_status->value ?? $item->fulfillment_status),
            'fulfillment_reference' => $item->fulfillment_reference,
            'delivered_at' => $item->delivered_at,
        ])->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function paymentsSection(Order $order): array
    {
        return $order->paymentAttempts->map(function ($attempt) {
            $session = $attempt->paymentSession;

            return [
                'attempt_id' => $attempt->id,
                'gateway' => $attempt->gateway,
                'gateway_reference' => $attempt->gateway_reference,
                'status' => (string) ($attempt->payment_status->value ?? $attempt->payment_status),
                'amount' => (float) $attempt->amount,
                'currency' => $attempt->currency,
                'created_at' => $attempt->created_at,
                'confirmed_at' => $attempt->confirmed_at,
                'failed_at' => $attempt->failed_at,
                'session_id' => $session?->id,
                'session_status' => $session ? (string) ($session->status->value ?? $session->status) : null,
                // Crypto settlement facts, when present.
                'pay_currency' => $attempt->pay_currency,
                'pay_amount' => $attempt->pay_amount,
                'actually_paid' => $attempt->actually_paid,
            ];
        })->all();
    }

    /**
     * The communications for THIS order only: its own correlation ids, plus the
     * customer's order-category mail inside the order's time window, so the
     * section stays short and relevant instead of dumping their whole history.
     *
     * @return list<array<string, mixed>>
     */
    private function communicationsSection(Order $order, ?User $user): array
    {
        $correlations = collect(['order_confirmed_'.$order->id, 'order_refund_'.$order->id])
            ->merge($order->items->map(fn ($i) => 'order_fulfilled_'.$i->id))
            ->all();

        $windowStart = ($order->placed_at ?? $order->created_at)->copy()->subHour();
        $windowEnd = ($order->completed_at ?? $order->placed_at ?? $order->created_at)->copy()->addDay();

        // Summarised, never a raw dump: identical events (same channel,
        // category and delivery status) collapse into one line with a count
        // and the first/last occurrence, so a retried email reads as one fact.
        return CommunicationLog::query()
            ->where(function ($q) use ($correlations, $user, $windowStart, $windowEnd) {
                $q->whereIn('correlation_id', $correlations);

                if ($user) {
                    $q->orWhere(fn ($qq) => $qq->where('user_id', $user->id)
                        ->whereIn('category', ['order_placed', 'order_fulfilled'])
                        ->whereBetween('created_at', [$windowStart, $windowEnd]));
                }
            })
            ->orderBy('created_at')
            ->limit(60)
            ->get()
            ->groupBy(fn ($log) => implode('|', [
                (string) ($log->channel->value ?? $log->channel),
                (string) ($log->category->value ?? $log->category),
                (string) ($log->status->value ?? $log->status),
            ]))
            ->map(fn ($group) => [
                'channel' => (string) ($group->first()->channel->value ?? $group->first()->channel),
                'category' => (string) ($group->first()->category->value ?? $group->first()->category),
                'status' => (string) ($group->first()->status->value ?? $group->first()->status),
                'count' => $group->count(),
                'first_at' => $group->min('created_at'),
                'last_at' => $group->max('created_at'),
            ])
            ->values()
            ->take(10)
            ->all();
    }

    /**
     * Proof the account holder was signed in before the order happened: the
     * last few logins BEFORE placement, time and IP, nothing more. Everything
     * else lives in the admin Audit Log.
     *
     * @return list<array<string, mixed>>
     */
    private function auditSection(Order $order, ?User $user): array
    {
        if (! $user) {
            return [];
        }

        $placedAt = $order->placed_at ?? $order->created_at;

        return AuditLog::query()
            ->where('model_type', User::class)
            ->where('model_id', $user->id)
            ->where('action', 'user_login')
            ->where('created_at', '<=', $placedAt)
            ->latest()
            ->limit(3)
            ->get()
            ->map(fn ($log) => [
                'at' => $log->created_at,
                'ip' => $log->ip_address,
            ])
            ->all();
    }
}

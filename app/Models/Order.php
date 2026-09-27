<?php

namespace App\Models;

use App\Domain\Fulfillment\Enums\FulfillmentStatus;
use App\Domain\Order\Enums\OrderStatus;
use App\Domain\Payment\Enums\PaymentStatus;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property int $user_id
 * @property string $order_number
 * @property string|null $cart_id
 * @property string $settlement_currency
 * @property string $display_currency
 * @property float $subtotal_amount
 * @property float $markup_amount
 * @property float $total_amount
 * @property string $payment_method
 * @property PaymentStatus $payment_status
 * @property FulfillmentStatus $fulfillment_status
 * @property OrderStatus $order_status
 * @property string|null $provider_status
 * @property string|null $provider_reference
 * @property Carbon|null $placed_at
 * @property Carbon|null $completed_at
 * @property Carbon|null $failed_at
 * @property array|null $metadata
 */
class Order extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'user_id',
        'order_number',
        'cart_id',
        'settlement_currency',
        'display_currency',
        'subtotal_amount',
        'markup_amount',
        'total_amount',
        'payment_method',
        'payment_status',
        'fulfillment_status',
        'order_status',
        'provider_status',
        'provider_reference',
        'placed_at',
        'completed_at',
        'failed_at',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'payment_status' => PaymentStatus::class,
            'fulfillment_status' => FulfillmentStatus::class,
            'order_status' => OrderStatus::class,
            'subtotal_amount' => 'decimal:4',
            'markup_amount' => 'decimal:4',
            'total_amount' => 'decimal:4',
            'placed_at' => 'datetime',
            'completed_at' => 'datetime',
            'failed_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    public function paymentAttempts(): HasMany
    {
        return $this->hasMany(PaymentAttempt::class);
    }

    /**
     * The channel the customer paid through, in customer words. Never a
     * provider name. The paid attempt knows best; failing one, the latest
     * attempt of any state; an order that never held an attempt (a wallet
     * purchase, or a legacy row) answers from its own method.
     */
    public function customerGatewayLabel(): string
    {
        // Ids are time-ordered UUIDs: a tie within one second still has a
        // definite newest.
        $attempts = $this->paymentAttempts->sortBy([['created_at', 'desc'], ['id', 'desc']]);

        $attempt = $attempts->first(fn (PaymentAttempt $attempt) => $attempt->payment_status === PaymentStatus::Paid)
            ?? $attempts->first();

        if ($attempt !== null) {
            return $attempt->customerGatewayLabel();
        }

        return match ($this->payment_method) {
            'wallet' => 'Wallet',
            'crypto' => 'Crypto',
            default => 'Card',
        };
    }

    // ────────────────────────────────────────────────────────────
    //  Admin-facing USD accessors
    //
    //  The admin dashboard needs the source-of-truth USD figures, not the
    //  customer's display_currency conversion (which can be wrong on legacy
    //  rows where the exchange rate was missing or applied as 1.0). These
    //  helpers prefer the snapshot metadata, fall back to deriving from the
    //  display amounts via the stored exchange rate, and finally fall back
    //  to the raw display amount if neither is present.
    // ────────────────────────────────────────────────────────────

    /**
     * The platform's settlement (USD) exchange rate captured at order time,
     * or null if the order pre-dates the snapshot.
     */
    public function exchangeRate(): ?float
    {
        $rate = $this->metadata['exchange_rate'] ?? null;

        return is_numeric($rate) && (float) $rate > 0 ? (float) $rate : null;
    }

    /**
     * The part of the order the customer paid in cash, from 0 to 1. The rest
     * was paid with Rcoin, which is not revenue.
     *
     * Checkout takes Rcoin off the order total after the item prices are
     * set, so the items still carry their full price. settlement_total_usd is
     * what was left to pay in cash and rcoin_discount_usd what the Rcoin
     * covered, both in USD whatever the display currency. An order with no
     * Rcoin, or without those figures, is paid in full: 1.
     *
     * DashboardMetricsQuery::itemCashUsdSql() applies the same rule in SQL.
     */
    public function cashShare(): float
    {
        $cash = $this->metadata['settlement_total_usd'] ?? null;
        $rcoin = $this->metadata['rcoin_discount_usd'] ?? null;

        if (! is_numeric($cash) || ! is_numeric($rcoin) || (float) $rcoin <= 0) {
            return 1.0;
        }

        $paid = (float) $cash + (float) $rcoin;

        return $paid > 0 ? (float) $cash / $paid : 1.0;
    }

    public function usdTotal(): float
    {
        return $this->resolveUsdAmount('settlement_total_usd', (float) $this->total_amount);
    }

    public function usdSubtotal(): float
    {
        return $this->resolveUsdAmount('settlement_subtotal_usd', (float) $this->subtotal_amount);
    }

    public function usdMarkup(): float
    {
        return max(0, round($this->usdTotal() - $this->usdSubtotal(), 4));
    }

    /**
     * Pricing data is suspect when the display currency is something other
     * than USD but no exchange rate was recorded — those are pre-snapshot
     * orders whose `total_amount` is actually the raw USD figure mis-labelled
     * with a non-USD currency. Surfacing this badge lets admins know not to
     * trust the customer-facing number on these rows.
     */
    public function hasSuspectPricing(): bool
    {
        $display = strtoupper((string) $this->display_currency);

        if ($display === '' || $display === 'USD') {
            return false;
        }

        return $this->exchangeRate() === null;
    }

    /**
     * Prefer the snapshot key; otherwise derive USD by dividing the display
     * amount by the recorded rate. As a last resort return the display
     * amount as-is (which is the only honest answer when neither piece of
     * provenance is present).
     */
    private function resolveUsdAmount(string $metadataKey, float $displayAmount): float
    {
        $snapshot = $this->metadata[$metadataKey] ?? null;
        if (is_numeric($snapshot)) {
            return round((float) $snapshot, 4);
        }

        $rate = $this->exchangeRate();
        if ($rate !== null && $rate > 0) {
            return round($displayAmount / $rate, 4);
        }

        return round($displayAmount, 4);
    }
}

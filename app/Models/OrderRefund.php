<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One refund, recorded once and never edited except to note the supplier's
 * credit. Written by RefundLedger from OrderService::applyRefundRecord, the one
 * place every refund already passes through.
 *
 * A customer_refund reverses revenue in the month of refunded_at. A
 * fulfilment_reversal returns money for an item that never delivered and
 * reverses nothing, because that item was never counted as revenue. A supplier
 * credit reduces supplier cost in the month of supplier_credited_at, matching
 * the supplier's own monthly invoice.
 *
 * @property int $id
 * @property string $order_id
 * @property string|null $order_item_id
 * @property int|null $user_id
 * @property string $kind
 * @property string $tag
 * @property string $amount
 * @property string $currency
 * @property string $revenue_reversed_usd
 * @property array<int, array{order_item_id: string, provider: string, category_id: int|null, revenue_reversed_usd: float}>|null $allocations
 * @property string|null $gateway
 * @property string|null $reason
 * @property int|null $refunded_by
 * @property Carbon $refunded_at
 * @property string $supplier_credit_status
 * @property string|null $supplier_credit_usd
 * @property string|null $supplier_credit_reference
 * @property string|null $supplier_credit_reason
 * @property Carbon|null $supplier_credited_at
 * @property array<string, mixed>|null $metadata
 */
class OrderRefund extends Model
{
    use HasFactory;

    public const KIND_CUSTOMER_REFUND = 'customer_refund';

    public const KIND_FULFILMENT_REVERSAL = 'fulfilment_reversal';

    /** No supplier credit is expected (gift cards, top-ups, reversals). */
    public const CREDIT_NOT_APPLICABLE = 'not_applicable';

    /** Claim filed with the supplier, not yet granted. */
    public const CREDIT_PENDING = 'pending';

    /** The supplier credited its account; supplier cost is recovered. */
    public const CREDIT_CREDITED = 'credited';

    /** The supplier refused the claim; the cost stays sunk. */
    public const CREDIT_REJECTED = 'rejected';

    protected $fillable = [
        'order_id',
        'order_item_id',
        'user_id',
        'kind',
        'tag',
        'amount',
        'currency',
        'revenue_reversed_usd',
        'allocations',
        'gateway',
        'reason',
        'refunded_by',
        'refunded_at',
        'supplier_credit_status',
        'supplier_credit_usd',
        'supplier_credit_reference',
        'supplier_credit_reason',
        'supplier_credited_at',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:4',
            'revenue_reversed_usd' => 'decimal:4',
            'supplier_credit_usd' => 'decimal:4',
            'allocations' => 'array',
            'metadata' => 'array',
            'refunded_at' => 'datetime',
            'supplier_credited_at' => 'datetime',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function orderItem(): BelongsTo
    {
        return $this->belongsTo(OrderItem::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function refundedBy(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'refunded_by');
    }

    public function isCustomerRefund(): bool
    {
        return $this->kind === self::KIND_CUSTOMER_REFUND;
    }

    public function isSupplierCreditPending(): bool
    {
        return $this->supplier_credit_status === self::CREDIT_PENDING;
    }
}

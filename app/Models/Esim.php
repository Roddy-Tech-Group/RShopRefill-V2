<?php

namespace App\Models;

use App\Domain\Fulfillment\Enums\EsimStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One eSIM we sold. Written by EsimRegistry when its order item delivers,
 * kept current by the usage sync, and the row the admin refunds from.
 *
 * @property int $id
 * @property string $order_item_id
 * @property string $order_id
 * @property int|null $user_id
 * @property string $provider
 * @property string $iccid
 * @property string|null $package_name
 * @property string|null $package_code
 * @property EsimStatus $status
 * @property string|null $provider_status
 * @property int|null $data_total_mb
 * @property int|null $data_remaining_mb
 * @property bool $is_unlimited
 * @property Carbon $purchased_at
 * @property Carbon|null $activated_at
 * @property Carbon|null $expires_at
 * @property string|null $refund_status
 * @property string|null $refund_amount
 * @property string|null $refund_currency
 * @property string|null $refund_reason
 * @property string|null $refund_note
 * @property string|null $refund_claim_reference
 * @property int|null $refund_requested_by
 * @property Carbon|null $refund_requested_at
 * @property int|null $refund_resolved_by
 * @property Carbon|null $refund_resolved_at
 * @property string|null $refund_resolution_note
 * @property Carbon|null $refunded_at
 * @property int|null $order_refund_id
 * @property Carbon|null $last_synced_at
 * @property string|null $sync_error
 * @property array<string, mixed>|null $metadata
 */
class Esim extends Model
{
    use HasFactory;

    /** Claim sent to the supplier; no money has moved. */
    public const REFUND_PENDING = 'pending';

    /** The supplier credited us and the customer's wallet was credited. */
    public const REFUND_COMPLETED = 'completed';

    /** The supplier refused; nothing moved. */
    public const REFUND_REJECTED = 'rejected';

    protected $fillable = [
        'order_item_id',
        'order_id',
        'user_id',
        'provider',
        'iccid',
        'package_name',
        'package_code',
        'status',
        'provider_status',
        'data_total_mb',
        'data_remaining_mb',
        'is_unlimited',
        'purchased_at',
        'activated_at',
        'expires_at',
        'refund_status',
        'refund_amount',
        'refund_currency',
        'refund_reason',
        'refund_note',
        'refund_claim_reference',
        'refund_requested_by',
        'refund_requested_at',
        'refund_resolved_by',
        'refund_resolved_at',
        'refund_resolution_note',
        'refunded_at',
        'order_refund_id',
        'last_synced_at',
        'sync_error',
        'metadata',
    ];

    protected $attributes = [
        'status' => 'inactive',
        'is_unlimited' => false,
    ];

    protected function casts(): array
    {
        return [
            'status' => EsimStatus::class,
            'data_total_mb' => 'integer',
            'data_remaining_mb' => 'integer',
            'is_unlimited' => 'boolean',
            'purchased_at' => 'datetime',
            'activated_at' => 'datetime',
            'expires_at' => 'datetime',
            'refund_amount' => 'decimal:4',
            'refund_requested_at' => 'datetime',
            'refund_resolved_at' => 'datetime',
            'refunded_at' => 'datetime',
            'last_synced_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    public function orderItem(): BelongsTo
    {
        return $this->belongsTo(OrderItem::class);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function refund(): BelongsTo
    {
        return $this->belongsTo(OrderRefund::class, 'order_refund_id');
    }

    public function refundRequestedBy(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'refund_requested_by');
    }

    public function refundResolvedBy(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'refund_resolved_by');
    }

    public function isRefunded(): bool
    {
        return $this->refunded_at !== null;
    }

    public function isRefundPending(): bool
    {
        return $this->refund_status === self::REFUND_PENDING;
    }

    /**
     * "1.2 GB left of 5 GB", "Unlimited", or null before the first sync.
     */
    public function dataLabel(): ?string
    {
        if ($this->is_unlimited) {
            return 'Unlimited';
        }

        if ($this->data_total_mb === null) {
            return null;
        }

        return self::formatMb((int) $this->data_remaining_mb).' left of '.self::formatMb($this->data_total_mb);
    }

    /**
     * Share of the data left, 0 to 100, for the usage bar.
     */
    public function dataRemainingPercent(): ?int
    {
        if ($this->is_unlimited || ! $this->data_total_mb) {
            return null;
        }

        return (int) max(0, min(100, round(((int) $this->data_remaining_mb / $this->data_total_mb) * 100)));
    }

    public static function formatMb(int $mb): string
    {
        return $mb >= 1024
            ? rtrim(rtrim(number_format($mb / 1024, 2), '0'), '.').' GB'
            : $mb.' MB';
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One row per coupon use, replacing the old `coupons.used_count` increment that
 * counted intent rather than redemption. A row is reserved at checkout, flipped
 * to redeemed on payment confirmation, and released when the checkout is
 * abandoned, so a slot is only consumed by someone who actually pays.
 */
class CouponRedemption extends Model
{
    public const STATUS_RESERVED = 'reserved';

    public const STATUS_REDEEMED = 'redeemed';

    public const STATUS_RELEASED = 'released';

    protected $fillable = [
        'coupon_id',
        'order_id',
        'user_id',
        'status',
        'discount_usd',
        'expires_at',
        'redeemed_at',
        'released_at',
        'release_reason',
    ];

    protected function casts(): array
    {
        return [
            'discount_usd' => 'decimal:4',
            'expires_at' => 'datetime',
            'redeemed_at' => 'datetime',
            'released_at' => 'datetime',
        ];
    }

    public function coupon(): BelongsTo
    {
        return $this->belongsTo(Coupon::class);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * The rows that consume a coupon's allowance: everything already paid for,
     * plus reservations still inside their window. A lapsed reservation counts
     * against nothing, which is what lets an abandoned checkout free its slot
     * even if no release ever runs.
     */
    public function scopeConsuming(Builder $query): Builder
    {
        return $query->where(function (Builder $inner) {
            $inner->where('status', self::STATUS_REDEEMED)
                ->orWhere(function (Builder $live) {
                    $live->where('status', self::STATUS_RESERVED)
                        ->where(function (Builder $window) {
                            $window->whereNull('expires_at')->orWhere('expires_at', '>', now());
                        });
                });
        });
    }
}

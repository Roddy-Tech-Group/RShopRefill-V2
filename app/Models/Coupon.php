<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Coupon extends Model
{
    protected $fillable = [
        'product_variant_id',
        'code',
        'discount_type',
        'discount_value',
        'max_uses',
        'used_count',
        'valid_from',
        'valid_until',
        'is_active',
        'allow_below_cost',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'discount_value' => 'decimal:4',
            'max_uses' => 'integer',
            'used_count' => 'integer',
            'valid_from' => 'datetime',
            'valid_until' => 'datetime',
            'is_active' => 'boolean',
            'allow_below_cost' => 'boolean',
        ];
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id');
    }

    public function redemptions(): HasMany
    {
        return $this->hasMany(CouponRedemption::class);
    }

    /**
     * Uses actually consumed: confirmed redemptions plus live reservations.
     *
     * This is the authoritative figure, NOT the `used_count` column. The column
     * is a cache for admin listings and can only ever be as fresh as the last
     * sync, whereas a reservation stops counting the moment it lapses. Reading
     * the table is what makes an abandoned checkout free its slot even if no
     * release ever ran for it.
     */
    public function consumedCount(): int
    {
        return $this->redemptions()->consuming()->count();
    }

    public function isExpired(): bool
    {
        return $this->valid_until !== null && $this->valid_until->isPast();
    }

    public function isNotYetValid(): bool
    {
        return $this->valid_from !== null && $this->valid_from->isFuture();
    }

    public function isUsedUp(): bool
    {
        return $this->max_uses !== null && $this->consumedCount() >= $this->max_uses;
    }

    /**
     * A coupon is redeemable when it's active, within its date window, and
     * has remaining uses. Centralised here so the checkout path and admin UI
     * agree on the same definition of "usable".
     */
    public function isRedeemable(): bool
    {
        return $this->is_active
            && ! $this->isExpired()
            && ! $this->isNotYetValid()
            && ! $this->isUsedUp();
    }

    /**
     * Apply this coupon to a USD sales price and return the final amount,
     * never going below zero. Used by both the admin preview and (later) the
     * checkout redemption path.
     */
    public function applyTo(float $salesPriceUsd): float
    {
        $discount = $this->discount_type === 'percent'
            ? $salesPriceUsd * ((float) $this->discount_value / 100)
            : (float) $this->discount_value;

        return max(0.0, round($salesPriceUsd - $discount, 2));
    }
}

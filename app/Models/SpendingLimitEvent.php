<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One customer reaching one spending cap, once.
 *
 * Written by SpendingLimitService whether the cap was enforced or only
 * observed, so `limits:report` can answer "who would this stop?" before the
 * flag flips and "who is it stopping?" after.
 *
 * @property int $id
 * @property int|null $user_id
 * @property string $context
 * @property string $limit_window
 * @property string $limit_usd
 * @property string $money_in_usd
 * @property string $amount_usd
 * @property bool $kyc_verified
 * @property bool $enforced
 * @property Carbon $created_at
 */
class SpendingLimitEvent extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'context',
        'limit_window',
        'limit_usd',
        'money_in_usd',
        'amount_usd',
        'kyc_verified',
        'enforced',
    ];

    protected function casts(): array
    {
        return [
            'limit_usd' => 'decimal:4',
            'money_in_usd' => 'decimal:4',
            'amount_usd' => 'decimal:4',
            'kyc_verified' => 'boolean',
            'enforced' => 'boolean',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * How far past the cap this action would have taken the customer.
     */
    public function overshootUsd(): float
    {
        return round(((float) $this->money_in_usd + (float) $this->amount_usd) - (float) $this->limit_usd, 2);
    }
}

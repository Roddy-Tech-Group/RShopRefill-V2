<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One row per rate-limit decision, written whether or not enforcement is on.
 * In shadow mode these rows are the entire product: they say who would have
 * been blocked, on which signal, and how often, so thresholds can be set from
 * evidence instead of guesswork.
 */
class PaymentBackoffEvent extends Model
{
    protected $fillable = [
        'identifier',
        'user_id',
        'product_line',
        'signal',
        'tier',
        'decline_count',
        'no_show_count',
        'wait_seconds',
        'enforced',
        'customer_ip',
        'device_uuid',
    ];

    protected function casts(): array
    {
        return [
            'decline_count' => 'integer',
            'no_show_count' => 'integer',
            'wait_seconds' => 'integer',
            'enforced' => 'boolean',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class FXQuote extends Model
{
    use HasUuids;

    protected $table = 'fx_quotes';

    protected $fillable = [
        'id',
        'provider_reference',
        'source_currency',
        'destination_currency',
        'source_amount',
        'destination_amount',
        'provider_destination_amount',
        'system_revenue',
        'rate',
        'status',
        'expires_at',
        'consumed_at',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
        'consumed_at' => 'datetime',
        'rate' => 'decimal:8',
    ];

    public const STATUS_CREATED = 'created';
    public const STATUS_RESERVED = 'reserved';
    public const STATUS_CONSUMED = 'consumed';
    public const STATUS_EXPIRED = 'expired';

    /**
     * Scope a query to only include valid, unconsumed quotes.
     */
    public function scopeValid($query)
    {
        return $query->where('status', self::STATUS_CREATED)
                     ->where('expires_at', '>', now());
    }
}

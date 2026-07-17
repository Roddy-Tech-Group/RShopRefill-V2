<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class VirtualCard extends Model
{
    protected $fillable = [
        'user_id',
        'provider',
        'provider_card_id',
        'currency',
        'status',
        'masked_pan',
        'name_on_card',
        'expiry_month',
        'expiry_year',
        'brand'
    ];

    public const STATUS_PENDING = 'PENDING';
    public const STATUS_ACTIVE = 'ACTIVE';
    public const STATUS_FROZEN = 'FROZEN';
    public const STATUS_TERMINATED = 'TERMINATED';
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CommunicationPreference extends Model
{
    public $timestamps = true;

    protected $fillable = [
        'user_id',
        'email_enabled',
        'push_enabled',
        'sms_enabled',
        'whatsapp_enabled',

        'wallet_email',
        'wallet_push',
        'orders_email',
        'orders_push',
        'transactions_email',
        'transactions_push',
        'product_updates_email',
        'product_updates_push',
        'marketing_email',
        'marketing_push',
    ];

    protected $casts = [
        'email_enabled' => 'boolean',
        'push_enabled' => 'boolean',
        'sms_enabled' => 'boolean',
        'whatsapp_enabled' => 'boolean',

        'wallet_email' => 'boolean',
        'wallet_push' => 'boolean',
        'orders_email' => 'boolean',
        'orders_push' => 'boolean',
        'transactions_email' => 'boolean',
        'transactions_push' => 'boolean',
        'product_updates_email' => 'boolean',
        'product_updates_push' => 'boolean',
        'marketing_email' => 'boolean',
        'marketing_push' => 'boolean',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}

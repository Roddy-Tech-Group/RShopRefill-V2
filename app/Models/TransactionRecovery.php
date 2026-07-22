<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TransactionRecovery extends Model
{
    protected $fillable = [
        'token',
        'payment_session_id',
        'user_id',
        'customer_email',
        'status',
        'expires_at',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
    ];

    public function paymentSession(): BelongsTo
    {
        return $this->belongsTo(PaymentSession::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}

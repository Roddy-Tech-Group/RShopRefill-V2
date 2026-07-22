<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A non-terminal environmental signal observed in the browser while a
 * payment was in flight (JS exception, offline blip, slow connection).
 * Analytics-only: never affects the payment session state machine.
 *
 * @property int $id
 * @property string $payment_session_id
 * @property int|null $user_id
 * @property string $code
 * @property array|null $context
 */
class PaymentTelemetryEvent extends Model
{
    protected $fillable = [
        'payment_session_id',
        'user_id',
        'code',
        'context',
    ];

    protected $casts = [
        'context' => 'array',
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

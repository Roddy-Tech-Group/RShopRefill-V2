<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A single interaction with a transaction recovery: the customer clicked the
 * recovery link, provided feedback, or retried the payment.
 *
 * @property int $id
 * @property int $transaction_recovery_id
 * @property string $action clicked_link | provided_feedback | retried_payment
 * @property array|null $payload
 */
class RecoveryAttempt extends Model
{
    protected $fillable = [
        'transaction_recovery_id',
        'action',
        'payload',
    ];

    protected $casts = [
        'payload' => 'array',
    ];

    public function transactionRecovery(): BelongsTo
    {
        return $this->belongsTo(TransactionRecovery::class);
    }
}

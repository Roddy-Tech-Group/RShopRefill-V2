<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A ground-truth label on a user/subject (chargeback, confirmed_fraud,
 * false_positive, good) that closes the feedback loop. Written by dispute
 * webhooks and admin actions (Stage 3); read by the fraud-history signals so the
 * engine improves from real outcomes.
 */
class RiskLabel extends Model
{
    use HasFactory;

    public const LABEL_CHARGEBACK = 'chargeback';

    public const LABEL_CONFIRMED_FRAUD = 'confirmed_fraud';

    public const LABEL_FALSE_POSITIVE = 'false_positive';

    public const LABEL_GOOD = 'good';

    protected $fillable = [
        'user_id',
        'subject_type',
        'subject_id',
        'label',
        'source',
        'note',
    ];

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}

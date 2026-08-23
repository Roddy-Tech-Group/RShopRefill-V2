<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One fired signal belonging to a risk assessment: its code, the weight applied,
 * and PII-safe detail. Table is risk_signals; the model is RiskSignalRecord to
 * avoid colliding with the RiskSignal contract in the domain layer.
 */
class RiskSignalRecord extends Model
{
    protected $table = 'risk_signals';

    public const UPDATED_AT = null;

    protected $fillable = [
        'risk_assessment_id',
        'code',
        'weight',
        'detail',
    ];

    protected function casts(): array
    {
        return [
            'weight' => 'integer',
            'detail' => 'array',
        ];
    }

    /**
     * @return BelongsTo<RiskAssessment, $this>
     */
    public function assessment(): BelongsTo
    {
        return $this->belongsTo(RiskAssessment::class, 'risk_assessment_id');
    }
}

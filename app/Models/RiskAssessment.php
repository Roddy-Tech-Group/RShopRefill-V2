<?php

namespace App\Models;

use App\Domain\Risk\Enums\RiskBand;
use App\Domain\Risk\Enums\RiskOutcome;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One immutable risk decision. Append-only: never updated after creation, so
 * only a created_at timestamp is kept. Reproducible from its signals + the
 * stamped policy_version.
 *
 * @property string $band
 * @property string $outcome
 */
class RiskAssessment extends Model
{
    use HasFactory;
    use HasUuids;

    public const UPDATED_AT = null;

    protected $fillable = [
        'user_id',
        'context',
        'subject_type',
        'subject_id',
        'score',
        'band',
        'outcome',
        'enforced',
        'ip_address',
        'device_uuid',
        'amount_usd',
        'currency',
        'policy_version',
        'correlation_id',
    ];

    protected function casts(): array
    {
        return [
            'score' => 'integer',
            'enforced' => 'boolean',
            'amount_usd' => 'decimal:2',
            'band' => RiskBand::class,
            'outcome' => RiskOutcome::class,
        ];
    }

    /**
     * @return HasMany<RiskSignalRecord, $this>
     */
    public function signals(): HasMany
    {
        return $this->hasMany(RiskSignalRecord::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}

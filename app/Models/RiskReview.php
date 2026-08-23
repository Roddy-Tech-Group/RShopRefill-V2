<?php

namespace App\Models;

use App\Domain\Risk\Enums\RiskReviewStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A queued manual risk review. Mutable (its status is the whole point), unlike
 * the immutable assessment it points back to.
 *
 * @property RiskReviewStatus $status
 */
class RiskReview extends Model
{
    use HasFactory;

    protected $fillable = [
        'risk_assessment_id',
        'user_id',
        'context',
        'subject_type',
        'subject_id',
        'status',
        'score',
        'band',
        'reason_codes',
        'assigned_admin_id',
        'decision_reason',
        'decided_by',
        'decided_at',
        'cleared_until',
    ];

    protected function casts(): array
    {
        return [
            'status' => RiskReviewStatus::class,
            'score' => 'integer',
            'reason_codes' => 'array',
            'decided_at' => 'datetime',
            'cleared_until' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<RiskAssessment, $this>
     */
    public function assessment(): BelongsTo
    {
        return $this->belongsTo(RiskAssessment::class, 'risk_assessment_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Whether an approved review is still inside its clearance window.
     */
    public function hasLiveClearance(): bool
    {
        return $this->status === RiskReviewStatus::Approved
            && $this->cleared_until !== null
            && $this->cleared_until->isFuture();
    }

    /**
     * Whether a rejection is still in force: the action stays refused for the
     * clearance window after the decision, after which the engine re-assesses.
     */
    public function isRejectionActive(): bool
    {
        return $this->status === RiskReviewStatus::Rejected
            && $this->decided_at !== null
            && $this->decided_at->addHours((int) config('risk.review.clearance_hours', 24))->isFuture();
    }

    /**
     * Whether this pending review has breached its SLA target.
     */
    public function isSlaBreached(): bool
    {
        if (! $this->status->isBlocking()) {
            return false;
        }

        $slaHours = (int) config('risk.review.sla_hours', 12);

        return $this->created_at !== null
            && $this->created_at->addHours($slaHours)->isPast();
    }
}

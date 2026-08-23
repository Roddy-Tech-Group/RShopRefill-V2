<?php

namespace App\Domain\Risk\Services;

use App\Domain\Audit\Services\AuditLogService;
use App\Domain\Risk\DTOs\RiskContext;
use App\Domain\Risk\DTOs\RiskDecision;
use App\Domain\Risk\Enums\RiskReviewStatus;
use App\Models\RiskLabel;
use App\Models\RiskReview;
use App\Models\User;
use App\Notifications\CriticalSystemAlert;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;

/**
 * Owns the manual review queue lifecycle: opening a review when the engine holds
 * an action, finding the review that currently governs a user+context, and the
 * admin decisions (approve / reject / escalate) with an audit-logged trail.
 *
 * An approval grants a time-boxed clearance so the customer's retry can actually
 * complete; a rejection keeps the action refused for the same window before the
 * engine re-assesses.
 */
class RiskReviewService
{
    public function __construct(private readonly AuditLogService $auditLog) {}

    /**
     * Open a pending review for a held decision, or return the existing blocking
     * review for this user+context so repeated attempts never fan out into
     * duplicate queue entries.
     */
    public function open(RiskDecision $decision, RiskContext $context): RiskReview
    {
        $existing = $this->blockingReview($context->user, $context->context);

        if ($existing !== null) {
            return $existing;
        }

        $review = RiskReview::create([
            'risk_assessment_id' => $decision->assessmentId,
            'user_id' => $context->user?->getKey(),
            'context' => $context->context,
            'subject_type' => $context->subjectType,
            'subject_id' => $context->subjectId,
            'status' => RiskReviewStatus::Pending,
            'score' => $decision->score,
            'band' => $decision->band->value,
            'reason_codes' => $decision->reasonCodes,
        ]);

        $this->notifyOwners($review);

        return $review;
    }

    /**
     * Alert the review-queue owners (config risk.review.owners) that a new review
     * needs attention. Delivery is queued (CriticalSystemAlert) and wrapped, so a
     * mail hiccup can never break the money action that opened the review.
     */
    private function notifyOwners(RiskReview $review): void
    {
        $owners = array_filter((array) config('risk.review.owners', []));

        if ($owners === []) {
            return;
        }

        try {
            Notification::route('mail', $owners)->notify(new CriticalSystemAlert(
                title: 'Risk review opened ('.strtoupper((string) $review->band).')',
                description: 'A '.$review->context.' action was held for manual review and needs a decision within the SLA window.',
                context: [
                    'Review' => $review->getKey(),
                    'Score' => $review->score,
                    'Band' => $review->band,
                    'Customer' => $review->user?->email ?? 'Guest',
                    'Reasons' => implode(', ', $review->reason_codes ?? []),
                    'SLA (hours)' => config('risk.review.sla_hours'),
                ],
            ));
        } catch (\Throwable $e) {
            Log::warning('risk.review_owner_notify_failed', [
                'review' => $review->getKey(),
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * The most recent review governing this user+context, whatever its state.
     * The gate interprets its status and timestamps.
     */
    public function latestFor(?User $user, string $context): ?RiskReview
    {
        if ($user === null) {
            return null;
        }

        return RiskReview::query()
            ->where('user_id', $user->getKey())
            ->where('context', $context)
            ->latest()
            ->first();
    }

    /**
     * An open (pending/escalated) review for this user+context, if any.
     */
    public function blockingReview(?User $user, string $context): ?RiskReview
    {
        if ($user === null) {
            return null;
        }

        return RiskReview::query()
            ->where('user_id', $user->getKey())
            ->where('context', $context)
            ->whereIn('status', [RiskReviewStatus::Pending->value, RiskReviewStatus::Escalated->value])
            ->latest()
            ->first();
    }

    public function approve(RiskReview $review, int $adminId, ?string $reason = null): RiskReview
    {
        $before = $review->only(['status', 'cleared_until']);

        $review->update([
            'status' => RiskReviewStatus::Approved,
            'decision_reason' => $reason,
            'decided_by' => $adminId,
            'decided_at' => now(),
            'cleared_until' => now()->addHours((int) config('risk.review.clearance_hours', 24)),
        ]);

        $this->audit('risk_review.approved', $review, $before, $adminId, $reason);

        return $review;
    }

    public function reject(RiskReview $review, int $adminId, string $reason): RiskReview
    {
        $before = $review->only(['status']);

        $review->update([
            'status' => RiskReviewStatus::Rejected,
            'decision_reason' => $reason,
            'decided_by' => $adminId,
            'decided_at' => now(),
            'cleared_until' => null,
        ]);

        // Close the feedback loop: a rejection is a ground-truth fraud label, so
        // the account trips the confirmed-fraud deny override on any future
        // attempt (and the label feeds threshold tuning).
        if ($review->user_id !== null) {
            RiskLabel::create([
                'user_id' => $review->user_id,
                'subject_type' => $review->subject_type,
                'subject_id' => $review->subject_id,
                'label' => RiskLabel::LABEL_CONFIRMED_FRAUD,
                'source' => 'admin',
                'note' => 'Auto-labelled from rejected risk review #'.$review->getKey(),
            ]);
        }

        $this->audit('risk_review.rejected', $review, $before, $adminId, $reason);

        return $review;
    }

    public function escalate(RiskReview $review, int $adminId, ?string $reason = null): RiskReview
    {
        $before = $review->only(['status']);

        $review->update([
            'status' => RiskReviewStatus::Escalated,
            'assigned_admin_id' => $adminId,
            'decision_reason' => $reason,
        ]);

        $this->audit('risk_review.escalated', $review, $before, $adminId, $reason);

        return $review;
    }

    /**
     * @param  array<string, mixed>  $before
     */
    private function audit(string $action, RiskReview $review, array $before, int $adminId, ?string $reason): void
    {
        $this->auditLog->log(
            action: $action,
            model: $review,
            beforeState: $before,
            afterState: $review->only(['status', 'cleared_until']),
            metadata: [
                'admin_id' => $adminId,
                'context' => $review->context,
                'reason' => $reason,
            ],
        );
    }
}

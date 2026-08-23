<?php

namespace Tests\Feature\Risk;

use App\Domain\Risk\DTOs\RiskContext;
use App\Domain\Risk\DTOs\RiskDecision;
use App\Domain\Risk\Enums\RiskBand;
use App\Domain\Risk\Enums\RiskOutcome;
use App\Domain\Risk\Enums\RiskReviewStatus;
use App\Domain\Risk\Services\RiskReviewService;
use App\Models\RiskAssessment;
use App\Models\RiskLabel;
use App\Models\RiskReview;
use App\Models\User;
use App\Notifications\CriticalSystemAlert;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * The review queue lifecycle: opening (with dedup), and the audit-logged admin
 * decisions with their clearance handling.
 */
class RiskReviewServiceTest extends TestCase
{
    use RefreshDatabase;

    private function service(): RiskReviewService
    {
        return app(RiskReviewService::class);
    }

    private function decisionFor(User $user): RiskDecision
    {
        $assessment = RiskAssessment::factory()->create(['user_id' => $user->id]);

        return new RiskDecision(
            outcome: RiskOutcome::Review,
            band: RiskBand::High,
            score: 60,
            reasonCodes: ['UNVERIFIED_HIGH_VALUE'],
            enforced: true,
            assessmentId: $assessment->getKey(),
        );
    }

    private function context(User $user): RiskContext
    {
        return new RiskContext(user: $user, context: 'order', amountUsd: 120.0);
    }

    public function test_open_creates_a_pending_review(): void
    {
        $user = User::factory()->create();

        $review = $this->service()->open($this->decisionFor($user), $this->context($user));

        $this->assertSame(RiskReviewStatus::Pending, $review->status);
        $this->assertSame(60, $review->score);
        $this->assertSame(['UNVERIFIED_HIGH_VALUE'], $review->reason_codes);
    }

    public function test_open_dedups_a_blocking_review_per_user_and_context(): void
    {
        $user = User::factory()->create();

        $first = $this->service()->open($this->decisionFor($user), $this->context($user));
        $second = $this->service()->open($this->decisionFor($user), $this->context($user));

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, RiskReview::where('user_id', $user->id)->count());
    }

    public function test_opening_a_review_alerts_the_owners(): void
    {
        Notification::fake();
        config(['risk.review.owners' => ['dev@roddytechgroup.com']]);
        $user = User::factory()->create();

        $this->service()->open($this->decisionFor($user), $this->context($user));

        Notification::assertSentOnDemand(CriticalSystemAlert::class);
    }

    public function test_rejecting_records_a_confirmed_fraud_label(): void
    {
        $user = User::factory()->create();
        $review = RiskReview::factory()->create(['user_id' => $user->id]);

        $this->service()->reject($review, adminId: 7, reason: 'Confirmed fraud');

        $this->assertDatabaseHas('risk_labels', [
            'user_id' => $user->id,
            'label' => RiskLabel::LABEL_CONFIRMED_FRAUD,
            'source' => 'admin',
        ]);
    }

    public function test_approve_grants_a_clearance_and_audits(): void
    {
        $review = RiskReview::factory()->create();

        $this->service()->approve($review, adminId: 7, reason: 'Verified by phone');

        $review->refresh();
        $this->assertSame(RiskReviewStatus::Approved, $review->status);
        $this->assertTrue($review->hasLiveClearance());
        $this->assertSame(7, $review->decided_by);
        $this->assertDatabaseHas('audit_logs', ['action' => 'risk_review.approved']);
    }

    public function test_reject_keeps_it_refused_and_audits(): void
    {
        $review = RiskReview::factory()->create();

        $this->service()->reject($review, adminId: 7, reason: 'Confirmed card testing');

        $review->refresh();
        $this->assertSame(RiskReviewStatus::Rejected, $review->status);
        $this->assertTrue($review->isRejectionActive());
        $this->assertNull($review->cleared_until);
        $this->assertDatabaseHas('audit_logs', ['action' => 'risk_review.rejected']);
    }

    public function test_escalate_marks_and_audits(): void
    {
        $review = RiskReview::factory()->create();

        $this->service()->escalate($review, adminId: 7, reason: 'Needs senior eyes');

        $review->refresh();
        $this->assertSame(RiskReviewStatus::Escalated, $review->status);
        $this->assertSame(7, $review->assigned_admin_id);
        $this->assertTrue($review->status->isBlocking());
        $this->assertDatabaseHas('audit_logs', ['action' => 'risk_review.escalated']);
    }
}

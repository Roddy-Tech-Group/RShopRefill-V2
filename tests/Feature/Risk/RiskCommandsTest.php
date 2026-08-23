<?php

namespace Tests\Feature\Risk;

use App\Domain\Risk\Enums\RiskReviewStatus;
use App\Models\RiskAssessment;
use App\Models\RiskReview;
use App\Notifications\CriticalSystemAlert;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * The scheduled risk commands: retention pruning and the daily report with its
 * SLA-breach alert to the queue owners.
 */
class RiskCommandsTest extends TestCase
{
    use RefreshDatabase;

    public function test_prune_deletes_expired_assessments_and_keeps_recent(): void
    {
        config(['risk.retention_months' => 24]);

        $old = RiskAssessment::factory()->create(['created_at' => now()->subMonths(25)]);
        $recent = RiskAssessment::factory()->create(['created_at' => now()->subMonths(1)]);

        $this->artisan('risk:prune')->assertSuccessful();

        $this->assertDatabaseMissing('risk_assessments', ['id' => $old->id]);
        $this->assertDatabaseHas('risk_assessments', ['id' => $recent->id]);
    }

    public function test_report_alerts_owners_when_a_review_breaches_sla(): void
    {
        Notification::fake();
        config(['risk.review.sla_hours' => 12, 'risk.review.owners' => ['dev@roddytechgroup.com']]);

        RiskReview::factory()->create([
            'status' => RiskReviewStatus::Pending,
            'created_at' => now()->subHours(24), // past the 12h SLA
        ]);

        $this->artisan('risk:report')->assertSuccessful();

        Notification::assertSentOnDemand(CriticalSystemAlert::class);
    }

    public function test_report_is_quiet_when_nothing_has_breached(): void
    {
        Notification::fake();
        config(['risk.review.sla_hours' => 12]);

        RiskReview::factory()->create([
            'status' => RiskReviewStatus::Pending,
            'created_at' => now()->subHour(), // well inside SLA
        ]);

        $this->artisan('risk:report')->assertSuccessful();

        Notification::assertNothingSent();
    }
}

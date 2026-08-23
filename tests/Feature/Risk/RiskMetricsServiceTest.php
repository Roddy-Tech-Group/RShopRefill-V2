<?php

namespace Tests\Feature\Risk;

use App\Domain\Risk\Enums\RiskReviewStatus;
use App\Domain\Risk\Services\RiskMetricsService;
use App\Models\RiskAssessment;
use App\Models\RiskLabel;
use App\Models\RiskReview;
use App\Models\SiteSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * The metrics reader: rates, the shadow-vs-enforce delta, chargeback rate, and
 * queue health, all computed from persisted rows.
 */
class RiskMetricsServiceTest extends TestCase
{
    use RefreshDatabase;

    private function assessment(string $outcome, string $band, bool $enforced): void
    {
        RiskAssessment::create([
            'user_id' => User::factory()->create()->id,
            'context' => 'order',
            'score' => 60,
            'band' => $band,
            'outcome' => $outcome,
            'enforced' => $enforced,
            'amount_usd' => 100,
        ]);
    }

    public function test_rates_and_shadow_delta(): void
    {
        // 10 assessments: 6 allow, 2 review, 1 deny, 1 step_up (all shadow).
        for ($i = 0; $i < 6; $i++) {
            $this->assessment('allow', 'LOW', false);
        }
        $this->assessment('review', 'HIGH', false);
        $this->assessment('review', 'HIGH', false);
        $this->assessment('deny', 'CRITICAL', false);
        $this->assessment('step_up', 'MEDIUM', false);

        $m = app(RiskMetricsService::class)->overview(30);

        $this->assertSame(10, $m['total_assessments']);
        $this->assertSame(20.0, $m['rates']['review_rate']);   // 2/10
        $this->assertSame(10.0, $m['rates']['decline_rate']);  // 1/10
        $this->assertSame(10.0, $m['rates']['step_up_rate']);  // 1/10
        // 4 blocking outcomes computed while in shadow.
        $this->assertSame(4, $m['shadow_would_act']);
    }

    public function test_enforcement_flags_are_reported(): void
    {
        SiteSetting::put('features.risk_engine_enabled', 'on', 'features');
        SiteSetting::put('features.risk_engine_enforce_enabled', 'off', 'features');
        Cache::flush();

        $m = app(RiskMetricsService::class)->overview();

        $this->assertTrue($m['enforcement']['scoring_on']);
        $this->assertFalse($m['enforcement']['enforcing']);
    }

    public function test_queue_health_and_chargeback_rate(): void
    {
        RiskReview::factory()->create(['status' => RiskReviewStatus::Pending]);
        RiskReview::factory()->create(['status' => RiskReviewStatus::Approved]);

        $u = User::factory()->create();
        RiskLabel::create(['user_id' => $u->id, 'label' => RiskLabel::LABEL_CHARGEBACK, 'source' => 'admin']);

        $m = app(RiskMetricsService::class)->overview();

        $this->assertSame(1, $m['reviews']['pending']);
        $this->assertSame(1, $m['reviews']['approved']);
        $this->assertSame(1, $m['chargeback']['chargebacks']);
        $this->assertArrayHasKey('rate', $m['chargeback']);
    }
}

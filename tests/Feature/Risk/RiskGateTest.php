<?php

namespace Tests\Feature\Risk;

use App\Domain\Kyc\Contracts\KycProvider;
use App\Domain\Kyc\DTOs\KycSession;
use App\Domain\Kyc\DTOs\KycVerdict;
use App\Domain\Kyc\Exceptions\KycStepUpRequiredException;
use App\Domain\Kyc\Services\KycProviderFactory;
use App\Domain\Risk\DTOs\RiskContext;
use App\Domain\Risk\DTOs\SignalHit;
use App\Domain\Risk\Enums\RiskReviewStatus;
use App\Domain\Risk\Exceptions\RiskDeniedException;
use App\Domain\Risk\Exceptions\RiskReviewRequiredException;
use App\Domain\Risk\Services\RiskGate;
use App\Domain\Risk\Signals\AbstractRiskSignal;
use App\Models\RiskAssessment;
use App\Models\RiskReview;
use App\Models\SiteSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * The unified enforcement seam: turning a risk decision into behaviour, honouring
 * an existing review's state, and staying inert in shadow. Scoring is driven by a
 * single controllable stub signal so each outcome is exact; KYC value-based
 * step-up is switched off (high threshold, enforcement off) so it never
 * interferes with the risk paths under test.
 */
class RiskGateTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'risk.signals' => [GateStubSignal::class],
            'risk.weights' => ['GATE_STUB' => 100],
            'risk.bands' => ['CRITICAL' => 80, 'HIGH' => 55, 'MEDIUM' => 30, 'LOW' => 0],
            'risk.band_outcomes' => ['LOW' => 'allow', 'MEDIUM' => 'step_up', 'HIGH' => 'review', 'CRITICAL' => 'deny'],
            'risk.overrides' => [],
            // Declared abuse so review/deny outcomes stay reachable through the
            // single stub under two-track scoring.
            'risk.abuse_codes' => ['GATE_STUB'],
            // Keep KYC's value-based step-up out of the way.
            'kyc.tier2_threshold_usd' => 1_000_000,
            'kyc.tier1_trigger_usd' => 0,
        ]);

        GateStubSignal::$fires = false;

        // Stub the KYC provider so a step-up mints a fake session, never Smile.
        $fake = new class implements KycProvider
        {
            public function key(): string
            {
                return 'fake';
            }

            public function startVerification(User $user, ?string $issuingCountry = null): KycSession
            {
                return new KycSession('ref-1', token: 'tok-1', metadata: ['partner_id' => 'p']);
            }

            public function verdictFromWebhook(array $payload): ?KycVerdict
            {
                return null;
            }
        };

        $this->mock(KycProviderFactory::class, function ($mock) use ($fake) {
            $mock->shouldReceive('forUser')->andReturn($fake);
        });
    }

    private function flags(bool $master, bool $enforce): void
    {
        SiteSetting::put('features.risk_engine_enabled', $master ? 'on' : 'off', 'features');
        SiteSetting::put('features.risk_engine_enforce_enabled', $enforce ? 'on' : 'off', 'features');
        Cache::flush();
    }

    private function context(?User $user = null): RiskContext
    {
        return new RiskContext(
            user: $user ?? User::factory()->create(['kyc_status' => 'verified']),
            context: 'order',
            amountUsd: 100.0,
            currency: 'USD',
            ip: '203.0.113.9',
            deviceUuid: 'dev-gate',
        );
    }

    private function weight(int $weight): void
    {
        config(['risk.weights.GATE_STUB' => $weight]);
    }

    public function test_shadow_never_blocks_even_on_a_critical_score(): void
    {
        $this->flags(master: true, enforce: false);
        GateStubSignal::$fires = true; // score 100 -> CRITICAL -> deny (computed)

        app(RiskGate::class)->guard($this->context());

        // No exception thrown: shadow records the assessment and returns.
        $this->assertDatabaseHas('risk_assessments', ['outcome' => 'deny', 'enforced' => false]);
        $this->assertSame(0, RiskReview::count());
    }

    public function test_enforced_critical_denies(): void
    {
        $this->flags(master: true, enforce: true);
        GateStubSignal::$fires = true; // CRITICAL -> deny

        $this->expectException(RiskDeniedException::class);
        app(RiskGate::class)->guard($this->context());
    }

    public function test_enforced_high_opens_a_review_and_holds(): void
    {
        $this->flags(master: true, enforce: true);
        $this->weight(60); // HIGH -> review
        GateStubSignal::$fires = true;
        $user = User::factory()->create(['kyc_status' => 'verified']);

        try {
            app(RiskGate::class)->guard($this->context($user));
            $this->fail('Expected RiskReviewRequiredException.');
        } catch (RiskReviewRequiredException $e) {
            $this->assertSame(RiskReviewStatus::Pending, $e->review->status);
            $this->assertSame($user->id, $e->review->user_id);
        }

        $this->assertSame(1, RiskReview::where('user_id', $user->id)->count());
    }

    public function test_repeated_high_attempts_share_one_review(): void
    {
        $this->flags(master: true, enforce: true);
        $this->weight(60);
        GateStubSignal::$fires = true;
        $user = User::factory()->create(['kyc_status' => 'verified']);

        foreach (range(1, 3) as $_) {
            try {
                app(RiskGate::class)->guard($this->context($user));
            } catch (RiskReviewRequiredException) {
                // expected
            }
        }

        $this->assertSame(1, RiskReview::where('user_id', $user->id)->count());
    }

    public function test_enforced_medium_forces_identity_step_up_once_the_step_up_switch_is_on(): void
    {
        $this->flags(master: true, enforce: true);
        SiteSetting::put('features.risk_step_up_enforce_enabled', 'on', 'features');
        SiteSetting::put('features.kyc_verification_enabled', 'on', 'features');
        Cache::flush();
        $this->weight(40); // MEDIUM -> step_up
        GateStubSignal::$fires = true;
        $user = User::factory()->create(['kyc_status' => 'pending']); // unverified

        $this->expectException(KycStepUpRequiredException::class);
        app(RiskGate::class)->guard($this->context($user));
    }

    /**
     * A step-up hands the customer to the identity provider and bypasses the
     * KYC enforcement flag, so risk enforcement alone must not put the
     * provider live through the back door. Review and deny still bite; the
     * step-up is logged and the customer goes through until its own switch
     * is flipped.
     */
    public function test_enforced_medium_lets_the_customer_through_while_the_step_up_switch_is_off(): void
    {
        $this->flags(master: true, enforce: true);
        SiteSetting::query()->where('key', 'features.risk_step_up_enforce_enabled')->delete();
        Cache::flush();
        $this->weight(40); // MEDIUM -> step_up
        GateStubSignal::$fires = true;
        $user = User::factory()->create(['kyc_status' => 'pending']); // unverified

        app(RiskGate::class)->guard($this->context($user));

        $this->assertDatabaseHas('risk_assessments', ['user_id' => $user->id, 'outcome' => 'step_up', 'enforced' => true]);
    }

    public function test_enforced_low_allows(): void
    {
        $this->flags(master: true, enforce: true);
        GateStubSignal::$fires = false; // score 0 -> LOW -> allow

        app(RiskGate::class)->guard($this->context());

        $this->assertDatabaseHas('risk_assessments', ['outcome' => 'allow', 'enforced' => true]);
    }

    public function test_an_approved_review_with_live_clearance_releases_the_retry(): void
    {
        $this->flags(master: true, enforce: true);
        $this->weight(60); // would be HIGH -> review
        GateStubSignal::$fires = true;
        $user = User::factory()->create(['kyc_status' => 'verified']);

        RiskReview::factory()->create([
            'user_id' => $user->id,
            'context' => 'order',
            'status' => RiskReviewStatus::Approved,
            'cleared_until' => now()->addHours(2),
        ]);

        // No exception: the live clearance releases the action despite a HIGH score.
        app(RiskGate::class)->guard($this->context($user));

        $this->assertTrue(true);
    }

    public function test_a_pending_review_keeps_holding_new_attempts(): void
    {
        $this->flags(master: true, enforce: true);
        GateStubSignal::$fires = false; // even a clean score is held while pending
        $user = User::factory()->create(['kyc_status' => 'verified']);

        RiskReview::factory()->create([
            'user_id' => $user->id,
            'context' => 'order',
            'status' => RiskReviewStatus::Pending,
        ]);

        $this->expectException(RiskReviewRequiredException::class);
        app(RiskGate::class)->guard($this->context($user));
    }

    public function test_a_fresh_rejection_keeps_denying(): void
    {
        $this->flags(master: true, enforce: true);
        GateStubSignal::$fires = false;
        $user = User::factory()->create(['kyc_status' => 'verified']);

        RiskReview::factory()->create([
            'user_id' => $user->id,
            'context' => 'order',
            'status' => RiskReviewStatus::Rejected,
            'decided_at' => now()->subHour(),
        ]);

        $this->expectException(RiskDeniedException::class);
        app(RiskGate::class)->guard($this->context($user));
    }

    public function test_master_off_is_fully_inert(): void
    {
        $this->flags(master: false, enforce: false);
        GateStubSignal::$fires = true; // would be CRITICAL if it ran

        app(RiskGate::class)->guard($this->context());

        $this->assertSame(0, RiskAssessment::count());
        $this->assertSame(0, RiskReview::count());
    }
}

class GateStubSignal extends AbstractRiskSignal
{
    public static bool $fires = false;

    public function code(): string
    {
        return 'GATE_STUB';
    }

    public function evaluate(RiskContext $context): ?SignalHit
    {
        return self::$fires ? $this->hit() : null;
    }
}

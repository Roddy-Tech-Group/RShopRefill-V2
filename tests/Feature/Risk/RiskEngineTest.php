<?php

namespace Tests\Feature\Risk;

use App\Domain\Risk\DTOs\RiskContext;
use App\Domain\Risk\DTOs\SignalHit;
use App\Domain\Risk\Enums\RiskBand;
use App\Domain\Risk\Enums\RiskOutcome;
use App\Domain\Risk\Services\RiskEngine;
use App\Domain\Risk\Signals\AbstractRiskSignal;
use App\Models\RiskAssessment;
use App\Models\SiteSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * The engine's scoring, banding, override resolution, persistence, and the
 * shadow -> enforce flag discipline. Signals are stubbed to a controlled set so
 * the maths is deterministic and independent of the real signal catalogue.
 */
class RiskEngineTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // A controlled policy: two scoring signals, one override signal, one
        // that never fires. Bands and outcomes are fixed so assertions are exact.
        config([
            'risk.policy_version' => 'test-1',
            'risk.signals' => [
                FiresTwentySignal::class,
                FiresFortySignal::class,
                NeverFiresSignal::class,
                OverrideDenySignal::class,
            ],
            'risk.weights' => [
                'STUB_20' => 20,
                'STUB_40' => 40,
                'STUB_NEVER' => 99,
                'STUB_OVERRIDE' => 100,
            ],
            'risk.bands' => ['CRITICAL' => 80, 'HIGH' => 55, 'MEDIUM' => 30, 'LOW' => 0],
            'risk.band_outcomes' => ['LOW' => 'allow', 'MEDIUM' => 'step_up', 'HIGH' => 'review', 'CRITICAL' => 'deny'],
            'risk.overrides' => ['STUB_OVERRIDE' => 'deny'],
            // The scoring stubs are declared ABUSE so the original single-score
            // expectations (HIGH -> review, CRITICAL -> deny) hold; the identity
            // cap is exercised by its own tests below.
            'risk.abuse_codes' => ['STUB_20', 'STUB_40', 'STUB_NEVER', 'STUB_OVERRIDE'],
        ]);

        FiresTwentySignal::$fires = false;
        FiresFortySignal::$fires = false;
        OverrideDenySignal::$fires = false;
    }

    private function master(bool $on): void
    {
        SiteSetting::put('features.risk_engine_enabled', $on ? 'on' : 'off', 'features');
        Cache::flush();
    }

    private function enforce(bool $on): void
    {
        SiteSetting::put('features.risk_engine_enforce_enabled', $on ? 'on' : 'off', 'features');
        Cache::flush();
    }

    private function context(?User $user = null): RiskContext
    {
        return new RiskContext(
            user: $user ?? User::factory()->create(),
            context: 'order',
            amountUsd: 100.0,
            currency: 'USD',
            ip: '203.0.113.5',
            deviceUuid: 'dev-1',
        );
    }

    public function test_master_flag_off_is_completely_inert(): void
    {
        $this->master(false);
        FiresFortySignal::$fires = true;

        $decision = app(RiskEngine::class)->assess($this->context());

        $this->assertSame(RiskOutcome::Allow, $decision->outcome);
        $this->assertSame(0, $decision->score);
        $this->assertNull($decision->assessmentId);
        $this->assertSame(0, RiskAssessment::count());
    }

    public function test_shadow_scores_and_persists_but_returns_allow(): void
    {
        $this->master(true);
        $this->enforce(false);
        FiresTwentySignal::$fires = true;
        FiresFortySignal::$fires = true; // score 60 -> HIGH -> review (computed)

        $decision = app(RiskEngine::class)->assess($this->context());

        // Computed outcome is preserved for the record...
        $this->assertSame(60, $decision->score);
        $this->assertSame(RiskBand::High, $decision->band);
        $this->assertSame(RiskOutcome::Review, $decision->outcome);
        // ...but shadow never acts on it.
        $this->assertFalse($decision->enforced);
        $this->assertSame(RiskOutcome::Allow, $decision->effectiveOutcome());
        $this->assertFalse($decision->shouldIntervene());

        $assessment = RiskAssessment::sole();
        $this->assertSame(60, $assessment->score);
        $this->assertSame(RiskBand::High, $assessment->band);
        $this->assertSame(RiskOutcome::Review, $assessment->outcome);
        $this->assertFalse($assessment->enforced);
        $this->assertSame('test-1', $assessment->policy_version);
        $this->assertEqualsCanonicalizing(['STUB_20', 'STUB_40'], $assessment->signals->pluck('code')->all());
    }

    public function test_enforce_acts_on_the_computed_outcome(): void
    {
        $this->master(true);
        $this->enforce(true);
        FiresFortySignal::$fires = true; // score 40 -> MEDIUM -> step_up

        $decision = app(RiskEngine::class)->assess($this->context());

        $this->assertSame(RiskBand::Medium, $decision->band);
        $this->assertSame(RiskOutcome::StepUp, $decision->outcome);
        $this->assertTrue($decision->enforced);
        $this->assertSame(RiskOutcome::StepUp, $decision->effectiveOutcome());
        $this->assertTrue($decision->shouldIntervene());
        $this->assertTrue(RiskAssessment::sole()->enforced);
    }

    public function test_no_signals_is_low_and_allow(): void
    {
        $this->master(true);
        $this->enforce(true);

        $decision = app(RiskEngine::class)->assess($this->context());

        $this->assertSame(0, $decision->score);
        $this->assertSame(RiskBand::Low, $decision->band);
        $this->assertSame(RiskOutcome::Allow, $decision->outcome);
        $this->assertSame([], $decision->reasonCodes);
    }

    public function test_an_override_signal_forces_deny_regardless_of_score(): void
    {
        $this->master(true);
        $this->enforce(true);
        // Only the override fires; its weight lands it in CRITICAL anyway, but the
        // override is what guarantees deny even if bands were retuned.
        OverrideDenySignal::$fires = true;

        $decision = app(RiskEngine::class)->assess($this->context());

        $this->assertSame(RiskOutcome::Deny, $decision->outcome);
        $this->assertContains('STUB_OVERRIDE', $decision->reasonCodes);
    }

    public function test_override_escalates_even_from_a_low_band(): void
    {
        // Weight the override to 0 so score stays LOW; the override map must still
        // force deny. Proves overrides are not merely a function of the score.
        config(['risk.weights.STUB_OVERRIDE' => 0]);
        $this->master(true);
        $this->enforce(true);
        OverrideDenySignal::$fires = true;

        $decision = app(RiskEngine::class)->assess($this->context());

        $this->assertSame(0, $decision->score);
        $this->assertSame(RiskBand::Low, $decision->band);
        $this->assertSame(RiskOutcome::Deny, $decision->outcome);
    }

    public function test_reason_codes_and_signal_details_are_persisted(): void
    {
        $this->master(true);
        $this->enforce(false);
        FiresTwentySignal::$fires = true;

        app(RiskEngine::class)->assess($this->context());

        $signal = RiskAssessment::sole()->signals->firstWhere('code', 'STUB_20');
        $this->assertSame(20, $signal->weight);
        $this->assertSame(['note' => 'stub'], $signal->detail);
    }

    public function test_a_faulty_signal_never_breaks_assessment(): void
    {
        config(['risk.signals' => [ThrowingSignal::class]]);
        $this->master(true);
        $this->enforce(true);

        $decision = app(RiskEngine::class)->assess($this->context());

        // Fail-open: the engine swallows the fault and allows.
        $this->assertSame(RiskOutcome::Allow, $decision->outcome);
        $this->assertNull($decision->assessmentId);
    }

    public function test_identity_only_score_is_capped_at_step_up(): void
    {
        // Remove the stubs from the abuse family: a maximal identity-only score
        // must resolve to step_up (self-clearable), never review or deny.
        config(['risk.abuse_codes' => []]);
        $this->master(true);
        $this->enforce(true);
        FiresTwentySignal::$fires = true;
        FiresFortySignal::$fires = true;
        config(['risk.weights.STUB_40' => 80]); // total 100 -> CRITICAL band

        $decision = app(RiskEngine::class)->assess($this->context());

        $this->assertSame(100, $decision->score);
        $this->assertSame(0, $decision->abuseScore);
        $this->assertSame(RiskBand::Critical, $decision->band);
        $this->assertSame(RiskOutcome::StepUp, $decision->outcome);
    }

    public function test_abuse_subscore_alone_drives_review(): void
    {
        // One identity signal + one abuse signal: total 60 (HIGH) would cap at
        // step_up, but the abuse subscore of 40+20=... keep only STUB_40 abuse:
        // abuse 40 (MEDIUM -> not review) => step_up; then raise abuse weight so
        // the abuse band crosses HIGH and review wins.
        config(['risk.abuse_codes' => ['STUB_40']]);
        $this->master(true);
        $this->enforce(true);
        FiresTwentySignal::$fires = true; // identity, 20
        FiresFortySignal::$fires = true;  // abuse
        config(['risk.weights.STUB_40' => 60]); // abuse 60 -> HIGH -> review

        $decision = app(RiskEngine::class)->assess($this->context());

        $this->assertSame(80, $decision->score);
        $this->assertSame(60, $decision->abuseScore);
        $this->assertSame(RiskOutcome::Review, $decision->outcome);
    }

    public function test_overrides_pierce_the_identity_cap(): void
    {
        // Even with the override signal classed as identity (not abuse), the
        // override map must still force deny.
        config(['risk.abuse_codes' => []]);
        $this->master(true);
        $this->enforce(true);
        OverrideDenySignal::$fires = true;

        $decision = app(RiskEngine::class)->assess($this->context());

        $this->assertSame(RiskOutcome::Deny, $decision->outcome);
    }

    public function test_guest_context_persists_with_null_user(): void
    {
        $this->master(true);
        $this->enforce(false);
        FiresTwentySignal::$fires = true;

        $decision = app(RiskEngine::class)->assess(new RiskContext(
            user: null,
            context: 'order',
            amountUsd: 50.0,
        ));

        $this->assertNotNull($decision->assessmentId);
        $this->assertNull(RiskAssessment::sole()->user_id);
    }
}

class FiresTwentySignal extends AbstractRiskSignal
{
    public static bool $fires = false;

    public function code(): string
    {
        return 'STUB_20';
    }

    public function evaluate(RiskContext $context): ?SignalHit
    {
        return self::$fires ? $this->hit(['note' => 'stub']) : null;
    }
}

class FiresFortySignal extends AbstractRiskSignal
{
    public static bool $fires = false;

    public function code(): string
    {
        return 'STUB_40';
    }

    public function evaluate(RiskContext $context): ?SignalHit
    {
        return self::$fires ? $this->hit() : null;
    }
}

class NeverFiresSignal extends AbstractRiskSignal
{
    public function code(): string
    {
        return 'STUB_NEVER';
    }

    public function evaluate(RiskContext $context): ?SignalHit
    {
        return null;
    }
}

class OverrideDenySignal extends AbstractRiskSignal
{
    public static bool $fires = false;

    public function code(): string
    {
        return 'STUB_OVERRIDE';
    }

    public function evaluate(RiskContext $context): ?SignalHit
    {
        return self::$fires ? $this->hit() : null;
    }
}

class ThrowingSignal extends AbstractRiskSignal
{
    public function code(): string
    {
        return 'STUB_THROW';
    }

    public function evaluate(RiskContext $context): ?SignalHit
    {
        throw new \RuntimeException('boom');
    }
}

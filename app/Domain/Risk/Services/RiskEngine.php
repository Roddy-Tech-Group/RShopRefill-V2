<?php

namespace App\Domain\Risk\Services;

use App\Domain\Risk\Contracts\RiskSignal;
use App\Domain\Risk\DTOs\RiskContext;
use App\Domain\Risk\DTOs\RiskDecision;
use App\Domain\Risk\DTOs\SignalHit;
use App\Domain\Risk\Enums\RiskBand;
use App\Domain\Risk\Enums\RiskOutcome;
use App\Domain\Security\Services\LoginCountryGuard;
use App\Models\RiskAssessment;
use App\Models\TrustedDevice;
use App\Models\User;
use App\Support\FeatureFlag;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The centralised fraud/risk engine.
 *
 * assess() is the single scoring entry point every money action calls: it runs
 * the registered signals over a RiskContext, sums their configured weights into a
 * score, buckets the score into a band, applies hard overrides, maps the band to
 * an outcome (allow / step_up / review / deny), and persists one immutable,
 * fully explainable risk_assessments row with its signal hits.
 *
 * Two flags gate it, same shadow -> enforce discipline as PaymentBackoffManager:
 *  - features.risk_engine_enabled: master. Off => the engine does not run at all
 *    and assess() returns an inert allow (nothing scored, nothing persisted).
 *  - features.risk_engine_enforce_enabled: shadow vs enforce. In shadow the
 *    engine scores, persists (enforced=false), and logs the would-be outcome,
 *    but the returned decision resolves to allow so no customer is turned away.
 *
 * assess() is designed to never break a checkout: any internal failure is logged
 * and degrades to allow (fail-open), which in shadow mode is a no-op anyway.
 *
 * evaluateLoginRisk() is the login-time risk path that drives email OTP; its
 * only caller is EvaluateLoginRiskListener. Its travel component now delegates
 * to LoginCountryGuard, which compares countries and time instead of the naive
 * "the IP changed" check it replaced. Device age and velocity still migrate onto
 * the signal model.
 */
class RiskEngine
{
    /**
     * Score a money action and return the decision. Safe to call unconditionally
     * at any money choke point; honours the feature flags internally.
     */
    public function assess(RiskContext $context): RiskDecision
    {
        if (! FeatureFlag::on('risk_engine', false)) {
            return $this->inertAllow();
        }

        $enforced = FeatureFlag::on('risk_engine_enforce', false);

        try {
            return $this->score($context, $enforced);
        } catch (Throwable $e) {
            // Never let risk scoring break a money action. In shadow this is a
            // no-op; even enforcing, availability wins over a scoring bug.
            Log::error('risk.assess_failed', [
                'context' => $context->context,
                'user_id' => $context->user?->getKey(),
                'error' => $e->getMessage(),
            ]);

            return $this->inertAllow();
        }
    }

    /**
     * Run the signals, compute the two-track decision, and persist it.
     *
     * Two scores are kept: the TOTAL (every fired signal) and the ABUSE subscore
     * (abuse_codes only). Identity/context evidence can raise the total into a
     * step-up, but only the abuse subscore can reach review/deny, so a customer
     * is never held or refused merely for being new/unverified; they are asked
     * to verify instead, which they can self-clear.
     */
    private function score(RiskContext $context, bool $enforced): RiskDecision
    {
        /** @var list<array{hit: SignalHit, weight: int}> $fired */
        $fired = [];
        $score = 0;
        $abuseScore = 0;
        $weights = (array) config('risk.weights', []);
        $abuseCodes = array_fill_keys(array_map('strval', (array) config('risk.abuse_codes', [])), true);

        foreach ($this->registeredSignals() as $signal) {
            $hit = $signal->evaluate($context);

            if ($hit === null) {
                continue;
            }

            $weight = (int) ($weights[$hit->code] ?? 0);
            $score += $weight;

            if (isset($abuseCodes[$hit->code])) {
                $abuseScore += $weight;
            }

            $fired[] = ['hit' => $hit, 'weight' => $weight];
        }

        $cutoffs = (array) config('risk.bands', []);
        $band = RiskBand::fromScore($score, $cutoffs);
        $abuseBand = RiskBand::fromScore($abuseScore, $cutoffs);
        $outcome = $this->resolveOutcome($band, $abuseBand, $fired);
        $reasonCodes = array_map(fn (array $f): string => $f['hit']->code, $fired);

        $assessment = $this->persist($context, $score, $band, $outcome, $enforced, $fired);

        if (! $enforced && $outcome->blocks()) {
            Log::info('risk.shadow_decision', [
                'assessment_id' => $assessment?->getKey(),
                'context' => $context->context,
                'user_id' => $context->user?->getKey(),
                'score' => $score,
                'abuse_score' => $abuseScore,
                'band' => $band->value,
                'would_be_outcome' => $outcome->value,
                'reason_codes' => $reasonCodes,
            ]);
        }

        return new RiskDecision(
            outcome: $outcome,
            band: $band,
            score: $score,
            reasonCodes: $reasonCodes,
            enforced: $enforced,
            abuseScore: $abuseScore,
            assessmentId: $assessment?->getKey(),
            policyVersion: (string) config('risk.policy_version'),
        );
    }

    /**
     * Two-track outcome resolution, escalated by any hard-override signal that
     * fired (most severe wins):
     *
     *  - the TOTAL band resolves through the band->outcome map but is CAPPED at
     *    step_up (identity/context evidence asks for verification, never holds);
     *  - the ABUSE band resolves uncapped but contributes only review/deny (its
     *    lighter outcomes are already covered by the total, which contains it);
     *  - the most severe of the two wins, then overrides force theirs.
     *
     * @param  list<array{hit: SignalHit, weight: int}>  $fired
     */
    private function resolveOutcome(RiskBand $band, RiskBand $abuseBand, array $fired): RiskOutcome
    {
        $map = (array) config('risk.band_outcomes', []);

        $fromTotal = RiskOutcome::from($map[$band->value] ?? 'allow');
        if ($fromTotal->rank() > RiskOutcome::StepUp->rank()) {
            $fromTotal = RiskOutcome::StepUp;
        }

        $fromAbuse = RiskOutcome::from($map[$abuseBand->value] ?? 'allow');
        if ($fromAbuse->rank() < RiskOutcome::Review->rank()) {
            $fromAbuse = RiskOutcome::Allow;
        }

        $outcome = $fromTotal->max($fromAbuse);

        $overrides = (array) config('risk.overrides', []);

        foreach ($fired as $f) {
            $code = $f['hit']->code;

            if (isset($overrides[$code])) {
                $outcome = $outcome->max(RiskOutcome::from($overrides[$code]));
            }
        }

        return $outcome;
    }

    /**
     * Write the immutable assessment + its signal hits. Returns null (and logs)
     * if persistence fails, so the caller still gets a decision.
     *
     * @param  list<array{hit: SignalHit, weight: int}>  $fired
     */
    private function persist(
        RiskContext $context,
        int $score,
        RiskBand $band,
        RiskOutcome $outcome,
        bool $enforced,
        array $fired,
    ): ?RiskAssessment {
        $assessment = RiskAssessment::create([
            'user_id' => $context->user?->getKey(),
            'context' => $context->context,
            'subject_type' => $context->subjectType,
            'subject_id' => $context->subjectId,
            'score' => $score,
            'band' => $band,
            'outcome' => $outcome,
            'enforced' => $enforced,
            'ip_address' => $context->ip,
            'device_uuid' => $context->deviceUuid,
            'amount_usd' => round($context->amountUsd, 2),
            'currency' => $context->currency,
            'policy_version' => (string) config('risk.policy_version'),
            'correlation_id' => $context->correlationId,
        ]);

        foreach ($fired as $f) {
            $assessment->signals()->create([
                'code' => $f['hit']->code,
                'weight' => $f['weight'],
                'detail' => $f['hit']->detail,
            ]);
        }

        return $assessment;
    }

    /**
     * The registered signal instances, resolved from config through the
     * container so each gets its dependencies. Config-driven so the policy's
     * signal set is changed without touching this class.
     *
     * @return list<RiskSignal>
     */
    private function registeredSignals(): array
    {
        $signals = [];

        foreach ((array) config('risk.signals', []) as $class) {
            $instance = app($class);

            if ($instance instanceof RiskSignal) {
                $signals[] = $instance;
            }
        }

        return $signals;
    }

    /**
     * The decision returned when the engine is off or has failed: allow, nothing
     * persisted, nothing enforced.
     */
    private function inertAllow(): RiskDecision
    {
        return new RiskDecision(
            outcome: RiskOutcome::Allow,
            band: RiskBand::Low,
            score: 0,
            reasonCodes: [],
            enforced: false,
        );
    }

    /**
     * Evaluates a login attempt and returns a Session Risk Level: LOW, MEDIUM, HIGH, CRITICAL.
     *
     * Login-risk path, which drives the email OTP via EvaluateLoginRiskListener.
     * The travel component is now a real country/time check owned by
     * LoginCountryGuard; the rest still migrates onto the signal model.
     */
    public function evaluateLoginRisk(User $user, TrustedDevice $device, string $ipAddress): string
    {
        $riskScore = 0;

        if (! $device->isTrusted()) {
            $riskScore += 50; // New device is inherently high risk
        } else {
            // A trusted device that hasn't been seen in 6 months might be medium risk
            if ($device->last_seen_at && $device->last_seen_at->diffInMonths(now()) >= 6) {
                $riskScore += 20;
            }
        }

        // 50, not the 40 the old IP check scored, because HIGH starts at 50 and
        // a genuine country change inside the window is worth a code on its own.
        // At 40 the flag could only ever matter in combination with something
        // else, which is a rule that does not do its own job.
        if (app(LoginCountryGuard::class)->challengeRequired($user, $ipAddress, $device->device_uuid)) {
            $riskScore += 50;
        }

        if ($this->hasHighVelocity($user->id, 'login')) {
            $riskScore += 30;
        }

        $this->recordAction($user->id, 'login');

        if ($riskScore >= 80) {
            return 'CRITICAL';
        }
        if ($riskScore >= 50) {
            return 'HIGH';
        }
        if ($riskScore >= 20) {
            return 'MEDIUM';
        }

        return 'LOW';
    }

    private function hasHighVelocity(int $userId, string $actionType): bool
    {
        $key = "fraud_velocity:{$userId}:{$actionType}";
        $attempts = Cache::get($key, 0);

        return $attempts > 5;
    }

    private function recordAction(int $userId, string $actionType): void
    {
        $key = "fraud_velocity:{$userId}:{$actionType}";
        Cache::add($key, 0, now()->addMinutes(10));
        Cache::increment($key);
    }
}

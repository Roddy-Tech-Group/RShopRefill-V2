<?php

namespace App\Domain\Risk\DTOs;

use App\Domain\Risk\Enums\RiskBand;
use App\Domain\Risk\Enums\RiskOutcome;

/**
 * The engine's verdict on one assessment: the score, the band, the outcome, and
 * the reason codes that produced it, plus whether the engine actually ACTED on
 * it (enforced) or only observed (shadow).
 *
 * `outcome` is always the outcome the policy computed. `enforced` says whether
 * the caller should act on it: in shadow mode the engine returns the true
 * computed outcome for the record and for tests, but enforced is false and the
 * gate lets the action through. effectiveOutcome() collapses that to what the
 * customer actually experiences.
 */
final readonly class RiskDecision
{
    /**
     * @param  int  $abuseScore  the abuse-family subscore; the only score that
     *                           can drive review/deny under two-track scoring
     * @param  list<string>  $reasonCodes
     */
    public function __construct(
        public RiskOutcome $outcome,
        public RiskBand $band,
        public int $score,
        public array $reasonCodes,
        public bool $enforced,
        public int $abuseScore = 0,
        public ?string $assessmentId = null,
        public ?string $policyVersion = null,
    ) {}

    /**
     * What the customer actually experiences: the computed outcome when enforcing,
     * always allow in shadow mode.
     */
    public function effectiveOutcome(): RiskOutcome
    {
        return $this->enforced ? $this->outcome : RiskOutcome::Allow;
    }

    /**
     * Should the caller stop/alter the action? Only ever true when enforcing.
     */
    public function shouldIntervene(): bool
    {
        return $this->effectiveOutcome()->blocks();
    }
}

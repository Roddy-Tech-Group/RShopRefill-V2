<?php

namespace App\Domain\Risk\Enums;

/**
 * What the engine decided to do with a money action. Mirrors the card-scheme /
 * PSD2 SCA mental model: allow (frictionless), step_up (challenge, customer can
 * self-clear), review (hold for a human), deny (decline).
 *
 * Ordered by severity via rank() so the override resolver and the band map can
 * always pick "the most severe outcome wins".
 */
enum RiskOutcome: string
{
    case Allow = 'allow';
    case StepUp = 'step_up';
    case Review = 'review';
    case Deny = 'deny';

    /**
     * Severity rank, higher is more severe. Used to pick the winning outcome
     * when several rules (band + overrides) each propose one.
     */
    public function rank(): int
    {
        return match ($this) {
            self::Allow => 0,
            self::StepUp => 1,
            self::Review => 2,
            self::Deny => 3,
        };
    }

    /**
     * Whether this outcome stops the action from proceeding as-is. allow passes;
     * everything else needs the gate to intervene (once enforcement is on).
     */
    public function blocks(): bool
    {
        return $this !== self::Allow;
    }

    /**
     * The more severe of two outcomes.
     */
    public function max(self $other): self
    {
        return $this->rank() >= $other->rank() ? $this : $other;
    }
}

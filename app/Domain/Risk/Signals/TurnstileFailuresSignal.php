<?php

namespace App\Domain\Risk\Signals;

use App\Domain\Risk\DTOs\RiskContext;
use App\Domain\Risk\DTOs\SignalHit;
use App\Support\TaggedCache;

/**
 * Excessive failed Turnstile (bot) challenges from this IP: an automation
 * indicator. Reads the SAME counter the VerifyTurnstile middleware increments on
 * each failure (tag 'security', key turnstile_failures_{ip}, 15-minute window),
 * so the signal reflects live bot pressure without a second bookkeeping path.
 */
class TurnstileFailuresSignal extends AbstractRiskSignal
{
    private const THRESHOLD = 5;

    public function code(): string
    {
        return 'TURNSTILE_FAILURES';
    }

    public function evaluate(RiskContext $context): ?SignalHit
    {
        if ($context->ip === null) {
            return null;
        }

        $failures = (int) TaggedCache::for(['security'])
            ->get("turnstile_failures_{$context->ip}", 0);

        if ($failures >= self::THRESHOLD) {
            return $this->hit(['failures' => $failures, 'threshold' => self::THRESHOLD]);
        }

        return null;
    }
}

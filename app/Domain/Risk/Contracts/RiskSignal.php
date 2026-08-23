<?php

namespace App\Domain\Risk\Contracts;

use App\Domain\Risk\DTOs\RiskContext;
use App\Domain\Risk\DTOs\SignalHit;

/**
 * One risk signal: a small, independently testable rule that inspects a
 * RiskContext and either fires (returns a SignalHit with its code + PII-safe
 * detail) or does not (returns null). Signals are pure and side-effect-free
 * apart from reading their own data source, which is what lets each be unit
 * tested in isolation and swapped/retuned from config without touching the
 * engine. Weight is applied by the engine from config, never by the signal.
 */
interface RiskSignal
{
    /**
     * The stable machine-readable reason code, e.g. VELOCITY_USER_1H. Doubles as
     * the config key for this signal's weight and any override mapping.
     */
    public function code(): string;

    /**
     * Evaluate the context; return a hit when the signal fires, or null.
     */
    public function evaluate(RiskContext $context): ?SignalHit;
}

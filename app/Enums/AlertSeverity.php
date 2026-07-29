<?php

namespace App\Enums;

/**
 * How loudly an operational problem is allowed to interrupt a human.
 *
 * The tiers exist so that silence can be trusted. If everything pages, nothing
 * does — a solo operator has no tolerance budget for noise, so an alert that
 * repeatedly turns out to be fine must be re-tiered or deleted the same day.
 */
enum AlertSeverity: string
{
    /** Money is wrong, or the platform is down. Wake someone, 24/7. */
    case Sev1 = 'sev1';

    /** A revenue path or provider is degraded. Act within hours. */
    case Sev2 = 'sev2';

    /** Degradation with a workaround, or a trend worth watching. Next working day. */
    case Sev3 = 'sev3';

    public function label(): string
    {
        return match ($this) {
            self::Sev1 => 'SEV-1',
            self::Sev2 => 'SEV-2',
            self::Sev3 => 'SEV-3',
        };
    }

    /**
     * SEV-1 and SEV-2 are real incidents and belong in the exception tracker so
     * they carry the same history and triage workflow as a crash.
     */
    public function reportsToSentry(): bool
    {
        return $this === self::Sev1 || $this === self::Sev2;
    }

    /**
     * Only SEV-1 is permitted to interrupt a human out of hours.
     */
    public function interruptsAHuman(): bool
    {
        return $this === self::Sev1;
    }

    public function logLevel(): string
    {
        return match ($this) {
            self::Sev1, self::Sev2 => 'critical',
            self::Sev3 => 'warning',
        };
    }

    public function emoji(): string
    {
        return match ($this) {
            self::Sev1 => '🚨',
            self::Sev2 => '⚠️',
            self::Sev3 => 'ℹ️',
        };
    }
}

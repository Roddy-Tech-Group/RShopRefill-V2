<?php

namespace App\Domain\Risk\Signals;

use App\Domain\Risk\Contracts\RiskSignal;
use App\Domain\Risk\DTOs\SignalHit;

/**
 * Small shared base for signals: a hit() helper and typed config readers so each
 * concrete signal stays a few lines of its actual rule. Weight is deliberately
 * absent: the engine applies it from config by code.
 */
abstract class AbstractRiskSignal implements RiskSignal
{
    /**
     * @param  array<string, mixed>  $detail
     */
    protected function hit(array $detail = []): SignalHit
    {
        return new SignalHit($this->code(), $detail);
    }

    protected function threshold(string $key, int|float|null $default = null): int|float|null
    {
        $value = config("risk.thresholds.{$key}", $default);

        return $value === null ? $default : $value;
    }
}

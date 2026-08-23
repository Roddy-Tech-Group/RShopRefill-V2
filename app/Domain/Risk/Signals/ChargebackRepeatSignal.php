<?php

namespace App\Domain\Risk\Signals;

use App\Domain\Risk\DTOs\RiskContext;
use App\Domain\Risk\DTOs\SignalHit;
use App\Models\RiskLabel;

/**
 * A PATTERN of chargebacks (two or more in the lookback window). Fires on top of
 * CHARGEBACK_HISTORY, so a single dispute adds modest weight while a repeat
 * offender's combined weight reaches the review band on its own. This graduation
 * is deliberate: one chargeback is often a misunderstanding; two is a habit.
 */
class ChargebackRepeatSignal extends AbstractRiskSignal
{
    public function code(): string
    {
        return 'CHARGEBACK_REPEAT';
    }

    public function evaluate(RiskContext $context): ?SignalHit
    {
        $user = $context->user;

        if ($user === null) {
            return null;
        }

        $lookbackDays = (int) $this->threshold('chargeback_history_lookback_days', 365);
        $min = (int) $this->threshold('chargeback_repeat_min', 2);

        $count = RiskLabel::query()
            ->where('user_id', $user->getKey())
            ->where('label', RiskLabel::LABEL_CHARGEBACK)
            ->where('created_at', '>=', now()->subDays($lookbackDays))
            ->count();

        if ($count >= $min) {
            return $this->hit(['chargebacks' => $count, 'min' => $min, 'lookback_days' => $lookbackDays]);
        }

        return null;
    }
}

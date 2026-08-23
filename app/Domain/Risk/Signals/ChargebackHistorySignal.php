<?php

namespace App\Domain\Risk\Signals;

use App\Domain\Risk\DTOs\RiskContext;
use App\Domain\Risk\DTOs\SignalHit;
use App\Models\RiskLabel;

/**
 * The account has a history of chargebacks within the lookback window. Unlike a
 * confirmed-fraud label (a hard deny), a chargeback ADDS risk weight: past
 * disputes strongly predict future ones, but a single chargeback should not
 * permanently block a customer. This is the read side of the feedback loop that
 * makes the engine improve from real outcomes.
 */
class ChargebackHistorySignal extends AbstractRiskSignal
{
    public function code(): string
    {
        return 'CHARGEBACK_HISTORY';
    }

    public function evaluate(RiskContext $context): ?SignalHit
    {
        $user = $context->user;

        if ($user === null) {
            return null;
        }

        $lookbackDays = (int) $this->threshold('chargeback_history_lookback_days', 365);

        $count = RiskLabel::query()
            ->where('user_id', $user->getKey())
            ->where('label', RiskLabel::LABEL_CHARGEBACK)
            ->where('created_at', '>=', now()->subDays($lookbackDays))
            ->count();

        if ($count > 0) {
            return $this->hit(['chargebacks' => $count, 'lookback_days' => $lookbackDays]);
        }

        return null;
    }
}

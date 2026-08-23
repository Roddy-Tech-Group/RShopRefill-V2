<?php

namespace App\Domain\Risk\Signals;

use App\Domain\Risk\DTOs\RiskContext;
use App\Domain\Risk\DTOs\SignalHit;
use App\Models\RiskLabel;

/**
 * The account carries a CONFIRMED-FRAUD label within the lookback window. An
 * override signal: when it fires the outcome is forced to deny regardless of
 * score (see config('risk.overrides')). Chargebacks are handled separately, as a
 * SCORED risk (CHARGEBACK_HISTORY), since a single dispute should add weight, not
 * permanently deny.
 */
class ConfirmedFraudLabelSignal extends AbstractRiskSignal
{
    public function code(): string
    {
        return 'CONFIRMED_FRAUD_LABEL';
    }

    public function evaluate(RiskContext $context): ?SignalHit
    {
        $user = $context->user;

        if ($user === null) {
            return null;
        }

        $lookbackDays = (int) $this->threshold('fraud_label_lookback_days', 365);

        $label = RiskLabel::query()
            ->where('user_id', $user->getKey())
            ->where('label', RiskLabel::LABEL_CONFIRMED_FRAUD)
            ->where('created_at', '>=', now()->subDays($lookbackDays))
            ->latest()
            ->first();

        if ($label !== null) {
            return $this->hit([
                'label' => $label->label,
                'source' => $label->source,
            ]);
        }

        return null;
    }
}

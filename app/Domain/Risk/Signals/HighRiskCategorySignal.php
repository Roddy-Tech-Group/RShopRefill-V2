<?php

namespace App\Domain\Risk\Signals;

use App\Domain\Risk\DTOs\RiskContext;
use App\Domain\Risk\DTOs\SignalHit;

/**
 * The action touches a high-risk product category (stored value / gift cards are
 * the classic card-testing and resale target). Matched against the category
 * slugs carried on the context; the high-risk set is config-driven.
 */
class HighRiskCategorySignal extends AbstractRiskSignal
{
    public function code(): string
    {
        return 'HIGH_RISK_CATEGORY';
    }

    public function evaluate(RiskContext $context): ?SignalHit
    {
        $highRisk = array_map('strval', (array) config('risk.high_risk_categories', []));

        $matched = array_values(array_intersect($context->categories, $highRisk));

        if ($matched !== []) {
            return $this->hit(['categories' => $matched]);
        }

        return null;
    }
}

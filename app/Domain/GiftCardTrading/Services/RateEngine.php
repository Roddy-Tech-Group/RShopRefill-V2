<?php

namespace App\Domain\GiftCardTrading\Services;

use App\Models\GiftCardRate;

class RateEngine
{
    /**
     * Calculate the expected payout for a given rate and declared value.
     */
    public function calculatePayout(GiftCardRate $rate, float $declaredValue): float
    {
        if ($rate->min_value > 0 && $declaredValue < $rate->min_value) {
            throw new \InvalidArgumentException('Declared value is below the minimum allowed for this rate.');
        }

        if ($rate->max_value > 0 && $declaredValue > $rate->max_value) {
            throw new \InvalidArgumentException('Declared value is above the maximum allowed for this rate.');
        }

        if (! $rate->is_active || ! $rate->brand->is_active) {
            throw new \InvalidArgumentException('This rate or brand is currently inactive.');
        }

        return round($declaredValue * $rate->rate, 2);
    }
}

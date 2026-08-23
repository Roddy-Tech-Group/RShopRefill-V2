<?php

namespace App\Domain\Risk\Signals;

use App\Domain\Risk\DTOs\RiskContext;
use App\Domain\Risk\DTOs\SignalHit;

/**
 * The account is young (default < 7d) but past the "brand new" window. A lighter
 * companion to ACCOUNT_NEW so the two do not double-count: this fires only once
 * the account is older than the new-account cutoff yet still inside the young
 * window.
 */
class AccountYoungSignal extends AbstractRiskSignal
{
    public function code(): string
    {
        return 'ACCOUNT_YOUNG';
    }

    public function evaluate(RiskContext $context): ?SignalHit
    {
        $user = $context->user;

        if ($user === null || $user->created_at === null) {
            return null;
        }

        $newHours = (int) $this->threshold('account_new_hours', 24);
        $youngDays = (int) $this->threshold('account_young_days', 7);

        $isNew = $user->created_at->greaterThan(now()->subHours($newHours));
        $isYoung = $user->created_at->greaterThan(now()->subDays($youngDays));

        if (! $isNew && $isYoung) {
            return $this->hit([
                'account_age_days' => (int) $user->created_at->diffInDays(now()),
                'threshold_days' => $youngDays,
            ]);
        }

        return null;
    }
}

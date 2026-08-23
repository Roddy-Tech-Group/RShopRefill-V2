<?php

namespace App\Domain\Risk\Signals;

use App\Domain\Risk\DTOs\RiskContext;
use App\Domain\Risk\DTOs\SignalHit;

/**
 * The account was created very recently (default < 24h). Fresh accounts are the
 * vehicle for most first-party and card-testing abuse, so age is a baseline
 * signal across every fraud stack.
 */
class AccountNewSignal extends AbstractRiskSignal
{
    public function code(): string
    {
        return 'ACCOUNT_NEW';
    }

    public function evaluate(RiskContext $context): ?SignalHit
    {
        $user = $context->user;

        if ($user === null || $user->created_at === null) {
            return null;
        }

        $hours = (int) $this->threshold('account_new_hours', 24);

        if ($user->created_at->greaterThan(now()->subHours($hours))) {
            return $this->hit([
                'account_age_hours' => (int) $user->created_at->diffInHours(now()),
                'threshold_hours' => $hours,
            ]);
        }

        return null;
    }
}

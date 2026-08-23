<?php

namespace App\Domain\Risk\Signals;

use App\Domain\Risk\DTOs\RiskContext;
use App\Domain\Risk\DTOs\SignalHit;

/**
 * The account is banned. An override signal that forces deny (see
 * config('risk.overrides')). A banned account should never transact; this makes
 * that a deterministic risk decision with a reason code and an audit row, rather
 * than relying only on the account-active middleware.
 */
class AccountBannedSignal extends AbstractRiskSignal
{
    public function code(): string
    {
        return 'ACCOUNT_BANNED';
    }

    public function evaluate(RiskContext $context): ?SignalHit
    {
        $user = $context->user;

        if ($user !== null && $user->isBanned()) {
            return $this->hit(['banned_at' => optional($user->banned_at)->toIso8601String()]);
        }

        return null;
    }
}

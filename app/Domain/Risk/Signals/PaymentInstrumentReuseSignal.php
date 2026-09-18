<?php

namespace App\Domain\Risk\Signals;

use App\Domain\Risk\DTOs\RiskContext;
use App\Domain\Risk\DTOs\SignalHit;
use App\Models\PaymentAttempt;
use App\Models\PaymentDecline;
use Illuminate\Support\Collection;

/**
 * One card used across many accounts: the strongest card-testing / account-farm
 * indicator, weighted highest.
 *
 * We deliberately do NOT store PANs. This reads a keyed card fingerprint from
 * two places: the attempt metadata a successful Flutterwave verification now
 * stamps, and the refused cards the gateway reported after a popup closed. A
 * stolen card that is declined on one account and then works on another is
 * exactly the shape this exists to catch, and the refusals are the half of
 * that picture we never used to have.
 */
class PaymentInstrumentReuseSignal extends AbstractRiskSignal
{
    public function code(): string
    {
        return 'PAYMENT_INSTRUMENT_REUSE';
    }

    public function evaluate(RiskContext $context): ?SignalHit
    {
        $user = $context->user;

        if ($user === null) {
            return null;
        }

        $windowDays = (int) $this->threshold('instrument_reuse_window_days', 30);
        $max = (int) $this->threshold('instrument_reuse_max_accounts', 1);
        $since = now()->subDays($windowDays);

        // Fingerprints seen on this user's recent attempts. Read from the cast
        // metadata array in PHP (a JSON-path pluck is not portable across drivers).
        $fromAttempts = PaymentAttempt::query()
            ->where('user_id', $user->getKey())
            ->where('created_at', '>=', $since)
            ->get(['id', 'metadata'])
            ->map(fn (PaymentAttempt $attempt) => $attempt->metadata['card_fingerprint'] ?? null);

        $fromDeclines = PaymentDecline::query()
            ->where('user_id', $user->getKey())
            ->where('declined_at', '>=', $since)
            ->pluck('card_fingerprint');

        $fingerprints = $fromAttempts->merge($fromDeclines)->filter()->unique()->values();

        foreach ($fingerprints as $fingerprint) {
            $accounts = $this->accountsUsing($fingerprint, $since);

            if ($accounts > $max) {
                return $this->hit(['accounts' => $accounts, 'max' => $max]);
            }
        }

        return null;
    }

    /**
     * Distinct accounts that presented this card in the window, whether it
     * paid or was refused.
     */
    private function accountsUsing(string $fingerprint, \DateTimeInterface $since): int
    {
        $paid = PaymentAttempt::query()
            ->where('metadata->card_fingerprint', $fingerprint)
            ->where('created_at', '>=', $since)
            ->whereNotNull('user_id')
            ->distinct()
            ->pluck('user_id');

        $refused = PaymentDecline::query()
            ->where('card_fingerprint', $fingerprint)
            ->where('declined_at', '>=', $since)
            ->whereNotNull('user_id')
            ->distinct()
            ->pluck('user_id');

        return (new Collection($paid))->merge($refused)->unique()->count();
    }
}

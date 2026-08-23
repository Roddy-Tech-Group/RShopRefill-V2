<?php

namespace App\Domain\Risk\Signals;

use App\Domain\Risk\DTOs\RiskContext;
use App\Domain\Risk\DTOs\SignalHit;
use App\Models\PaymentAttempt;

/**
 * One card used across many accounts: the strongest card-testing / account-farm
 * indicator, weighted highest. We deliberately do NOT store PANs, so this reads a
 * non-sensitive card FINGERPRINT that a gateway may return in the attempt
 * metadata (config key metadata.card_fingerprint). Until a gateway populates it,
 * the signal abstains cleanly, a live-read typed slot that activates the moment
 * fingerprints start arriving, with no schema change.
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

        // Fingerprints seen on this user's recent attempts. Read from the cast
        // metadata array in PHP (a JSON-path pluck is not portable across drivers).
        $fingerprints = PaymentAttempt::query()
            ->where('user_id', $user->getKey())
            ->where('created_at', '>=', now()->subDays($windowDays))
            ->get(['id', 'metadata'])
            ->map(fn (PaymentAttempt $attempt) => $attempt->metadata['card_fingerprint'] ?? null)
            ->filter()
            ->unique()
            ->values();

        foreach ($fingerprints as $fingerprint) {
            $accounts = (int) PaymentAttempt::query()
                ->where('metadata->card_fingerprint', $fingerprint)
                ->where('created_at', '>=', now()->subDays($windowDays))
                ->distinct()
                ->count('user_id');

            if ($accounts > $max) {
                return $this->hit(['accounts' => $accounts, 'max' => $max]);
            }
        }

        return null;
    }
}

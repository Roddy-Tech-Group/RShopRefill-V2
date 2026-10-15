<?php

namespace App\Domain\Kyc\Services;

use App\Domain\Kyc\DTOs\KycVerdict;
use App\Models\KycVerification;
use App\Models\User;
use Illuminate\Support\Facades\Log;

/**
 * The single place a provider verdict becomes state: resolves the attempt row
 * by its job reference, stamps the outcome on it, and moves the customer's
 * kyc_status. Used by the Smile webhook and by the admin "check with provider"
 * sync, so both paths land identically.
 *
 * A rejected verdict never demotes an already-verified customer: a stale or
 * retried job failing later must not undo a real verification.
 *
 * An approval also keeps what the document said (legal name, date of birth,
 * document type, issuing country, expiry) on the attempt it approved. Nothing
 * on screen edits those; the customer's display name stays their own.
 *
 * The customer is told the outcome once, when the verdict actually changes
 * something. A replayed callback for an attempt that is already decided moves
 * nothing and announces nothing.
 */
class KycVerdictRecorder
{
    public function __construct(
        private readonly KycOutcomeNotifier $notifier,
    ) {}

    /**
     * Apply a verdict. Returns the affected user, or null when the reference
     * matches no recorded attempt and no fallback user is given (e.g. a job
     * minted before attempts were persisted).
     *
     * @param  bool  $correctDecided  let an approved verdict overwrite an attempt
     *                                that was already decided. Only for reading a
     *                                pass back from the provider, where the stored
     *                                decision is the one known to be wrong.
     */
    public function apply(KycVerdict $verdict, ?User $fallbackUser = null, string $source = 'webhook', bool $correctDecided = false): ?User
    {
        $verification = KycVerification::where('reference', $verdict->reference)->first();
        $user = $verification?->user ?? $fallbackUser;

        if (! $user) {
            return null;
        }

        $wasVerified = $user->isKycVerified();
        $resolvedAttempt = false;

        if ($verification && ($verification->isAwaitingVerdict() || ($correctDecided && $verdict->approved && $verification->status !== 'approved'))) {
            $resultCode = data_get($verdict->raw, 'ResultCode', data_get($verdict->raw, 'result_code'));

            $verification->update([
                'status' => $verdict->approved ? 'approved' : 'rejected',
                'result_code' => is_scalar($resultCode) ? (string) $resultCode : null,
                'result_text' => $verdict->reason,
                'decided_at' => now(),
                // Only a pass proves who the customer is; a failed document
                // read names nobody we should keep.
                ...($verdict->approved && $verdict->identity ? $verdict->identity->attributes() : []),
            ]);

            $resolvedAttempt = true;
        }

        // Only a verdict that decided something moves the account. A replay for
        // an attempt already decided (Smile retries callbacks) must not undo a
        // later admin decision, such as a customer set back to rejected.
        if ($verification === null || $resolvedAttempt) {
            if ($verdict->approved) {
                $user->update(['kyc_status' => 'verified']);
            } elseif (! $wasVerified) {
                $user->update(['kyc_status' => 'rejected']);
            }
        }

        Log::info('kyc.verdict_applied', [
            'source' => $source,
            'user_id' => $user->getKey(),
            'reference' => $verdict->reference,
            'approved' => $verdict->approved,
        ]);

        // A decided attempt being replayed changes nothing, so it says nothing.
        // Without an attempt row (a legacy job) the account status is the
        // only signal of a real change.
        $changedSomething = $verification ? $resolvedAttempt : $user->wasChanged('kyc_status');

        if ($changedSomething) {
            if ($verdict->approved && ! $wasVerified) {
                $this->notifier->verified($user, $verdict->reference);
            } elseif (! $verdict->approved && ! $wasVerified) {
                $this->notifier->notVerified($user, $verdict->reference);
            }
        }

        return $user;
    }
}

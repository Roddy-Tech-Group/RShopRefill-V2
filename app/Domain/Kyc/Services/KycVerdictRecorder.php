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
 */
class KycVerdictRecorder
{
    /**
     * Apply a verdict. Returns the affected user, or null when the reference
     * matches no recorded attempt and no fallback user is given (e.g. a job
     * minted before attempts were persisted).
     */
    public function apply(KycVerdict $verdict, ?User $fallbackUser = null, string $source = 'webhook'): ?User
    {
        $verification = KycVerification::where('reference', $verdict->reference)->first();
        $user = $verification?->user ?? $fallbackUser;

        if (! $user) {
            return null;
        }

        if ($verification && $verification->isPending()) {
            $resultCode = data_get($verdict->raw, 'ResultCode', data_get($verdict->raw, 'result_code'));

            $verification->update([
                'status' => $verdict->approved ? 'approved' : 'rejected',
                'result_code' => is_scalar($resultCode) ? (string) $resultCode : null,
                'result_text' => $verdict->reason,
                'decided_at' => now(),
            ]);
        }

        if ($verdict->approved) {
            $user->update(['kyc_status' => 'verified']);
        } elseif (! $user->isKycVerified()) {
            $user->update(['kyc_status' => 'rejected']);
        }

        Log::info('kyc.verdict_applied', [
            'source' => $source,
            'user_id' => $user->getKey(),
            'reference' => $verdict->reference,
            'approved' => $verdict->approved,
        ]);

        return $user;
    }
}

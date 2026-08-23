<?php

namespace App\Domain\Risk\Signals;

use App\Domain\Risk\DTOs\RiskContext;
use App\Domain\Risk\DTOs\SignalHit;
use App\Models\User;
use Illuminate\Support\Str;

/**
 * Near-duplicate identities: the same phone number, or the same email once plus-
 * aliasing is stripped (john+1@x.com, john+2@x.com -> john@x.com), spread across
 * multiple accounts. A classic way one actor spins up many accounts. Fires when
 * the number of DISTINCT accounts sharing the normalised email or the phone
 * EXCEEDS the configured maximum.
 */
class EmailPhoneSimilaritySignal extends AbstractRiskSignal
{
    public function code(): string
    {
        return 'EMAIL_PHONE_SIMILARITY';
    }

    public function evaluate(RiskContext $context): ?SignalHit
    {
        $user = $context->user;

        if ($user === null) {
            return null;
        }

        $max = (int) $this->threshold('similarity_max_accounts', 1);

        $emailMatches = $this->accountsSharingEmail($user);
        $phoneMatches = $this->accountsSharingPhone($user);
        $total = max($emailMatches, $phoneMatches);

        if ($total > $max) {
            return $this->hit([
                'email_accounts' => $emailMatches,
                'phone_accounts' => $phoneMatches,
                'max' => $max,
            ]);
        }

        return null;
    }

    /**
     * Distinct accounts (incl. this one) whose email shares the same address once
     * plus-aliasing is removed.
     */
    private function accountsSharingEmail(User $user): int
    {
        $email = Str::lower((string) $user->email);

        if (! str_contains($email, '@')) {
            return 0;
        }

        [$local, $domain] = explode('@', $email, 2);
        $base = Str::before($local, '+');

        return (int) User::query()
            ->where(function ($q) use ($base, $domain) {
                $q->whereRaw('LOWER(email) = ?', ["{$base}@{$domain}"])
                    ->orWhereRaw('LOWER(email) LIKE ?', ["{$base}+%@{$domain}"]);
            })
            ->distinct()
            ->count('id');
    }

    /**
     * Distinct accounts sharing this exact phone number (E.164).
     */
    private function accountsSharingPhone(User $user): int
    {
        $phone = trim((string) $user->phone);

        if ($phone === '') {
            return 0;
        }

        return (int) User::query()
            ->where('phone', $phone)
            ->distinct()
            ->count('id');
    }
}

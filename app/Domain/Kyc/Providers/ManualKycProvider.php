<?php

namespace App\Domain\Kyc\Providers;

use App\Domain\Kyc\Contracts\KycProvider;
use App\Domain\Kyc\DTOs\KycSession;
use App\Domain\Kyc\DTOs\KycVerdict;
use App\Models\User;

/**
 * The built-in provider: the customer uploads documents on our own KYC page and
 * an admin approves or rejects them in the dashboard. It is the default so the
 * engine is fully functional before any paid vendor is wired, and the fallback
 * if a configured vendor is unavailable.
 *
 * There is no webhook — the decision is made by a human in the admin UI, which
 * flips users.kyc_status directly — so verdictFromWebhook never fires.
 */
class ManualKycProvider implements KycProvider
{
    public function key(): string
    {
        return 'manual';
    }

    public function startVerification(User $user, ?string $issuingCountry = null): KycSession
    {
        // "Hosted" flow is our own private document-upload page. The issuing
        // country is irrelevant here; a human reviews whatever is uploaded.
        return new KycSession(
            reference: 'manual_'.$user->getKey(),
            hostedUrl: route('dashboard.kyc'),
        );
    }

    public function verdictFromWebhook(array $payload): ?KycVerdict
    {
        return null;
    }
}

<?php

namespace App\Domain\Kyc\Services;

use App\Domain\Kyc\Contracts\KycProvider;
use App\Domain\Kyc\Providers\ManualKycProvider;
use App\Domain\Kyc\Providers\SmileKycProvider;
use App\Models\User;

/**
 * Resolves the KycProvider for a verification.
 *
 * Smile ID serves every market today: African users via Biometric KYC (backed
 * by direct government-database lookups), the rest of the world via Global
 * Document Verification. That product choice is made inside SmileKycProvider by
 * country, so every user routes to Smile here. Sumsub is held in reserve for
 * when non-African volume justifies its monthly commitment; when it lands it
 * registers a key here and forUser() splits by region again, no engine change.
 *
 * Unknown or not-yet-integrated keys fall back to the built-in manual reviewer
 * rather than failing, so a mis-set provider never leaves verification with no
 * path.
 */
class KycProviderFactory
{
    /**
     * Resolve a provider by key, or the configured default when none is given.
     */
    public function make(?string $key = null): KycProvider
    {
        $key = $key ?? (string) config('kyc.provider', 'smile');

        return match ($key) {
            'manual' => new ManualKycProvider,
            'smile' => app(SmileKycProvider::class),
            // 'sumsub' => app(SumsubKycProvider::class), // reserved for non-African volume
            default => new ManualKycProvider,
        };
    }

    /**
     * The provider to verify this user. Smile for everyone today.
     */
    public function forUser(User $user): KycProvider
    {
        return $this->make('smile');
    }
}

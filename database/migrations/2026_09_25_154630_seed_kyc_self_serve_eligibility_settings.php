<?php

use App\Domain\Kyc\Services\KycSelfServeEligibility;
use App\Models\SiteSetting;
use Illuminate\Database\Migrations\Migration;

/**
 * The CEO's transact-first rule for self-serve KYC (2026-09-22): a customer
 * must have completed at least one purchase or wallet top-up of at least $1
 * before "Verify now" will start a paid identity check. Two admin rows under
 * Verification & Limits so the count and the floor can move without a
 * deploy. Descriptions stay under 255 characters: the column is VARCHAR(255)
 * on MariaDB and sqlite does not enforce it.
 */
return new class extends Migration
{
    /** @var array<string, array{0: string, 1: string}> */
    private const ROWS = [
        KycSelfServeEligibility::MIN_TRANSACTIONS_KEY => ['1', 'Self-serve KYC: how many completed purchases or wallet top-ups a customer needs before Verify now will start a paid identity check. Step-ups at checkout and funding ignore this. 0 = no requirement.'],
        KycSelfServeEligibility::MIN_TRANSACTION_USD_KEY => ['1', 'Self-serve KYC: the smallest purchase or wallet top-up (USD) that counts toward the requirement above, so a few cents cannot unlock a paid check. Refunded orders never count.'],
    ];

    public function up(): void
    {
        foreach (self::ROWS as $key => [$value, $description]) {
            if (SiteSetting::query()->where('key', $key)->exists()) {
                continue;
            }

            SiteSetting::put($key, $value, 'features', $description);
        }
    }

    public function down(): void
    {
        SiteSetting::query()->whereIn('key', array_keys(self::ROWS))->delete();
    }
};

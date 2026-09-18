<?php

use App\Models\SiteSetting;
use Illuminate\Database\Migrations\Migration;

/**
 * Put the transaction caps where the admin Settings page can edit them
 * (Verification & Limits).
 *
 * They used to be read from the legacy `settings` table, which has no admin
 * screen and was never seeded, so "admin-tunable" meant a deploy. Five rows,
 * not six: the standard (unverified) monthly cap is the cumulative KYC dial
 * (`features.kyc_cumulative_threshold_usd`), one row doing both jobs, so the
 * ceiling an unverified customer is shown is the amount at which they are
 * asked to verify and the two can never drift apart.
 *
 * Values are USD of money in (wallet fundings plus card, mobile money and
 * crypto orders; orders paid from wallet balance are not counted). 0 = no cap
 * on that window. Only enforced while spending_limits_enforce is on.
 */
return new class extends Migration
{
    /** @var array<string, array{0: string, 1: string}> */
    private const ROWS = [
        'features.spend_limit_standard_day_usd' => ['500', 'Unverified customers: most money in (USD) in any 24 hours. Wallet fundings and card, mobile money or crypto orders count; wallet-paid orders do not. Usually equals the single-transaction KYC dial. 0 = no cap. Needs spending_limits_enforce on.'],
        'features.spend_limit_standard_week_usd' => ['1000', 'Unverified customers: most money in (USD) allowed in any 7 days. Their monthly cap is the cumulative KYC dial above, one number doing both jobs. 0 = no cap. Only enforced while spending_limits_enforce is on.'],
        'features.spend_limit_verified_day_usd' => ['5000', 'Identity-verified customers: most money in (USD) allowed in any 24 hours. 0 = no cap. Only enforced while spending_limits_enforce is on.'],
        'features.spend_limit_verified_week_usd' => ['10000', 'Identity-verified customers: most money in (USD) allowed in any 7 days. 0 = no cap. Only enforced while spending_limits_enforce is on.'],
        'features.spend_limit_verified_month_usd' => ['15000', 'Identity-verified customers: most money in (USD) allowed in any 30 days. 0 = no cap. Only enforced while spending_limits_enforce is on.'],
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

<?php

use App\Models\SiteSetting;
use Illuminate\Database\Migrations\Migration;

/**
 * Split the one KYC amount into two dials, the way a bank sets a
 * per-transaction limit and a cumulative one.
 *
 * `features.kyc_tier2_threshold_usd` used to do both jobs: a single action at
 * or above it, or lifetime paid-order volume crossing it. It keeps its value
 * ($500 in production) and becomes the single-transaction dial only. The new
 * `features.kyc_cumulative_threshold_usd` row governs money in over a rolling
 * window (config kyc.cumulative_window_days, 30), counting completed wallet
 * fundings and gateway-settled orders but never an order paid from wallet
 * balance, which was counted when it was funded. Seeded at $2,000. The same row
 * is the standard (unverified) monthly transaction cap, so the two engines
 * read as one ladder.
 */
return new class extends Migration
{
    private const SINGLE = 'features.kyc_tier2_threshold_usd';

    private const CUMULATIVE = 'features.kyc_cumulative_threshold_usd';

    private const SINGLE_DESCRIPTION_BEFORE = 'USD amount that triggers KYC identity verification: a single order or funding at or above it, or lifetime volume crossing it. The risk engine\'s high-value signal uses the same number. Only enforced while kyc_enforce is on.';

    public function up(): void
    {
        if (! SiteSetting::query()->where('key', self::CUMULATIVE)->exists()) {
            SiteSetting::put(
                self::CUMULATIVE,
                '2000',
                'features',
                'Cumulative KYC dial (USD): money in over the last 30 days (wallet fundings plus card, mobile money and crypto orders; wallet-paid orders not counted) plus the action in hand. Also the monthly limit unverified customers are shown. Needs kyc_enforce on.',
            );
        }

        // Same row, same value: only its job changes.
        SiteSetting::query()->where('key', self::SINGLE)->update([
            'description' => 'Single-transaction KYC dial in USD: one order or one wallet funding at or above this amount must verify their identity. The risk engine\'s high-value signal uses the same number. Only enforced while kyc_enforce is on.',
        ]);
    }

    public function down(): void
    {
        SiteSetting::query()->where('key', self::CUMULATIVE)->delete();

        SiteSetting::query()->where('key', self::SINGLE)->update([
            'description' => self::SINGLE_DESCRIPTION_BEFORE,
        ]);
    }
};

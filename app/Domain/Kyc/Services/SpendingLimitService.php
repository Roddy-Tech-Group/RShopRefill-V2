<?php

namespace App\Domain\Kyc\Services;

use App\Domain\Kyc\Exceptions\SpendingLimitExceededException;
use App\Models\SiteSetting;
use App\Models\User;
use App\Support\FeatureFlag;
use Illuminate\Support\Facades\Log;

/**
 * The per-verification-level transaction caps: rolling daily / weekly / monthly
 * sums of MONEY IN (settled USD) against the limits the KYC page displays.
 *
 * One meter with the KYC tier engine. Money in is KycTierService::moneyInUsd():
 * every completed wallet funding plus every paid order settled by card, mobile
 * money or crypto. An order paid from wallet balance is not counted, because
 * that money was counted when it was funded, and it is never refused here,
 * because spending a balance the customer already holds brings nothing new in.
 * So the caps apply to fundings and to gateway-paid orders alike, and a
 * customer cannot reach past a daily cap by parking money in the wallet first.
 *
 * One ladder with the KYC dials. The standard (unverified) monthly cap IS the
 * cumulative KYC dial: the same site_settings row over the same window, so the
 * ceiling an unverified customer is shown is the amount at which they are asked
 * to verify, and the two engines cannot drift apart. The other five amounts are
 * their own rows on the admin Settings page (Verification & Limits).
 *
 * One source of truth: sets() resolves every amount, and BOTH the KYC page and
 * this gate read it, so display and enforcement can never disagree.
 *
 * Enforcement is behind features.spending_limits_enforce_enabled (seeded off).
 * In shadow the gate logs the refusal it WOULD issue and lets the action pass.
 */
class SpendingLimitService
{
    public function __construct(
        private readonly KycTierService $tiers,
    ) {}

    public function enforcementEnabled(): bool
    {
        return FeatureFlag::on('spending_limits_enforce', false);
    }

    /**
     * Both limit sets, admin-tunable per value, in the exact shape the KYC page
     * renders.
     *
     * @return array{standard: array{day: float, week: float, month: float}, verified: array{day: float, week: float, month: float}}
     */
    public function sets(): array
    {
        return [
            'standard' => [
                'day' => $this->amount('standard', 'day'),
                'week' => $this->amount('standard', 'week'),
                // The cumulative KYC dial, not a row of its own.
                'month' => $this->tiers->cumulativeThresholdUsd(),
            ],
            'verified' => [
                'day' => $this->amount('verified', 'day'),
                'week' => $this->amount('verified', 'week'),
                'month' => $this->amount('verified', 'month'),
            ],
        ];
    }

    /**
     * The set that applies to this customer.
     *
     * @return array{day: float, week: float, month: float}
     */
    public function limitsFor(User $user): array
    {
        return $this->sets()[$user->isKycVerified() ? 'verified' : 'standard'];
    }

    /**
     * Refuse the action when it would push any rolling window past its cap.
     * A cap of 0 means "no limit on this window". Inert in shadow mode, and
     * never applied to an action that spends balance already held.
     *
     * @param  string  $context  'order' | 'funding'
     *
     * @throws SpendingLimitExceededException
     */
    public function guard(User $user, float $amountUsd, string $context = 'order', bool $bringsNewMoney = true): void
    {
        if (! $bringsNewMoney) {
            return;
        }

        $limits = $this->limitsFor($user);

        $windows = [
            'daily' => ['limit' => $limits['day'], 'since' => now()->subDay()],
            'weekly' => ['limit' => $limits['week'], 'since' => now()->subWeek()],
            // The same window the cumulative KYC dial sums over.
            'monthly' => ['limit' => $limits['month'], 'since' => now()->subDays($this->tiers->cumulativeWindowDays())],
        ];

        foreach ($windows as $window => $rule) {
            if ($rule['limit'] <= 0) {
                continue;
            }

            $moneyIn = $this->tiers->moneyInUsd($user, $rule['since']);

            if ($moneyIn + $amountUsd <= $rule['limit']) {
                continue;
            }

            if (! $this->enforcementEnabled()) {
                Log::info('limits.shadow_exceeded', [
                    'user_id' => $user->getKey(),
                    'context' => $context,
                    'window' => $window,
                    'limit_usd' => $rule['limit'],
                    'money_in_usd' => round($moneyIn, 2),
                    'amount_usd' => round($amountUsd, 2),
                    'kyc_verified' => $user->isKycVerified(),
                ]);

                return;
            }

            throw new SpendingLimitExceededException($window, $rule['limit'], $user->isKycVerified(), KycGate::verificationAvailable());
        }
    }

    /**
     * One cap in USD, from its admin-editable site_settings row, falling back
     * to config/limits.php when the row is unset or non-numeric.
     */
    private function amount(string $tier, string $window): float
    {
        $value = SiteSetting::get("features.spend_limit_{$tier}_{$window}_usd");

        return is_numeric($value) ? (float) $value : (float) config("limits.{$tier}.{$window}", 0);
    }
}

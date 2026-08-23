<?php

namespace App\Domain\Kyc\Services;

use App\Domain\Kyc\Exceptions\SpendingLimitExceededException;
use App\Domain\Payment\Enums\PaymentStatus;
use App\Models\Setting;
use App\Models\User;
use App\Support\FeatureFlag;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * The per-verification-level spending caps: rolling 24h / 7d / 30d sums of PAID
 * order value (settlement USD) against the limits the KYC page displays.
 *
 * One source of truth: sets() resolves each amount from the settings table with
 * the config/limits.php default, and BOTH the page and this gate read it, so
 * display and enforcement can never disagree.
 *
 * Purchases only: wallet funding is deliberately not counted, because funded
 * balance can only leave through checkout, where these caps catch it; counting
 * both would charge the same dollar twice against the limit.
 *
 * Enforcement is behind features.spending_limits_enforce_enabled (seeded off).
 * In shadow the gate logs the refusal it WOULD issue and lets the order pass.
 */
class SpendingLimitService
{
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
        $resolve = fn (string $tier, string $window): float => (float) Setting::get(
            "spend_limit_{$tier}_{$window}_usd",
            (float) config("limits.{$tier}.{$window}", 0),
        );

        return [
            'standard' => [
                'day' => $resolve('standard', 'day'),
                'week' => $resolve('standard', 'week'),
                'month' => $resolve('standard', 'month'),
            ],
            'verified' => [
                'day' => $resolve('verified', 'day'),
                'week' => $resolve('verified', 'week'),
                'month' => $resolve('verified', 'month'),
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
     * Refuse the order when it would push any rolling window past its cap.
     * A cap of 0 means "no limit on this window". Inert in shadow mode.
     *
     * @throws SpendingLimitExceededException
     */
    public function guard(User $user, float $amountUsd): void
    {
        $limits = $this->limitsFor($user);

        $windows = [
            'daily' => ['limit' => $limits['day'], 'since' => now()->subDay()],
            'weekly' => ['limit' => $limits['week'], 'since' => now()->subWeek()],
            'monthly' => ['limit' => $limits['month'], 'since' => now()->subMonth()],
        ];

        foreach ($windows as $window => $rule) {
            if ($rule['limit'] <= 0) {
                continue;
            }

            $spent = $this->spentSince($user, $rule['since']);

            if ($spent + $amountUsd <= $rule['limit']) {
                continue;
            }

            if (! $this->enforcementEnabled()) {
                Log::info('limits.shadow_exceeded', [
                    'user_id' => $user->getKey(),
                    'window' => $window,
                    'limit_usd' => $rule['limit'],
                    'spent_usd' => round($spent, 2),
                    'amount_usd' => round($amountUsd, 2),
                    'kyc_verified' => $user->isKycVerified(),
                ]);

                return;
            }

            throw new SpendingLimitExceededException($window, $rule['limit'], $user->isKycVerified());
        }
    }

    /**
     * Settled USD the customer has spent since the window opened (paid orders).
     * Orders with suspect pricing (non-USD display currency, no recorded
     * exchange rate) are excluded: usdTotal() would read their display amount
     * at face value as USD and one degraded NGN order could jam every window
     * shut for its owner. The rolling windows are recent, where every healthy
     * order carries its settlement snapshot, so nothing real is lost.
     */
    private function spentSince(User $user, Carbon $since): float
    {
        return (float) $user->orders()
            ->where('payment_status', PaymentStatus::Paid)
            ->where('created_at', '>=', $since)
            ->get()
            ->reject(fn ($order) => $order->hasSuspectPricing())
            ->sum(fn ($order) => $order->usdTotal());
    }
}

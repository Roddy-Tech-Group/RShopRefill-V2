<?php

namespace App\Domain\Kyc\Services;

use App\Domain\Kyc\Enums\KycTier;
use App\Domain\Payment\Enums\PaymentStatus;
use App\Domain\PaymentInsights\Services\PaymentBackoffManager;
use App\Domain\Shared\Enums\FundingStatus;
use App\Models\Order;
use App\Models\SiteSetting;
use App\Models\User;
use App\Models\WalletFunding;
use App\Support\FeatureFlag;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * The KYC tier engine.
 *
 * Answers two questions at every money action: what tier is this user AT, and
 * what tier does this action REQUIRE? When the user is below the requirement the
 * action needs a step-up. Requirements are driven by RISK EXPOSURE, the way a
 * bank sets limits: a single transaction at or above one amount, or the
 * customer's cumulative money in over a rolling window reaching another. Not by
 * profit, so a fraudster cannot outrun verification on the way up; the
 * thresholds (config/kyc.php, admin-tunable) are set high enough that realised
 * margin has typically covered a paid identity check by the time one fires.
 *
 * Money in is what the cumulative dial counts: completed wallet fundings and
 * orders settled straight from a card, mobile money or crypto. An order paid
 * from wallet balance is not money in (it was counted when it was funded), so
 * it neither adds to the sum nor adds itself at checkout. Counting both would
 * put a wallet customer at the gate on half the volume of a card customer.
 *
 * Enforcement is a feature flag (features.kyc_enforce_enabled), seeded off, so
 * the engine ships in shadow mode: it records the step-ups it WOULD demand
 * without turning anyone away, and is switched to enforcing on a deliberate flip
 * once the higher tiers' flows (phone OTP, a provider integration) exist. This
 * mirrors the payment-eligibility gate.
 */
class KycTierService
{
    /**
     * Is the engine actually demanding step-ups, or only observing?
     */
    public function enforcementEnabled(): bool
    {
        return FeatureFlag::on('kyc_enforce', false);
    }

    /**
     * The tier the user currently holds, from their verified state.
     */
    public function currentTier(User $user): KycTier
    {
        if ($user->isKycVerified()) {
            return KycTier::IdentityVerified;
        }

        if ($user->hasVerifiedPhone()) {
            return KycTier::PhoneVerified;
        }

        return KycTier::Registered;
    }

    /**
     * The minimum tier a money action of this USD value requires. Any single
     * Tier 2 exposure trigger crossing lifts the requirement to identity
     * verification; otherwise the phone tier applies.
     *
     * $riskFloor lets the risk engine raise the requirement independently of
     * value: a HIGH-risk action can force identity verification even on a small
     * amount. The stricter of the value-based tier and the risk floor wins.
     *
     * @param  string  $context  'order' or 'funding'
     * @param  bool  $bringsNewMoney  false for an order paid from wallet balance,
     *                                which the cumulative count does not add to itself
     */
    public function requiredTierFor(User $user, float $amountUsd, string $context = 'order', ?KycTier $riskFloor = null, bool $bringsNewMoney = true): KycTier
    {
        $single = $this->singleThresholdUsd();
        $cumulative = $this->cumulativeThresholdUsd();
        $ordersCap = (int) config('kyc.tier2_orders_in_24h', 0);

        // A threshold/cap of 0 means "unset — never triggers on this axis".
        // Context ('order' | 'funding') is accepted for logging/future
        // per-context rules; the amount rules are the same for both.
        $crossesTier2 =
            ($single > 0 && $amountUsd >= $single)
            || ($cumulative > 0 && $this->cumulativeExposureUsd($user, $amountUsd, $bringsNewMoney) >= $cumulative)
            || ($ordersCap > 0 && $this->ordersInLast24h($user) >= $ordersCap);

        $required = KycTier::Registered;

        // Tier 1 uses the same "0 = unset, never triggers" semantics as Tier 2.
        // This matters doubly while no phone-OTP flow exists: a 0 trigger used to
        // demand an UNATTAINABLE phone tier on every amount, stepping up $10
        // orders the moment enforcement was on.
        $tier1Trigger = (float) config('kyc.tier1_trigger_usd', 0);

        if ($crossesTier2) {
            $required = KycTier::IdentityVerified;
        } elseif ($tier1Trigger > 0 && $amountUsd >= $tier1Trigger) {
            $required = KycTier::PhoneVerified;
        }

        // The risk engine can raise, never lower, the requirement.
        if ($riskFloor !== null && $riskFloor->value > $required->value) {
            $required = $riskFloor;
        }

        return $required;
    }

    /**
     * The single-transaction amount in USD: one order or one funding at or
     * above it demands identity verification. Edited on the admin Settings
     * page (Verification & Limits) as `features.kyc_tier2_threshold_usd`; the
     * key predates the split into two dials and is kept so the production row
     * and the risk engine's high-value signal, which reads through here too,
     * carry on unchanged. Falls back to the config default when the row is
     * unset or non-numeric.
     */
    public function singleThresholdUsd(): float
    {
        $value = SiteSetting::get('features.kyc_tier2_threshold_usd');

        return is_numeric($value) ? (float) $value : (float) config('kyc.tier2_threshold_usd', 500);
    }

    /**
     * The cumulative amount in USD: money in over the rolling window, plus the
     * action in hand, at or above it demands identity verification. Edited on
     * the admin Settings page as `features.kyc_cumulative_threshold_usd`.
     */
    public function cumulativeThresholdUsd(): float
    {
        $value = SiteSetting::get('features.kyc_cumulative_threshold_usd');

        return is_numeric($value) ? (float) $value : (float) config('kyc.cumulative_threshold_usd', 2000);
    }

    /**
     * How far back money in is summed, in days.
     */
    public function cumulativeWindowDays(): int
    {
        return max(1, (int) config('kyc.cumulative_window_days', 30));
    }

    /**
     * The tier this user must reach before the action may proceed, or null when
     * they already satisfy it. In shadow mode the shortfall is logged and null
     * returned, so nothing is blocked until enforcement is switched on.
     *
     * @param  string  $context  'order' or 'funding'
     */
    public function requiredStepUp(User $user, float $amountUsd, string $context = 'order', ?KycTier $riskFloor = null, bool $bringsNewMoney = true): ?KycTier
    {
        $required = $this->requiredTierFor($user, $amountUsd, $context, $riskFloor, $bringsNewMoney);
        $current = $this->currentTier($user);

        if ($current->satisfies($required)) {
            return null;
        }

        if (! $this->enforcementEnabled()) {
            Log::info('kyc.shadow_step_up', [
                'user_id' => $user->getKey(),
                'context' => $context,
                'amount_usd' => round($amountUsd, 2),
                'brings_new_money' => $bringsNewMoney,
                'money_in_usd' => round($this->moneyInUsd($user), 2),
                'current_tier' => $current->value,
                'required_tier' => $required->value,
            ]);

            return null;
        }

        return $required;
    }

    /**
     * What the cumulative dial is measured against for this action: money in
     * over the window, plus the action itself when it brings new money.
     */
    public function cumulativeExposureUsd(User $user, float $amountUsd, bool $bringsNewMoney = true): float
    {
        return $this->moneyInUsd($user) + ($bringsNewMoney ? $amountUsd : 0.0);
    }

    /**
     * New money the customer has brought onto the platform since the window
     * opened, in settled USD: completed wallet fundings, plus paid orders that
     * were settled by a gateway rather than from wallet balance. Wallet-paid
     * orders are left out on purpose: that money was counted as a funding.
     *
     * Orders with suspect pricing (non-USD display currency, no recorded
     * exchange rate) are excluded, exactly as the spending limits exclude
     * them: usdTotal() would read their display amount at face value as USD.
     */
    public function moneyInUsd(User $user, ?Carbon $since = null): float
    {
        $since ??= now()->subDays($this->cumulativeWindowDays());

        $fundings = (float) WalletFunding::query()
            ->where('user_id', $user->getKey())
            ->where('status', FundingStatus::Completed)
            ->where('created_at', '>=', $since)
            ->sum('settled_amount_usd');

        $gatewayOrders = (float) $user->orders()
            ->where('payment_status', PaymentStatus::Paid)
            ->whereNotIn('payment_method', PaymentBackoffManager::BALANCE_PROVIDERS)
            ->where('created_at', '>=', $since)
            ->get()
            ->reject(fn (Order $order) => $order->hasSuspectPricing())
            ->sum(fn (Order $order) => $order->usdTotal());

        return round($fundings + $gatewayOrders, 4);
    }

    /**
     * The user's realised transaction volume in USD across every paid order,
     * however it was paid, since the account began. A reporting figure (the
     * customer data export), not a gate: the gates read moneyInUsd().
     */
    public function lifetimeVolumeUsd(User $user): float
    {
        return (float) $user->orders()
            ->where('payment_status', PaymentStatus::Paid)
            ->get()
            ->sum(fn ($order) => $order->usdTotal());
    }

    /**
     * How many orders the user has placed in the last 24 hours (velocity).
     */
    public function ordersInLast24h(User $user): int
    {
        return $user->orders()
            ->where('created_at', '>=', now()->subDay())
            ->count();
    }
}

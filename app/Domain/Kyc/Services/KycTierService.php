<?php

namespace App\Domain\Kyc\Services;

use App\Domain\Kyc\Enums\KycTier;
use App\Domain\Payment\Enums\PaymentStatus;
use App\Models\SiteSetting;
use App\Models\User;
use App\Support\FeatureFlag;
use Illuminate\Support\Facades\Log;

/**
 * The KYC tier engine.
 *
 * Answers two questions at every money action: what tier is this user AT, and
 * what tier does this action REQUIRE? When the user is below the requirement the
 * action needs a step-up. Requirements are driven by RISK EXPOSURE (single
 * transaction value, wallet-funding value, cumulative volume, velocity), not by
 * profit, so a fraudster cannot outrun verification on the way up; the
 * thresholds (config/kyc.php) are set high enough that realised margin has
 * typically covered a paid identity check by the time one fires.
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
     */
    public function requiredTierFor(User $user, float $amountUsd, string $context = 'order', ?KycTier $riskFloor = null): KycTier
    {
        // The single admin-tunable amount governs every value-based Tier 2
        // trigger: a single money action at or above it, or cumulative lifetime
        // volume crossing it. Context ('order' | 'funding') is accepted for
        // logging/future per-context rules; the amount rule is the same for both.
        $threshold = $this->tier2ThresholdUsd();
        $ordersCap = (int) config('kyc.tier2_orders_in_24h', 0);

        // A threshold/cap of 0 means "unset — never triggers on this axis".
        $crossesTier2 =
            ($threshold > 0 && $amountUsd >= $threshold)
            || ($threshold > 0 && ($this->lifetimeVolumeUsd($user) + $amountUsd) >= $threshold)
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
     * The admin-tunable Tier 2 amount in USD, edited on the admin Settings
     * page (Verification & Limits). It used to read the legacy `settings`
     * table, which has no admin UI, so the "tunable at runtime" promise was
     * unreachable; site_settings is where every other admin knob lives. Falls
     * back to the config default when the row is unset or non-numeric. The
     * risk engine's high-value signal reads through here too, so this one
     * number moves both gates.
     */
    public function tier2ThresholdUsd(): float
    {
        $value = SiteSetting::get('features.kyc_tier2_threshold_usd');

        return is_numeric($value) ? (float) $value : (float) config('kyc.tier2_threshold_usd', 500);
    }

    /**
     * The tier this user must reach before the action may proceed, or null when
     * they already satisfy it. In shadow mode the shortfall is logged and null
     * returned, so nothing is blocked until enforcement is switched on.
     *
     * @param  string  $context  'order' or 'funding'
     */
    public function requiredStepUp(User $user, float $amountUsd, string $context = 'order', ?KycTier $riskFloor = null): ?KycTier
    {
        $required = $this->requiredTierFor($user, $amountUsd, $context, $riskFloor);
        $current = $this->currentTier($user);

        if ($current->satisfies($required)) {
            return null;
        }

        if (! $this->enforcementEnabled()) {
            Log::info('kyc.shadow_step_up', [
                'user_id' => $user->getKey(),
                'context' => $context,
                'amount_usd' => round($amountUsd, 2),
                'current_tier' => $current->value,
                'required_tier' => $required->value,
            ]);

            return null;
        }

        return $required;
    }

    /**
     * The user's realised transaction volume in USD across paid orders. Summed
     * from each order's USD total; a cached column can replace this if the query
     * cost ever matters.
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

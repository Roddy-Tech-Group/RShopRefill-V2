<?php

namespace App\Domain\Fraud\Services;

use App\Models\User;
use App\Notifications\CriticalSystemAlert;
use App\Support\TaggedCache;
use Illuminate\Support\Facades\Notification;

class FraudDetectionService
{
    /**
     * Inspect a checkout attempt for signs of fraud or abuse.
     *
     * @return bool Returns true if the transaction is flagged as suspicious.
     */
    public function isSuspiciousCheckout(User $user, float $amount, string $ip): bool
    {
        $flagged = false;
        $reason = '';

        // 1. Velocity check: Too many purchases in a short window
        $purchaseCountKey = "fraud_velocity_purchases_{$user->id}";
        $recentPurchases = TaggedCache::for(['fraud'])->get($purchaseCountKey, 0);

        if ($recentPurchases > 10) {
            $flagged = true;
            $reason = 'User exceeded 10 purchases in an hour.';
        }

        // 2. High Value Transaction for unverified accounts
        if (! $flagged && $amount > 1000 && ! $user->isKycVerified()) {
            $flagged = true;
            $reason = 'High value transaction (>1000) for an unverified account.';
        }

        // 3. IP address velocity (prevent carding attacks)
        if (! $flagged) {
            $ipVelocityKey = "fraud_velocity_ip_{$ip}";
            $recentIpCheckouts = TaggedCache::for(['fraud'])->get($ipVelocityKey, 0);

            if ($recentIpCheckouts > 15) {
                $flagged = true;
                $reason = "More than 15 checkouts from the same IP {$ip} in an hour.";
            }
        }

        // 4. Excessive Turnstile failures (abuse cooldown)
        if (! $flagged) {
            if ($this->hasExcessiveTurnstileFailures($ip)) {
                $flagged = true;
                $reason = "Excessive Turnstile verification failures from IP {$ip}.";
            }
        }

        if ($flagged) {
            $adminEmail = config('mail.admin_address', 'admin@roddytechgroup.com');
            Notification::route('mail', $adminEmail)
                ->notify(new CriticalSystemAlert(
                    title: 'Fraud Alert: Suspicious Checkout Blocked',
                    description: 'A checkout attempt was blocked by the Fraud Detection Engine.',
                    context: [
                        'User ID' => $user->id,
                        'Email' => $user->email,
                        'Amount' => $amount,
                        'IP Address' => $ip,
                        'Reason' => $reason,
                    ]
                ));

            return true;
        }

        return false;
    }

    /**
     * Record a successful checkout attempt to update velocity metrics.
     */
    public function recordCheckout(User $user, string $ip): void
    {
        $purchaseCountKey = "fraud_velocity_purchases_{$user->id}";
        $currentPurchases = TaggedCache::for(['fraud'])->get($purchaseCountKey, 0);
        TaggedCache::for(['fraud'])->put($purchaseCountKey, $currentPurchases + 1, now()->addHour());

        $ipVelocityKey = "fraud_velocity_ip_{$ip}";
        $currentIpCheckouts = TaggedCache::for(['fraud'])->get($ipVelocityKey, 0);
        TaggedCache::for(['fraud'])->put($ipVelocityKey, $currentIpCheckouts + 1, now()->addHour());
    }

    /**
     * Record a failed Turnstile verification attempt for an IP.
     */
    public function recordTurnstileFailure(string $ip): void
    {
        $key = "fraud_turnstile_failures_{$ip}";
        $failures = TaggedCache::for(['fraud', 'security'])->get($key, 0);
        TaggedCache::for(['fraud', 'security'])->put($key, $failures + 1, now()->addMinutes(30));
    }

    /**
     * Check if an IP has exceeded the Turnstile failure threshold.
     */
    public function hasExcessiveTurnstileFailures(string $ip): bool
    {
        $key = "fraud_turnstile_failures_{$ip}";
        $failures = TaggedCache::for(['fraud', 'security'])->get($key, 0);

        return $failures >= 5;
    }
}

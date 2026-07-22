<?php

namespace App\Domain\PaymentInsights\Services;

use App\Domain\PaymentInsights\Enums\OutcomeType;
use App\Models\PaymentOutcome;
use App\Models\User;
use Illuminate\Support\Facades\Cache;

class PaymentBackoffManager
{
    /**
     * Get the backoff status for a given user and product line.
     * Returns ['is_rate_limited' => bool, 'wait_seconds' => int, 'consecutive_fails' => int]
     */
    public function getBackoffStatus(?User $user, string $productLine, string $ip, ?string $deviceUuid = null): array
    {
        // For guests, we rely on IP and device_uuid
        $identifier = $user ? 'user:'.$user->id : 'guest:'.$ip.':'.($deviceUuid ?? 'unknown');

        $cacheKey = "payment_backoff_reset:{$identifier}:{$productLine}";
        $lastReset = Cache::get($cacheKey);

        $query = PaymentOutcome::where('product_line', $productLine)
            ->whereIn('outcome', [OutcomeType::Failed->value, OutcomeType::Cancelled->value, OutcomeType::Expired->value])
            ->where('created_at', '>=', now()->subHours(2)); // Only look at last 2 hours

        if ($user) {
            $query->where('user_id', $user->id);
        } else {
            $query->whereNull('user_id')->where(function ($q) use ($ip, $deviceUuid) {
                $q->where('customer_ip', $ip);
                if ($deviceUuid) {
                    $q->orWhere('device_uuid', $deviceUuid);
                }
            });
        }

        if ($lastReset) {
            $query->where('created_at', '>', $lastReset);
        }

        $lastSuccess = PaymentOutcome::where('product_line', $productLine)
            ->where('outcome', OutcomeType::Confirmed->value);

        if ($user) {
            $lastSuccess->where('user_id', $user->id);
        } else {
            $lastSuccess->whereNull('user_id')->where('customer_ip', $ip);
        }

        $lastSuccess = $lastSuccess->orderByDesc('created_at')->first();

        if ($lastSuccess && (! $lastReset || $lastSuccess->created_at > $lastReset)) {
            $query->where('created_at', '>', $lastSuccess->created_at);
        }

        $failures = $query->orderByDesc('created_at')->get();
        $consecutiveFails = $failures->count();

        if ($consecutiveFails < 3) {
            return ['is_rate_limited' => false, 'wait_seconds' => 0, 'consecutive_fails' => $consecutiveFails];
        }

        $lastFailureTime = $failures->first()->created_at;
        $waitSeconds = 0;

        if ($consecutiveFails === 3) {
            $waitSeconds = 30;
        } elseif ($consecutiveFails === 4) {
            $waitSeconds = 60;
        } elseif ($consecutiveFails === 5) {
            $waitSeconds = 300;
        } else {
            $waitSeconds = 900; // 15 mins
        }

        // Measured FROM the failure TO now — Carbon 3 diffs are signed, and the
        // reversed direction returns a negative elapsed, which would make the
        // cooldown grow forever instead of expiring.
        $elapsed = (int) $lastFailureTime->diffInSeconds(now());
        $remaining = max(0, $waitSeconds - $elapsed);

        return [
            'is_rate_limited' => $remaining > 0,
            'wait_seconds' => $remaining,
            'consecutive_fails' => $consecutiveFails,
        ];
    }

    public function resetBackoff(?User $user, string $productLine, string $ip, ?string $deviceUuid = null): void
    {
        $identifier = $user ? 'user:'.$user->id : 'guest:'.$ip.':'.($deviceUuid ?? 'unknown');
        $cacheKey = "payment_backoff_reset:{$identifier}:{$productLine}";

        Cache::put($cacheKey, now(), now()->addHours(2));
    }
}

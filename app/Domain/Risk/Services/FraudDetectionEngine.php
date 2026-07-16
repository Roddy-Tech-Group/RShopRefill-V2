<?php

namespace App\Domain\Risk\Services;

use App\Models\User;
use App\Models\WalletTransaction;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class FraudDetectionEngine
{
    /**
     * Evaluate a transaction for potential fraud.
     * 
     * @return int Risk Score (0-100)
     */
    public function evaluateTransaction(User $user, int $amount, string $actionType, string $ipAddress): int
    {
        $score = 0;

        // 1. Velocity Checks (e.g., more than 5 withdrawals in 10 minutes)
        if ($this->hasHighVelocity($user->id, $actionType)) {
            $score += 40;
        }

        // 2. Impossible Travel (e.g., login from IP matching Nigeria, now acting from IP matching UK within 5 mins)
        if ($this->detectImpossibleTravel($user, $ipAddress)) {
            $score += 50;
        }

        // 3. High Value Anomalies
        if ($amount > 5000000) { // e.g., > 50,000 NGN
            $score += 20;
        }

        // 4. Record the action for future velocity checks
        $this->recordAction($user->id, $actionType, $ipAddress);

        if ($score >= 80) {
            Log::alert("FRAUD ALERT: High risk transaction blocked for User {$user->id}", ['score' => $score]);
            // In a real system, we might throw an Exception or transition User to 'Suspended'
        } elseif ($score >= 40) {
            Log::warning("Fraud Engine: Suspicious activity for User {$user->id}", ['score' => $score]);
        }

        return min(100, $score);
    }

    private function hasHighVelocity(int $userId, string $actionType): bool
    {
        $key = "fraud_velocity:{$userId}:{$actionType}";
        $attempts = Cache::get($key, 0);
        return $attempts > 5;
    }

    private function detectImpossibleTravel(User $user, string $currentIp): bool
    {
        $lastIp = Cache::get("user_last_ip:{$user->id}");
        
        // Very basic mock: if IP completely changed subnet within a short window
        // Real implementation would use GeoIP
        if ($lastIp && $lastIp !== $currentIp) {
            return true;
        }
        
        return false;
    }

    private function recordAction(int $userId, string $actionType, string $ipAddress): void
    {
        $key = "fraud_velocity:{$userId}:{$actionType}";
        Cache::add($key, 0, now()->addMinutes(10));
        Cache::increment($key);

        Cache::put("user_last_ip:{$userId}", $ipAddress, now()->addHours(1));
    }
}

<?php

namespace App\Domain\Risk\Services;

use App\Models\TrustedDevice;
use App\Models\User;
use Illuminate\Support\Facades\Cache;

class RiskEngine
{
    /**
     * Evaluates a login attempt and returns a Session Risk Level: LOW, MEDIUM, HIGH, CRITICAL.
     */
    public function evaluateLoginRisk(User $user, TrustedDevice $device, string $ipAddress): string
    {
        $riskScore = 0;

        if (!$device->isTrusted()) {
            $riskScore += 50; // New device is inherently high risk
        } else {
            // A trusted device that hasn't been seen in 6 months might be medium risk
            if ($device->last_seen_at && $device->last_seen_at->diffInMonths(now()) >= 6) {
                $riskScore += 20;
            }
        }

        if ($this->detectImpossibleTravel($user, $ipAddress)) {
            $riskScore += 40;
        }

        if ($this->hasHighVelocity($user->id, 'login')) {
            $riskScore += 30;
        }

        $this->recordAction($user->id, 'login', $ipAddress);

        if ($riskScore >= 80) return 'CRITICAL';
        if ($riskScore >= 50) return 'HIGH';
        if ($riskScore >= 20) return 'MEDIUM';
        
        return 'LOW';
    }

    /**
     * Evaluates a financial transaction.
     * Returns a risk level that the caller can use to block or flag the transaction.
     */
    public function evaluateTransactionRisk(User $user, int $amount, string $actionType, string $ipAddress): string
    {
        $score = 0;

        if ($this->hasHighVelocity($user->id, $actionType)) {
            $score += 40;
        }

        if ($this->detectImpossibleTravel($user, $ipAddress)) {
            $score += 50;
        }

        if ($amount > 5000000) {
            $score += 20;
        }

        $this->recordAction($user->id, $actionType, $ipAddress);

        if ($score >= 80) return 'CRITICAL';
        if ($score >= 40) return 'HIGH';
        if ($score >= 20) return 'MEDIUM';

        return 'LOW';
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

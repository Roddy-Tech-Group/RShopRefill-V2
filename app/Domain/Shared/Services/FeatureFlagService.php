<?php

namespace App\Domain\Shared\Services;

use App\Models\FeatureFlag;
use Illuminate\Support\Facades\Cache;

class FeatureFlagService
{
    /**
     * Check if a feature is enabled.
     * Caches the result for performance.
     */
    public function isEnabled(string $key, ?int $userId = null): bool
    {
        $flag = Cache::remember("feature_flag:{$key}", 300, function () use ($key) {
            return FeatureFlag::where('key', $key)->first();
        });

        if (!$flag) {
            return false;
        }

        return match ($flag->status) {
            FeatureFlag::STATUS_ENABLED => true,
            FeatureFlag::STATUS_DISABLED => false,
            FeatureFlag::STATUS_MAINTENANCE => false,
            FeatureFlag::STATUS_STAGED => $this->isUserInStagedRollout($userId, $flag->percentage),
            default => false,
        };
    }

    /**
     * Asserts that a feature is enabled. Throws an exception if not.
     */
    public function assertEnabled(string $key, ?int $userId = null): void
    {
        if (!$this->isEnabled($key, $userId)) {
            $flag = Cache::get("feature_flag:{$key}");
            
            if ($flag && $flag->status === FeatureFlag::STATUS_MAINTENANCE) {
                throw new \Exception("The feature '{$key}' is currently undergoing maintenance. Please try again later.");
            }
            
            throw new \Exception("The feature '{$key}' is currently disabled.");
        }
    }

    public function clearCache(string $key): void
    {
        Cache::forget("feature_flag:{$key}");
    }

    private function isUserInStagedRollout(?int $userId, ?int $percentage): bool
    {
        if (!$userId || !$percentage) {
            return false;
        }

        // Use a deterministic hash based on the user ID
        $hash = crc32((string) $userId);
        $bucket = $hash % 100; // 0-99

        return $bucket < $percentage;
    }
}

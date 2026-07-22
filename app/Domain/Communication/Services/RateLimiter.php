<?php

namespace App\Domain\Communication\Services;

use App\Domain\Communication\Enums\CommunicationCategory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\RateLimiter as LaravelRateLimiter;

/**
 * Prevents communication bursts from starving the queue or
 * triggering ESP rate limits (e.g., Resend 10/sec limits on certain tiers).
 */
class RateLimiter
{
    /**
     * Check if the user has exceeded the rate limit for this category.
     *
     * @return bool True if allowed, false if rate limited.
     */
    public function check(Model $recipient, CommunicationCategory $category): bool
    {
        // Admin notifications are exempt from rate limiting to prevent silencing alerts
        if ($recipient->email === config('communication.admin_email')) {
            return true;
        }

        $key = 'comm_rate_limit:'.$recipient->id.':'.$category->value;

        $maxAttempts = $this->maxAttemptsPerMinute($category);

        if (LaravelRateLimiter::tooManyAttempts($key, $maxAttempts)) {
            return false;
        }

        LaravelRateLimiter::hit($key, 60);

        return true;
    }

    /**
     * Determine the maximum allowed sends per minute based on the category urgency.
     */
    private function maxAttemptsPerMinute(CommunicationCategory $category): int
    {
        return match ($category) {
            // Highly sensitive actions: allow few attempts
            CommunicationCategory::Otp,
            CommunicationCategory::PasswordReset,
            CommunicationCategory::PinReset => 3,

            // Order/Wallet activity: typical user behavior (bulk buy cards)
            CommunicationCategory::OrderPlaced,
            CommunicationCategory::WalletFunding,
            CommunicationCategory::WalletDebit => 20,

            // Default fallback
            default => 10,
        };
    }
}

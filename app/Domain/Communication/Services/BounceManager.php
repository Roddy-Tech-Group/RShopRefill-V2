<?php

namespace App\Domain\Communication\Services;

use App\Models\EmailSuppression;
use Illuminate\Support\Facades\Log;

class BounceManager
{
    /**
     * Handle an email bounce or complaint event.
     */
    public function handleBounce(string $email, string $type, string $reason, ?string $provider = 'resend'): void
    {
        $email = strtolower(trim($email));

        if (empty($email)) {
            return;
        }

        // Only handle hard bounces or spam complaints. Soft bounces might recover.
        if (! in_array($type, ['hard_bounce', 'complaint'])) {
            Log::info("Ignored soft bounce for {$email}", ['type' => $type, 'reason' => $reason]);

            return;
        }

        EmailSuppression::updateOrCreate(
            ['email' => $email],
            [
                'reason' => $reason,
                'provider' => $provider,
                'suppressed_at' => now(),
            ]
        );

        Log::warning("Email suppressed due to {$type}", [
            'email' => $email,
            'reason' => $reason,
            'provider' => $provider,
        ]);
    }

    /**
     * Check if an email is suppressed.
     */
    public function isSuppressed(string $email): bool
    {
        $email = strtolower(trim($email));

        return EmailSuppression::where('email', $email)->exists();
    }
}

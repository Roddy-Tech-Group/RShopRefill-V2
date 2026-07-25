<?php

namespace App\Domain\Communication\Services;

use App\Domain\Communication\Enums\CommunicationCategory;
use App\Domain\Communication\Enums\CommunicationChannel;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * Determines which delivery channels a communication should use based on:
 * - The category's mandatory status
 * - User preferences per category × channel
 * - Whether a mailable is provided (no mailable = no email channel)
 * - Whether an override email exists (gifting bypasses preferences)
 */
class ChannelResolver
{
    /**
     * Resolve the active channels for a communication.
     *
     * @return CommunicationChannel[]
     */
    public function resolve(
        Model $recipient,
        CommunicationCategory $category,
        bool $hasMailable,
        bool $hasOverrideEmail,
        PreferenceManager $preferenceManager,
    ): array {
        $channels = [];

        // In-App writes to the customer bell feed (notifications.user_id is an
        // FK to users), so only real User recipients get it. An Admin or
        // NewsletterSubscriber id here would land the message on whichever
        // customer happens to share that id.
        if ($recipient instanceof User) {
            $channels[] = CommunicationChannel::InApp;
        }

        // Email channel: only if a mailable template exists.
        if ($hasMailable) {
            if ($this->isEmailAllowed($recipient, $category, $hasOverrideEmail, $preferenceManager)) {
                $channels[] = CommunicationChannel::Email;
            }
        }

        // Push channel: check preferences.
        if ($this->isPushAllowed($recipient, $category, $preferenceManager)) {
            $channels[] = CommunicationChannel::Push;
        }

        return $channels;
    }

    /**
     * Check if email is allowed for this recipient + category combination.
     */
    private function isEmailAllowed(
        Model $recipient,
        CommunicationCategory $category,
        bool $hasOverrideEmail,
        PreferenceManager $preferenceManager,
    ): bool {
        // Override email (gifting) always sends — the inbox belongs to
        // someone else, so the account holder's preferences must not
        // suppress their delivery.
        if ($hasOverrideEmail) {
            return true;
        }

        // Mandatory categories (Security, Verify, Compliance) cannot be disabled.
        if (! $category->isUserDisableable()) {
            return true;
        }

        // Check user preferences.
        return $preferenceManager->isAllowed($recipient, $category, CommunicationChannel::Email);
    }

    /**
     * Check if push is allowed for this recipient + category combination.
     */
    private function isPushAllowed(
        Model $recipient,
        CommunicationCategory $category,
        PreferenceManager $preferenceManager,
    ): bool {
        // Mandatory categories always push.
        if (! $category->isUserDisableable()) {
            return true;
        }

        return $preferenceManager->isAllowed($recipient, $category, CommunicationChannel::Push);
    }
}

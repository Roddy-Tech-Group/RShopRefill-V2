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

        // One-time codes are email-only, decided here rather than at each call
        // site so no future caller can widen it back. The bell feed and a push
        // banner both render the message body verbatim, which means the code
        // reaches anyone already holding a session or a stale push
        // subscription - neither of which proves control of the inbox the code
        // exists to verify.
        if ($category->carriesOneTimeCode()) {
            return $hasMailable && $this->isEmailAllowed($recipient, $category, $hasOverrideEmail, $preferenceManager)
                ? [CommunicationChannel::Email]
                : [];
        }

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

        // Push channel: only to a recipient that could hold a subscription, and
        // then only if preferences allow it.
        //
        // The capability check is the same one WebPushChannel makes before it
        // refuses. Without it here, a newsletter broadcast resolved a push
        // channel for every NewsletterSubscriber on the list, queued a job per
        // subscriber, and had each one suppressed with "Recipient model does
        // not support push subscriptions" - 1504 such rows in production. The
        // email still went, so nothing was lost, but the queue carried work
        // that could never succeed and the push metrics were mostly noise.
        if ($this->canReceivePush($recipient) && $this->isPushAllowed($recipient, $category, $preferenceManager)) {
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
     * Could this recipient hold a push subscription at all?
     *
     * Recipient is typed as Model, not User, so anything an Eloquent model can
     * be may arrive here. Users and Admins have the relation; a
     * NewsletterSubscriber does not, and no preference makes one appear.
     */
    private function canReceivePush(Model $recipient): bool
    {
        return method_exists($recipient, 'pushSubscriptions');
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

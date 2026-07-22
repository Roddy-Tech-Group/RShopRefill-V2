<?php

namespace App\Domain\Communication\Services;

use App\Models\NewsletterSubscriber;

/**
 * Manages newsletter subscription state for the marketing mailbox.
 */
class NewsletterService
{
    /**
     * Subscribe an email address to the newsletter.
     */
    public function subscribeNewsletter(string $email, ?string $source = null): NewsletterSubscriber
    {
        $subscriber = NewsletterSubscriber::firstOrNew(['email' => $email]);

        $subscriber->status = 'active';
        $subscriber->subscribed_at = now();
        $subscriber->unsubscribed_at = null;
        $subscriber->source = $source;
        $subscriber->save();

        return $subscriber;
    }

    /**
     * Unsubscribe an email address from the newsletter.
     */
    public function unsubscribeNewsletter(string $email): ?NewsletterSubscriber
    {
        $subscriber = NewsletterSubscriber::where('email', $email)->first();

        if ($subscriber) {
            $subscriber->update([
                'status' => 'unsubscribed',
                'unsubscribed_at' => now(),
            ]);
        }

        return $subscriber;
    }
}

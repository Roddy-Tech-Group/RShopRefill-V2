<?php

namespace App\Domain\Communication\Services;

use App\Models\NewsletterSubscriber;
use Illuminate\Validation\ValidationException;

/**
 * Manages newsletter subscription state for the marketing mailbox.
 */
class NewsletterService
{
    /**
     * Subscribe an email address to the newsletter.
     *
     * @throws ValidationException when the domain is reserved / undeliverable,
     *                             so HTTP callers render a 422, never a 500.
     */
    public function subscribeNewsletter(string $email, ?string $source = null): NewsletterSubscriber
    {
        // Block RFC 2606 reserved domains and other known-undeliverable addresses.
        // These pass format validation but Resend rejects them with a 422, creating
        // noise in the failed_jobs table and Sentry on every broadcast.
        if (UndeliverableDomains::blocks($email)) {
            throw ValidationException::withMessages([
                'email' => 'This email address cannot receive mail. Please use a different address.',
            ]);
        }

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

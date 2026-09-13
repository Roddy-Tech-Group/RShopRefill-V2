<?php

namespace App\Domain\Communication\Listeners\System;

use App\Domain\Communication\Enums\CommunicationCategory;
use App\Domain\Communication\Mail\Security\WelcomeMail;
use App\Domain\Communication\Services\CommunicationManager;
use App\Models\CommunicationLog;
use App\Models\User;
use Illuminate\Auth\Events\Registered;
use Illuminate\Auth\Events\Verified;

/**
 * Sends the welcome email once the address is PROVEN, not at signup: a fresh
 * registration gets the verification email alone, and the welcome follows the
 * moment the customer clicks the link (Verified event). Accounts created with an
 * already-verified email (Google sign-in) are welcomed without a verification
 * step - but only once they have finished signing up, since a Google account
 * arrives half-registered and is held on the completion form. Welcoming someone
 * to an account they cannot yet use contradicts the page they are looking at,
 * and would greet an abandoned signup that never became a customer. The
 * completion page calls this listener itself when it lifts the hold.
 *
 * Guest shadow accounts never get a welcome, and the correlation id makes the
 * welcome once-per-account for life, so re-verification flows can never send a
 * second one.
 */
class WelcomeListener
{
    public function __construct(
        private readonly CommunicationManager $communication,
    ) {}

    public function handle(Registered|Verified $event): void
    {
        $user = $event->user;

        if (! $user instanceof User || $user->is_guest) {
            return;
        }

        // On Registered, only a pre-verified account (Google) is welcomed;
        // everyone else's welcome waits for the Verified event.
        if ($event instanceof Registered && ! $user->hasVerifiedEmail()) {
            return;
        }

        // Still owing the completion form: the welcome waits until they submit
        // it, which is where this listener gets called again.
        if ($user->needsProfileCompletion()) {
            return;
        }

        // Once per account, for life. (The manager's own idempotency guard only
        // spans 24h, which would not stop a re-verification months later.)
        $correlationId = 'welcome_'.$user->getKey();

        if (CommunicationLog::query()->where('correlation_id', $correlationId)->exists()) {
            return;
        }

        $this->communication->send(
            recipient: $user,
            category: CommunicationCategory::AccountActivation, // Using AccountActivation for Welcome
            title: 'Welcome to RShopRefills!',
            message: "Hi {$user->first_name}, welcome to RShopRefills! We're glad you're here.",
            mailable: new WelcomeMail($user),
            correlationId: $correlationId
        );
    }
}

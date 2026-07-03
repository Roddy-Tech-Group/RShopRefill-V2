<?php

namespace App\Domain\Notification\Listeners;

use App\Domain\Notification\Mail\WelcomeMail;
use App\Domain\Notification\Services\NotificationDispatcher;
use App\Models\SiteSetting;
use Illuminate\Auth\Events\Registered;

class SendWelcomeEmailListener
{
    public function __construct(
        private readonly NotificationDispatcher $dispatcher
    ) {}

    public function handle(Registered $event): void
    {
        $user = $event->user;
        $isGoogleAuth = ! empty($user->google_id);

        $this->dispatcher->dispatch(
            user: $user,
            title: 'Welcome to '.SiteSetting::get('site.name', 'RshopRefills').'!',
            message: $isGoogleAuth
                ? 'Welcome! Your account has been registered successfully via Google.'
                : 'Welcome! Your account has been registered successfully.',
            category: 'security',
            mailable: new WelcomeMail($user, $isGoogleAuth)
        );
    }
}

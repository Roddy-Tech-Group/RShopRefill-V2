<?php

namespace App\Domain\Communication\Listeners\System;

use App\Domain\Communication\Enums\CommunicationCategory;
use App\Domain\Communication\Mail\Security\WelcomeMail;
use App\Domain\Communication\Services\CommunicationManager;
use Illuminate\Auth\Events\Registered;

class WelcomeListener
{
    public function __construct(
        private readonly CommunicationManager $communication,
    ) {}

    public function handle(Registered $event): void
    {
        $user = $event->user;

        $this->communication->send(
            recipient: $user,
            category: CommunicationCategory::AccountActivation, // Using AccountActivation for Welcome
            title: 'Welcome to RShopRefills!',
            message: "Hi {$user->first_name}, welcome to RShopRefills! We're glad you're here.",
            mailable: new WelcomeMail($user),
            correlationId: 'welcome_'.$user->id
        );
    }
}

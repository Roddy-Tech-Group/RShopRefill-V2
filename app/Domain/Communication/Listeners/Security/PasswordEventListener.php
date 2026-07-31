<?php

namespace App\Domain\Communication\Listeners\Security;

use App\Domain\Communication\Enums\CommunicationCategory;
use App\Domain\Communication\Mail\Security\PasswordChangedMail;
use App\Domain\Communication\Services\CommunicationManager;
use App\Domain\Security\Events\PasswordChanged;
use Illuminate\Contracts\Queue\ShouldQueue;

class PasswordEventListener implements ShouldQueue
{
    /**
     * Explicit queue: keeps one workload from starving unrelated ones (bulkhead).
     * A property rather than onQueue() because this listener implements
     * ShouldQueue without using the Queueable trait.
     */
    public $queue = 'comm-critical';

    public function __construct(
        private readonly CommunicationManager $manager
    ) {}

    public function handle(PasswordChanged $event): void
    {
        $this->manager->send(
            recipient: $event->user,
            category: CommunicationCategory::PasswordChanged,
            title: 'Account Password Changed',
            message: 'Your account password has been updated successfully.',
            mailable: new PasswordChangedMail($event->user)
        );
    }
}

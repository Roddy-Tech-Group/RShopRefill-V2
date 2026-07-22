<?php

namespace App\Domain\Communication\Listeners\System;

use App\Domain\Communication\Enums\CommunicationCategory;
use App\Domain\Communication\Services\CommunicationManager;
use App\Events\AdminActionLogged; // Example placeholder event

class AdminEventListener
{
    public function __construct(
        private readonly CommunicationManager $communication,
    ) {}

    public function handle(AdminActionLogged $event): void
    {
        $this->communication->sendToAdmin(
            category: CommunicationCategory::AdminAlert,
            title: 'Admin Action: '.$event->action,
            message: "User {$event->admin->email} performed action: {$event->description}.",
            correlationId: 'admin_action_'.time()
        );
    }
}

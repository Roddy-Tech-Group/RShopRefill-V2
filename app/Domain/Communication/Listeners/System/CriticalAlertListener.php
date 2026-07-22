<?php

namespace App\Domain\Communication\Listeners\System;

use App\Domain\Communication\Enums\CommunicationCategory;
use App\Domain\Communication\Services\CommunicationManager;
use App\Events\CriticalSystemAlert; // Assuming this exists or will exist

class CriticalAlertListener
{
    public function __construct(
        private readonly CommunicationManager $communication,
    ) {}

    public function handle(CriticalSystemAlert $event): void
    {
        $this->communication->sendToAdmin(
            category: CommunicationCategory::CriticalSystemAlert,
            title: 'CRITICAL ALERT: '.$event->title,
            message: $event->message,
            metadata: $event->context
        );
    }
}

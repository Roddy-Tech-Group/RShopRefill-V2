<?php

namespace App\Domain\Communication\Listeners\Security;

use App\Domain\Communication\Enums\CommunicationCategory;
use App\Domain\Communication\Mail\Security\NewDeviceDetectedMail;
use App\Domain\Communication\Services\CommunicationManager;
use App\Domain\Security\Events\NewDeviceDetected;

class NewDeviceAlertListener
{
    public function __construct(
        private readonly CommunicationManager $communication,
    ) {}

    public function handle(NewDeviceDetected $event): void
    {
        $this->communication->send(
            recipient: $event->device->user,
            category: CommunicationCategory::NewDevice,
            title: 'New Device Detected',
            message: "A new sign-in was detected on {$event->device->device_name} from {$event->device->city}.",
            mailable: new NewDeviceDetectedMail($event->device),
            correlationId: 'new_device_'.$event->device->id,
            metadata: [
                'ip_address' => $event->device->ip_address,
                'device_name' => $event->device->device_name,
            ]
        );
    }
}

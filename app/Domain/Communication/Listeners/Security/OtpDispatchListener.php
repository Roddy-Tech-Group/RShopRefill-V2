<?php

namespace App\Domain\Communication\Listeners\Security;

use App\Domain\Communication\Enums\CommunicationCategory;
use App\Domain\Communication\Mail\Security\EmailOtpMail;
use App\Domain\Communication\Services\CommunicationManager;
use App\Domain\Security\Events\OtpRequested;

class OtpDispatchListener
{
    public function __construct(
        private readonly CommunicationManager $communication,
    ) {}

    public function handle(OtpRequested $event): void
    {
        $this->communication->send(
            recipient: $event->user,
            category: CommunicationCategory::Otp,
            title: 'Your Security Code',
            message: "Your OTP is {$event->otp}. Do not share this code.",
            mailable: new EmailOtpMail($event->otp, $event->context),
            correlationId: "otp_{$event->context}_{$event->user->id}",
            metadata: [
                'context' => $event->context,
            ]
        );
    }
}

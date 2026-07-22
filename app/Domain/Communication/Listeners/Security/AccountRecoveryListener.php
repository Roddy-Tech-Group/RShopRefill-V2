<?php

namespace App\Domain\Communication\Listeners\Security;

use App\Domain\Communication\Enums\CommunicationCategory;
use App\Domain\Communication\Mail\Security\AccountRecoveredMail;
use App\Domain\Communication\Services\CommunicationManager;
use App\Domain\Security\Events\AccountRecovered;

class AccountRecoveryListener
{
    public function __construct(
        private readonly CommunicationManager $communication,
    ) {}

    public function handle(AccountRecovered $event): void
    {
        $this->communication->send(
            recipient: $event->user,
            category: CommunicationCategory::AccountRecovered,
            title: 'Account Successfully Recovered',
            message: 'Your account access has been restored. If this was not you, contact support immediately.',
            mailable: new AccountRecoveredMail(request()->ip() ?? 'unknown'),
            correlationId: 'account_recovery_'.$event->user->id
        );
    }
}

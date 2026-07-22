<?php

namespace App\Domain\Communication\Listeners\Security;

use App\Domain\Communication\Enums\CommunicationCategory;
use App\Domain\Communication\Mail\Security\TransactionPinChangedMail;
use App\Domain\Communication\Mail\Security\TransactionPinLockedMail;
use App\Domain\Communication\Mail\Security\TransactionPinResetMail;
use App\Domain\Communication\Services\CommunicationManager;
use App\Domain\Wallet\Events\TransactionPinChanged;
use App\Domain\Wallet\Events\TransactionPinLocked;
use App\Domain\Wallet\Events\TransactionPinResetRequested;

class PinEventListener
{
    public function __construct(
        private readonly CommunicationManager $communication,
    ) {}

    public function handlePinChanged(TransactionPinChanged $event): void
    {
        $this->communication->send(
            recipient: $event->user,
            category: CommunicationCategory::PinChanged,
            title: 'Transaction PIN Changed',
            message: 'Your transaction PIN has been successfully changed.',
            mailable: new TransactionPinChangedMail($event->user)
        );
    }

    public function handlePinLocked(TransactionPinLocked $event): void
    {
        $this->communication->send(
            recipient: $event->user,
            category: CommunicationCategory::PinLocked,
            title: 'Transaction PIN Locked',
            message: 'Your transaction PIN has been locked due to too many failed attempts.',
            mailable: new TransactionPinLockedMail($event->user)
        );
    }

    public function handlePinReset(TransactionPinResetRequested $event): void
    {
        $this->communication->send(
            recipient: $event->user,
            category: CommunicationCategory::PinReset,
            title: 'Transaction PIN Reset',
            message: 'A reset of your transaction PIN was requested.',
            mailable: new TransactionPinResetMail($event->user, $event->token)
        );
    }
}

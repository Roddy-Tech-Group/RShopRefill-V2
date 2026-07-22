<?php

namespace App\Domain\Communication\Listeners\Wallet;

use App\Domain\Communication\Enums\CommunicationCategory;
use App\Domain\Communication\Mail\Wallet\WalletFundedMail;
use App\Domain\Communication\Services\CommunicationManager;
use App\Domain\Wallet\Events\WalletCredited;

class WalletCreditListener
{
    public function __construct(
        private readonly CommunicationManager $communication,
    ) {}

    public function handle(WalletCredited $event): void
    {
        $transaction = $event->transaction;
        $user = $transaction->user ?? $transaction->wallet->user;

        $this->communication->send(
            recipient: $user,
            category: CommunicationCategory::WalletFunding,
            title: 'Wallet Funded Successfully',
            message: "Your wallet has been credited with {$transaction->currency->value} {$transaction->amount}.",
            mailable: new WalletFundedMail($user, $transaction),
            correlationId: 'wallet_credit_'.$transaction->id
        );
    }
}

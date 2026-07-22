<?php

namespace App\Domain\Communication\Listeners\Wallet;

use App\Domain\Communication\Enums\CommunicationCategory;
use App\Domain\Communication\Mail\Wallet\WalletDebitedMail;
use App\Domain\Communication\Services\CommunicationManager;
use App\Domain\Wallet\Events\WalletDebited;

class WalletDebitListener
{
    public function __construct(
        private readonly CommunicationManager $communication,
    ) {}

    public function handle(WalletDebited $event): void
    {
        $transaction = $event->transaction;
        $user = $transaction->user ?? $transaction->wallet->user;

        $this->communication->send(
            recipient: $user,
            category: CommunicationCategory::WalletDebit,
            title: 'Wallet Debited',
            message: "Your wallet has been debited by {$transaction->currency->value} {$transaction->amount}.",
            mailable: new WalletDebitedMail($user, $transaction),
            correlationId: 'wallet_debit_'.$transaction->id
        );
    }
}

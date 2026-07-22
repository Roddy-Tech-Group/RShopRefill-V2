<?php

namespace App\Domain\Communication\Listeners\Wallet;

use App\Domain\Communication\Enums\CommunicationCategory;
use App\Domain\Communication\Services\CommunicationManager;
use App\Domain\Wallet\Events\FundingFailed;

class FundingFailedListener
{
    public function __construct(
        private readonly CommunicationManager $communication,
    ) {}

    public function handle(FundingFailed $event): void
    {
        $funding = $event->funding;
        $user = $funding->user;

        if (! $user) {
            return;
        }

        $this->communication->send(
            recipient: $user,
            category: CommunicationCategory::FundingFailed,
            title: 'Wallet Funding Failed',
            message: "Your recent attempt to fund your wallet with {$funding->amount} failed. Reason: {$event->reason}.",
            correlationId: 'funding_failed_'.$funding->id
        );
    }
}

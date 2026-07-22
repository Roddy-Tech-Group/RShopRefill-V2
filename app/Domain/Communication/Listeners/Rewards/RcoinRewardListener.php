<?php

namespace App\Domain\Communication\Listeners\Rewards;

use App\Domain\Communication\Enums\CommunicationCategory;
use App\Domain\Communication\Mail\Rewards\RcoinEarnedMail;
use App\Domain\Communication\Services\CommunicationManager;
use App\Domain\Rewards\Events\RcoinEarned;

class RcoinRewardListener
{
    public function __construct(
        private readonly CommunicationManager $communication,
    ) {}

    public function handle(RcoinEarned $event): void
    {
        $this->communication->send(
            recipient: $event->user,
            category: CommunicationCategory::RcoinReward,
            title: 'You Earned RCoins!',
            message: "Congratulations! You just earned {$event->amount} RCoins.",
            mailable: new RcoinEarnedMail(
                recipient: $event->user,
                rcoinAmount: $event->amount,
                newBalance: $event->newBalance,
                kind: $event->kind,
            ),
            correlationId: 'rcoin_earned_'.($event->transactionId ?? $event->user->id)
        );
    }
}

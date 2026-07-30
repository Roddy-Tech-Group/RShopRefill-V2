<?php

namespace App\Domain\Communication\Listeners\Wallet;

use App\Domain\Communication\Enums\CommunicationCategory;
use App\Domain\Communication\Mail\Rewards\RcoinEarnedMail;
use App\Domain\Communication\Mail\Wallet\WalletFundedMail;
use App\Domain\Communication\Services\CommunicationManager;
use App\Domain\Shared\Enums\TransactionCategory;
use App\Domain\Wallet\Events\WalletCredited;

/**
 * Routes the "your wallet was credited" communication by WHAT the credit was.
 *
 * Every WalletService::credit() raises WalletCredited, so this listener used
 * to greet every credit with "Wallet Funded Successfully" - Rcoin cashback
 * arrived as a wallet top-up email, and referral bonuses / refunds were
 * double-mailed on top of their own dedicated notifications.
 */
class WalletCreditListener
{
    public function __construct(
        private readonly CommunicationManager $communication,
    ) {}

    public function handle(WalletCredited $event): void
    {
        $transaction = $event->transaction;
        $user = $transaction->user ?? $transaction->wallet->user;

        switch ($transaction->transaction_category) {
            // Cashback is a reward, not a deposit - say so. RcoinEarnedMail
            // already carries the cashback subject/copy branch.
            case TransactionCategory::RewardCashback:
                $rcoinAmount = (int) round((float) $transaction->amount);
                $orderNumber = $transaction->metadata['order_number'] ?? null;

                $this->communication->send(
                    recipient: $user,
                    category: CommunicationCategory::RcoinReward,
                    title: 'Cashback Reward Earned',
                    message: "You earned {$rcoinAmount} Rcoin cashback".($orderNumber ? " on order {$orderNumber}" : '').'.',
                    mailable: new RcoinEarnedMail(
                        recipient: $user,
                        rcoinAmount: $rcoinAmount,
                        newBalance: (int) $transaction->balance_after,
                        kind: 'cashback',
                        orderNumber: $orderNumber,
                    ),
                    correlationId: 'wallet_credit_'.$transaction->id
                );

                return;

                // The referrer already gets a dedicated RcoinEarnedMail from
                // RewardEngine (with the referred customer's name - context
                // this listener does not have). A second mail here was noise.
            case TransactionCategory::RewardReferral:
                return;

                // Refund communications belong to RefundNotificationListener /
                // RefundProcessedMail; celebrating a reversal as "funded" was
                // both wrong and a duplicate.
            case TransactionCategory::Refund:
            case TransactionCategory::Reversal:
            case TransactionCategory::RewardReversal:
                return;

                // Genuine deposits - and credits that behave like them, e.g. an
                // Rcoin-to-USD conversion landing in the cash wallet.
            default:
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
}

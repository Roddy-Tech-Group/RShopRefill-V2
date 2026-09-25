<?php

namespace App\Domain\Communication\Listeners\Wallet;

use App\Domain\Communication\Enums\CommunicationCategory;
use App\Domain\Communication\Services\CommunicationManager;
use App\Domain\PaymentInsights\Enums\ProductLine;
use App\Domain\PaymentInsights\Services\FailureReasonClassifier;
use App\Domain\PaymentInsights\Support\RecoveryCopy;
use App\Domain\Wallet\Events\FundingFailed;

/**
 * The immediate in-app notice when a wallet top-up fails.
 *
 * It used to read "Wallet Funding Failed. Reason: <whatever the gateway
 * said>", which put raw provider text in front of the customer twenty
 * minutes before the recovery email arrived with a polite, reasoned version
 * of the same event. Both now speak with one voice: the failure is graded by
 * the same classifier and worded by the same copy table, in top-up terms.
 * This channel is the bell feed and push only; the email, when one is
 * warranted, is the recovery engine's job.
 */
class FundingFailedListener
{
    public function __construct(
        private readonly CommunicationManager $communication,
        private readonly FailureReasonClassifier $classifier,
    ) {}

    public function handle(FundingFailed $event): void
    {
        $funding = $event->funding;
        $user = $funding->user;

        if (! $user) {
            return;
        }

        $failureBlock = $funding->provider_payload_snapshot['failure'] ?? null;

        $verdict = $this->classifier->classify(
            (string) ($funding->gateway ?? 'unknown'),
            (string) $event->reason,
            'processing',
            is_array($failureBlock) ? $failureBlock : null,
        );

        $copy = RecoveryCopy::for($verdict['code'], ProductLine::WalletFunding);

        $currency = $funding->currency instanceof \BackedEnum ? $funding->currency->value : (string) $funding->currency;
        $amount = trim(strtoupper($currency).' '.number_format((float) $funding->amount, 2));
        $message = "Your {$amount} top-up was not completed. {$copy['guidance']}";

        if ($copy['hint']) {
            $message .= ' '.$copy['hint'];
        }

        $this->communication->send(
            recipient: $user,
            category: CommunicationCategory::FundingFailed,
            title: 'Your wallet top-up did not go through',
            message: $message,
            correlationId: 'funding_failed_'.$funding->id,
            metadata: [
                // The bell and push read `url`: the funding sheet, opened on
                // this currency and amount (RecoveryController does the same).
                'url' => route('dashboard', ['fund' => strtoupper($currency), 'amount' => round((float) $funding->amount, 2)]),
                'funding_id' => $funding->id,
                'failure_reason_code' => $verdict['code'],
            ],
        );
    }
}

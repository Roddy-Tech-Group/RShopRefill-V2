<?php

namespace App\Domain\Payment\Services;

use App\Domain\Audit\Services\AuditLogService;
use App\Domain\Payment\Enums\PaymentStatus;
use App\Domain\Payment\Exceptions\ManualSettlementRejectedException;
use App\Domain\Payment\Providers\NowPaymentsProvider;
use App\Domain\Shared\Enums\FundingStatus;
use App\Jobs\VerifyPaymentJob;
use App\Models\Admin;
use App\Models\PaymentAttempt;
use App\Models\WalletFunding;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * The operator exit for a crypto payment that arrived short.
 *
 * Zero tolerance holds every short payment for the customer's top-up. When
 * the balance is settled another way (paid by hand to support, forgiven for
 * a returning customer, or too small for the top-up to charge but above the
 * dust ceiling) an operator settles it here with a stated reason.
 *
 * This service deliberately does NOT touch the order, the session, the
 * wallet, or fulfilment. It stamps the attempt Paid, records who did it and
 * why, and hands the attempt to VerifyPaymentJob, which already knows how to
 * finish a Paid attempt whose settlement never landed: order to Paid, session
 * confirmed (receipt, cart, coupon, RCOIN), payment confirmed event, and
 * fulfilment, all after commit. One settlement path, not two.
 */
class CryptoManualSettlementService
{
    public function __construct(
        private readonly NowPaymentsProvider $provider,
        private readonly AuditLogService $auditLog,
    ) {}

    /**
     * @return array{reason: string, admin_id: int|string, admin_email: string|null, previous_status: string|null, shortfall_crypto: float, shortfall_fiat: float|null, price_currency: string|null, settled_at: string}
     *
     * @throws ManualSettlementRejectedException
     */
    public function settle(PaymentAttempt $attempt, Admin $admin, string $reason): array
    {
        $note = null;

        DB::transaction(function () use ($attempt, $admin, $reason, &$note) {
            $attempt = PaymentAttempt::query()->lockForUpdate()->findOrFail($attempt->id);

            $this->assertSettleable($attempt);

            $shortfallFiat = $this->provider->shortfallInPriceCurrency($attempt);

            $note = [
                'reason' => $reason,
                'admin_id' => $admin->getKey(),
                'admin_email' => $admin->email,
                'previous_status' => $attempt->payment_status?->value,
                'shortfall_crypto' => round(max(0, (float) $attempt->pay_amount - (float) $attempt->actually_paid), 8),
                'shortfall_fiat' => $shortfallFiat['amount'] ?? null,
                'price_currency' => $shortfallFiat['currency'] ?? null,
                'settled_at' => now()->toIso8601String(),
            ];

            $attempt->payment_status = PaymentStatus::Paid;
            $attempt->confirmed_at = now();
            $attempt->metadata = array_merge($attempt->metadata ?? [], [
                'crypto' => array_merge((array) data_get($attempt->metadata, 'crypto', []), [
                    'manual_settlement' => $note,
                ]),
            ]);
            $attempt->save();

            // Money moved by an operator's decision is always audited: who,
            // what was short, and the stated reason.
            $this->auditLog->log(
                action: 'crypto.settled_manually',
                model: $attempt,
                beforeState: ['payment_status' => $note['previous_status']],
                afterState: ['payment_status' => PaymentStatus::Paid->value],
                metadata: $note + [
                    'gateway_reference' => $attempt->gateway_reference,
                    'pay_currency' => $attempt->pay_currency,
                    'order_id' => $attempt->order_id,
                ],
            );

            Log::warning('Crypto payment settled by an operator despite a shortfall', [
                'attempt_id' => $attempt->id,
                'payment_id' => $attempt->gateway_reference,
                'admin_id' => $note['admin_id'],
                'shortfall_crypto' => $note['shortfall_crypto'],
                'shortfall_fiat' => $note['shortfall_fiat'],
                'price_currency' => $note['price_currency'],
            ]);

            // Queued only once the Paid stamp is committed: a rollback must
            // not leave a settlement job in flight for an attempt that is
            // still partially paid.
            DB::afterCommit(fn () => VerifyPaymentJob::dispatch($attempt));
        });

        return $note;
    }

    /**
     * @throws ManualSettlementRejectedException
     */
    private function assertSettleable(PaymentAttempt $attempt): void
    {
        if (! in_array($attempt->gateway, ['nowpayments', 'crypto'], true)) {
            throw ManualSettlementRejectedException::because('Only a crypto payment can be settled this way.');
        }

        if ($attempt->payment_status !== PaymentStatus::PartiallyPaid) {
            $label = $attempt->payment_status?->label() ?? 'Pending';

            throw ManualSettlementRejectedException::because("Only a partially paid payment can be settled. This one is {$label}.");
        }

        if ((float) $attempt->actually_paid <= 0 || (float) $attempt->pay_amount <= 0) {
            throw ManualSettlementRejectedException::because('Nothing has been received against this payment, so there is nothing to settle.');
        }

        if ($attempt->order && in_array($attempt->order->payment_status, [
            PaymentStatus::Paid,
            PaymentStatus::Refunded,
            PaymentStatus::PartiallyRefunded,
        ], true)) {
            throw ManualSettlementRejectedException::because('This order is already paid by another attempt.');
        }

        if ($attempt->payable_type === WalletFunding::class
            && $attempt->payable?->status === FundingStatus::Completed) {
            throw ManualSettlementRejectedException::because('This wallet funding has already been credited.');
        }
    }
}

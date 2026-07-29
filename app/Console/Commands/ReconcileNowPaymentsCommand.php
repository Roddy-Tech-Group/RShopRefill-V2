<?php

namespace App\Console\Commands;

use App\Domain\Payment\Enums\PaymentStatus;
use App\Jobs\VerifyPaymentJob;
use App\Models\PaymentAttempt;
use Illuminate\Console\Command;

/**
 * Recovery sweep for crypto payments that were paid at NOWPayments but never
 * confirmed locally (the webhook/verify chain was broken). For each still-pending
 * attempt it re-runs the full verification path - which now queries the correct
 * GET /payment/{id} endpoint - and confirms + fulfills any that actually paid.
 * Safe to re-run: attempts that aren't paid at the gateway are left untouched.
 */
class ReconcileNowPaymentsCommand extends Command
{
    protected $signature = 'reconcile:nowpayments
        {--hours= : Only re-check attempts created within the last N hours}
        {--dry-run : List candidates without re-verifying anything}';

    protected $description = 'Re-verify pending NOWPayments crypto attempts and confirm/fulfill any that have actually been paid.';

    public function handle(): int
    {
        $hours = $this->option('hours');

        $candidates = PaymentAttempt::query()
            // 'crypto' is the interim gateway an attempt carries between order
            // placement and coin selection. Filtering on 'nowpayments' alone made
            // every attempt abandoned before coin selection invisible to this
            // sweep — precisely the ones most likely to need recovering.
            ->whereIn('gateway', ['nowpayments', 'crypto'])
            ->whereNotNull('gateway_reference')
            ->whereNotIn('payment_status', [
                PaymentStatus::Paid->value,
                PaymentStatus::Failed->value,
                PaymentStatus::Refunded->value,
                // PartiallyPaid is settled at the gateway and terminal for this
                // payment id — re-verifying it hourly forever produced no state
                // change, only noise. Recovery is the customer's top-up (which
                // creates a NEW payment) or an operator, not this sweep.
                PaymentStatus::PartiallyPaid->value,
                PaymentStatus::Expired->value,
            ])
            ->when($hours !== null, fn ($q) => $q->where('created_at', '>=', now()->subHours((int) $hours)))
            ->orderBy('id')
            ->get();

        if ($candidates->isEmpty()) {
            $this->info('No pending NOWPayments attempts to reconcile.');

            return self::SUCCESS;
        }

        $this->info("Found {$candidates->count()} pending NOWPayments attempt(s) to re-verify.");

        if ($this->option('dry-run')) {
            $this->table(
                ['Attempt', 'Gateway ref', 'Order', 'Amount', 'Created'],
                $candidates->map(fn (PaymentAttempt $attempt) => [
                    $attempt->id,
                    $attempt->gateway_reference,
                    $attempt->order?->order_number ?? '-',
                    $attempt->amount.' '.$attempt->currency,
                    $attempt->created_at?->toDateTimeString(),
                ])->all()
            );
            $this->comment('Dry run - nothing was changed. Re-run without --dry-run to recover.');

            return self::SUCCESS;
        }

        $recovered = 0;
        $stillPending = 0;

        foreach ($candidates as $attempt) {
            // dispatchSync reuses the canonical confirmation path: gateway status
            // check, order transition, session sync, fulfillment dispatch and the
            // duplicate-payment refund guard. It only marks paid if the gateway
            // reports the crypto actually landed.
            VerifyPaymentJob::dispatchSync($attempt);

            if ($attempt->fresh()?->payment_status === PaymentStatus::Paid) {
                $recovered++;
                $this->line("  <info>recovered</info> attempt {$attempt->id} ({$attempt->gateway_reference})");
            } else {
                $stillPending++;
            }
        }

        $this->newLine();
        $this->info("Done. Recovered {$recovered}, still pending/unpaid {$stillPending}.");

        return self::SUCCESS;
    }
}

<?php

namespace App\Domain\Reconciliation\Console;

use App\Domain\Payment\Enums\PaymentStatus;
use App\Domain\Payment\Providers\KorapayPaymentProvider;
use App\Domain\Payment\Services\PaymentGatewayFactory;
use App\Domain\Reconciliation\Models\ReconciliationReport;
use App\Domain\Shared\Services\OperationalAlerter;
use App\Enums\AlertSeverity;
use App\Jobs\VerifyPaymentJob;
use App\Models\PaymentAttempt;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

/**
 * Kora's book against ours. Every Kora attempt in the window is looked up at
 * Kora by OUR reference and the two records are compared. Three things are
 * worth a human: Kora was paid and we have not settled (a customer is owed),
 * we settled and Kora was not paid (we fulfilled without the money), and the
 * two sides disagree on the amount. Report-only unless --settle, which re-runs
 * our own verification path on the first kind; nothing here marks anything
 * paid on its own say-so.
 */
class ReconcileKorapayCommand extends Command
{
    protected $signature = 'korapay:reconcile
        {--hours=48 : Attempts created within the last N hours}
        {--settle : Re-run our verification on attempts Kora reports paid}';

    protected $description = "Compare Kora's record of each attempt with ours and report every disagreement";

    /** Disagreements where we may have given something away. */
    private const URGENT = ['settled_but_failed_at_gateway', 'settled_but_unknown_at_gateway', 'foreign_reference', 'amount_mismatch'];

    public function handle(PaymentGatewayFactory $gateways, OperationalAlerter $alerter): int
    {
        if (empty(config('services.korapay.secret_key'))) {
            $this->info('Kora keys are not configured; there is nothing to reconcile.');

            return self::SUCCESS;
        }

        $provider = $gateways->getProvider('korapay');

        if (! $provider instanceof KorapayPaymentProvider) {
            $this->error('The korapay provider is not the Kora provider.');

            return self::FAILURE;
        }

        $hours = max(1, (int) $this->option('hours'));

        $attempts = PaymentAttempt::query()
            ->where('gateway', 'korapay')
            ->where('created_at', '>=', now()->subHours($hours))
            ->orderBy('id')
            ->get();

        if ($attempts->isEmpty()) {
            $this->info("No Kora attempts in the last {$hours} hour(s).");

            return self::SUCCESS;
        }

        $report = ReconciliationReport::create([
            'type' => 'korapay',
            'status' => 'processing',
            'started_at' => now(),
        ]);

        $counts = ['checked' => 0, 'agree' => 0, 'pending' => 0, 'never_charged' => 0, 'unreached' => 0, 'settled' => 0];
        $anomalies = [];

        foreach ($attempts as $attempt) {
            $counts['checked']++;
            $facts = $provider->chargeFacts($attempt);

            if ($facts === null) {
                $counts['unreached']++;

                continue;
            }

            $ourPaid = in_array($attempt->payment_status, [PaymentStatus::Paid, PaymentStatus::Refunded], true);

            // No charge at Kora under our reference: ordinary for an attempt the
            // customer abandoned before paying, alarming if we hold it as paid.
            if (! $facts['found']) {
                if ($ourPaid) {
                    $anomalies[] = $this->anomaly($attempt, 'settled_but_unknown_at_gateway', $facts);
                } else {
                    $counts['never_charged']++;
                }

                continue;
            }

            if (! $facts['ours']) {
                $anomalies[] = $this->anomaly($attempt, 'foreign_reference', $facts);

                continue;
            }

            $gatewayPaid = $facts['status'] === 'success';
            $gatewayFailed = in_array($facts['status'], ['failed', 'expired', 'cancelled', 'abandoned'], true);

            if ($gatewayPaid && ! $ourPaid) {
                // Our own verification path decides, with its cross-binding and
                // amount checks; this command never marks paid by itself.
                if ($this->option('settle')) {
                    VerifyPaymentJob::dispatchSync($attempt);

                    if ($attempt->fresh()?->payment_status === PaymentStatus::Paid) {
                        $counts['settled']++;
                        $this->line("  <info>settled</info> {$attempt->idempotency_key}");

                        continue;
                    }
                }

                $anomalies[] = $this->anomaly($attempt, 'paid_at_gateway_not_settled', $facts);

                continue;
            }

            if ($gatewayFailed && $ourPaid) {
                $anomalies[] = $this->anomaly($attempt, 'settled_but_failed_at_gateway', $facts);

                continue;
            }

            if ($gatewayPaid && $ourPaid
                && ($facts['amount'] < $facts['expected'] || $facts['currency'] !== strtoupper((string) $attempt->currency))) {
                $anomalies[] = $this->anomaly($attempt, 'amount_mismatch', $facts);

                continue;
            }

            if (! $gatewayPaid && ! $gatewayFailed) {
                $counts['pending']++;

                continue;
            }

            $counts['agree']++;
        }

        $report->update([
            'status' => $anomalies === [] ? 'clean' : 'anomalies_found',
            'anomalies_found' => $anomalies === [] ? null : $anomalies,
            'completed_at' => now(),
        ]);

        $this->info(sprintf(
            'Checked %d Kora attempt(s): %d agree, %d still pending, %d never charged, %d settled now, %d unreached, %d disagreement(s).',
            $counts['checked'], $counts['agree'], $counts['pending'], $counts['never_charged'], $counts['settled'], $counts['unreached'], count($anomalies),
        ));

        if ($anomalies !== []) {
            $this->table(
                ['Reference', 'Disagreement', 'Ours', 'Kora', 'Kora amount', 'Expected'],
                collect($anomalies)->map(fn (array $a) => [
                    $a['reference'], $a['type'], $a['our_status'], $a['gateway_status'] ?? '-',
                    $a['gateway_amount'] === null ? '-' : $a['gateway_amount'].' '.$a['gateway_currency'], $a['expected'].' '.$a['currency'],
                ])->all(),
            );

            $this->raiseAlert($alerter, collect($anomalies), $hours);

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    /**
     * @param  array{found: bool, ours: bool, status: ?string, amount: ?float, currency: ?string, expected: float}  $facts
     * @return array<string, mixed>
     */
    private function anomaly(PaymentAttempt $attempt, string $type, array $facts): array
    {
        $this->line("  <comment>{$type}</comment> {$attempt->idempotency_key}");

        return [
            'type' => $type,
            'attempt_id' => $attempt->id,
            'reference' => $attempt->idempotency_key,
            'order_id' => $attempt->order_id,
            'our_status' => $attempt->payment_status?->value ?? (string) $attempt->payment_status,
            'gateway_status' => $facts['status'],
            'gateway_amount' => $facts['amount'],
            'gateway_currency' => $facts['currency'],
            'expected' => $facts['expected'],
            'currency' => strtoupper((string) $attempt->currency),
        ];
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $anomalies
     */
    private function raiseAlert(OperationalAlerter $alerter, Collection $anomalies, int $hours): void
    {
        $urgent = $anomalies->whereIn('type', self::URGENT);
        $owed = $anomalies->where('type', 'paid_at_gateway_not_settled');

        $alerter->send(
            severity: $urgent->isNotEmpty() ? AlertSeverity::Sev1 : AlertSeverity::Sev2,
            key: 'korapay:reconcile',
            title: 'Kora and our records disagree',
            message: sprintf(
                '%d Kora attempt(s) from the last %d hour(s) do not match our books: %d paid at Kora but not settled here, %d settled here without a matching Kora payment or amount. Run `php artisan korapay:reconcile --hours=%d --settle` to settle what Kora confirms; the rest needs a human.',
                $anomalies->count(), $hours, $owed->count(), $urgent->count(), $hours,
            ),
            context: $anomalies->take(8)->mapWithKeys(fn (array $a) => [
                $a['reference'] => $a['type'].' (ours '.$a['our_status'].', Kora '.($a['gateway_status'] ?? 'none').')',
            ])->all(),
            runbook: 'docs/runbooks/payment-provider-failure.md',
        );
    }
}

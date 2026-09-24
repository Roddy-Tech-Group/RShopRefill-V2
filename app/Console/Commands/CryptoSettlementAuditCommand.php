<?php

namespace App\Console\Commands;

use App\Domain\Payment\Enums\PaymentStatus;
use App\Domain\Shared\Services\OperationalAlerter;
use App\Enums\AlertSeverity;
use App\Models\PaymentAttempt;
use Carbon\CarbonInterface;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

/**
 * Surfaces crypto payments whose settled amount does not match what was invoiced.
 *
 * These previously had no operational exit at all: an underpaid order was written
 * to PartiallyPaid and then nothing happened to it — no alert, no admin screen,
 * and the reconciler simply re-verified it every hour forever. Overpayments were
 * worse, since nothing in the schema could even find them after the fact.
 *
 * Run on a schedule: held payments are announced by name on the ops channel;
 * --json makes it pipeable and never alerts.
 */
class CryptoSettlementAuditCommand extends Command
{
    protected $signature = 'crypto:settlements
        {--days=7 : Only inspect attempts created within the last N days}
        {--json : Emit machine-readable JSON instead of a table}';

    protected $description = 'Report crypto payments that settled short or over the invoiced amount.';

    public function handle(): int
    {
        $since = now()->subDays((int) $this->option('days'));

        $attempts = PaymentAttempt::query()
            ->whereIn('gateway', ['nowpayments', 'crypto'])
            ->where('created_at', '>=', $since)
            ->whereNotNull('settlement_delta')
            ->where('settlement_delta', '!=', 0)
            ->orderByDesc('created_at')
            ->get();

        // Held short payments need a human; dust and operator settlements were
        // fulfilled and are listed so the write-offs stay visible.
        $underpaid = $attempts->filter(fn (PaymentAttempt $a) => $a->isUnderpaid());
        $overpaid = $attempts->filter(fn (PaymentAttempt $a) => $a->isOverpaid());
        $dust = $attempts->filter(fn (PaymentAttempt $a) => $a->settledAsDust());
        $manual = $attempts->filter(fn (PaymentAttempt $a) => $a->settledManually());

        if ($this->option('json')) {
            $this->line((string) json_encode([
                'since' => $since->toIso8601String(),
                'underpaid_count' => $underpaid->count(),
                'overpaid_count' => $overpaid->count(),
                'dust_settled_count' => $dust->count(),
                'manually_settled_count' => $manual->count(),
                'rows' => $attempts->map(fn (PaymentAttempt $a) => $this->row($a))->values(),
            ], JSON_PRETTY_PRINT));

            return self::SUCCESS;
        }

        if ($attempts->isEmpty()) {
            $this->info("No crypto settlement mismatches since {$since->toDateString()}.");

            return self::SUCCESS;
        }

        $this->warn("{$underpaid->count()} underpaid (held), {$dust->count()} dust-settled, {$manual->count()} settled by an operator, {$overpaid->count()} overpaid since {$since->toDateString()}.");
        $this->newLine();

        $this->table(
            ['Attempt', 'Payment ID', 'Coin', 'Invoiced', 'Received', 'Delta', 'Status', 'Resolution', 'Created'],
            $attempts->map(fn (PaymentAttempt $a) => array_values($this->row($a)))->all()
        );

        if ($underpaid->isNotEmpty()) {
            $this->newLine();
            $this->comment('Underpaid orders are never auto-fulfilled. Resolve by asking the customer to');
            $this->comment('top up the shortfall, by settling it with a reason on the transaction page,');
            $this->comment('or by refunding what arrived to their wallet.');
        }

        if ($dust->isNotEmpty()) {
            $this->newLine();
            $this->comment('Dust settlements were fulfilled: each shortfall was under half a cent in the');
            $this->comment('price currency, below what a top-up could even charge.');
        }

        if ($overpaid->isNotEmpty()) {
            $this->newLine();
            $this->comment('Overpaid orders were fulfilled. The surplus is owed back to the customer.');
        }

        // A held payment needs a human, so it is announced by name on the ops
        // channel. This used to be an exit code of 1 (for held AND overpaid),
        // which the scheduler filed every morning in Sentry as "the report
        // crashed" (issue 7618307421), and overpaid is normal: the quoted
        // amount rounds up, so a satoshi of surplus is routine and already
        // fulfilled. The report ran, so it exits 0; held rows reach Telegram.
        if ($underpaid->isNotEmpty()) {
            $this->alertHeld($underpaid, $since);
        }

        return self::SUCCESS;
    }

    /**
     * @param  Collection<int, PaymentAttempt>  $underpaid
     */
    private function alertHeld(Collection $underpaid, CarbonInterface $since): void
    {
        $lines = $underpaid->map(fn (PaymentAttempt $a) => sprintf(
            '%s: received %s of %s%s, waiting since %s, %s',
            $a->order?->order_number ?? ($a->payable_type ? 'wallet funding' : 'attempt '.$a->id),
            $this->trim($a->actually_paid),
            $this->trim($a->pay_amount),
            trim(' '.strtoupper((string) $a->pay_currency)),
            $a->created_at?->toDateString() ?? 'unknown',
            route('admin.transaction', $a),
        ))->values();

        app(OperationalAlerter::class)->send(
            severity: AlertSeverity::Sev2,
            key: 'crypto:settlements:held',
            title: 'Crypto payments held short',
            message: sprintf(
                '%d crypto payment(s) arrived short since %s and are held, never fulfilled on their own. Settle with a reason on the transaction page, wait for the customer to pay the rest, or refund what arrived to their wallet. This repeats daily until none are held.',
                $underpaid->count(),
                $since->toDateString(),
            ),
            context: ['Payments' => $lines->take(10)->implode("\n")] + ($lines->count() > 10 ? ['More' => ($lines->count() - 10).' not listed; run crypto:settlements'] : []),
            runbook: 'docs/runbooks/payment-provider-failure.md',
        );
    }

    /**
     * @return array<string, string|null>
     */
    private function row(PaymentAttempt $attempt): array
    {
        $delta = (float) $attempt->settlement_delta;

        return [
            'attempt' => $attempt->id,
            'payment_id' => $attempt->gateway_reference,
            'coin' => strtoupper((string) $attempt->pay_currency),
            'invoiced' => $this->trim($attempt->pay_amount),
            'received' => $this->trim($attempt->actually_paid),
            'delta' => ($delta > 0 ? '+' : '').$this->trim($delta),
            'status' => $attempt->payment_status?->value ?? PaymentStatus::Pending->value,
            'resolution' => match (true) {
                $attempt->settledAsDust() => 'dust',
                $attempt->settledManually() => 'operator',
                $attempt->isUnderpaid() => 'held',
                $attempt->isOverpaid() => 'surplus owed',
                default => '-',
            },
            'created' => $attempt->created_at?->toDateTimeString(),
        ];
    }

    /** Coins range from 2 to 8 significant decimals; trailing zeros are noise. */
    private function trim(mixed $value): string
    {
        if ($value === null) {
            return '-';
        }

        return rtrim(rtrim(number_format((float) $value, 8, '.', ''), '0'), '.') ?: '0';
    }
}

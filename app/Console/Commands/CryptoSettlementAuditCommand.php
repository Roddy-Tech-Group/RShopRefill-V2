<?php

namespace App\Console\Commands;

use App\Domain\Payment\Enums\PaymentStatus;
use App\Models\PaymentAttempt;
use Illuminate\Console\Command;

/**
 * Surfaces crypto payments whose settled amount does not match what was invoiced.
 *
 * These previously had no operational exit at all: an underpaid order was written
 * to PartiallyPaid and then nothing happened to it — no alert, no admin screen,
 * and the reconciler simply re-verified it every hour forever. Overpayments were
 * worse, since nothing in the schema could even find them after the fact.
 *
 * Run on a schedule so a human sees the queue; --json makes it pipeable into an
 * alerting channel.
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

        // Non-zero exit so a scheduled run can trip an alert on a queue that
        // still needs a human. Dust and operator settlements are closed.
        return $underpaid->isEmpty() && $overpaid->isEmpty() ? self::SUCCESS : self::FAILURE;
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

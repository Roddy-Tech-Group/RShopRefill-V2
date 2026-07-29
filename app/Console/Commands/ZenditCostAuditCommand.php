<?php

namespace App\Console\Commands;

use App\Domain\Catalog\Support\ZenditCostBlocks;
use App\Models\FulfillmentLog;
use App\Models\ProductVariant;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * Read-only audit of Zendit cost data against Zendit's documented semantics.
 *
 * Per developers.zendit.io the catalog `cost` block is "provided in your
 * wallet currency" - it IS the USD deduction per unit - and `cost.fx` is "the
 * FX rate from the cost to the sent value", a value ratio, not a currency
 * conversion. Two things follow and this command reports both:
 *
 *   COST OVER FACE  rows that cost more than the nominal value they deliver
 *                   (fee-loaded US prepaid, Lebanese airtime). Legitimate data
 *                   - but the storefront prices on face value, so every such
 *                   row sells below cost until PRICING_FLOOR_MODE=enforce
 *                   reprices it. This table is the enforce-mode target list.
 *   BAD CURRENCY    cost blocks labeled in a currency other than the wallet's
 *                   USD (an AED case has been observed historically). Zendit
 *                   documents this cannot legitimately happen; such rows are
 *                   the real corruption candidates.
 *
 * The TRANSACTION EVIDENCE section then lays every logged Zendit purchase
 * response next to the stored catalog cost. Purchase responses carry the
 * actual wallet deduction ("cost: Zendit price for partner inclusive of
 * fees"), so any mislabeled block - local-currency amounts under a 'USD'
 * label, invisible to the census by definition - shows up as a mismatch
 * between stored cost and the charge Zendit itself reported.
 */
class ZenditCostAuditCommand extends Command
{
    protected $signature = 'zendit:audit-costs
        {--operator= : Only products whose name contains this substring}
        {--limit=100 : Maximum fulfilled transactions to list in the evidence section}';

    protected $description = 'Audit Zendit catalog cost data: rows costing more than the value they deliver, wallet-currency violations, and transaction evidence from fulfillment logs.';

    /** Payload keys (any path segment) that look like money when scanning transaction responses. */
    private const MONEY_KEY_PATTERN = '/cost|price|value|amount|charge|fee|total|discount|fx|margin|debit|deduct|balance/i';

    /**
     * Keys naming an actual charge outrank catalog-echo keys when the field
     * list is truncated: a status poll that repeats the offer's cost/price/fx
     * blocks must not evict the one field that states what was deducted.
     */
    private const CHARGE_KEY_PATTERN = '/charge|debit|deduct|balance|total|amount/i';

    /** Cap the discovered-field list per transaction so one verbose payload cannot flood the report. */
    private const MAX_FIELDS_PER_TRANSACTION = 18;

    public function handle(): int
    {
        $this->auditCatalog();
        $this->auditTransactions();

        return self::SUCCESS;
    }

    /**
     * Census of the catalog against the two documented facts: cost is USD, and
     * cost may legitimately exceed the delivered face value.
     */
    private function auditCatalog(): void
    {
        $operator = (string) $this->option('operator');

        $totals = [
            'with_cost_block' => 0,
            'ok' => 0,
            'cost_over_face' => 0,
            'bad_currency' => 0,
            'no_counterpart' => 0,
        ];
        /** @var array<string, array{rows: int, sum_cost: float, sum_face: float}> $overFaceByProduct */
        $overFaceByProduct = [];
        /** @var array<string, array{rows: int, currencies: array<string, true>}> $badCurrencyByProduct */
        $badCurrencyByProduct = [];

        ProductVariant::query()
            ->whereHas('product', function ($q) use ($operator) {
                $q->where('provider_name', 'zendit');
                if ($operator !== '') {
                    $q->where('name', 'like', '%'.$operator.'%');
                }
            })
            ->with('product:id,name')
            ->chunk(500, function ($variants) use (&$totals, &$overFaceByProduct, &$badCurrencyByProduct) {
                foreach ($variants as $variant) {
                    $reading = ZenditCostBlocks::read($variant);
                    if ($reading === null) {
                        continue;
                    }

                    $totals['with_cost_block']++;
                    $name = $variant->product?->name ?? '-';

                    if (ZenditCostBlocks::hasWalletCurrencyViolation($variant)) {
                        $totals['bad_currency']++;
                        $badCurrencyByProduct[$name] ??= ['rows' => 0, 'currencies' => []];
                        $badCurrencyByProduct[$name]['rows']++;
                        $badCurrencyByProduct[$name]['currencies'][$reading['cost_currency'] ?? '?'] = true;

                        continue;
                    }

                    if ($reading['face'] === null) {
                        // Variable-amount offers carry no fixed counterpart value.
                        $totals['no_counterpart']++;

                        continue;
                    }

                    if (ZenditCostBlocks::costExceedsFace($variant)) {
                        $totals['cost_over_face']++;
                        $overFaceByProduct[$name] ??= ['rows' => 0, 'sum_cost' => 0.0, 'sum_face' => 0.0];
                        $overFaceByProduct[$name]['rows']++;
                        $overFaceByProduct[$name]['sum_cost'] += $reading['cost_usd'];
                        $overFaceByProduct[$name]['sum_face'] += $reading['face'];

                        continue;
                    }

                    $totals['ok']++;
                }
            });

        $this->newLine();
        $this->line('<options=bold>COST OVER FACE (face-anchored pricing sells these below cost)</>');
        $this->line('Per Zendit docs the cost block is the real USD deduction; these offers cost more than the nominal value they deliver.');

        if ($totals['cost_over_face'] === 0) {
            $this->info('  none');
        } else {
            uasort($overFaceByProduct, fn ($x, $y) => ($y['sum_cost'] - $y['sum_face']) <=> ($x['sum_cost'] - $x['sum_face']));

            $this->table(
                ['Product', 'Rows', 'CostSum $', 'FaceSum $', 'Cost over face $'],
                collect($overFaceByProduct)->map(fn ($row, $name) => [
                    Str::limit($name, 38),
                    $row['rows'],
                    number_format($row['sum_cost'], 2),
                    number_format($row['sum_face'], 2),
                    number_format($row['sum_cost'] - $row['sum_face'], 2),
                ])->values()->all()
            );
        }

        $this->newLine();
        $this->line('<options=bold>WALLET-CURRENCY VIOLATIONS (cost block not labeled USD)</>');

        if ($totals['bad_currency'] === 0) {
            $this->info('  none');
        } else {
            $this->table(
                ['Product', 'Rows', 'Currencies seen'],
                collect($badCurrencyByProduct)->map(fn ($row, $name) => [
                    Str::limit($name, 38),
                    $row['rows'],
                    implode(', ', array_keys($row['currencies'])),
                ])->values()->all()
            );
        }

        $this->info(sprintf(
            'Zendit variants with a cost block: %d. Cost exceeds delivered face on %d. Wallet-currency violations: %d.',
            $totals['with_cost_block'], $totals['cost_over_face'], $totals['bad_currency']
        ));
        $this->info(sprintf(
            'Unremarkable: %d priced at or under face, %d variable-amount (no fixed counterpart).',
            $totals['ok'], $totals['no_counterpart']
        ));
    }

    /**
     * Evidence: every logged Zendit transaction response next to the stored
     * catalog cost. The purchase response's own cost fields are the wallet
     * deduction Zendit reported at sale time - the ground truth any mislabeled
     * catalog block has to answer to.
     */
    private function auditTransactions(): void
    {
        $limit = max(1, (int) $this->option('limit'));

        // Loads every Zendit log row before grouping: acceptable while the
        // table spans weeks, revisit with a windowed query when it spans years.
        $logs = FulfillmentLog::query()
            ->where('provider_name', 'zendit')
            ->whereNotNull('response_payload')
            ->with('orderItem.order:id,metadata', 'orderItem.variant.product:id,name')
            ->orderByDesc('processed_at')
            ->get()
            ->groupBy('order_item_id')
            ->take($limit);

        $this->newLine();
        $this->line('<options=bold>TRANSACTION EVIDENCE (fulfillment_logs, newest first)</>');

        if ($logs->isEmpty()) {
            $this->info('  No Zendit fulfillment responses logged yet.');

            return;
        }

        foreach ($logs as $itemLogs) {
            /** @var FulfillmentLog $latest */
            $latest = $itemLogs->first();
            $item = $latest->orderItem;
            $variant = $item?->variant;
            $reading = $variant ? ZenditCostBlocks::read($variant) : null;

            // Merge money-ish fields across every logged row for the item -
            // the accept response and the status polls carry different shapes.
            $fields = [];
            foreach ($itemLogs as $log) {
                $fields += $this->moneyFields((array) $log->response_payload);
            }
            $fields = $this->truncatePreferringChargeFields($fields);

            // subtotal_amount is stored in the order's DISPLAY currency; divide
            // the exchange-rate snapshot back out so the line reads in USD next
            // to the USD catalog figures (same correction pricing:audit-below-cost
            // applies to its order-bleed scan).
            $rate = (float) ($item?->order?->metadata['exchange_rate'] ?? 1.0) ?: 1.0;
            $chargedUsd = (float) ($item->subtotal_amount ?? 0) / $rate;

            $this->newLine();
            $this->line(sprintf(
                '--- %s | %s | %s',
                $latest->processed_at?->format('Y-m-d H:i') ?? '-',
                $variant->sku ?? $item?->provider_offer_id ?? 'unknown offer',
                Str::limit($variant?->product?->name ?? '-', 40),
            ));
            $this->line(sprintf(
                '    stored cost at sale $%s | catalog cost now $%s | face $%s | charged customer $%s',
                number_format((float) ($item->provider_cost_usd ?? 0), 2),
                $reading ? number_format($reading['cost_usd'], 2) : 'n/a',
                $reading && $reading['face'] !== null ? number_format($reading['face'], 2) : 'n/a',
                number_format($chargedUsd, 2),
            ));

            if ($fields === []) {
                $this->line('    payload carries no money-shaped fields');

                continue;
            }

            foreach ($fields as $path => $value) {
                $this->line("    {$path} = {$value}");
            }
        }

        $this->newLine();
        $this->warn('The purchase response cost/costBase/costFee fields are the deduction Zendit reported. If a row\'s stored catalog cost disagrees with them, that catalog block is mislabeled; if the payloads carry no cost fields, the Zendit dashboard wallet ledger settles it per transactionId.');
    }

    /**
     * Flatten a payload to `dot.path => value` for keys that look like money.
     * Numeric leaves (including numbers serialized as strings) and 3-letter
     * currency codes only - everything else is noise.
     *
     * @param  array<mixed>  $payload
     * @return array<string, string>
     */
    private function moneyFields(array $payload, string $prefix = ''): array
    {
        $found = [];

        foreach ($payload as $key => $value) {
            $path = $prefix === '' ? (string) $key : $prefix.'.'.$key;

            if (is_array($value)) {
                $found += $this->moneyFields($value, $path);

                continue;
            }

            if (! preg_match(self::MONEY_KEY_PATTERN, $path)) {
                continue;
            }

            if (is_int($value) || is_float($value) || (is_string($value) && is_numeric($value))) {
                $found[$path] = rtrim(rtrim(number_format((float) $value, 8, '.', ''), '0'), '.');
            } elseif (is_string($value) && preg_match('/^[A-Z]{3}$/', $value)) {
                $found[$path] = $value;
            }
        }

        return $found;
    }

    /**
     * Apply the per-transaction cap with charge-named keys ranked first, so
     * catalog echoes never push an actual deduction figure off the report.
     *
     * @param  array<string, string>  $fields
     * @return array<string, string>
     */
    private function truncatePreferringChargeFields(array $fields): array
    {
        if (count($fields) <= self::MAX_FIELDS_PER_TRANSACTION) {
            return $fields;
        }

        uksort($fields, function (string $a, string $b) {
            $rank = fn (string $path) => preg_match(self::CHARGE_KEY_PATTERN, $path) ? 0 : 1;

            return [$rank($a), $a] <=> [$rank($b), $b];
        });

        return array_slice($fields, 0, self::MAX_FIELDS_PER_TRANSACTION, true);
    }
}

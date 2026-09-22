<?php

namespace App\Console\Commands;

use App\Domain\Audit\Models\AuditLog;
use App\Domain\Order\Services\RefundLedger;
use App\Models\Admin;
use App\Models\Order;
use App\Models\OrderRefund;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Writes the refund ledger for every refund issued before the ledger existed.
 *
 * The source of truth is orders.metadata.refund_records, the tag => amount map
 * every refund has always written. It carries no dates, so the date and the
 * who and why come from the admin.order_refunded audit row the refund wrote
 * at the same moment, paired in order and checked on amount. An admin refund
 * with no matching audit row is booked at the order's last update and marked
 * estimated, so nothing is silently guessed.
 *
 * Idempotent: tags already in the ledger are skipped, so it can run twice.
 * --dry-run writes nothing and prints, per month, what a real run would book.
 */
class BackfillRefundLedgerCommand extends Command
{
    protected $signature = 'finance:backfill-refunds {--dry-run : Report what would be booked without saving}';

    protected $description = 'Record every past refund in the refund ledger so the Finances page deducts it.';

    /**
     * @var array<string, array{customer: int, reversed_usd: float, reversals: int, estimated: int}>
     */
    private array $months = [];

    private int $skipped = 0;

    private int $unrecordedOrders = 0;

    /**
     * @var array<int, int|null>
     */
    private array $adminIds = [];

    public function handle(RefundLedger $ledger): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $this->months = [];
        $this->skipped = 0;
        $this->unrecordedOrders = 0;

        Order::query()
            ->whereNotNull('metadata->refund_records')
            ->orderBy('id')
            ->chunkById(200, function (Collection $orders) use ($ledger, $dryRun) {
                foreach ($orders as $order) {
                    $this->backfillOrder($ledger, $order, $dryRun);
                }
            });

        $this->report($dryRun);

        return self::SUCCESS;
    }

    private function backfillOrder(RefundLedger $ledger, Order $order, bool $dryRun): void
    {
        $records = (array) data_get($order->metadata, 'refund_records', []);

        if (round((float) data_get($order->metadata, 'refunded_total', 0), 2) > round(array_sum($records), 2)) {
            $this->unrecordedOrders++;
        }

        $recorded = OrderRefund::query()->where('order_id', $order->getKey())->pluck('tag')->all();
        $audits = AuditLog::query()
            ->where('action', 'admin.order_refunded')
            ->where('model_type', Order::class)
            ->where('model_id', $order->getKey())
            ->orderBy('id')
            ->get()
            ->values();

        foreach ($records as $tag => $amount) {
            $tag = (string) $tag;
            $amount = (float) $amount;
            $isAdminRefund = str_starts_with($tag, 'admin-');
            $audit = $isAdminRefund ? $this->takeAudit($audits, $amount) : null;

            // An admin refund pairs with its audit row even when the tag is
            // already in the ledger, so the next refund pairs with the next row.
            if (in_array($tag, $recorded, true)) {
                $this->skipped++;

                continue;
            }

            $estimated = $isAdminRefund && $audit === null;
            $refundedAt = $audit?->created_at
                ?? ($isAdminRefund ? $order->updated_at : ($order->completed_at ?? $order->updated_at))
                ?? now();

            $context = [
                'gateway' => data_get($audit?->metadata, 'gateway'),
                'reason' => data_get($audit?->metadata, 'reason'),
                'refunded_by' => $this->existingAdminId(data_get($audit?->metadata, 'actor_id')),
                'refunded_at' => Carbon::parse($refundedAt),
                'metadata' => array_filter([
                    'backfilled' => true,
                    'audit_log_id' => $audit?->getKey(),
                    'refunded_at_estimated' => $estimated ?: null,
                ]),
            ];

            $row = $dryRun
                ? $ledger->attributesFor($order, $amount, $tag, $context)
                : $ledger->record($order, $amount, $tag, $context)->getAttributes();

            $this->tally($row, $context['refunded_at'], $estimated);
        }
    }

    /**
     * The first unused audit row for this refund's amount. Refunds are written
     * in the order they happened, so the first match is the right one.
     *
     * @param  Collection<int, AuditLog>  $audits
     */
    private function takeAudit(Collection $audits, float $amount): ?AuditLog
    {
        $key = $audits->search(fn (AuditLog $audit) => round((float) data_get($audit->metadata, 'amount'), 2) === round($amount, 2));

        return $key === false ? null : $audits->pull($key);
    }

    /**
     * The audit row names the admin by id; one since removed keeps the refund
     * (with the name still in the audit trail) but not a dangling key.
     */
    private function existingAdminId(mixed $adminId): ?int
    {
        if (! is_numeric($adminId)) {
            return null;
        }

        $adminId = (int) $adminId;

        if (! array_key_exists($adminId, $this->adminIds)) {
            $this->adminIds[$adminId] = Admin::query()->whereKey($adminId)->exists() ? $adminId : null;
        }

        return $this->adminIds[$adminId];
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function tally(array $row, Carbon $refundedAt, bool $estimated): void
    {
        $month = $refundedAt->format('Y-m');
        $this->months[$month] ??= ['customer' => 0, 'reversed_usd' => 0.0, 'reversals' => 0, 'estimated' => 0];

        if ($row['kind'] === OrderRefund::KIND_CUSTOMER_REFUND) {
            $this->months[$month]['customer']++;
            $this->months[$month]['reversed_usd'] += (float) $row['revenue_reversed_usd'];
        } else {
            $this->months[$month]['reversals']++;
        }

        if ($estimated) {
            $this->months[$month]['estimated']++;
        }
    }

    private function report(bool $dryRun): void
    {
        ksort($this->months);

        $this->table(
            ['Month', 'Customer refunds', 'Revenue coming off (USD)', 'Failed-delivery reversals', 'Date estimated'],
            collect($this->months)->map(fn (array $m, string $month) => [
                $month,
                $m['customer'],
                number_format($m['reversed_usd'], 2),
                $m['reversals'],
                $m['estimated'],
            ])->values()->all(),
        );

        $booked = array_sum(array_map(fn (array $m) => $m['customer'] + $m['reversals'], $this->months));

        $this->info(sprintf(
            '%s: %d refunds %s; %d already in the ledger (skipped).',
            $dryRun ? 'DRY RUN' : 'Backfilled',
            $booked,
            $dryRun ? 'would be booked' : 'booked',
            $this->skipped,
        ));

        if ($this->unrecordedOrders > 0) {
            $this->warn("{$this->unrecordedOrders} orders show a refunded total larger than their refund records; those extra amounts have no tag and are not booked.");
        }
    }
}

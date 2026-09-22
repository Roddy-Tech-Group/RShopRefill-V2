<?php

namespace App\Console\Commands;

use App\Domain\Fulfillment\Enums\FulfillmentStatus;
use App\Domain\Fulfillment\Services\EsimRegistry;
use App\Models\Esim;
use App\Models\OrderItem;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

/**
 * Writes an esims row for every eSIM delivered before the table existed, from
 * the delivery payload each order item already holds. Idempotent: an eSIM
 * already recorded is left alone, so it can run twice.
 */
class BackfillEsimsCommand extends Command
{
    protected $signature = 'esims:backfill {--dry-run : Count what would be recorded without saving}';

    protected $description = 'Record every delivered eSIM in the esims table for the Manage eSIMs page.';

    public function handle(EsimRegistry $registry): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $items = 0;
        $recorded = 0;
        $existing = 0;
        $noIccid = 0;

        OrderItem::query()
            ->where('fulfillment_status', FulfillmentStatus::Fulfilled->value)
            ->whereHas('category', fn ($query) => $query->where('slug', EsimRegistry::CATEGORY_SLUG))
            ->orderBy('id')
            ->chunkById(200, function (Collection $chunk) use ($registry, $dryRun, &$items, &$recorded, &$existing, &$noIccid) {
                foreach ($chunk as $item) {
                    if (! $registry->isDeliveredEsimPurchase($item)) {
                        continue;
                    }

                    $items++;
                    $iccids = EsimRegistry::iccidsFrom((array) $item->fulfillment_payload);

                    if ($iccids === []) {
                        $noIccid++;

                        continue;
                    }

                    $already = Esim::query()->where('order_item_id', $item->getKey())->whereIn('iccid', $iccids)->count();
                    $existing += $already;
                    $recorded += count($iccids) - $already;

                    if (! $dryRun) {
                        $registry->recordFor($item);
                    }
                }
            });

        $this->info(sprintf(
            '%s: %d eSIM items scanned; %d eSIMs %s; %d already recorded.',
            $dryRun ? 'DRY RUN' : 'Backfilled',
            $items,
            $recorded,
            $dryRun ? 'would be recorded' : 'recorded',
            $existing,
        ));

        if ($noIccid > 0) {
            $this->warn("{$noIccid} delivered eSIM items carry no ICCID in their payload and were skipped.");
        }

        return self::SUCCESS;
    }
}

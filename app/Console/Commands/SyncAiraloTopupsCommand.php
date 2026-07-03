<?php

namespace App\Console\Commands;

use App\Domain\Catalog\Services\AiraloTopupCatalogService;
use App\Domain\Fulfillment\Enums\FulfillmentStatus;
use App\Domain\Fulfillment\Providers\AiraloFulfillmentProvider;
use App\Models\OrderItem;
use Illuminate\Console\Command;

/**
 * Backfills the "Airalo Top-Up" catalog. Airalo has no global top-up list -
 * top-ups only come back per installed eSIM - so we walk the fulfilled Airalo
 * eSIMs customers already hold, take ONE representative ICCID per parent product
 * (its top-ups are identical for every customer on that operator/region), fetch
 * that eSIM's live top-ups, and upsert them as catalog variants.
 *
 * Idempotent: variants are keyed by the Airalo package id, so re-running only
 * updates in place. Admin price overrides + availability toggles are preserved.
 * Safe to schedule (e.g. daily) alongside airalo:sync-esims.
 */
class SyncAiraloTopupsCommand extends Command
{
    protected $signature = 'airalo:sync-topups {--limit=0 : Max parent eSIMs to walk (0 = all)}';

    protected $description = 'Backfill the Airalo Top-Up catalog from existing fulfilled Airalo eSIMs';

    public function __construct(
        private readonly AiraloFulfillmentProvider $provider,
        private readonly AiraloTopupCatalogService $catalog,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $limit = max(0, (int) $this->option('limit'));

        // One representative fulfilled eSIM per parent product. Exclude top-up
        // order items themselves (metadata.is_topup) - we only follow real eSIMs.
        $items = OrderItem::query()
            ->where('provider_name', 'airalo')
            ->where('fulfillment_status', FulfillmentStatus::Fulfilled)
            ->whereNotNull('fulfillment_payload')
            ->where(function ($q) {
                $q->whereNull('metadata->is_topup')->orWhere('metadata->is_topup', false);
            })
            ->with('product')
            ->orderByDesc('id')
            ->get()
            ->filter(fn (OrderItem $i) => $i->product && $this->iccidOf($i) !== null)
            ->unique('product_id')
            ->values();

        if ($limit > 0) {
            $items = $items->take($limit);
        }

        if ($items->isEmpty()) {
            $this->warn('No fulfilled Airalo eSIMs with an ICCID found - nothing to backfill yet.');

            return self::SUCCESS;
        }

        $this->info("Walking {$items->count()} Airalo eSIM(s) for top-up packages...");

        $totalVariants = 0;
        foreach ($items as $item) {
            $iccid = $this->iccidOf($item);

            try {
                $packages = $this->provider->listTopupsForIccid((string) $iccid);
            } catch (\Throwable $e) {
                $this->warn("  {$item->product->name}: fetch failed ({$e->getMessage()})");

                continue;
            }

            if ($packages === []) {
                $this->line("  {$item->product->name}: no top-ups offered");

                continue;
            }

            $map = $this->catalog->upsertForParent($item->product, $packages);
            $totalVariants += count($map);
            $this->line("  {$item->product->name}: ".count($map).' top-up(s)');
        }

        $this->info("Done. Upserted {$totalVariants} Airalo top-up variant(s).");

        return self::SUCCESS;
    }

    private function iccidOf(OrderItem $item): ?string
    {
        $payload = (array) ($item->fulfillment_payload ?? []);
        $iccid = $payload['iccid'] ?? ($payload['esim']['iccid'] ?? null);

        return is_scalar($iccid) && (string) $iccid !== '' ? (string) $iccid : null;
    }
}

<?php

namespace App\Console\Commands;

use App\Domain\Catalog\Support\BrandBlocklist;
use App\Models\Product;
use App\Support\TaggedCache;
use Illuminate\Console\Command;

/**
 * Applies config/catalog.php `blocked_brands` to the live catalog right now,
 * instead of waiting for the next supplier sync to walk past the brand.
 * Meant for takedown responses: run it once and the storefront pages for the
 * blocked brands 301 to the category listing immediately.
 */
class EnforceBrandBlocklistCommand extends Command
{
    protected $signature = 'catalog:enforce-brand-blocklist
        {--dry-run : Report what would be deactivated without changing anything}';

    protected $description = 'Deactivate products whose brand is on the takedown blocklist, then flush the catalog cache';

    public function handle(): int
    {
        $entries = BrandBlocklist::entries();

        if ($entries === []) {
            $this->info('Brand blocklist is empty; nothing to enforce.');

            return self::SUCCESS;
        }

        $this->info('Blocklist: '.implode(', ', $entries));

        $dryRun = (bool) $this->option('dry-run');
        $deactivated = 0;

        // Matching is normalization-based (see BrandBlocklist), so filter in
        // PHP rather than SQL. Only id/name/brand_key are pulled per row.
        Product::query()
            ->whereNotNull('brand_key')
            ->where('is_active', true)
            ->get(['id', 'name', 'brand_key'])
            ->each(function (Product $product) use (&$deactivated, $dryRun) {
                if (! BrandBlocklist::isBlocked($product->brand_key)) {
                    return;
                }

                if (! $dryRun) {
                    $product->update(['is_active' => false]);
                }

                $verb = $dryRun ? 'Would deactivate' : 'Deactivated';
                $this->line("{$verb} #{$product->id} \"{$product->name}\" (brand_key: {$product->brand_key})");
                $deactivated++;
            });

        if ($deactivated === 0) {
            $this->info('No active products matched the blocklist.');

            return self::SUCCESS;
        }

        if ($dryRun) {
            $this->info("{$deactivated} product(s) would be deactivated (dry run, nothing changed).");

            return self::SUCCESS;
        }

        // Brand-page lookups are cached for an hour under the `catalog` tag;
        // flush so the takedown takes effect immediately, not at TTL expiry.
        TaggedCache::for(['catalog'])->flush();

        $this->info("{$deactivated} product(s) deactivated; catalog cache flushed.");

        return self::SUCCESS;
    }
}

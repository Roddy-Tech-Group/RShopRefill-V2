<?php

use App\Http\Controllers\EsimStoreController;
use App\Models\Product;
use Illuminate\Database\Migrations\Migration;

/**
 * Backfill: the Airalo sync historically named EVERY non-country ("WW") eSIM
 * product a blanket "Global eSIM" - so the admin product list / command bar
 * showed a wall of identical rows (the regional eSIMs, the worldwide Discover
 * Global, Europe, Asia, MENA, Latin America, etc. all read "Global eSIM").
 *
 * The storefront was unaffected because it derives the real region name from the
 * slug via EsimStoreController::regionMeta(); this only relabels the stored
 * `name` so the admin surfaces show each product's real region. The normalizer
 * now writes the region name for new syncs (and preserves the name thereafter),
 * so this one-time pass fixes the rows already in the DB.
 *
 * Targets only rows still holding the generic name, so any admin-renamed product
 * is left untouched. The 'Global' fallback (a slug with no derivable region) is
 * skipped, so it keeps its "Global eSIM" name rather than becoming "Global eSIM"
 * a second time.
 */
return new class extends Migration
{
    public function up(): void
    {
        Product::query()
            ->where('name', 'Global eSIM')
            ->where('country_code', 'WW')
            ->get()
            ->each(function (Product $product): void {
                $region = EsimStoreController::regionMeta($product->country_code, $product->slug, $product->name)['name'];

                if ($region !== '' && strcasecmp($region, 'Global') !== 0) {
                    $product->forceFill(['name' => "{$region} eSIM"])->save();
                }
            });
    }

    public function down(): void
    {
        // Non-reversible data backfill: the original blanket "Global eSIM" name
        // carried no region information to restore to.
    }
};

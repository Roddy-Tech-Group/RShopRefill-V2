<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_variants', function (Blueprint $table) {
            // Which supplier catalog feed owns this variant. Only the feed's own
            // sync may hide it for disappearing; variants no sync owns (Airalo
            // top-up packages, manual rows) stay null and are never touched.
            $table->string('catalog_feed', 32)->nullable()->after('provider_offer_id');
            // Last time a sync saw this offer in the supplier's feed. updated_at
            // cannot answer that: it only moves when the offer's data changed.
            $table->dateTime('last_seen_at')->nullable()->after('is_available');
            // Set when a sync (or a purchase the supplier rejected as "offer not
            // found") hid the variant because the supplier no longer lists it.
            // Cleared when the offer comes back. Admin toggles never set it.
            $table->dateTime('delisted_at')->nullable()->after('last_seen_at');

            $table->index(['catalog_feed', 'is_available']);
        });

        // Claim the variants every existing sync already created, so the first
        // cleanup run can see the offers suppliers dropped before this shipped.
        $zenditProducts = DB::table('products')->select('id')->where('provider_name', 'zendit');

        $claims = [
            'zendit_vouchers' => fn ($query) => $query->whereIn('product_id', $zenditProducts)->where('metadata->productType', 'VOUCHER'),
            'zendit_topups' => fn ($query) => $query->whereIn('product_id', $zenditProducts)->where('metadata->productType', 'TOPUP'),
            'zendit_esims' => fn ($query) => $query->whereIn('product_id', $zenditProducts)->where('metadata->provider', 'zendit'),
            'airalo_esims' => fn ($query) => $query->where('metadata->provider', 'airalo')
                ->where('provider_offer_id', 'like', 'airalo_%')
                ->where('provider_offer_id', 'not like', 'airalo_topup_%'),
        ];

        foreach ($claims as $feed => $scope) {
            $scope(DB::table('product_variants')->whereNull('catalog_feed'))->update(['catalog_feed' => $feed]);
        }

        // Offers already off sale when this shipped were hidden without the
        // engine: 312 stale gift cards by hand on 2026-09-17 (all of Apple US
        // among them). Marking them delisted lets the engine count and announce
        // their return the day the supplier lists them again, instead of
        // switching them back on silently. A variant the supplier itself has
        // disabled is caught here too; its marker is cleared on the first run
        // that sees it, and it stays off sale, so nothing changes for it.
        DB::table('product_variants')
            ->whereNotNull('catalog_feed')
            ->where('is_available', false)
            ->update(['delisted_at' => now()]);
    }

    public function down(): void
    {
        Schema::table('product_variants', function (Blueprint $table) {
            $table->dropIndex(['catalog_feed', 'is_available']);
            $table->dropColumn(['catalog_feed', 'last_seen_at', 'delisted_at']);
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Give eSIM top-up packages back the supplier's actual word.
 *
 * The availability override migration copied is_available into
 * supplier_available for every row. Top-up packages have no catalog feed, and
 * the service that writes them never recorded a supplier verdict, so a package
 * an admin had switched off by hand came out of that backfill marked as one the
 * supplier withdrew. Switching it back on was then refused for good.
 *
 * The service now records the verdict on every fetch, but nothing fetches these
 * on a schedule: a package heals only when a customer opens a top-up page that
 * lists it. This heals the rest now.
 *
 * Conservative on purpose. It only raises supplier_available on top-up rows no
 * feed owns. It never touches is_available, so nothing goes on sale here; an
 * admin still has to switch each one back on. And a purchase re-checks the
 * package against the supplier's live list before anyone is charged.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('product_variants')
            ->whereNull('catalog_feed')
            ->where('provider_offer_id', 'like', 'airalo_topup_%')
            ->where('supplier_available', false)
            ->update(['supplier_available' => true]);
    }

    /**
     * Nothing to undo. Which rows this raised is not recorded, and lowering
     * them again would only strand the packages it freed.
     */
    public function down(): void
    {
        //
    }
};

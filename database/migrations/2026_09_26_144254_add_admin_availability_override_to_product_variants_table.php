<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Separate what the SUPPLIER says about an offer from what an ADMIN decided.
 *
 * Until now both lived in `is_available`, and all four normalizers write it on
 * every sync from the feed's own flag. So switching a variant off in the admin
 * panel, because it is mispriced or the supplier is flaky, lasted until the next
 * run and was silently undone within six hours, with nobody told.
 *
 * After this, `supplier_available` is the feed's word, `admin_disabled_at` is
 * ours, and `is_available` stays the single effective flag every read already
 * uses, computed from the two. An admin can only ever force an offer OFF, never
 * on: forcing on something the supplier has disabled just moves the failure to
 * the customer at fulfilment.
 *
 * Backfill sets supplier_available from today's is_available, which keeps
 * behaviour identical on day one. It cannot recover WHICH of the currently
 * unavailable variants were switched off by hand rather than by a supplier,
 * because that distinction was never recorded; any deliberate toggle made
 * before this ships must be re-applied once.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_variants', function (Blueprint $table) {
            $table->boolean('supplier_available')->default(true)->after('is_available');
            $table->dateTime('admin_disabled_at')->nullable()->after('supplier_available');
            $table->unsignedBigInteger('admin_disabled_by')->nullable()->after('admin_disabled_at');

            $table->index('admin_disabled_at');
        });

        DB::table('product_variants')->update([
            'supplier_available' => DB::raw('is_available'),
        ]);
    }

    public function down(): void
    {
        Schema::table('product_variants', function (Blueprint $table) {
            $table->dropIndex(['admin_disabled_at']);
            $table->dropColumn(['supplier_available', 'admin_disabled_at', 'admin_disabled_by']);
        });
    }
};

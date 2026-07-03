<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * A top-up product groups every operator offer, but the sync had been writing a
     * single offer's per-bundle shortNotes ("3GB + Call (30d)") into the brand-level
     * description - so ~30% of top-ups showed a stray bundle name under the title.
     * Clear every top-up description so the storefront's own top-up tagline renders;
     * the normalizer no longer sets a description for top-ups. All existing values are
     * supplier-derived bundle names, not admin copy, so nothing original is lost.
     */
    public function up(): void
    {
        $categoryId = DB::table('categories')->where('slug', 'mobile-airtime')->value('id');

        if ($categoryId === null) {
            return;
        }

        DB::table('products')
            ->where('category_id', $categoryId)
            ->whereNotNull('description')
            ->update(['description' => null]);
    }

    /**
     * Irreversible: the cleared values were supplier-derived bundle names, not
     * original content, so there is nothing to restore.
     */
    public function down(): void
    {
        //
    }
};

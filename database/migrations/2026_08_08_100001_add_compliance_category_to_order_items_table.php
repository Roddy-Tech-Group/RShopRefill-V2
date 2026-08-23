<?php

use App\Domain\Shared\Enums\ComplianceCategory;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Snapshots the compliance category onto each order item, alongside the existing
 * category_id / provider_name snapshot, so the authoritative pay() gate and any
 * later evidence view can read what a placed order actually contained without
 * re-deriving it from a product that may since have changed.
 *
 * Historical rows are backfilled from their storefront category, the same
 * mapping used for products, so an already-placed gift-card order reads as a
 * gift card rather than defaulting to a normal digital good.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('order_items', function (Blueprint $table) {
            $table->string('compliance_category')
                ->default(ComplianceCategory::NormalDigitalGood->value)
                ->after('subcategory_id');
        });

        $slugToCategory = [
            'gift-cards' => ComplianceCategory::GiftCard->value,
            'esims' => ComplianceCategory::Esim->value,
            'mobile-airtime' => ComplianceCategory::Airtime->value,
        ];

        foreach ($slugToCategory as $slug => $complianceCategory) {
            $categoryIds = DB::table('categories')->where('slug', $slug)->pluck('id');

            if ($categoryIds->isNotEmpty()) {
                DB::table('order_items')
                    ->whereIn('category_id', $categoryIds)
                    ->update(['compliance_category' => $complianceCategory]);
            }
        }
    }

    public function down(): void
    {
        Schema::table('order_items', function (Blueprint $table) {
            $table->dropColumn('compliance_category');
        });
    }
};

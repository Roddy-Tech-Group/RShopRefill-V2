<?php

use App\Domain\Shared\Enums\ComplianceCategory;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Adds the compliance category the payment eligibility gate reads. Additive and
 * safe: the column is NULLABLE, and null means "derive from the storefront
 * category" via Product::complianceCategory(). The known restricted categories
 * are backfilled to an explicit value so a gift card is never treated as an
 * ordinary digital good; everything else stays null and derives to
 * normal_digital_good. A gift-card product created AFTER this migration is
 * classified correctly by the accessor's slug fallback, so the gate cannot be
 * fooled by an untagged row.
 *
 * With the enforcement flag off (its default), tagging changes nothing customers
 * experience; it only makes the correct category available to the gate once
 * enforcement is switched on.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->string('compliance_category')
                ->nullable()
                ->after('subcategory_id');
        });

        // Backfill the known restricted categories from their storefront slug.
        // Everything else stays null and derives to normal_digital_good.
        $slugToCategory = [
            'gift-cards' => ComplianceCategory::GiftCard->value,
            'esims' => ComplianceCategory::Esim->value,
            'mobile-airtime' => ComplianceCategory::Airtime->value,
        ];

        foreach ($slugToCategory as $slug => $complianceCategory) {
            $categoryIds = DB::table('categories')->where('slug', $slug)->pluck('id');

            if ($categoryIds->isNotEmpty()) {
                DB::table('products')
                    ->whereIn('category_id', $categoryIds)
                    ->update(['compliance_category' => $complianceCategory]);
            }
        }
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn('compliance_category');
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            // Explicit markup basis (App\Enums\PricingBasis: face_value |
            // supplier_cost | provider_retail). Nullable on purpose: NULL means
            // the engine keeps using the legacy currency heuristic, so this
            // deploys as a no-op and `pricing:backfill-basis` assigns
            // behaviour-preserving values afterwards. Made NOT NULL only in
            // the final cleanup stage.
            $table->string('pricing_basis', 32)->nullable()->after('currency_code');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn('pricing_basis');
        });
    }
};

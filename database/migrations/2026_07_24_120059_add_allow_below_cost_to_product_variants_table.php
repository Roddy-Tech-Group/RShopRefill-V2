<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_variants', function (Blueprint $table) {
            // Explicit, audited escape hatch from the cost-anchored margin
            // floor. When false (default) an admin manual_retail_price_usd
            // below supplier cost is clamped up to the floor in enforce mode;
            // true means "we are deliberately selling this below cost"
            // (loss-leader) and the override is honoured verbatim.
            $table->boolean('allow_below_cost')->default(false)->after('manual_retail_price_usd');
        });
    }

    public function down(): void
    {
        Schema::table('product_variants', function (Blueprint $table) {
            $table->dropColumn('allow_below_cost');
        });
    }
};

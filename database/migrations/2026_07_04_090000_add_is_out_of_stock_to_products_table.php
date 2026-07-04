<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Admin-controlled "out of stock" flag. Distinct from is_active (which HIDES the
     * product): an out-of-stock product stays VISIBLE but greyed and non-purchasable,
     * with an "Out of stock" label, until the admin marks it back in stock.
     */
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->boolean('is_out_of_stock')->default(false)->index()->after('is_active');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropIndex(['is_out_of_stock']);
            $table->dropColumn('is_out_of_stock');
        });
    }
};

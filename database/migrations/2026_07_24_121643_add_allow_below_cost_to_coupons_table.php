<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('coupons', function (Blueprint $table) {
            // Marketing escape hatch from the coupon cost-floor clamp: a
            // coupon flagged true may discount a line below supplier cost
            // (deliberate promo at a loss). Default false — the discount is
            // clamped so the line never falls under cost x (1 + min margin).
            $table->boolean('allow_below_cost')->default(false)->after('is_active');
        });
    }

    public function down(): void
    {
        Schema::table('coupons', function (Blueprint $table) {
            $table->dropColumn('allow_below_cost');
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('order_items', function (Blueprint $table) {
            // The variable/custom-amount face value in the PROVIDER's offer currency
            // (e.g. USD for a Visa card), captured before display-currency conversion.
            // This is the value sent to the fulfilment provider. display_amount holds
            // the display-currency figure for the customer and must NOT be sent to the
            // provider. Null for fixed-denomination items (the offerId encodes the value).
            $table->decimal('provider_face_value', 16, 4)->nullable()->after('display_amount');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('order_items', function (Blueprint $table) {
            $table->dropColumn('provider_face_value');
        });
    }
};

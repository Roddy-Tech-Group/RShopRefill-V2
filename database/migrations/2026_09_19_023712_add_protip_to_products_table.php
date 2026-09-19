<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The one warning a buyer needs BEFORE paying, in our own words: the thing
     * about this brand that gets people a code they cannot use, such as a card
     * whose country has to match the account it is redeemed on.
     *
     * It is its own column on purpose. redeem_instructions and
     * terms_and_conditions are rewritten wholesale by the brand sync every few
     * hours (ZenditBrandSyncService), so a note written into either of those
     * would disappear on its own. No sync writer names this column, and none
     * ever should: omission from those payloads is what keeps a hand-written
     * note alive.
     *
     * NULL means "nothing brand-specific to say", and the product page falls
     * back to the warning that is true of every gift card we sell.
     */
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->text('protip')->nullable()->after('redeem_instructions');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn('protip');
        });
    }
};

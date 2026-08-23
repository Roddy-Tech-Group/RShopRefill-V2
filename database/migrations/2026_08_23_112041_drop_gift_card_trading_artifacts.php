<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Gift card trading is removed from the product entirely. The feature never
 * processed a single trade in production (its flag shipped off and stayed
 * off), so there are no financial records to retain: every table it created
 * is dropped, child tables first so the foreign keys unwind cleanly, and its
 * feature-flag row goes with them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('payouts');
        Schema::dropIfExists('trade_audit_logs');
        Schema::dropIfExists('trade_messages');
        Schema::dropIfExists('trade_media');
        Schema::dropIfExists('gift_card_trades');
        Schema::dropIfExists('bank_accounts');
        Schema::dropIfExists('gift_card_rates');
        Schema::dropIfExists('gift_card_brands');

        DB::table('site_settings')->where('key', 'features.sell_gift_cards_enabled')->delete();
    }

    /**
     * Irreversible by design: the tables held no production data and the
     * feature's code is gone, so there is nothing meaningful to restore.
     */
    public function down(): void
    {
        // no-op
    }
};

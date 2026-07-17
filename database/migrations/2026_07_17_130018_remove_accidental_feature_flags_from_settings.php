<?php

use Illuminate\Database\Migrations\Migration;
use App\Models\SiteSetting;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Remove accidental feature flags that were manually added or seeded 
        // during testing, causing duplicates in the Admin Panel.
        SiteSetting::whereIn('key', [
            'features.virtual_cards_enabled',
            'features.transfers_enabled',
            'features.withdrawals_enabled',
            'features.currency_conversion',
            'features.wallet.transfers_enabled',
            'features.wallet.virtual_cards_enabled',
            'features.wallet.withdrawals_enabled',
            'features.wallet.fx_enabled',
        ])->delete();
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // No down migration needed for cleanup
    }
};

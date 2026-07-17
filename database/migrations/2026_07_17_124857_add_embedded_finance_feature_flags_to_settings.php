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
        $features = [
            [
                'key' => 'features.ef_transfers_enabled',
                'group' => 'features',
                'value' => 'off',
                'description' => 'Embedded Finance: Enables the P2P Transfers functionality and UI elements.',
            ],
            [
                'key' => 'features.ef_virtual_cards_enabled',
                'group' => 'features',
                'value' => 'off',
                'description' => 'Embedded Finance: Enables the Virtual Cards functionality and UI elements.',
            ],
            [
                'key' => 'features.ef_fiat_withdrawals_enabled',
                'group' => 'features',
                'value' => 'off',
                'description' => 'Embedded Finance: Enables the Fiat Withdrawals functionality and UI elements.',
            ],
            [
                'key' => 'features.ef_exchange_enabled',
                'group' => 'features',
                'value' => 'off',
                'description' => 'Embedded Finance: Enables the Currency Exchange functionality and UI elements.',
            ],
        ];

        foreach ($features as $feature) {
            SiteSetting::updateOrCreate(
                ['key' => $feature['key']],
                $feature
            );
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        SiteSetting::whereIn('key', [
            'features.ef_transfers_enabled',
            'features.ef_virtual_cards_enabled',
            'features.ef_fiat_withdrawals_enabled',
            'features.ef_exchange_enabled',
        ])->delete();
    }
};

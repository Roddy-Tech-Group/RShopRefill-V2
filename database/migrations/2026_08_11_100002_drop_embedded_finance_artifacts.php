<?php

use App\Models\FeatureFlag;
use App\Models\SiteSetting;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;

/**
 * Removes the database artifacts of the embedded-finance suite (virtual cards,
 * FX, transfers, fiat withdrawals, Maplerad) now that the code is gone, so the
 * platform matches its closed-loop, not-a-bank description.
 *
 * Everything here is guarded, so it is a clean no-op on a fresh database (the
 * create migrations were deleted, so the tables and flags never existed) and a
 * cleanup on an existing one. Gift-card trading and Rcoin are deliberately left
 * untouched.
 */
return new class extends Migration
{
    /** Orphaned tables that backed embedded-finance features only. */
    private const EF_TABLES = ['virtual_cards', 'fx_quotes'];

    /** SiteSetting-backed enforcement flags for the embedded-finance dashboard. */
    private const EF_SETTING_KEYS = [
        'features.ef_virtual_cards_enabled',
        'features.ef_transfers_enabled',
        'features.ef_fiat_withdrawals_enabled',
        'features.ef_exchange_enabled',
    ];

    /** Dormant embedded-finance entries in the parallel FeatureFlag table. */
    private const EF_FEATURE_FLAGS = [
        'wallet.withdrawals',
        'wallet.transfers',
        'wallet.fx',
        'wallet.virtual_cards',
        'wallet.physical_cards',
        'wallet.bank_accounts',
        'wallet.loans',
        'wallet.investments',
    ];

    public function up(): void
    {
        foreach (self::EF_TABLES as $table) {
            Schema::dropIfExists($table);
        }

        SiteSetting::query()->whereIn('key', self::EF_SETTING_KEYS)->delete();
        foreach (self::EF_SETTING_KEYS as $key) {
            Cache::forget('site_setting:'.$key);
        }

        $featureFlag = new FeatureFlag;
        if (Schema::hasTable($featureFlag->getTable())) {
            FeatureFlag::query()->whereIn('key', self::EF_FEATURE_FLAGS)->delete();
        }
    }

    public function down(): void
    {
        // Intentionally irreversible. Embedded finance was removed to match the
        // closed-loop platform description; the tables, flags, and their code are
        // gone, so there is nothing to recreate here.
    }
};

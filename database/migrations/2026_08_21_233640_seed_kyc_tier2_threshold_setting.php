<?php

use App\Models\SiteSetting;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The Tier 2 KYC threshold was promised as admin-tunable but was wired to
     * the legacy `settings` table, which has no admin UI, so it could only be
     * changed by hand. Seed it into site_settings where the Settings page can
     * edit it (Verification & Limits). Any hand-written legacy value is
     * migrated so a tuned production threshold survives the move.
     */
    public function up(): void
    {
        $default = '500';

        if (Schema::hasTable('settings')) {
            $legacy = DB::table('settings')->where('key', 'kyc_tier2_threshold_usd')->value('value');

            if (is_numeric(is_string($legacy) ? trim($legacy, '"') : $legacy)) {
                $default = (string) trim((string) $legacy, '"');
            }
        }

        SiteSetting::updateOrCreate(
            ['key' => 'features.kyc_tier2_threshold_usd'],
            [
                'group' => 'features',
                'value' => $default,
                'description' => 'USD amount that triggers KYC identity verification: a single order or funding at or above it, or lifetime volume crossing it. The risk engine\'s high-value signal uses the same number. Only enforced while kyc_enforce is on.',
            ],
        );
    }

    public function down(): void
    {
        SiteSetting::where('key', 'features.kyc_tier2_threshold_usd')->delete();
    }
};

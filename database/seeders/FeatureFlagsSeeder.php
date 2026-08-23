<?php

namespace Database\Seeders;

use App\Models\FeatureFlag;
use Illuminate\Database\Seeder;

class FeatureFlagsSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $flags = [
            'wallet.enabled',
            'wallet.crypto',
            'wallet.cashback',
            'wallet.rewards',
            'travel.enabled',
            'travel.flights',
            'travel.hotels',
            'travel.activities',
            'travel.insurance',
            'travel.transfers',
            'giftcards.enabled',
            'esim.enabled',
            'crypto.enabled',
            'notifications.push',
            'notifications.marketing',
            'notifications.promotional',
            'notifications.transactional',
            'admin.experimental',
            'security.trusted_devices',
            'security.customer_email_otp',
            'security.customer_2fa',
            'security.security_center',
            'security.session_management',
            'security.risk_engine',
            'security.future_passkeys',
        ];

        foreach ($flags as $flag) {
            FeatureFlag::firstOrCreate(
                ['key' => $flag],
                ['status' => FeatureFlag::STATUS_DISABLED]
            );
        }
    }
}

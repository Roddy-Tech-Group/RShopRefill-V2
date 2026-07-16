<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use App\Models\FeatureFlag;

class FeatureFlagsSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $flags = [
            'wallet.enabled',
            'wallet.withdrawals',
            'wallet.transfers',
            'wallet.fx',
            'wallet.virtual_cards',
            'wallet.physical_cards',
            'wallet.crypto',
            'wallet.bank_accounts',
            'wallet.cashback',
            'wallet.rewards',
            'wallet.loans',
            'wallet.investments',
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

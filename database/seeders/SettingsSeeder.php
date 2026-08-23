<?php

namespace Database\Seeders;

use App\Models\Setting;
use Illuminate\Database\Seeder;

class SettingsSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $settings = [
            // General Reward Engine
            ['key' => 'rcoin_enabled', 'value' => true, 'type' => 'boolean', 'description' => 'Enable or disable the RCOIN reward engine globally'],
            ['key' => 'rcoin_usd_rate', 'value' => 0.005, 'type' => 'float', 'description' => 'USD value of 1 RCOIN (0.005 = half a cent, so 2000 RCOIN = $10)'],
            ['key' => 'reward_reversal_enabled', 'value' => true, 'type' => 'boolean', 'description' => 'Reverse rewards when orders are refunded'],
            ['key' => 'fraud_hold_enabled', 'value' => false, 'type' => 'boolean', 'description' => 'Hold rewards for a period before they become available'],
            ['key' => 'fraud_hold_days', 'value' => 0, 'type' => 'integer', 'description' => 'Days to hold rewards if fraud_hold_enabled is true'],

            // Cashback (Buyer)
            ['key' => 'cashback_percentage', 'value' => 0.5, 'type' => 'float', 'description' => 'Percentage of order total awarded as cashback (0.5% = $1 spent earns 1 Rcoin worth $0.005)'],
            ['key' => 'max_daily_reward_per_user', 'value' => 5000, 'type' => 'integer', 'description' => 'Max RCOIN a user can earn per day (cashback + referral)'],
            ['key' => 'max_monthly_reward_per_user', 'value' => 50000, 'type' => 'integer', 'description' => 'Max RCOIN a user can earn per month'],

            // Referrals
            ['key' => 'referral_enabled', 'value' => true, 'type' => 'boolean', 'description' => 'Enable or disable the referral system'],
            ['key' => 'referral_reward_percentage', 'value' => 1.0, 'type' => 'float', 'description' => 'Percentage of referred user order total awarded to referrer (2× the cashback rate)'],
            ['key' => 'recurring_referral_rewards_enabled', 'value' => true, 'type' => 'boolean', 'description' => 'Reward referrer for every order the referred user makes'],
            ['key' => 'max_referral_rewards_per_user', 'value' => 0, 'type' => 'integer', 'description' => 'Max number of times a referrer can earn from a specific user (0 = unlimited)'],
            ['key' => 'max_referral_rewards_daily', 'value' => 1000, 'type' => 'integer', 'description' => 'Max RCOIN a referrer can earn from all referrals in a day'],
            ['key' => 'max_referral_rewards_monthly', 'value' => 10000, 'type' => 'integer', 'description' => 'Max RCOIN a referrer can earn from all referrals in a month'],

            // Withdrawal
            ['key' => 'withdrawal_enabled', 'value' => false, 'type' => 'boolean', 'description' => 'Allow users to withdraw RCOIN to fiat wallet'],
            ['key' => 'withdrawal_minimum_usd', 'value' => 10.00, 'type' => 'float', 'description' => 'Minimum USD value required to request a withdrawal'],
            ['key' => 'withdrawal_min_rcoin', 'value' => 2000, 'type' => 'integer', 'description' => 'Minimum RCOIN required to request a withdrawal'],
            ['key' => 'withdrawal_fee_percentage', 'value' => 0.0, 'type' => 'float', 'description' => 'Fee percentage applied to withdrawals'],
            ['key' => 'withdrawal_conversion_rate', 'value' => 0.005, 'type' => 'float', 'description' => 'Specific conversion rate applied during withdrawals (usually same as rcoin_usd_rate)'],

            // Wallet conversion - customers can swap Rcoin for fiat wallet
            // balance any time, without admin approval. Different from
            // withdrawal (which is Rcoin → external bank/momo and needs review).
            ['key' => 'wallet_conversion_enabled', 'value' => true, 'type' => 'boolean', 'description' => 'Allow users to convert Rcoin to wallet balance (instant, no admin approval)'],
            ['key' => 'wallet_conversion_min_usd', 'value' => 2.00, 'type' => 'float', 'description' => 'Minimum USD value a single conversion must produce. Stops dust conversions.'],

            // Redemption (Checkout)
            ['key' => 'redemption_enabled', 'value' => true, 'type' => 'boolean', 'description' => 'Allow users to spend RCOIN at checkout'],
            ['key' => 'redemption_min_rcoin', 'value' => 2000, 'type' => 'integer', 'description' => 'Minimum RCOIN required to use for a purchase'],
            ['key' => 'redemption_max_percentage', 'value' => 30.0, 'type' => 'float', 'description' => 'Maximum percentage of an order total that can be paid via RCOIN'],

            // Compliance & security - soft by default so flipping OFF stays a
            // deliberate product decision. Toggle ON to hard-gate withdrawals
            // behind verified KYC. Email verification at checkout is mandatory
            // and enforced by the EnsureEmailVerified middleware, not a setting.
            ['key' => 'require_kyc_for_withdrawal', 'value' => false, 'type' => 'boolean', 'description' => 'Require KYC-verified status before allowing Rcoin withdrawals'],
            ['key' => 'kyc_tier2_threshold_usd', 'value' => 500, 'type' => 'float', 'description' => 'USD amount at which a customer must complete identity (KYC) verification before higher-value transactions'],
        ];

        foreach ($settings as $setting) {
            Setting::set($setting['key'], $setting['value'], $setting['type'], $setting['description']);
        }
    }
}

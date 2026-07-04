<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Shared\Enums\Currency;
use App\Domain\Shared\Enums\TransactionCategory;
use App\Http\Controllers\Controller;
use App\Models\Referral;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use Illuminate\Support\Facades\DB;

class AdminRewardAnalyticsController extends Controller
{
    public function metrics()
    {
        $totalMinted = WalletTransaction::where('currency', Currency::RCOIN->value)
            ->whereIn('transaction_category', [
                TransactionCategory::RewardCashback->value,
                TransactionCategory::RewardReferral->value,
            ])
            ->sum('amount');

        $totalRedeemed = WalletTransaction::where('currency', Currency::RCOIN->value)
            ->where('transaction_category', TransactionCategory::RewardRedemption->value)
            ->sum('amount');

        $totalReversed = WalletTransaction::where('currency', Currency::RCOIN->value)
            ->where('transaction_category', TransactionCategory::RewardReversal->value)
            ->sum('amount');

        // Rcoin actually held by customers right now = sum of Rcoin wallet balances.
        // The source of truth; the ledger-derived (minted - redeemed - reversed) drifts
        // because reward_withdrawal / adjustment aren't part of that formula.
        $circulating = (float) Wallet::where('currency', Currency::RCOIN->value)->sum('balance');

        $topReferrers = Referral::select('referrer_id', DB::raw('COUNT(*) as total_referrals'), DB::raw('SUM(total_rewards_generated) as total_earned'))
            ->groupBy('referrer_id')
            ->orderByDesc('total_earned')
            ->with('referrer:id,first_name,last_name,email')
            ->limit(10)
            ->get();

        return response()->json([
            'metrics' => [
                'total_minted' => (int) $totalMinted,
                'total_redeemed' => (int) $totalRedeemed,
                'total_reversed' => (int) $totalReversed,
                'circulating_supply' => $circulating,
            ],
            'top_referrers' => $topReferrers,
        ]);
    }
}

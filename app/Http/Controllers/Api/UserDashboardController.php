<?php

namespace App\Http\Controllers\Api;

use App\Domain\Shared\Enums\FundingStatus;
use App\Domain\Wallet\Resources\WalletResource;
use App\Domain\Wallet\Resources\WalletTransactionResource;
use App\Http\Controllers\Controller;
use App\Models\WalletFunding;
use Illuminate\Http\Request;

class UserDashboardController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();
        $user->loadMissing('wallets');

        $wallets = $user->wallets ?? []; // Requires adding HasMany wallets to User model
        $recentTransactions = $user->walletTransactions()->latest()->take(5)->get();

        $recentFundings = WalletFunding::where('user_id', $user->id)
            ->latest()
            ->take(5)
            ->get();

        $pendingFundingTotal = (float) WalletFunding::where('user_id', $user->id)
            ->where('status', FundingStatus::Pending)
            ->sum('settled_amount_usd');

        return response()->json([
            'user' => [
                'name' => $user->name,
                'email' => $user->email,
            ],
            'wallets' => WalletResource::collection($wallets),
            'recent_transactions' => WalletTransactionResource::collection($recentTransactions),
            'recent_fundings' => $recentFundings,
            'pending_funding_total_usd' => $pendingFundingTotal,
        ]);
    }
}

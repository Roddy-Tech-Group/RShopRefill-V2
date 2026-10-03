<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Shared\Enums\FundingStatus;
use App\Domain\Wallet\Jobs\RetryFailedFundingVerificationJob;
use App\Domain\Wallet\Resources\WalletFundingResource;
use App\Domain\Wallet\Resources\WalletResource;
use App\Domain\Wallet\Resources\WalletTransactionResource;
use App\Http\Controllers\Controller;
use App\Models\ExchangeRate;
use App\Models\PaymentAttempt;
use App\Models\PaymentWebhook;
use App\Models\Wallet;
use App\Models\WalletFunding;
use App\Models\WalletTransaction;
use Illuminate\Http\Request;

class AdminFintechController extends Controller
{
    public function transactions(Request $request)
    {
        $transactions = WalletTransaction::with('user')
            ->when($request->queryString('currency'), fn ($q, $c) => $q->where('currency', strtoupper($c)))
            ->when($request->queryString('type'), fn ($q, $t) => $q->where('type', $t))
            ->latest()
            ->paginate((int) $request->query('per_page', 15));

        return WalletTransactionResource::collection($transactions);
    }

    public function fundings(Request $request)
    {
        $fundings = WalletFunding::with(['user', 'wallet'])
            ->when($request->queryString('status'), fn ($q, $s) => $q->where('status', $s))
            ->latest()
            ->paginate((int) $request->query('per_page', 15));

        return WalletFundingResource::collection($fundings);
    }

    public function wallets(Request $request)
    {
        $wallets = Wallet::with('user')
            ->when($request->queryString('currency'), fn ($q, $c) => $q->where('currency', strtoupper($c)))
            ->orderByDesc('balance')
            ->paginate((int) $request->query('per_page', 15));

        return WalletResource::collection($wallets);
    }

    public function paymentAttempts(Request $request)
    {
        $attempts = PaymentAttempt::with(['user'])
            ->when($request->queryString('gateway'), fn ($q, $g) => $q->where('gateway', $g))
            ->when($request->queryString('status'), fn ($q, $s) => $q->where('payment_status', $s))
            ->latest()
            ->paginate((int) $request->query('per_page', 15));

        return response()->json($attempts);
    }

    public function paymentWebhooks(Request $request)
    {
        $webhooks = PaymentWebhook::query()
            ->when($request->queryString('gateway'), fn ($q, $g) => $q->where('gateway', $g))
            ->when($request->queryString('processing_status'), fn ($q, $p) => $q->where('processing_status', $p))
            ->when($request->has('processed'), fn ($q) => $q->where('processed', $request->boolean('processed')))
            ->latest()
            ->paginate((int) $request->query('per_page', 15));

        return response()->json($webhooks);
    }

    public function pendingReconciliations(Request $request)
    {
        $pending = WalletFunding::with(['user', 'wallet'])
            ->where('status', FundingStatus::Pending)
            ->where('created_at', '<', now()->subHours(2))
            ->latest()
            ->paginate((int) $request->query('per_page', 15));

        return WalletFundingResource::collection($pending);
    }

    public function retryReconciliation(Request $request, int $id)
    {
        $funding = WalletFunding::findOrFail($id);

        // Dispatch manual verification retry job
        RetryFailedFundingVerificationJob::dispatch($funding->id);

        return response()->json([
            'message' => 'Manual verification retry queued successfully.',
            'funding_id' => $funding->id,
            'reference' => $funding->reference,
        ]);
    }

    public function metrics(Request $request)
    {
        $currencies = ['USD', 'NGN', 'EUR', 'GBP', 'BTC', 'USDT'];
        $balancesByCurrency = [];

        foreach ($currencies as $currency) {
            $balancesByCurrency[$currency] = (float) Wallet::where('currency', $currency)->sum('balance');
        }

        $fundingStats = [
            'total_deposits_count' => WalletFunding::count(),
            'successful_deposits_count' => WalletFunding::where('status', FundingStatus::Completed)->count(),
            'pending_deposits_count' => WalletFunding::where('status', FundingStatus::Pending)->count(),
            'failed_deposits_count' => WalletFunding::where('status', FundingStatus::Failed)->count(),
            'total_settled_usd' => (float) WalletFunding::where('status', FundingStatus::Completed)->sum('settled_amount_usd'),
        ];

        $webhookStats = [
            'total_received' => PaymentWebhook::count(),
            'failed_processing_count' => PaymentWebhook::where('processing_status', 'failed')->count(),
            'pending_processing_count' => PaymentWebhook::where('processing_status', 'pending')->count(),
        ];

        $exchangeRates = ExchangeRate::active()->latest()->take(20)->get();

        return response()->json([
            'wallet_balances' => $balancesByCurrency,
            'funding_statistics' => $fundingStats,
            'webhook_statistics' => $webhookStats,
            'latest_exchange_rates' => $exchangeRates,
        ]);
    }
}

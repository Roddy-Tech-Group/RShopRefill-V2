<?php

namespace App\Http\Controllers\Api;

use App\Domain\Shared\Enums\Currency;
use App\Domain\Wallet\Resources\WalletResource;
use App\Domain\Wallet\Services\WalletFundingService;
use App\Domain\Wallet\Services\WalletService;
use App\Http\Controllers\Controller;
use App\Http\Resources\PaymentSessionResource;
use App\Models\WalletFunding;
use Illuminate\Http\Request;
use Illuminate\Validation\Rules\Enum;

class UserWalletController extends Controller
{
    public function __construct(
        private readonly WalletService $walletService,
        private readonly WalletFundingService $fundingService,
    ) {}

    public function index(Request $request)
    {
        $user = $request->user();
        $user->loadMissing('wallets');
        
        $wallets = $user->wallets; // Assumes relation exists

        return WalletResource::collection($wallets);
    }

    public function show(Request $request, string $currency)
    {
        $currencyEnum = Currency::tryFrom(strtoupper($currency));
        if (! $currencyEnum) {
            return response()->json(['message' => 'Invalid currency.'], 400);
        }

        $wallet = $this->walletService->getOrCreateWallet($request->user(), $currencyEnum);

        return new WalletResource($wallet);
    }

    public function initiateFunding(Request $request)
    {
        $validated = $request->validate([
            'currency' => ['required', new Enum(Currency::class)],
            'amount' => ['required', 'numeric', 'min:1'],
            'display_currency' => ['nullable', 'string', 'max:10'],
        ]);

        $currency = Currency::from($validated['currency']);
        $wallet = $this->walletService->getOrCreateWallet($request->user(), $currency);
        $displayCurrency = $validated['display_currency'] ?? null;

        try {
            $fraudService = app(\App\Domain\Fraud\Services\FraudDetectionService::class);
            $usdRate = app(\App\Domain\Wallet\Services\CurrencyRateService::class)->resolveRate($currency->value, 'USD');
            $usdAmount = (float) $validated['amount'] * $usdRate;

            if ($fraudService->isSuspiciousCheckout($request->user(), $usdAmount, $request->ip())) {
                $msg = $request->user()->isKycVerified()
                    ? 'Your funding attempt was flagged by our security systems. Please contact support.'
                    : 'Your funding attempt was flagged by our security systems. Please complete KYC verification to proceed.';
                return response()->json(['message' => $msg], 403);
            }

            $result = $this->fundingService->initializeFunding(
                user: $request->user(),
                wallet: $wallet,
                amount: (float) $validated['amount'],
                currency: $currency,
                displayCurrency: $displayCurrency
            );

            return response()->json([
                'message' => 'Funding initialized successfully.',
                'payment_link' => null,
                'payment_session' => new PaymentSessionResource($result['payment_session']),
                'reference' => $result['funding']->reference,
                'requested_amount' => $result['funding']->requested_amount,
                'display_currency' => $result['funding']->display_currency,
                'exchange_rate' => $result['funding']->exchange_rate_snapshot,
            ]);
        } catch (\Exception $e) {
            return response()->json(['message' => $e->getMessage()], 400);
        }
    }

    public function fundings(Request $request)
    {
        $fundings = WalletFunding::where('user_id', $request->user()->id)
            ->orderBy('created_at', 'desc')
            ->paginate(15);

        return response()->json($fundings);
    }

    public function fundingDetails(Request $request, string $reference)
    {
        $funding = WalletFunding::where('user_id', $request->user()->id)
            ->where('reference', $reference)
            ->firstOrFail();

        return response()->json($funding);
    }
}

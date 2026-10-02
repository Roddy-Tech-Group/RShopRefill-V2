<?php

namespace App\Http\Controllers\Api;

use App\Domain\Kyc\Exceptions\KycStepUpRequiredException;
use App\Domain\Kyc\Exceptions\SpendingLimitExceededException;
use App\Domain\PaymentInsights\Exceptions\PaymentBackoffException;
use App\Domain\Risk\Exceptions\RiskDeniedException;
use App\Domain\Risk\Exceptions\RiskReviewRequiredException;
use App\Domain\Shared\Enums\Currency;
use App\Domain\Wallet\Resources\WalletResource;
use App\Domain\Wallet\Services\WalletFundingService;
use App\Domain\Wallet\Services\WalletService;
use App\Http\Controllers\Controller;
use App\Http\Resources\PaymentSessionResource;
use App\Models\WalletFunding;
use App\Rules\ChargeableCurrency;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
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
        if (is_string($request->input('display_currency'))) {
            $request->merge(['display_currency' => strtoupper(trim($request->input('display_currency')))]);
        }

        $validated = $request->validate([
            // A cash wallet. Rcoin is a rewards balance: it is earned and
            // redeemed, never bought, and it has no rate to charge at.
            'currency' => ['required', new Enum(Currency::class), Rule::notIn([Currency::RCOIN->value])],
            'amount' => ['required', 'numeric', 'min:1'],
            // The currency the customer is charged in. It must hold a live,
            // active rate: the lookup behind funding used to answer an unknown
            // code with the USD row, so a 50 USD top-up "paid in ZZZ" raised a
            // 50.00 ZZZ charge and the wallet was credited the full 50 USD.
            'display_currency' => ['nullable', 'string', 'size:3', new ChargeableCurrency],
        ]);

        $currency = Currency::from($validated['currency']);
        $wallet = $this->walletService->getOrCreateWallet($request->user(), $currency);
        $displayCurrency = $validated['display_currency'] ?? null;

        try {
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
        } catch (PaymentBackoffException $e) {
            // Paused or locked by the payment rate limiter: the same 429, wait
            // and lock flag checkout returns, rather than a bare 400 that hides
            // how long to wait.
            return $e->render($request);
        } catch (KycStepUpRequiredException $e) {
            // Crossed the identity-verification threshold; hand back the Smile
            // session so the client can launch verification, then retry.
            return response()->json($e->toResponse(), 422);
        } catch (SpendingLimitExceededException $e) {
            // Rolling transaction cap reached. Nothing was written; the message
            // nudges unverified customers toward KYC, which raises the cap.
            return response()->json($e->toResponse(), 422);
        } catch (RiskReviewRequiredException $e) {
            // Held for manual review; neutral message, nothing written.
            return response()->json($e->toResponse(), 422);
        } catch (RiskDeniedException $e) {
            // Refused by the risk engine; customer-safe message, no reason codes.
            return response()->json($e->toResponse(), 403);
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

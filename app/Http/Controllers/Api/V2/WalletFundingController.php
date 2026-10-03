<?php

namespace App\Http\Controllers\Api\V2;

use App\Domain\Shared\Enums\Currency;
use App\Domain\Wallet\Services\WalletFundingService;
use App\Domain\Wallet\Services\WalletService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V2\FundWalletRequest;
use App\Http\Resources\V2\PaymentSessionResource;
use App\Support\ApiMoney;
use App\Support\ApiV2PaymentFailures;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Adding money to a wallet.
 *
 * Behind the idempotency guard, and this is the endpoint that most needed it. The
 * storefront's equivalent has NO duplicate protection at all: two taps make two
 * funding records and two payment sessions, and a customer who pays both has funded
 * twice and is owed a refund. On a browser that is rare. On a phone that loses its
 * connection mid-request it is routine.
 *
 * Everything else is WalletFundingService's: the wallet-funding kill switch, the risk
 * gate, the spending cap and the identity threshold all live behind that one seam,
 * which is exactly why this calls it rather than assembling a funding here.
 */
class WalletFundingController extends Controller
{
    public function __construct(
        private WalletService $wallets,
        private WalletFundingService $funding,
    ) {}

    public function __invoke(FundWalletRequest $request): JsonResponse
    {
        $currency = Currency::from(strtoupper((string) $request->input('currency')));
        $amount = (float) $request->input('amount');

        $wallet = $this->wallets->getOrCreateWallet($request->user(), $currency);

        try {
            $result = $this->funding->initializeFunding(
                user: $request->user(),
                wallet: $wallet,
                amount: $amount,
                currency: $currency,
                displayCurrency: $request->input('display_currency'),
            );
        } catch (Throwable $e) {
            $envelope = ApiV2PaymentFailures::toEnvelope($e);

            if ($envelope !== null) {
                return $envelope;
            }

            // Unanticipated. Logged with the figures so it can be traced, and rethrown
            // rather than dressed up: a 5xx also releases the idempotency key, so the
            // customer can try again instead of being stuck on that intent for a day.
            Log::error('api.v2.wallet_funding_failed', [
                'user_id' => $request->user()->id,
                'currency' => $currency->value,
                'amount' => $amount,
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);

            throw $e;
        }

        $session = $result['payment_session'];

        return response()->json([
            'data' => [
                // What the customer quotes to support about this top-up.
                'reference' => $result['funding']->reference,

                // What is being added, in the wallet's own currency. Not converted:
                // this is a fact about the wallet, not a price.
                'amount' => ApiMoney::native($amount, $currency->value),

                // How to pay for it.
                'payment' => (new PaymentSessionResource(
                    $session->loadMissing('paymentAttempt.order')
                ))->resolve(),
            ],
        ], 201);
    }
}

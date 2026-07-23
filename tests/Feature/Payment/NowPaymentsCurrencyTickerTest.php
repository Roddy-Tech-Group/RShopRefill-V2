<?php

namespace Tests\Feature\Payment;

use App\Domain\Payment\Providers\NowPaymentsProvider;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Guards the coin-code -> NOWPayments-ticker normalization. NOWPayments rejects
 * the bare "bnb" and "usdt" tickers as invalid currencies, so the provider must
 * translate them (bnb -> bnbbsc, usdt -> usdttrc20) before every outbound call.
 * Regression cover for the production "payment API rejected bnb" errors.
 */
class NowPaymentsCurrencyTickerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // A non-MOCK key forces the provider down the real HTTP path so the
        // faked client intercepts the outgoing request (mock mode never calls out).
        config([
            'services.nowpayments.api_key' => 'NOWPAYMENTS_LIVE_TEST_KEY',
            'services.nowpayments.base_url' => 'https://api.nowpayments.io/v1',
        ]);
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function tickerProvider(): array
    {
        return [
            'BNB normalizes to bnbbsc (bare bnb is rejected)' => ['BNB', 'bnbbsc'],
            'USDT defaults to the TRC20 chain' => ['USDT', 'usdttrc20'],
            'BTC passes through untouched' => ['BTC', 'btc'],
        ];
    }

    #[DataProvider('tickerProvider')]
    public function test_estimate_sends_normalized_pay_currency_ticker(string $input, string $expected): void
    {
        Http::fake([
            'api.nowpayments.io/*' => Http::response(['estimated_amount' => 1.23], 200),
        ]);

        (new NowPaymentsProvider)->estimatePayment(100.0, 'USD', $input);

        Http::assertSent(function ($request) use ($expected) {
            return str_contains($request->url(), '/estimate')
                && str_contains($request->url(), 'currency_to='.$expected);
        });
    }
}

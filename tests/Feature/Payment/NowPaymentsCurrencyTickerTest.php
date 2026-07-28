<?php

namespace Tests\Feature\Payment;

use App\Domain\Payment\Providers\NowPaymentsProvider;
use App\Models\Order;
use App\Models\PaymentAttempt;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
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
    use RefreshDatabase;

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
            'USDC passes through as the ERC-20 ticker (no Tron chain exists)' => ['USDC', 'usdc'],
            'USDCSOL passes through untouched' => ['USDCSOL', 'usdcsol'],
            'USDCBASE passes through untouched' => ['USDCBASE', 'usdcbase'],
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

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function usdcNetworkProvider(): array
    {
        return [
            'USDC resolves to the ethereum network' => ['usdc', 'ethereum'],
            'USDCSOL resolves to the solana network' => ['usdcsol', 'solana'],
            'USDCBASE resolves to the base network' => ['usdcbase', 'base'],
        ];
    }

    /** The checkout QR + network label read chargeCrypto's resolved network. */
    #[DataProvider('usdcNetworkProvider')]
    public function test_charge_crypto_resolves_usdc_chain_networks(string $payCurrency, string $expectedNetwork): void
    {
        // Mock mode is now decided by environment policy (no key + local/testing),
        // not by looking for "MOCK" inside the key — a real production key containing
        // that substring used to silently disable the live gateway. Clearing the key
        // is what selects the offline path; network resolution is identical on the
        // live path (same resolveNetwork call).
        config(['services.nowpayments.api_key' => null]);

        $user = User::factory()->create();
        $order = Order::create([
            'user_id' => $user->id,
            'order_number' => 'ORD-'.Str::upper(Str::random(8)),
            'payment_method' => 'crypto',
        ]);
        $attempt = PaymentAttempt::create([
            'order_id' => $order->id,
            'user_id' => $user->id,
            'gateway' => 'nowpayments',
            'idempotency_key' => (string) Str::uuid(),
            'currency' => 'USD',
            'amount' => 20,
        ]);

        $result = (new NowPaymentsProvider)->chargeCrypto($attempt, $payCurrency);

        $this->assertSame($expectedNetwork, $result['network']);
        $this->assertSame($payCurrency, $result['pay_currency']);
    }
}

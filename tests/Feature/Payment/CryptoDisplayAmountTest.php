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
 * The figure shown to the customer is the gateway's quote rounded UP.
 *
 * The gateway quotes to full precision — 11.655811 USDT for an $11.20 order.
 * Nobody types that; they type 11.65, which is short by half a cent, and under
 * zero tolerance that holds the order. The shortfall is then far below every
 * coin's minimum, so even the top-up flow cannot rescue it and the customer is
 * stranded over fractions of a cent.
 *
 * Rounding up removes the problem rather than tolerating it: paying exactly what
 * we display always clears what the gateway requires, and the few thousandths of
 * surplus are recorded as a fractional overpayment. No tolerance is introduced.
 */
class CryptoDisplayAmountTest extends TestCase
{
    use RefreshDatabase;

    private function attempt(float $amount = 11.20): PaymentAttempt
    {
        $user = User::factory()->create();
        $order = Order::create([
            'user_id' => $user->id,
            'order_number' => 'ORD-'.Str::upper(Str::random(8)),
            'payment_method' => 'crypto',
        ]);

        return PaymentAttempt::create([
            'order_id' => $order->id,
            'user_id' => $user->id,
            'gateway' => 'nowpayments',
            'idempotency_key' => (string) Str::uuid(),
            'currency' => 'USD',
            'amount' => $amount,
        ]);
    }

    /**
     * One charge per test case.
     *
     * Http::fake() appends stubs rather than replacing them, so calling it twice
     * in one test leaves the first stub matching — every call then returns the
     * same payment_id and collides on the unique gateway_reference index. Data
     * providers keep each case to a single call and sidestep that entirely.
     *
     * @return array<string, mixed>
     */
    private function charge(float $gatewayQuote, string $coin = 'usdtsol'): array
    {
        config(['services.nowpayments.api_key' => 'live-key-xyz']);

        Http::fake([
            '*' => Http::response([
                'payment_id' => (string) Str::uuid(),
                'pay_address' => '9WzDXwBbmkg8ZTbNMqUxvQRAyrZzDsGYdLVL9zYtAWWM',
                'pay_amount' => $gatewayQuote,
                'network' => 'sol',
                'expiration_estimate_date' => now()->addMinutes(20)->toIso8601String(),
            ], 200),
        ]);

        return (new NowPaymentsProvider)->chargeCrypto($this->attempt(), $coin);
    }

    /** The exact case from production: $11.20 quoted at 11.655811. */
    public function test_it_rounds_a_stablecoin_quote_up_to_two_decimals(): void
    {
        $result = $this->charge(11.655811);

        $this->assertSame('11.66', $result['pay_amount']);
        $this->assertSame('11.655811', $result['exact_pay_amount']);
    }

    /**
     * @return array<string, array{0: float}>
     */
    public static function quoteProvider(): array
    {
        return [
            'production case' => [11.655811],
            'just over a whole unit' => [5.001],
            'rounds across the unit boundary' => [99.999999],
            'already exact' => [1.0],
            'four decimals' => [250.4444],
        ];
    }

    /** Paying the displayed figure must always satisfy the gateway. */
    #[DataProvider('quoteProvider')]
    public function test_the_displayed_amount_always_clears_the_gateway_quote(float $quote): void
    {
        $result = $this->charge($quote);

        $this->assertGreaterThanOrEqual(
            $quote,
            (float) $result['pay_amount'],
            "Displayed amount for {$quote} was below the gateway quote"
        );
    }

    /**
     * Sub-unit amounts keep 8 decimals. Coarsening a BTC quote to 2 places would
     * turn a rounding nicety into a real overpayment.
     */
    public function test_it_preserves_precision_on_sub_unit_amounts(): void
    {
        $result = $this->charge(0.00017234, 'btc');

        $this->assertSame('0.00017234', $result['pay_amount']);
        $this->assertLessThan(0.000001, (float) $result['pay_amount'] - 0.00017234);
    }

    /** The overpayment introduced by rounding must stay negligible. */
    public function test_the_rounding_surplus_is_negligible(): void
    {
        $result = $this->charge(11.655811);
        $surplus = (float) $result['pay_amount'] - 11.655811;

        $this->assertLessThan(0.01, $surplus);
        $this->assertLessThan(0.1, ($surplus / 11.655811) * 100, 'Rounding surplus exceeded 0.1% of the quote');
    }

    /** The QR encodes what is displayed, so a scanned payment settles in full. */
    public function test_the_qr_payload_carries_the_displayed_amount(): void
    {
        $result = $this->charge(11.655811);

        $this->assertStringContainsString('amount=11.66', $result['qr_payload']);
        $this->assertStringNotContainsString('11.655811', $result['qr_payload']);
    }

    /**
     * @return array<string, array{0: float, 1: string}>
     */
    public static function trailingZeroProvider(): array
    {
        return [
            'whole number drops the decimals' => [12.0, '12'],
            'one decimal keeps one' => [12.5, '12.5'],
        ];
    }

    /** Trailing zeros are noise on a figure someone copies by hand. */
    #[DataProvider('trailingZeroProvider')]
    public function test_it_strips_trailing_zeros(float $quote, string $expected): void
    {
        $this->assertSame($expected, $this->charge($quote)['pay_amount']);
    }

    /**
     * The settlement yardstick stays the gateway's exact figure — rounding is a
     * presentation concern and must never move what we compare against.
     */
    public function test_the_stored_pay_amount_remains_the_exact_gateway_quote(): void
    {
        config(['services.nowpayments.api_key' => 'live-key-xyz']);
        $attempt = $this->attempt();

        Http::fake([
            '*' => Http::response([
                'payment_id' => '9911223344',
                'pay_address' => 'addr',
                'pay_amount' => 11.655811,
                'network' => 'sol',
                'expiration_estimate_date' => now()->addMinutes(20)->toIso8601String(),
            ], 200),
        ]);

        (new NowPaymentsProvider)->chargeCrypto($attempt, 'usdtsol');

        $this->assertSame(11.655811, (float) $attempt->fresh()->pay_amount);
    }
}

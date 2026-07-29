<?php

namespace Tests\Feature\Payment;

use App\Domain\Payment\Enums\PaymentStatus;
use App\Models\CurrencyRate;
use App\Models\Order;
use App\Models\PaymentAttempt;
use App\Models\PaymentSession;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Per-coin minimums must be enforced before a payment is created.
 *
 * Minimums differ by more than two orders of magnitude across the coin set and
 * float with network conditions, so without this a customer commits to a payment
 * method and then meets a raw gateway rejection. The picker hides unusable coins,
 * but the server is the authority — the client list can be stale, and it is
 * trivially bypassed.
 */
class CryptoMinimumEnforcementTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.nowpayments.api_key' => null]);
        $this->user = User::factory()->create();

        // updateOrCreate, not create: the crypto-launch migration already seeds
        // these codes, so a plain insert collides on the unique code index.
        $rows = [
            ['code' => 'USD', 'name' => 'United States Dollar', 'type' => 'fiat',
                'rate_per_usd' => 1.0, 'is_active' => true],
            ['code' => 'USDTTRC20', 'name' => 'Tether (Tron)', 'type' => 'crypto',
                'rate_per_usd' => 1.0, 'is_active' => true, 'is_payable' => true,
                'network' => 'tron', 'min_amount_usd' => 17.70],
            ['code' => 'USDTBSC', 'name' => 'Tether (BNB Chain)', 'type' => 'crypto',
                'rate_per_usd' => 1.0, 'is_active' => true, 'is_payable' => true,
                'network' => 'bnb', 'min_amount_usd' => 12.22],
            ['code' => 'LTC', 'name' => 'Litecoin', 'type' => 'crypto',
                'rate_per_usd' => 0.01, 'is_active' => true, 'is_payable' => false,
                'network' => 'litecoin', 'min_amount_usd' => 12.19],
            ['code' => 'SOL', 'name' => 'Solana', 'type' => 'crypto',
                'rate_per_usd' => 0.006, 'is_active' => true, 'is_payable' => true,
                'network' => 'solana', 'min_amount_usd' => null],
        ];

        foreach ($rows as $row) {
            CurrencyRate::updateOrCreate(['code' => $row['code']], $row);
        }

        // Everything else the migration seeded would otherwise widen the payable
        // scope assertions beyond the fixtures under test.
        CurrencyRate::query()->crypto()
            ->whereNotIn('code', ['USDTTRC20', 'USDTBSC', 'LTC', 'SOL'])
            ->update(['is_active' => false]);
    }

    private function cryptoSession(float $amount): PaymentSession
    {
        $order = Order::create([
            'user_id' => $this->user->id,
            'order_number' => 'ORD-'.Str::upper(Str::random(8)),
            'payment_method' => 'crypto',
        ]);
        $attempt = PaymentAttempt::create([
            'order_id' => $order->id,
            'user_id' => $this->user->id,
            'gateway' => 'crypto',
            'idempotency_key' => (string) Str::uuid(),
            'currency' => 'USD',
            'amount' => $amount,
            'payment_status' => PaymentStatus::Pending,
        ]);

        return PaymentSession::create([
            'payment_attempt_id' => $attempt->id,
            'provider' => 'crypto',
            'session_type' => 'crypto',
            'status' => 'awaiting_method',
            'client_reference' => 'SESS_'.Str::random(40),
            'amount' => $amount,
            'currency' => 'USD',
            'display_currency' => 'USD',
            'exchange_rate_snapshot' => 1.0,
            'payment_payload' => [],
            'customer_email' => $this->user->email,
            'expires_at' => now()->addMinutes(20),
        ]);
    }

    private function pay(PaymentSession $session, string $coin)
    {
        return $this->actingAs($this->user)->postJson(
            route('api.payment-sessions.pay', $session->id),
            ['method' => 'crypto', 'details' => ['pay_currency' => $coin]]
        );
    }

    public function test_it_rejects_a_coin_whose_minimum_exceeds_the_order(): void
    {
        Http::fake();
        $response = $this->pay($this->cryptoSession(5.00), 'USDTTRC20');

        $response->assertStatus(422);
        $this->assertStringContainsString('at least $17.70', $response->json('message'));
        // Nothing may be created at the gateway for a payment we already know fails.
        Http::assertNothingSent();
    }

    public function test_it_allows_a_coin_whose_minimum_is_met(): void
    {
        $this->pay($this->cryptoSession(25.00), 'USDTBSC')->assertOk();
    }

    public function test_it_rejects_a_coin_the_gateway_no_longer_accepts(): void
    {
        $response = $this->pay($this->cryptoSession(50.00), 'LTC');

        $response->assertStatus(422);
        $this->assertStringContainsString('temporarily unavailable', $response->json('message'));
    }

    public function test_it_rejects_an_unknown_coin(): void
    {
        $this->pay($this->cryptoSession(50.00), 'DOGECOIN')->assertStatus(422);
    }

    /**
     * An unsynced minimum must not block a payment the gateway would accept —
     * a failed sync emptying the checkout is worse than the gateway rejecting a
     * genuinely sub-minimum payment.
     */
    public function test_an_unknown_minimum_fails_open(): void
    {
        $this->pay($this->cryptoSession(1.00), 'SOL')->assertOk();
    }

    public function test_the_model_gate_matches_the_controller(): void
    {
        $tron = CurrencyRate::where('code', 'USDTTRC20')->first();
        $this->assertFalse($tron->acceptsOrderValue(17.69));
        $this->assertTrue($tron->acceptsOrderValue(17.70));

        $sol = CurrencyRate::where('code', 'SOL')->first();
        $this->assertTrue($sol->acceptsOrderValue(0.01));
    }

    public function test_payable_scope_excludes_gateway_disabled_coins(): void
    {
        $codes = CurrencyRate::query()->payable()->crypto()->pluck('code')->all();

        $this->assertContains('USDTBSC', $codes);
        $this->assertNotContains('LTC', $codes);
    }
}

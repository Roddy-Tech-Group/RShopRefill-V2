<?php

namespace Tests\Feature\Payment;

use App\Domain\Payment\Enums\PaymentStatus;
use App\Models\Order;
use App\Models\PaymentAttempt;
use App\Models\PaymentSession;
use App\Models\SiteSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Recovery path for an underpaid crypto order.
 *
 * Because a short payment is never auto-fulfilled, a customer whose exchange
 * deducted its withdrawal fee has paid, received nothing, and — without this —
 * has no self-service way to make up the difference. Most shortfalls are not the
 * customer's fault, so stranding them is not an acceptable outcome.
 */
class CryptoTopUpTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.nowpayments.api_key' => 'live-key-xyz']);
        $this->user = User::factory()->create();
    }

    /**
     * @return array{0: PaymentSession, 1: PaymentAttempt}
     */
    private function underpaidSession(bool $withPurchaseId = true): array
    {
        $order = Order::create([
            'user_id' => $this->user->id,
            'order_number' => 'ORD-'.Str::upper(Str::random(8)),
            'payment_method' => 'crypto',
        ]);

        $attempt = PaymentAttempt::create([
            'order_id' => $order->id,
            'user_id' => $this->user->id,
            'gateway' => 'nowpayments',
            'gateway_reference' => '5519782510',
            'idempotency_key' => (string) Str::uuid(),
            'currency' => 'USD',
            'amount' => 100,
            'payment_status' => PaymentStatus::PartiallyPaid,
            'pay_currency' => 'usdtbsc',
            'pay_amount' => 100,
            'actually_paid' => 96,
            'settlement_delta' => -4,
            'network' => 'bnb',
            'pay_address' => '0xdeadbeef',
            'purchase_id' => $withPurchaseId ? '778899' : null,
        ]);

        $session = PaymentSession::create([
            'payment_attempt_id' => $attempt->id,
            'provider' => 'nowpayments',
            'session_type' => 'crypto',
            'status' => 'awaiting_transfer',
            'client_reference' => 'SESS_'.Str::random(40),
            'amount' => 100,
            'currency' => 'USD',
            'display_currency' => 'USD',
            'exchange_rate_snapshot' => 1.0,
            'payment_payload' => ['pay_address' => '0xdeadbeef'],
            'customer_email' => $this->user->email,
            'expires_at' => now()->addMinutes(20),
        ]);

        return [$session, $attempt];
    }

    public function test_the_status_poll_reports_the_outstanding_balance(): void
    {
        [$session] = $this->underpaidSession();

        $response = $this->actingAs($this->user)
            ->getJson(route('api.payment-sessions.status', $session->id));

        $response->assertOk();
        $this->assertSame('4', $response->json('underpayment.shortfall'));
        $this->assertSame('USDTBSC', $response->json('underpayment.currency'));
        $this->assertTrue($response->json('underpayment.can_top_up'));
    }

    /**
     * The shortfall is derived in crypto terms and applied proportionally to the
     * original fiat price — 4 of 100 short means a 4% balance, so $4 of a $100 order.
     */
    public function test_it_creates_a_top_up_payment_for_the_shortfall(): void
    {
        [$session, $attempt] = $this->underpaidSession();

        Http::fake([
            '*' => Http::response([
                'payment_id' => 'topup-999',
                'pay_address' => '0xnewaddress',
                'pay_amount' => 4.02,
                'network' => 'bnb',
                'purchase_id' => '778899',
                'expiration_estimate_date' => now()->addMinutes(20)->toIso8601String(),
            ], 200),
        ]);

        $this->actingAs($this->user)
            ->postJson(route('api.payment-sessions.top-up', $session->id))
            ->assertOk();

        Http::assertSent(function ($request) {
            $body = $request->data();

            return str_contains($request->url(), '/payment')
                && $body['purchase_id'] === '778899'
                && abs($body['price_amount'] - 4.0) < 0.01
                && $body['is_fixed_rate'] === true;
        });

        $fresh = $attempt->fresh();
        // The new payment id must own the attempt so its IPN matches.
        $this->assertSame('topup-999', $fresh->gateway_reference);
        $this->assertSame('0xnewaddress', $fresh->pay_address);
        // Pending, not PartiallyPaid — otherwise the reconciler skips it.
        $this->assertSame(PaymentStatus::Pending, $fresh->payment_status);
    }

    /**
     * A top-up is a fresh crypto charge. With the crypto gateway switched off
     * it must be refused like any other new charge, not slip past because the
     * first payment happened while the gateway was still on.
     */
    public function test_it_refuses_a_top_up_while_the_crypto_gateway_is_switched_off(): void
    {
        [$session, $attempt] = $this->underpaidSession();
        SiteSetting::put('features.gateway_nowpayments_enabled', 'off', 'features');
        Http::fake();

        $this->actingAs($this->user)
            ->postJson(route('api.payment-sessions.top-up', $session->id))
            ->assertStatus(503)
            ->assertJson(['gateway_disabled' => true]);

        Http::assertNothingSent();
        $this->assertSame(PaymentStatus::PartiallyPaid, $attempt->fresh()->payment_status, 'the shortfall is still owed, nothing was reset');
    }

    public function test_it_refuses_a_top_up_when_nothing_is_outstanding(): void
    {
        [$session, $attempt] = $this->underpaidSession();
        $attempt->update(['payment_status' => PaymentStatus::Paid]);

        $this->actingAs($this->user)
            ->postJson(route('api.payment-sessions.top-up', $session->id))
            ->assertStatus(422);
    }

    /**
     * Without a purchase_id the gateway cannot bind the second payment to the
     * first, so we would be creating an unrelated invoice that reconciles as a
     * separate purchase.
     */
    public function test_it_refuses_a_top_up_without_a_purchase_id(): void
    {
        [$session] = $this->underpaidSession(withPurchaseId: false);

        $response = $this->actingAs($this->user)
            ->postJson(route('api.payment-sessions.top-up', $session->id));

        $response->assertStatus(422);
        $this->assertStringContainsString('contact support', $response->json('message'));
    }

    public function test_another_customer_cannot_top_up_someone_elses_payment(): void
    {
        [$session] = $this->underpaidSession();
        $intruder = User::factory()->create();

        $this->actingAs($intruder)
            ->postJson(route('api.payment-sessions.top-up', $session->id))
            ->assertStatus(403);
    }
}

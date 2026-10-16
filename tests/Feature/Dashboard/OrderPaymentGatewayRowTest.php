<?php

namespace Tests\Feature\Dashboard;

use App\Models\Order;
use App\Models\PaymentAttempt;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Tests\TestCase;

/**
 * Under "Payment method" an order says which channel the money went through,
 * in customer words. The order's own method cannot tell card from mobile
 * money; the paid attempt can. Provider names never appear.
 */
class OrderPaymentGatewayRowTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_row_shows_the_paid_attempts_channel(): void
    {
        $user = User::factory()->create();
        $order = Order::factory()->create(['user_id' => $user->id, 'payment_method' => 'flutterwave']);
        $this->attempt($order, ['payment_method' => 'mobile_money', 'payment_status' => 'paid']);
        $this->actingAs($user);

        Volt::test('dashboard.orders')
            ->assertSeeHtml('<dt class="text-zinc-500">Payment method</dt>')
            ->assertSee('Mobile Money')
            // One row, named for what it is: the old second row called the
            // channel a "gateway" while the first called this payment "Card".
            ->assertDontSeeHtml('<dt class="text-zinc-500">Payment gateway</dt>')
            ->assertDontSeeHtml('<dd class="font-semibold text-zinc-900">Card</dd>')
            ->assertDontSee('flutterwave');
    }

    public function test_the_paid_attempt_wins_over_a_later_failed_one(): void
    {
        $order = Order::factory()->create(['payment_method' => 'flutterwave']);
        $this->travelTo(now()->subMinutes(5));
        $this->attempt($order, ['payment_method' => 'mobile_money', 'payment_status' => 'paid']);
        $this->travelBack();
        $this->attempt($order, ['payment_method' => 'card', 'payment_status' => 'failed']);

        $this->assertSame('Mobile Money', $order->fresh()->customerGatewayLabel());
    }

    public function test_without_a_paid_attempt_the_latest_attempt_answers(): void
    {
        $order = Order::factory()->create(['payment_method' => 'flutterwave']);
        $this->travelTo(now()->subMinutes(5));
        $this->attempt($order, ['payment_method' => 'card', 'payment_status' => 'failed']);
        $this->travelBack();
        $this->attempt($order, ['payment_method' => 'bank_transfer', 'payment_status' => 'pending']);

        $this->assertSame('Bank transfer', $order->fresh()->customerGatewayLabel());
    }

    public function test_an_unrecorded_method_is_read_from_the_gateway_payload_then_the_gateway_family(): void
    {
        $fromPayload = Order::factory()->create(['payment_method' => 'flutterwave']);
        $this->attempt($fromPayload, [
            'payment_method' => null,
            'payment_status' => 'paid',
            'verification_payload' => ['data' => ['payment_type' => 'mobilemoneyghana']],
        ]);

        $fromFamily = Order::factory()->create(['payment_method' => 'crypto']);
        $this->attempt($fromFamily, ['gateway' => 'nowpayments', 'payment_method' => null, 'payment_status' => 'pending']);

        $this->assertSame('Mobile Money', $fromPayload->fresh()->customerGatewayLabel());
        $this->assertSame('Crypto', $fromFamily->fresh()->customerGatewayLabel());
    }

    public function test_an_order_with_no_attempt_answers_from_its_own_method(): void
    {
        $this->assertSame('Wallet', Order::factory()->create(['payment_method' => 'wallet'])->customerGatewayLabel());
        $this->assertSame('Crypto', Order::factory()->create(['payment_method' => 'crypto'])->customerGatewayLabel());
        $this->assertSame('Card', Order::factory()->create(['payment_method' => 'flutterwave'])->customerGatewayLabel());
        $this->assertSame('Card', Order::factory()->create(['payment_method' => 'card'])->customerGatewayLabel());
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function attempt(Order $order, array $overrides = []): PaymentAttempt
    {
        return PaymentAttempt::create(array_merge([
            'order_id' => $order->id,
            'user_id' => $order->user_id,
            'gateway' => 'flutterwave',
            'gateway_reference' => 'REF-'.fake()->unique()->numerify('######'),
            'idempotency_key' => 'idem-'.fake()->unique()->numerify('######'),
            'currency' => 'USD',
            'amount' => 12.00,
            'payment_status' => 'pending',
        ], $overrides));
    }
}

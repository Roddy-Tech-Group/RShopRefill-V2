<?php

namespace Tests\Feature\Shop;

use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A failed order's headline used to say "no charge was taken" whatever had
 * happened. When the payment went through and delivery failed, the money
 * went back to the wallet, and a customer who just paid XAF 9,830 was told
 * they had not been charged.
 */
class OrderPageFailedCopyTest extends TestCase
{
    use RefreshDatabase;

    /** @param  array<string, mixed>  $overrides */
    private function failedOrder(array $overrides = []): array
    {
        $user = User::factory()->create();
        $order = Order::factory()->create(array_merge([
            'user_id' => $user->id,
            'order_status' => 'failed',
            'display_currency' => 'XAF',
            'total_amount' => 9830,
        ], $overrides));

        return [$user, $order];
    }

    public function test_a_paid_then_refunded_order_says_the_money_went_back_to_the_wallet(): void
    {
        [$user, $order] = $this->failedOrder(['payment_status' => 'paid', 'metadata' => ['refunded_total' => 9830]]);

        $this->withoutVite()->actingAs($user)
            ->get(route('shop.order', $order->order_number))
            ->assertOk()
            ->assertSee(__('orders.hero_failed_refunded_line', ['amount' => 'XAF 9,830.00']))
            ->assertDontSee(__('orders.hero_failed_line'));
    }

    public function test_an_order_whose_payment_never_went_through_keeps_the_no_charge_line(): void
    {
        [$user, $order] = $this->failedOrder(['payment_status' => 'failed']);

        $this->withoutVite()->actingAs($user)
            ->get(route('shop.order', $order->order_number))
            ->assertOk()
            ->assertSee(__('orders.hero_failed_line'))
            ->assertDontSee('refunded to your RShopRefill wallet');
    }
}

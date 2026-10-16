<?php

namespace Tests\Feature\Shop;

use App\Models\Order;
use App\Models\PaymentAttempt;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * The order page's detailed view (every state but completed) names the
 * channel the customer paid through. The order's own column names a gateway
 * family, and the page used to fold the whole family into "Card", so a
 * mobile money payment read "Payment method: Card".
 */
class OrderPagePaymentMethodTest extends TestCase
{
    use RefreshDatabase;

    private function paidOrder(string $gateway, string $method): array
    {
        $user = User::factory()->create();
        $order = Order::factory()->create(['user_id' => $user->id, 'payment_method' => $gateway, 'payment_status' => 'paid', 'order_status' => 'processing']);
        PaymentAttempt::create([
            'order_id' => $order->id,
            'user_id' => $user->id,
            'gateway' => $gateway,
            'payment_method' => $method,
            'gateway_reference' => $ref = 'PAY-'.Str::lower(Str::random(12)),
            'idempotency_key' => $ref,
            'currency' => 'XAF',
            'amount' => 6560,
            'payment_status' => 'paid',
        ]);

        return [$user, $order];
    }

    public function test_a_mobile_money_payment_is_called_mobile_money_not_card(): void
    {
        [$user, $order] = $this->paidOrder('korapay', 'mobile_money');

        $this->assertSame('Mobile Money', $this->paymentMethodRow($user, $order));
        $this->assertSame('mobile_money', $order->fresh()->customerMethodKey());
    }

    public function test_a_card_payment_is_still_called_card(): void
    {
        [$user, $order] = $this->paidOrder('flutterwave', 'card');

        $this->assertSame('Card', $this->paymentMethodRow($user, $order));
    }

    /** The text of the "Payment method" row on the rendered order page. */
    private function paymentMethodRow(User $user, Order $order): string
    {
        $html = $this->withoutVite()->actingAs($user)->get(route('shop.order', $order->order_number))->assertOk()->getContent();
        $this->assertStringNotContainsString('korapay', $html, 'the gateway name never reaches the customer');

        $labelText = __('checkout.payment_method');
        $at = strpos($html, $labelText.'</dt>');
        $this->assertNotFalse($at, 'the Payment method label is missing; the page has '.substr_count($html, $labelText).' mention(s) of it');
        $snippet = substr($html, $at, 400);
        $this->assertTrue((bool) preg_match('/<dd[^>]*>([^<]*)<\/dd>/', $snippet, $m), 'no value cell after the label: '.preg_replace('/\s+/', ' ', $snippet));

        return trim($m[1]);
    }
}

<?php

namespace Tests\Feature\Payment;

use App\Domain\Payment\Enums\PaymentStatus;
use App\Domain\Payment\Support\SupportedPaymentMethods;
use App\Models\Order;
use App\Models\PaymentAttempt;
use App\Models\PaymentSession;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Checkout and the pay step must accept exactly the same payment methods.
 *
 * When PaymentSessionController::pay held a shorter allow-list than
 * CheckoutController, a customer could select USSD / Pay With Bank / Bank QR /
 * Mobile Wallet on the checkout page, get a real RSR- order plus a
 * PaymentAttempt, and then be told at the pay step that the method was "not
 * available for currency" - an order that could never be paid by any means.
 */
class PaymentMethodAvailabilityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.flutterwave.secret_key' => 'FLW_SECRET_KEY_MOCK',
            'services.flutterwave.public_key' => 'FLW_PUB_KEY_MOCK',
        ]);
    }

    /**
     * Build the exact records checkout leaves behind: an order awaiting payment
     * with a live session the customer is about to pay.
     *
     * @return array{0: User, 1: PaymentSession}
     */
    private function orderAwaitingPayment(string $currency): array
    {
        $user = User::factory()->create();

        $order = Order::create([
            'user_id' => $user->id,
            'order_number' => 'RSR-'.Str::upper(Str::random(8)),
            'display_currency' => $currency,
            'payment_method' => 'flutterwave',
            'payment_status' => PaymentStatus::Unpaid,
            'total_amount' => 5000,
        ]);

        $attempt = PaymentAttempt::create([
            'order_id' => $order->id,
            'user_id' => $user->id,
            'gateway' => 'flutterwave',
            'idempotency_key' => (string) Str::uuid(),
            'currency' => $currency,
            'amount' => 5000,
            'payment_status' => PaymentStatus::Pending,
        ]);

        $session = PaymentSession::create([
            'payment_attempt_id' => $attempt->id,
            'provider' => 'flutterwave',
            'session_type' => 'card',
            'status' => 'awaiting_method',
            'client_reference' => 'SESS_'.Str::random(20),
            'amount' => 5000,
            'currency' => $currency,
            'display_currency' => $currency,
            'customer_email' => $user->email,
            'expires_at' => now()->addMinutes(15),
        ]);

        return [$user, $session];
    }

    /**
     * The four hosted-redirect methods the NGN checkout page offers. Each one
     * created an unpayable order before the allow-lists were unified.
     */
    public static function hostedNgnMethods(): array
    {
        return [
            'USSD' => ['ussd'],
            'Pay With Bank' => ['pay_with_bank'],
            'Bank QR' => ['bank_qr'],
            'Mobile Wallet' => ['mobile_wallet'],
        ];
    }

    #[DataProvider('hostedNgnMethods')]
    public function test_ngn_order_can_be_paid_with_every_method_checkout_offers(string $method): void
    {
        [$user, $session] = $this->orderAwaitingPayment('NGN');

        $response = $this->actingAs($user)->postJson(
            route('api.payment-sessions.pay', $session->id),
            ['method' => $method],
        );

        $response->assertStatus(200)
            ->assertJsonPath('data.status', 'awaiting_redirect');

        $this->assertNotEmpty(
            $response->json('data.payment_payload.redirect_url'),
            "No redirect link was issued for {$method}, so the customer has nowhere to pay.",
        );
    }

    public function test_gbp_order_can_be_paid_with_pay_with_bank(): void
    {
        [$user, $session] = $this->orderAwaitingPayment('GBP');

        $response = $this->actingAs($user)->postJson(
            route('api.payment-sessions.pay', $session->id),
            ['method' => 'pay_with_bank'],
        );

        $response->assertStatus(200)
            ->assertJsonPath('data.status', 'awaiting_redirect');
    }

    /**
     * The pay step must never refuse a method the checkout validator accepts.
     * Both now read SupportedPaymentMethods, and this pins the currencies the
     * storefront actually sells in so a future edit to one layer can't quietly
     * strand orders again.
     */
    public function test_checkout_and_pay_step_share_one_allow_list(): void
    {
        $this->assertContains('ussd', SupportedPaymentMethods::forCurrency('NGN'));
        $this->assertContains('bank_qr', SupportedPaymentMethods::forCurrency('NGN'));
        $this->assertContains('mobile_wallet', SupportedPaymentMethods::forCurrency('NGN'));
        $this->assertContains('pay_with_bank', SupportedPaymentMethods::forCurrency('NGN'));
        $this->assertContains('pay_with_bank', SupportedPaymentMethods::forCurrency('GBP'));
        $this->assertContains('pay_with_bank', SupportedPaymentMethods::forCurrency('EUR'));
        $this->assertContains('mobile_money', SupportedPaymentMethods::forCurrency('KES'));
        // USD borrows the Francophone rail and is charged in CFA francs
        // (MobileMoneyRail), so a dollar order is offered MTN and Orange too.
        $this->assertContains('mobile_money', SupportedPaymentMethods::forCurrency('USD'));

        // Methods the gateway does not accept on the currency stay rejected.
        $this->assertNotContains('mobile_money', SupportedPaymentMethods::forCurrency('EUR'));
        $this->assertNotContains('bank_transfer', SupportedPaymentMethods::forCurrency('GBP'));
    }

    public function test_method_the_currency_does_not_support_is_still_rejected(): void
    {
        [$user, $session] = $this->orderAwaitingPayment('USD');

        $response = $this->actingAs($user)->postJson(
            route('api.payment-sessions.pay', $session->id),
            ['method' => 'ussd'],
        );

        $response->assertStatus(422)
            ->assertJsonFragment(['message' => 'The payment method ussd is not available for currency USD.']);
    }
}

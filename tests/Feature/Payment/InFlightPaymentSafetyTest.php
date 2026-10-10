<?php

namespace Tests\Feature\Payment;

use App\Domain\Order\Enums\OrderStatus;
use App\Domain\Order\Services\OrderService;
use App\Domain\Payment\Enums\PaymentStatus;
use App\Domain\Payment\Jobs\ExpireStalePaymentSessionsJob;
use App\Domain\Payment\Providers\FlutterwavePaymentProvider;
use App\Domain\Payment\Services\PaymentGatewayFactory;
use App\Domain\Payment\Services\PaymentSessionService;
use App\Domain\Shared\Enums\Currency;
use App\Jobs\VerifyPaymentJob;
use App\Models\CurrencyRate;
use App\Models\Order;
use App\Models\PaymentAttempt;
use App\Models\PaymentSession;
use App\Models\SiteSetting;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * A payment under way is never ended, re-priced or lost by our own code
 * while the gateway may still complete it. The clock, a retry, or a declined
 * first card must leave the real payment able to land.
 */
class InFlightPaymentSafetyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.flutterwave.secret_key' => 'FLWSECK_TEST-realish',
            'services.flutterwave.public_key' => 'FLWPUBK_TEST-realish',
        ]);
        SiteSetting::put('features.gateway_flutterwave_enabled', 'on', 'features');
        Http::preventStrayRequests();
        Mail::fake();
        Notification::fake();
    }

    /**
     * @return array{0: Order, 1: PaymentAttempt, 2: PaymentSession}
     */
    private function orderWith(User $user, string $sessionStatus, string $method = 'card', string $currency = 'NGN', float $amount = 15000): array
    {
        $order = Order::factory()->create(['user_id' => $user->id, 'order_status' => OrderStatus::Pending, 'payment_status' => PaymentStatus::Pending, 'display_currency' => $currency, 'total_amount' => $amount]);

        $attempt = PaymentAttempt::create([
            'order_id' => $order->id,
            'user_id' => $user->id,
            'gateway' => 'flutterwave',
            'payment_method' => $method,
            'idempotency_key' => 'inflight_'.Str::random(12),
            'currency' => $currency,
            'amount' => $amount,
            'payment_status' => PaymentStatus::Pending,
        ]);

        $session = app(PaymentSessionService::class)->createForOrder($order, $attempt, []);
        $session->forceFill(['status' => $sessionStatus])->save();

        return [$order, $attempt->fresh(), $session->fresh()];
    }

    private function successByReference(string $reference, float $amount, string $currency, int $id = 555001): array
    {
        return [
            'status' => 'success',
            'data' => ['id' => $id, 'tx_ref' => $reference, 'status' => 'successful', 'amount' => $amount, 'currency' => $currency, 'processor_response' => 'Approved'],
        ];
    }

    private function runVerify(PaymentAttempt $attempt): void
    {
        (new VerifyPaymentJob($attempt))->handle(app(PaymentGatewayFactory::class), app(OrderService::class));
    }

    // ------------------------------------------------------ C: the 10-minute reaper

    /** C: a card waiting on 3D Secure for 15 minutes is still a live payment, not an orphan. */
    public function test_the_orphan_reconciler_leaves_a_live_3ds_session_alone(): void
    {
        $user = User::factory()->create();
        [, $attempt, $session] = $this->orderWith($user, 'awaiting_customer_action');
        $session->forceFill(['created_at' => now()->subMinutes(15)])->save();

        $this->artisan('reconcile:orphaned-sessions')->assertSuccessful();

        $this->assertSame('awaiting_customer_action', $session->fresh()->status, 'a live 3DS challenge was reaped by the clock');
        $this->assertNotSame(PaymentStatus::Failed, $attempt->fresh()->payment_status);
        Http::assertNothingSent();
    }

    /** C: a session that never got past "pending" is still cleaned up. */
    public function test_the_orphan_reconciler_still_fails_a_session_that_never_started(): void
    {
        $user = User::factory()->create();
        [, , $session] = $this->orderWith($user, 'pending');
        $session->forceFill(['created_at' => now()->subMinutes(15)])->save();

        $this->artisan('reconcile:orphaned-sessions')->assertSuccessful();

        $this->assertSame('failed', $session->fresh()->status);
    }

    // ---------------------------------------------------- D: re-pricing on retry

    /**
     * D: a USD mobile money customer taps Pay again while the first prompt is
     * still live. The franc figure the first charge recorded must stand, or
     * the payment they then approve never matches what we check for.
     */
    public function test_retrying_a_converted_mobile_money_charge_keeps_the_figure_first_charged(): void
    {
        CurrencyRate::create(['code' => 'XAF', 'name' => 'CFA', 'type' => 'fiat', 'rate_per_usd' => 600.0, 'is_active' => true]);
        $user = User::factory()->create();
        [, $attempt] = $this->orderWith($user, 'awaiting_confirmation', 'mobile_money', 'USD', 10.0);

        Http::fake(['api.flutterwave.com/v3/charges*' => Http::response(['status' => 'success', 'data' => ['status' => 'pending', 'tx_ref' => $attempt->idempotency_key]], 200)]);
        $provider = app(FlutterwavePaymentProvider::class);

        $provider->chargeMobileMoney($attempt, '+237 6 70 00 00 00', 'MTN');
        $attempt->refresh();
        $firstFigure = (float) $attempt->pay_amount;
        $this->assertSame('XAF', $attempt->pay_currency);
        $this->assertEqualsWithDelta(6000.0, $firstFigure, 0.001);

        // The rate moves between the two taps.
        CurrencyRate::where('code', 'XAF')->update(['rate_per_usd' => 650.0]);

        $provider->chargeMobileMoney($attempt->fresh(), '+237 6 70 00 00 00', 'MTN');

        $this->assertEqualsWithDelta($firstFigure, (float) $attempt->fresh()->pay_amount, 0.001, 'the retry re-priced the payment');
        $sent = [];
        Http::assertSent(function (Request $request) use (&$sent) {
            $sent[] = $request['amount'];

            return true;
        });
        $this->assertSame([6000.0, 6000.0], array_map('floatval', $sent), 'both charges must ask for the same francs');
    }

    // ------------------------------------------------ E: the hosted return leg

    /**
     * E: the customer comes back from a USSD/bank page with status=cancelled,
     * but the gateway has the money. The return leg must ask the gateway, not
     * trust the query string, so the order is settled instead of retried.
     */
    public function test_a_hosted_return_with_a_non_success_status_still_settles_a_payment_the_gateway_confirms(): void
    {
        $user = User::factory()->create();
        [$order, $attempt, $session] = $this->orderWith($user, 'awaiting_redirect', 'ussd');

        Http::fake(['api.flutterwave.com/v3/transactions/verify_by_reference*' => Http::response($this->successByReference($attempt->idempotency_key, 15000, 'NGN'), 200)]);

        $this->actingAs($user)
            ->get(route('shop.checkout.return', ['session' => $session->id, 'status' => 'cancelled']))
            ->assertRedirect();

        $this->assertSame(PaymentStatus::Paid, $attempt->fresh()->payment_status, 'the gateway had the money; the query string said otherwise');
        $this->assertSame(PaymentStatus::Paid, $order->fresh()->payment_status);
    }

    // ----------------------------------------- F: a declined card's id hides the paid one

    /**
     * F: card A is declined, card B pays under the same reference. If the
     * declined charge's id is what we look up, the lookup says "failed" and
     * must fall back to the reference, which knows card B paid.
     */
    public function test_a_declined_charge_id_does_not_hide_the_card_that_paid(): void
    {
        $user = User::factory()->create();
        [$order, $attempt] = $this->orderWith($user, 'awaiting_payment');
        $attempt->forceFill(['gateway_reference' => '111'])->save();

        Http::fake([
            'api.flutterwave.com/v3/transactions/111/verify' => Http::response(['status' => 'success', 'data' => ['id' => 111, 'tx_ref' => $attempt->idempotency_key, 'status' => 'failed', 'amount' => 15000, 'currency' => 'NGN', 'processor_response' => 'Insufficient Funds']], 200),
            'api.flutterwave.com/v3/transactions/verify_by_reference*' => Http::response($this->successByReference($attempt->idempotency_key, 15000, 'NGN', 222), 200),
        ]);

        $this->runVerify($attempt->fresh());

        $this->assertSame(PaymentStatus::Paid, $attempt->fresh()->payment_status, 'card B paid under this reference');
        $this->assertSame('222', (string) $attempt->fresh()->gateway_reference, 'the paid charge is the one to keep');
        $this->assertSame(PaymentStatus::Paid, $order->fresh()->payment_status);
    }

    // ---------------------------------------------- H: a hold left in "processing"

    /** H: a wallet payment that broke after the hold was taken must not keep the customer's money frozen. */
    public function test_a_wallet_hold_left_in_processing_is_released_once_the_session_expires(): void
    {
        $user = User::factory()->create();
        $wallet = Wallet::create(['user_id' => $user->id, 'currency' => Currency::NGN, 'balance' => 20000, 'locked_balance' => 15000, 'is_active' => true]);

        $order = Order::factory()->create(['user_id' => $user->id, 'order_status' => OrderStatus::Pending, 'payment_status' => PaymentStatus::Pending, 'display_currency' => 'NGN', 'total_amount' => 15000]);
        $attempt = PaymentAttempt::create([
            'order_id' => $order->id,
            'user_id' => $user->id,
            'gateway' => 'wallet',
            'payment_method' => 'wallet',
            'idempotency_key' => 'hold_'.Str::random(12),
            'currency' => 'NGN',
            'amount' => 15000,
            'payment_status' => PaymentStatus::Reserved,
            'gateway_reference' => 'WL-LOCK-x',
        ]);
        $session = app(PaymentSessionService::class)->createForOrder($order, $attempt, []);
        $session->forceFill(['status' => 'processing', 'expires_at' => now()->subMinutes(40), 'created_at' => now()->subMinutes(70)])->save();

        $logged = [];
        Log::listen(function ($event) use (&$logged): void {
            $logged[] = $event->level.': '.$event->message.' '.json_encode($event->context);
        });

        app(ExpireStalePaymentSessionsJob::class)->handle(app(PaymentSessionService::class), app(OrderService::class));

        $this->assertEqualsWithDelta(0.0, (float) $wallet->fresh()->locked_balance, 0.001, 'the hold is still frozen: '.implode(' | ', $logged));
        $this->assertEqualsWithDelta(20000.0, (float) $wallet->fresh()->balance, 0.001, 'nothing was debited');
        $this->assertNotSame('processing', $session->fresh()->status);
    }
}

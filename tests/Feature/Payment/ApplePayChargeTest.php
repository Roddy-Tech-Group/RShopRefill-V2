<?php

namespace Tests\Feature\Payment;

use App\Domain\Payment\Providers\FlutterwavePaymentProvider;
use App\Domain\Payment\Support\SupportedPaymentMethods;
use App\Models\Order;
use App\Models\PaymentAttempt;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Every production Apple Pay attempt (twelve of twelve, all USD, by
 * 2026-09-10) was refused with "Transaction failed compliance checks". The
 * charge named nobody: no full name, no phone, no country, no return URL,
 * although the gateway's compliance step keys on exactly those. Separately,
 * the checkout offered the tile in currencies the gateway refuses outright
 * ("Currency not supported for Apple Pay", sandbox-verified).
 */
class ApplePayChargeTest extends TestCase
{
    use RefreshDatabase;

    private function attemptFor(User $user, string $currency = 'USD'): PaymentAttempt
    {
        $order = Order::factory()->create(['user_id' => $user->id]);

        return PaymentAttempt::create([
            'order_id' => $order->id,
            'user_id' => $user->id,
            'gateway' => 'flutterwave',
            'idempotency_key' => 'ap_'.Str::random(12),
            'currency' => $currency,
            'amount' => 112.00,
            'payment_status' => 'pending',
        ]);
    }

    public function test_the_charge_names_the_customer_and_carries_the_return_url(): void
    {
        config(['services.flutterwave.secret_key' => 'FLWSECK_TEST-realish']);
        Http::fake([
            'api.flutterwave.com/v3/charges*' => Http::response([
                'status' => 'success',
                'message' => 'Charge initiated',
                'meta' => ['authorization' => ['mode' => 'redirect', 'redirect' => 'https://checkout.flutterwave.com/v3/hosted/pay/ap']],
            ]),
        ]);

        $user = User::factory()->create(['name' => 'Ada Mensah', 'phone' => '+1 (415) 555-0134', 'country' => 'us']);

        $result = app(FlutterwavePaymentProvider::class)->chargeApplePay(
            $this->attemptFor($user),
            [],
            'https://rshoprefill.com/checkout/return/abc',
        );

        $this->assertSame('awaiting_redirect', $result['status']);

        Http::assertSent(function (Request $request) {
            return str_contains($request->url(), '/charges?type=applepay')
                && $request['fullname'] === 'Ada Mensah'
                && $request['phone_number'] === '14155550134'
                && $request['billing_country'] === 'US'
                && $request['redirect_url'] === 'https://rshoprefill.com/checkout/return/abc'
                && $request['currency'] === 'USD'
                && $request['email'] !== null;
        });
    }

    public function test_a_customer_without_phone_or_country_is_still_charged_with_a_name(): void
    {
        config(['services.flutterwave.secret_key' => 'FLWSECK_TEST-realish']);
        Http::fake(['api.flutterwave.com/v3/charges*' => Http::response(['status' => 'success', 'meta' => ['authorization' => ['mode' => 'redirect', 'redirect' => 'https://checkout.flutterwave.com/x']]])]);

        $user = User::factory()->create(['name' => '  ', 'phone' => null, 'country' => null]);

        app(FlutterwavePaymentProvider::class)->chargeApplePay($this->attemptFor($user));

        Http::assertSent(function (Request $request) {
            return $request['fullname'] === 'Customer'
                && ! array_key_exists('phone_number', $request->data())
                && ! array_key_exists('billing_country', $request->data())
                && ! array_key_exists('redirect_url', $request->data());
        });
    }

    /**
     * The charge above stays proven for the day Flutterwave enables Apple Pay on
     * the live account. Until then it is withheld from every currency, including
     * the three the sandbox accepted it in (see ApplePayWithheldTest). Lifting
     * the hold is deleting one line in SupportedPaymentMethods; this test then
     * fails on purpose so the USD/GBP/NGN assertion is restored with it.
     */
    public function test_apple_pay_is_withheld_everywhere_until_the_live_account_takes_it(): void
    {
        $this->assertArrayHasKey('apple_pay', SupportedPaymentMethods::withheld());

        foreach (['USD', 'GBP', 'NGN', 'EUR', 'GHS', 'XAF', 'XOF', 'KES', 'UGX', 'RWF', 'ZAR'] as $currency) {
            $this->assertFalse(SupportedPaymentMethods::allows($currency, 'apple_pay'), "{$currency} must not offer Apple Pay while it is withheld");
        }
    }
}

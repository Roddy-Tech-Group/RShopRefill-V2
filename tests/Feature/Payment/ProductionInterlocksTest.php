<?php

namespace Tests\Feature\Payment;

use App\Domain\Fulfillment\Providers\ZenditFulfillmentProvider;
use App\Domain\Payment\Providers\FlutterwavePaymentProvider;
use App\Domain\Payment\Providers\KorapayPaymentProvider;
use App\Domain\Payment\Providers\NowPaymentsProvider;
use App\Domain\Payment\Providers\PaystackPaymentProvider;
use App\Domain\Payment\Support\MockMode;
use Tests\TestCase;

/**
 * In production a provider either talks to its real gateway or refuses to
 * start. It never quietly pretends: no mock codes to customers, no payments
 * marked paid by a fake gateway, no live traffic against a sandbox.
 */
class ProductionInterlocksTest extends TestCase
{
    private function inProduction(): void
    {
        $this->app['env'] = 'production';
    }

    private function isMock(object $provider): bool
    {
        $property = new \ReflectionProperty($provider, 'mock');

        return (bool) $property->getValue($provider);
    }

    public function test_the_mock_opt_in_is_ignored_in_production(): void
    {
        config(['services.payment_mock' => true]);
        $this->assertTrue(MockMode::allowed(), 'the opt-in works outside production');

        $this->inProduction();

        $this->assertFalse(MockMode::allowed(), 'PAYMENT_MOCK=true must never enable mock payments in production');
    }

    public function test_a_blank_zendit_key_refuses_to_start_in_production(): void
    {
        config(['services.zendit.api_key' => null]);

        // Local and testing keep their mock fulfilment.
        $this->assertTrue($this->isMock(new ZenditFulfillmentProvider));

        $this->inProduction();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('ZENDIT_API_KEY');
        new ZenditFulfillmentProvider;
    }

    public function test_a_real_key_that_happens_to_contain_mock_is_not_mock_in_production(): void
    {
        config([
            'services.flutterwave.secret_key' => 'FLWSECK-MOCKINGBIRD-1234',
            'services.paystack.secret_key' => 'sk_live_MOCK1234',
            'services.korapay.secret_key' => 'sk_live_MOCK1234',
            'services.zendit.api_key' => 'zd_live_MOCK1234',
        ]);

        // Outside production the naming convention still switches mocks on,
        // which every test in this suite relies on.
        $this->assertTrue($this->isMock(new FlutterwavePaymentProvider));
        $this->assertTrue($this->isMock(new PaystackPaymentProvider));
        $this->assertTrue($this->isMock(new KorapayPaymentProvider));
        $this->assertTrue($this->isMock(new ZenditFulfillmentProvider));

        $this->inProduction();

        $this->assertFalse($this->isMock(new FlutterwavePaymentProvider));
        $this->assertFalse($this->isMock(new PaystackPaymentProvider));
        $this->assertFalse($this->isMock(new KorapayPaymentProvider));
        $this->assertFalse($this->isMock(new ZenditFulfillmentProvider));
    }

    public function test_blank_gateway_keys_refuse_to_start_in_production_even_with_the_opt_in(): void
    {
        config([
            'services.payment_mock' => true,
            'services.flutterwave.secret_key' => null,
            'services.paystack.secret_key' => null,
            'services.korapay.secret_key' => null,
            'services.nowpayments.api_key' => null,
        ]);
        $this->inProduction();

        foreach ([FlutterwavePaymentProvider::class, PaystackPaymentProvider::class, KorapayPaymentProvider::class, NowPaymentsProvider::class] as $provider) {
            try {
                new $provider;
                $this->fail("{$provider} started with no credential in production");
            } catch (\RuntimeException $e) {
                $this->assertStringContainsString('not configured', $e->getMessage());
            }
        }
    }

    public function test_the_crypto_sandbox_refuses_to_serve_production(): void
    {
        config([
            'services.nowpayments.api_key' => 'real-key',
            'services.nowpayments.sandbox' => true,
        ]);

        // Fine outside production: that is what the sandbox is for.
        new NowPaymentsProvider;

        $this->inProduction();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('NOWPAYMENTS_SANDBOX');
        new NowPaymentsProvider;
    }
}

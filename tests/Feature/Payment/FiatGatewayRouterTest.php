<?php

namespace Tests\Feature\Payment;

use App\Domain\Payment\Services\PaymentGatewayFactory;
use App\Domain\Payment\Support\FiatGatewayRouter;
use App\Domain\Payment\Support\MobileMoneyRail;
use App\Domain\Payment\Support\SupportedPaymentMethods;
use App\Domain\Shared\Enums\Currency;
use App\Models\CurrencyRate;
use App\Models\SiteSetting;
use App\Models\User;
use App\Support\FeatureFlag;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Per-method fiat failover. The rule these tests pin: Flutterwave first for
 * everything while its flag is on; when it is off, each method independently
 * falls to the first enabled backup that covers it in the checkout currency;
 * a pair no live gateway covers greys out rather than routing anywhere.
 */
class FiatGatewayRouterTest extends TestCase
{
    use RefreshDatabase;

    private function setGateway(string $gateway, bool $on): void
    {
        SiteSetting::put("features.gateway_{$gateway}_enabled", $on ? 'on' : 'off', 'features');
    }

    public function test_primary_wins_every_method_and_currency_while_enabled(): void
    {
        foreach (array_keys(FiatGatewayRouter::METHOD_PRIORITY) as $method) {
            foreach (['USD', 'EUR', 'GBP', 'NGN', 'GHS', 'XAF', 'KES'] as $currency) {
                // Mobile money exists only where Flutterwave has a rail for it.
                if ($method === 'mobile_money' && ! MobileMoneyRail::supports($currency)) {
                    $this->assertNull(FiatGatewayRouter::resolve($method, $currency), "no rail serves mobile money in {$currency}");

                    continue;
                }

                $this->assertSame(
                    'flutterwave',
                    FiatGatewayRouter::resolve($method, $currency),
                    "'{$method}' in {$currency} did not resolve to the primary",
                );
            }
        }
    }

    /**
     * The capability matrix, pinned against what each provider's docs say
     * (verified 2026-08-07). If a backup gains or loses coverage, this test
     * is the map to update FIRST, then the routing follows.
     */
    public function test_backup_capability_matrix_matches_the_researched_coverage(): void
    {
        // Paystack: NGN cards until USD collection is approved on the account.
        $this->assertTrue(FiatGatewayRouter::supports('paystack', 'card', 'NGN'));
        $this->assertFalse(FiatGatewayRouter::supports('paystack', 'card', 'USD'), 'USD cards require account approval; off by default');
        $this->assertFalse(FiatGatewayRouter::supports('paystack', 'card', 'EUR'), 'Paystack cannot charge EUR at all');
        $this->assertTrue(FiatGatewayRouter::supports('paystack', 'bank_transfer', 'NGN'));
        $this->assertTrue(FiatGatewayRouter::supports('paystack', 'ussd', 'NGN'));
        // Paystack offers mobile money only to businesses registered in Ghana,
        // Kenya or Côte d'Ivoire; ours is Nigerian (docs read 2026-10-06).
        $this->assertFalse(FiatGatewayRouter::supports('paystack', 'mobile_money', 'GHS'));
        $this->assertFalse(FiatGatewayRouter::supports('paystack', 'mobile_money', 'KES'));
        $this->assertFalse(FiatGatewayRouter::supports('paystack', 'mobile_money', 'XAF'), 'Paystack has no Cameroon coverage');

        // The account upgrade is an env change, not a deploy.
        config(['services.paystack.charge_currencies' => ['NGN', 'USD']]);
        $this->assertTrue(FiatGatewayRouter::supports('paystack', 'card', 'USD'));
        $this->assertTrue(FiatGatewayRouter::supports('paystack', 'apple_pay', 'USD'));

        // Kora: the francophone mobile-money backup plus NGN bank rails.
        $this->assertTrue(FiatGatewayRouter::supports('korapay', 'mobile_money', 'XAF'));
        $this->assertTrue(FiatGatewayRouter::supports('korapay', 'mobile_money', 'EGP'));
        $this->assertTrue(FiatGatewayRouter::supports('korapay', 'mobile_money', 'TZS'));
        $this->assertTrue(FiatGatewayRouter::supports('korapay', 'card', 'NGN'));
        $this->assertFalse(FiatGatewayRouter::supports('korapay', 'card', 'USD'), 'Kora cards are NGN only');
        $this->assertTrue(FiatGatewayRouter::supports('korapay', 'pay_with_bank', 'NGN'));
        $this->assertFalse(FiatGatewayRouter::supports('korapay', 'apple_pay', 'NGN'), 'Kora has no Apple Pay');

        // No backup serves these; they grey whenever the primary is off.
        $this->assertFalse(FiatGatewayRouter::supports('paystack', 'bank_qr', 'NGN'));
        $this->assertFalse(FiatGatewayRouter::supports('korapay', 'bank_qr', 'NGN'));
        $this->assertFalse(FiatGatewayRouter::supports('paystack', 'mobile_wallet', 'NGN'));
        $this->assertFalse(FiatGatewayRouter::supports('korapay', 'mobile_wallet', 'NGN'));
    }

    public function test_no_live_gateway_means_null_not_a_crash(): void
    {
        // Backups keep their seeded OFF state: primary down + backups dark
        // is a full outage and everything fiat must resolve to nothing.
        $this->setGateway('flutterwave', false);

        $this->assertNull(FiatGatewayRouter::resolve('card', 'NGN'));
        $this->assertNull(FiatGatewayRouter::resolve('mobile_money', 'XAF'));
        $this->assertNull(FiatGatewayRouter::defaultGateway());
        $this->assertFalse(FiatGatewayRouter::anyFiatGatewayEnabled());
    }

    public function test_the_router_never_resolves_a_gateway_the_factory_cannot_build(): void
    {
        // Worst-case flag posture: primary off, every other flag on. Whatever
        // comes back must be constructible, or pay() dies inside getProvider.
        $this->setGateway('flutterwave', false);
        foreach (array_keys(FeatureFlag::GATEWAY_FLAGS) as $gateway) {
            if ($gateway !== 'flutterwave') {
                $this->setGateway($gateway, true);
            }
        }

        foreach (array_keys(FiatGatewayRouter::METHOD_PRIORITY) as $method) {
            foreach (['USD', 'EUR', 'NGN', 'GHS', 'XAF', 'KES', 'EGP', 'TZS'] as $currency) {
                $resolved = FiatGatewayRouter::resolve($method, $currency);
                if ($resolved !== null) {
                    $this->assertContains(
                        $resolved,
                        PaymentGatewayFactory::IMPLEMENTED,
                        "'{$method}' in {$currency} resolved to unimplemented '{$resolved}'",
                    );
                }
            }
        }
    }

    public function test_method_enabled_answers_per_currency_through_the_router(): void
    {
        $this->assertTrue(FeatureFlag::methodEnabled('card', 'USD'));
        $this->assertTrue(FeatureFlag::methodEnabled('mobile_money', 'XAF'));

        $this->setGateway('flutterwave', false);

        $this->assertFalse(FeatureFlag::methodEnabled('card', 'USD'));
        $this->assertFalse(FeatureFlag::methodEnabled('mobile_money', 'XAF'));
        $this->assertTrue(FeatureFlag::methodEnabled('crypto'), 'crypto must not ride the fiat rail');
        $this->assertTrue(FeatureFlag::methodEnabled('wallet'), 'wallet must not ride the fiat rail');
    }

    public function test_unavailable_map_greys_nothing_in_normal_operation_and_everything_fiat_in_a_full_outage(): void
    {
        $map = FiatGatewayRouter::unavailableFiatMethodsByCurrency();

        $this->assertArrayHasKey('__fallback', $map);
        foreach ($map as $currency => $methods) {
            $this->assertSame([], $methods, "'{$currency}' greys methods while every gateway is up");
        }

        $this->setGateway('flutterwave', false);
        $map = FiatGatewayRouter::unavailableFiatMethodsByCurrency();

        foreach (SupportedPaymentMethods::knownCurrencies() as $currency) {
            $expected = array_values(array_intersect(
                SupportedPaymentMethods::forCurrency($currency),
                array_keys(FiatGatewayRouter::METHOD_PRIORITY),
            ));
            $this->assertSame($expected, $map[$currency], "'{$currency}' should grey every fiat method during a full outage");
        }
    }

    public function test_eur_is_a_first_class_checkout_currency(): void
    {
        $this->assertSame('€', Currency::EUR->symbol());
        $this->assertSame('Euro', Currency::EUR->label());
        $this->assertGreaterThan(0, Currency::EUR->minimumFundingAmount());

        $this->assertContains('card', SupportedPaymentMethods::forCurrency('EUR'));
        $this->assertContains('pay_with_bank', SupportedPaymentMethods::forCurrency('EUR'));

        $eur = CurrencyRate::query()->where('code', 'EUR')->first();
        $this->assertNotNull($eur, 'the seed migration must guarantee an EUR rate row');
        $this->assertTrue((bool) $eur->is_active, 'EUR must be active for the storefront');

        $this->actingAs(User::factory()->create())
            ->get('/checkout')
            ->assertOk()
            ->assertSee('Euro');
    }
}

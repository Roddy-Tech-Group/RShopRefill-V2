<?php

namespace Tests\Feature\Payment;

use App\Domain\Payment\Services\PaymentEligibilityService;
use App\Domain\Payment\Services\PaymentGatewayFactory;
use App\Domain\Payment\Support\FiatGatewayRouter;
use App\Domain\Payment\Support\FundingMethodCatalog;
use App\Domain\Payment\Support\PaymentRouteTable;
use App\Domain\Payment\Support\SupportedPaymentMethods;
use App\Domain\Shared\Enums\ComplianceCategory;
use App\Models\PaymentRoute;
use App\Models\SiteSetting;
use App\Support\FeatureFlag;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * The payment switchboard decides which methods each currency offers, in what
 * order, and which gateway takes each, first choice first.
 *
 * The first half proves deploying it changed nothing a customer can see: the
 * same methods per currency, in the tile order checkout really rendered, and for every
 * method, currency and combination of gateway switches, the same gateway the
 * old fixed-order router would have chosen. The second half proves a row edit
 * really moves a rail, and that a gateway switched off is skipped.
 */
class PaymentSwitchboardTest extends TestCase
{
    use RefreshDatabase;

    /**
     * What checkout really rendered before the switchboard, Apple Pay already
     * withheld. Its tiles followed the allMethods catalogue (card first), not the
     * order of its per-currency lists.
     */
    private const OLD_CHECKOUT_MAP = [
        'USD' => ['card', 'mobile_money', 'crypto', 'wallet'],
        'EUR' => ['card', 'pay_with_bank', 'crypto', 'wallet'],
        'GBP' => ['card', 'pay_with_bank', 'crypto', 'wallet'],
        'NGN' => ['card', 'bank_transfer', 'pay_with_bank', 'ussd', 'bank_qr', 'mobile_wallet', 'crypto', 'wallet'],
        'GHS' => ['card', 'mobile_money', 'crypto', 'wallet'],
        'XAF' => ['card', 'mobile_money', 'crypto', 'wallet'],
        'XOF' => ['card', 'mobile_money', 'crypto', 'wallet'],
        'KES' => ['card', 'mobile_money', 'crypto', 'wallet'],
        'UGX' => ['card', 'mobile_money', 'crypto', 'wallet'],
        'RWF' => ['card', 'mobile_money', 'crypto', 'wallet'],
        // No row before: the card-only fallback, which their rows now spell out.
        'EGP' => ['card'],
        'TZS' => ['card'],
        'ZMW' => ['card'],
        'ZAR' => ['card'],
    ];

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
    }

    private function gateway(string $gateway, bool $on): void
    {
        SiteSetting::put("features.gateway_{$gateway}_enabled", $on ? 'on' : 'off', 'features');
    }

    /**
     * The router as it was before the switchboard: the fixed METHOD_PRIORITY
     * order, filtered by switch and capability. The reference the new router
     * must match for the seeded rows.
     */
    private function oldResolve(string $method, string $currency): ?string
    {
        foreach (FiatGatewayRouter::METHOD_PRIORITY[$method] as $gateway) {
            if (FeatureFlag::gatewayEnabled($gateway)
                && PaymentGatewayFactory::isImplemented($gateway)
                && FiatGatewayRouter::supports($gateway, $method, $currency)) {
                return $gateway;
            }
        }

        return null;
    }

    // ------------------------------------------------- deploy changes nothing

    public function test_every_currency_offers_what_checkout_offered_in_the_same_order(): void
    {
        // Per currency, in tile order; the order the currencies are listed in carries nothing.
        $expected = self::OLD_CHECKOUT_MAP;
        $actual = SupportedPaymentMethods::byCurrency();
        ksort($expected);
        ksort($actual);

        $this->assertSame($expected, $actual);
    }

    public function test_a_currency_with_no_row_still_offers_card_only(): void
    {
        $this->assertSame(['card'], SupportedPaymentMethods::forCurrency('COP'));
        $this->assertTrue(SupportedPaymentMethods::allows('COP', 'card'));
        $this->assertSame('flutterwave', FiatGatewayRouter::resolve('card', 'COP'));
    }

    /**
     * THE one. For every fiat method in every currency, under every on/off
     * combination of the three fiat gateways, the router picks exactly the
     * gateway the old fixed-order router picked.
     */
    public function test_routing_matches_the_old_router_under_every_combination_of_switches(): void
    {
        $currencies = [...array_keys(self::OLD_CHECKOUT_MAP), 'COP'];
        $checked = 0;

        foreach ([true, false] as $flutterwave) {
            foreach ([true, false] as $paystack) {
                foreach ([true, false] as $korapay) {
                    $this->gateway('flutterwave', $flutterwave);
                    $this->gateway('paystack', $paystack);
                    $this->gateway('korapay', $korapay);

                    foreach ($currencies as $currency) {
                        foreach (array_keys(FiatGatewayRouter::METHOD_PRIORITY) as $method) {
                            $this->assertSame(
                                $this->oldResolve($method, $currency),
                                FiatGatewayRouter::resolve($method, $currency),
                                "{$method} in {$currency} with flutterwave=".var_export($flutterwave, true).', paystack='.var_export($paystack, true).', korapay='.var_export($korapay, true),
                            );
                            $checked++;
                        }
                    }
                }
            }
        }

        $this->assertSame(8 * count($currencies) * 8, $checked);
    }

    /**
     * Egypt and Tanzania mobile money exist as rows so they can be managed,
     * but stay off, with the reason, until a real payment proves them.
     */
    public function test_untested_markets_are_listed_switched_off_with_the_reason(): void
    {
        foreach (['EGP', 'TZS'] as $currency) {
            $row = PaymentRoute::where('currency', $currency)->where('method', 'mobile_money')->sole();

            $this->assertFalse($row->is_offered, "{$currency} mobile money must start switched off");
            $this->assertSame(SupportedPaymentMethods::OFF_UNTIL_TESTED_NOTE, $row->note);
            $this->assertSame(['card'], SupportedPaymentMethods::forCurrency($currency));
            $this->assertSame('flutterwave', FiatGatewayRouter::resolve('card', $currency));
        }

        // Switched on from the admin, Tanzania routes to Flutterwave's rail
        // today and gains Kora's networks the day Kora is on.
        PaymentRoute::where('currency', 'TZS')->where('method', 'mobile_money')->sole()->update(['is_offered' => true]);
        $this->assertSame(['card', 'mobile_money'], SupportedPaymentMethods::forCurrency('TZS'));
        $this->assertSame('flutterwave', FiatGatewayRouter::resolve('mobile_money', 'TZS'));

        // Egypt's mobile money is Kora's alone: nothing takes it until Kora is on.
        PaymentRoute::where('currency', 'EGP')->where('method', 'mobile_money')->sole()->update(['is_offered' => true]);
        $this->assertNull(FiatGatewayRouter::resolve('mobile_money', 'EGP'));
        $this->gateway('korapay', true);
        $this->assertSame('korapay', FiatGatewayRouter::resolve('mobile_money', 'EGP'));
    }

    public function test_apple_pay_stays_withheld_with_its_reason(): void
    {
        $this->assertSame('Not enabled', SupportedPaymentMethods::withheld()['apple_pay']);
        $this->assertNotContains('apple_pay', SupportedPaymentMethods::allMethods());
    }

    // ----------------------------------------------------- a row edit moves it

    /**
     * Kora first for XAF mobile money: while Kora is switched off it is skipped
     * and Flutterwave keeps the payment; switched on, Kora takes it.
     */
    public function test_a_gateway_first_on_its_row_takes_the_payment_only_while_switched_on(): void
    {
        PaymentRoute::where('currency', 'XAF')->where('method', 'mobile_money')
            ->update(['gateways' => json_encode(['korapay', 'flutterwave'])]);
        PaymentRouteTable::forget();

        $this->gateway('flutterwave', true);
        $this->gateway('korapay', false);
        $this->assertSame('flutterwave', FiatGatewayRouter::resolve('mobile_money', 'XAF'), 'Kora is off, so Flutterwave keeps it');

        $this->gateway('korapay', true);
        $this->assertSame('korapay', FiatGatewayRouter::resolve('mobile_money', 'XAF'));

        // Every other row is untouched.
        $this->assertSame('flutterwave', FiatGatewayRouter::resolve('mobile_money', 'GHS'));
        $this->assertSame('flutterwave', FiatGatewayRouter::resolve('card', 'XAF'));
    }

    public function test_a_change_through_the_model_reaches_the_next_payment_at_once(): void
    {
        $this->gateway('korapay', true);
        $this->assertSame('flutterwave', FiatGatewayRouter::resolve('bank_transfer', 'NGN'));

        $route = PaymentRoute::where('currency', 'NGN')->where('method', 'bank_transfer')->sole();
        $route->update(['gateways' => ['korapay', 'flutterwave']]);

        $this->assertSame('korapay', FiatGatewayRouter::resolve('bank_transfer', 'NGN'), 'the cached switchboard was not dropped');
    }

    /**
     * A copy written back by a request racing an admin's commit cannot stand
     * for ever: the cache expires on its own within a minute.
     */
    public function test_the_cached_switchboard_expires_within_a_minute(): void
    {
        PaymentRouteTable::forget();
        PaymentRouteTable::all();
        $this->assertTrue(Cache::has('payment_routes:v1'));

        $this->travel(61)->seconds();

        $this->assertFalse(Cache::has('payment_routes:v1'), 'the switchboard cache never expires');
    }

    public function test_switching_a_row_off_removes_the_method_everywhere(): void
    {
        PaymentRoute::where('currency', 'NGN')->where('method', 'ussd')->sole()->update(['is_offered' => false]);

        $this->assertNotContains('ussd', SupportedPaymentMethods::forCurrency('NGN'));
        $this->assertFalse(SupportedPaymentMethods::allows('NGN', 'ussd'));
        $this->assertNotContains('ussd', array_column(FundingMethodCatalog::forCurrency('NGN'), 'type'));
    }

    public function test_a_tile_order_change_reorders_every_surface(): void
    {
        PaymentRoute::where('currency', 'XAF')->where('method', 'mobile_money')->sole()->update(['sort_order' => 1]);

        $this->assertSame(['mobile_money', 'card', 'crypto', 'wallet'], SupportedPaymentMethods::forCurrency('XAF'));
        $this->assertSame('mobile_money', FundingMethodCatalog::forCurrency('XAF')[0]['type']);
    }

    /** A hand-edited row can never send a fiat method to the crypto or wallet rail. */
    public function test_a_non_fiat_gateway_on_a_fiat_row_is_ignored(): void
    {
        PaymentRoute::where('currency', 'XAF')->where('method', 'mobile_money')->sole()
            ->update(['gateways' => ['nowpayments', 'wallet', 'flutterwave']]);

        $this->assertSame(['flutterwave'], FiatGatewayRouter::order('mobile_money', 'XAF'));
        $this->assertSame('flutterwave', FiatGatewayRouter::resolve('mobile_money', 'XAF'));
    }

    /** A gateway taken off a row no longer counts as able to serve it for eligibility. */
    public function test_eligibility_only_considers_gateways_on_the_row(): void
    {
        SiteSetting::put('features.payment_eligibility_enforce_enabled', 'on', 'features');
        SiteSetting::put('features.eligibility_flutterwave_gift_card_enabled', 'off', 'features');
        SiteSetting::put('features.eligibility_korapay_gift_card_enabled', 'on', 'features');
        $this->gateway('korapay', true);

        $eligibility = app(PaymentEligibilityService::class);
        $giftCards = [ComplianceCategory::GiftCard];

        $this->assertNotContains('mobile_money', $eligibility->ineligibleMethodsByCurrency($giftCards)['XAF'], 'Kora can take it');

        PaymentRoute::where('currency', 'XAF')->where('method', 'mobile_money')->sole()->update(['gateways' => ['flutterwave']]);

        $this->assertContains('mobile_money', $eligibility->ineligibleMethodsByCurrency($giftCards)['XAF'], 'Kora is off the row');
    }

    // ------------------------------------------------------------ resilience

    /**
     * Between a code deploy and its migration, or with the database failing,
     * the switchboard answers with the old behaviour instead of taking
     * checkout down.
     */
    public function test_an_unreadable_table_falls_back_to_the_old_behaviour(): void
    {
        // Renamed away and always put back: on MySQL a drop would outlive this
        // test, because DDL commits the surrounding test transaction.
        PaymentRouteTable::forget();
        Schema::rename('payment_routes', 'payment_routes_away');

        try {
            $this->assertSame(['card', 'mobile_money', 'crypto', 'wallet'], SupportedPaymentMethods::forCurrency('XAF'));
            $this->assertSame('flutterwave', FiatGatewayRouter::resolve('mobile_money', 'XAF'));
            $this->assertArrayHasKey('apple_pay', SupportedPaymentMethods::withheld());
        } finally {
            Schema::rename('payment_routes_away', 'payment_routes');
            PaymentRouteTable::forget();
        }
    }

    /** A failing cache store still answers from the table, not the old defaults. */
    public function test_a_failing_cache_still_reads_the_table(): void
    {
        DB::table('payment_routes')->where('currency', 'XAF')->where('method', 'mobile_money')
            ->update(['gateways' => json_encode(['korapay', 'flutterwave'])]);

        Cache::shouldReceive('memo')->andThrow(new \RuntimeException('cache store down'));

        $this->assertSame(['korapay', 'flutterwave'], PaymentRouteTable::gatewayOrder('mobile_money', 'XAF'));
    }

    public function test_the_fallback_defaults_match_the_seed(): void
    {
        $seeded = PaymentRouteTable::all();
        PaymentRouteTable::forget();

        $defaults = PaymentRouteTable::defaults();
        ksort($defaults);
        ksort($seeded);

        $this->assertSame($defaults, $seeded);
    }
}

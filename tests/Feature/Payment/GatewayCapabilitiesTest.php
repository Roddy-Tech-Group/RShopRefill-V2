<?php

namespace Tests\Feature\Payment;

use App\Domain\Payment\Support\GatewayCapabilities;
use Tests\TestCase;

/**
 * What each gateway can take, as its documentation states it (verified
 * 2026-10-06). The storefront offers, and the router sends, only within this.
 */
class GatewayCapabilitiesTest extends TestCase
{
    public function test_flutterwave_takes_cards_and_bank_methods_everywhere_and_mobile_money_per_market(): void
    {
        foreach (['USD', 'EUR', 'GBP', 'NGN', 'XAF', 'COP'] as $currency) {
            $this->assertTrue(GatewayCapabilities::supports('flutterwave', 'card', $currency));
        }

        $this->assertTrue(GatewayCapabilities::supports('flutterwave', 'mobile_money', 'XAF', 'CM', 'Orange'));
        $this->assertTrue(GatewayCapabilities::supports('flutterwave', 'mobile_money', 'XOF', 'CI', 'Wave'));
        $this->assertTrue(GatewayCapabilities::supports('flutterwave', 'mobile_money', 'XOF', 'SN', 'Wave'));
        $this->assertFalse(GatewayCapabilities::supports('flutterwave', 'mobile_money', 'XOF', 'SN', 'MTN'), 'Senegal has no MTN');
        $this->assertFalse(GatewayCapabilities::supports('flutterwave', 'mobile_money', 'XOF', 'ML'), 'Mali is not on the rail');
        $this->assertFalse(GatewayCapabilities::supports('flutterwave', 'mobile_money', 'XAF', 'GA'), 'Gabon is not on the rail');
        $this->assertTrue(GatewayCapabilities::supports('flutterwave', 'mobile_money', 'UGX'));
        $this->assertTrue(GatewayCapabilities::supports('flutterwave', 'mobile_money', 'RWF'));
        $this->assertFalse(GatewayCapabilities::supports('flutterwave', 'mobile_money', 'EUR'));
    }

    public function test_a_usd_mobile_money_order_has_the_franc_countries_coverage(): void
    {
        $coverage = GatewayCapabilities::mobileMoneyCoverage('flutterwave', 'USD');

        $this->assertSame(['CM', 'CI', 'SN', 'BF'], array_keys($coverage));
        $this->assertSame(['MTN', 'Orange'], $coverage['CM']);
        $this->assertSame(['MTN', 'Orange', 'Moov', 'Wave'], $coverage['CI']);
    }

    public function test_kora_takes_ngn_bank_methods_and_mobile_money_in_six_markets(): void
    {
        $this->assertTrue(GatewayCapabilities::supports('korapay', 'card', 'NGN'));
        $this->assertFalse(GatewayCapabilities::supports('korapay', 'card', 'USD'));
        $this->assertTrue(GatewayCapabilities::supports('korapay', 'bank_transfer', 'NGN'));
        $this->assertTrue(GatewayCapabilities::supports('korapay', 'pay_with_bank', 'NGN'));
        $this->assertFalse(GatewayCapabilities::supports('korapay', 'ussd', 'NGN'));

        $this->assertTrue(GatewayCapabilities::supports('korapay', 'mobile_money', 'KES', 'KE', 'Equitel'));
        $this->assertTrue(GatewayCapabilities::supports('korapay', 'mobile_money', 'XOF', 'CI', 'Moov'));
        $this->assertFalse(GatewayCapabilities::supports('korapay', 'mobile_money', 'XOF', 'SN'), 'Kora covers Côte d\'Ivoire only');
        $this->assertFalse(GatewayCapabilities::supports('korapay', 'mobile_money', 'UGX'));
        $this->assertFalse(GatewayCapabilities::supports('korapay', 'mobile_money', 'RWF'));
        $this->assertTrue(GatewayCapabilities::supports('korapay', 'mobile_money', 'TZS'));
        $this->assertTrue(GatewayCapabilities::supports('korapay', 'mobile_money', 'EGP'));
    }

    public function test_paystack_takes_ngn_only_plus_usd_cards_once_approved(): void
    {
        config(['services.paystack.charge_currencies' => ['NGN']]);

        $this->assertTrue(GatewayCapabilities::supports('paystack', 'card', 'NGN'));
        $this->assertFalse(GatewayCapabilities::supports('paystack', 'card', 'USD'));
        $this->assertTrue(GatewayCapabilities::supports('paystack', 'bank_transfer', 'NGN'));
        $this->assertTrue(GatewayCapabilities::supports('paystack', 'ussd', 'NGN'));
        $this->assertFalse(GatewayCapabilities::supports('paystack', 'mobile_money', 'GHS'));
        $this->assertFalse(GatewayCapabilities::supports('paystack', 'mobile_money', 'KES'));
        $this->assertFalse(GatewayCapabilities::supports('paystack', 'pay_with_bank', 'NGN'));

        config(['services.paystack.charge_currencies' => ['NGN', 'USD']]);

        $this->assertTrue(GatewayCapabilities::supports('paystack', 'card', 'USD'));
        $this->assertTrue(GatewayCapabilities::supports('paystack', 'apple_pay', 'USD'));
    }

    public function test_the_union_of_two_gateways_keeps_the_first_ones_order(): void
    {
        $union = GatewayCapabilities::mobileMoneyUnion(['flutterwave', 'korapay'], 'KES');

        $this->assertSame(['KE' => ['M-Pesa', 'Airtel', 'Equitel']], $union);
        $this->assertSame([], GatewayCapabilities::mobileMoneyUnion(['paystack'], 'KES'));
    }

    public function test_network_labels_match_without_regard_to_case(): void
    {
        $this->assertSame('M-Pesa', GatewayCapabilities::labelIn('m-pesa', ['M-Pesa', 'Airtel']));
        $this->assertNull(GatewayCapabilities::labelIn('Safaricom', ['M-Pesa', 'Airtel']));
        $this->assertSame('Mali', GatewayCapabilities::countryName('ML'));
    }
}

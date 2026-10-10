<?php

namespace Tests\Feature\Payment;

use App\Domain\Payment\Support\FundingMethodCatalog;
use App\Domain\Payment\Support\PaymentStripBadges;
use App\Domain\Payment\Support\SupportedPaymentMethods;
use App\Models\PaymentSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Apple Pay is offered nowhere until Flutterwave enables it on the live account.
 *
 * All 17 production attempts between 2026-09-02 and 2026-10-01 were refused by
 * the gateway, in the currencies the sandbox had accepted. Each one cost a
 * customer a failed checkout and a strike toward the payment pause. One entry in
 * SupportedPaymentMethods::withheld() takes the method off every surface; these
 * tests make sure no surface keeps a private copy of the old list.
 */
class ApplePayWithheldTest extends TestCase
{
    use RefreshDatabase;

    public function test_apple_pay_is_named_as_withheld_with_a_reason(): void
    {
        $withheld = SupportedPaymentMethods::withheld();

        $this->assertArrayHasKey('apple_pay', $withheld);
        $this->assertSame('Not enabled', $withheld['apple_pay']);
    }

    public function test_no_currency_offers_apple_pay(): void
    {
        foreach (['USD', 'GBP', 'NGN', 'EUR', 'GHS', 'XAF', 'XOF', 'KES', 'ZAR'] as $currency) {
            $this->assertFalse(SupportedPaymentMethods::allows($currency, 'apple_pay'), "{$currency} still offers Apple Pay");
            $this->assertNotContains('apple_pay', SupportedPaymentMethods::forCurrency($currency));
        }

        $this->assertNotContains('apple_pay', SupportedPaymentMethods::allMethods());
    }

    /** The other methods those currencies take are untouched. */
    public function test_withholding_apple_pay_removes_nothing_else(): void
    {
        $this->assertSame(['card', 'mobile_money', 'crypto', 'wallet'], SupportedPaymentMethods::forCurrency('USD'));
        $this->assertSame(['card', 'pay_with_bank', 'crypto', 'wallet'], SupportedPaymentMethods::forCurrency('GBP'));
        $this->assertContains('bank_transfer', SupportedPaymentMethods::forCurrency('NGN'));
    }

    public function test_the_storefront_strip_no_longer_advertises_apple_pay(): void
    {
        $labels = array_column(PaymentStripBadges::all(), 'label');

        $this->assertNotContains('Apple Pay', $labels);
        $this->assertContains('Visa', $labels, 'the other badges stay');
    }

    public function test_the_wallet_sheet_no_longer_offers_apple_pay(): void
    {
        foreach (['USD', 'GBP', 'NGN'] as $currency) {
            $this->assertNotContains('apple_pay', array_column(FundingMethodCatalog::forCurrency($currency), 'type'));
        }
    }

    /** The session's own method list reads the shared list rather than its old private one. */
    public function test_a_payment_sessions_available_methods_no_longer_list_apple_pay(): void
    {
        $session = new PaymentSession(['currency' => 'USD', 'session_type' => 'card']);

        $this->assertNotContains('apple_pay', array_column($session->getAvailableMethods(), 'type'));
    }

    /** The checkout page renders the server's list, which does not name Apple Pay. */
    public function test_the_checkout_pages_currency_map_does_not_name_apple_pay(): void
    {
        foreach (SupportedPaymentMethods::byCurrency() as $currency => $methods) {
            $this->assertNotContains('apple_pay', $methods, "{$currency} offers Apple Pay");
        }
    }
}

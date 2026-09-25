<?php

namespace Tests\Feature\Checkout;

use App\Domain\Payment\Support\SupportedPaymentMethods;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Checkout does not ask which currency to pay in, and the pages before it make
 * sure it does not have to.
 *
 * The choice is made twice already: in the locale modal behind the nav's region
 * pill, and beside a gift card's price on its own page. Asking again on the
 * page where the money moves invited a customer to contradict what they had
 * told us, so the control is gone. What replaces it is a handoff: the card
 * page's pick is written to the same key the modal writes, which is the key
 * checkout reads.
 *
 * These are source checks. The currency reaches checkout through the browser's
 * own storage, so nothing about the handoff is visible in a server render; what
 * is worth pinning is that the writer, the reader and the guard between them
 * stay the same three things.
 */
class CheckoutCurrencyHandoffTest extends TestCase
{
    use RefreshDatabase;

    private function checkout(): string
    {
        return file_get_contents(resource_path('views/shop/checkout.blade.php'));
    }

    /** No currency control on checkout, in the source or on the page. */
    public function test_checkout_does_not_offer_a_currency_chooser(): void
    {
        $source = $this->checkout();

        $this->assertStringNotContainsString('checkout_currency_choice', $source);
        $this->assertStringNotContainsString('checkout-currency-label', $source);
        $this->assertStringNotContainsString('ccyOpen', $source);

        // And nothing left that would write the choice from this page.
        $this->assertStringNotContainsString("localStorage.setItem('locale.currency'", $source);

        $html = $this->withoutVite()->get(route('shop.checkout'))->assertOk()->getContent();
        $this->assertStringNotContainsString('checkout_currency_choice', $html);
    }

    /**
     * The currency still reaches the server. This is the field the order is
     * priced and charged in, so it outlives the control that used to set it.
     */
    public function test_the_chosen_currency_is_still_what_gets_posted(): void
    {
        $source = $this->checkout();

        $this->assertStringContainsString(
            '<input type="hidden" name="currency" :value="$store.cart.currency">',
            $source
        );
    }

    /**
     * The charge currency is still stated on the page. It was the chooser's
     * hint; with the chooser gone this is the only place that says it, and on a
     * phone the nav's region pill shows a flag with no code beside it.
     */
    public function test_the_page_still_says_what_it_will_charge_in(): void
    {
        $html = $this->withoutVite()->get(route('shop.checkout'))->assertOk()->getContent();

        $this->assertStringContainsString(__('checkout.total_amount'), $html);
        $this->assertStringContainsString(e(__('checkout.checkout_currency_hint')), $html);
    }

    /**
     * A currency with no rail list of its own gets a card and nothing else,
     * which is what the server accepts.
     *
     * The page used to offer Crypto there too, and the pay step answered a 422.
     * That was survivable while a customer could switch currency on this page;
     * with the chooser gone there is no way out of it, so the tile must not be
     * offered at all.
     */
    public function test_a_currency_without_rails_is_offered_only_a_card(): void
    {
        $this->assertSame(['card'], SupportedPaymentMethods::forCurrency('COP'));

        $source = $this->checkout();

        $this->assertStringContainsString("const allowedKeys = mapping[currency] || ['card'];", $source);
        $this->assertStringNotContainsString("mapping[currency] || ['card', 'crypto']", $source);
    }

    /**
     * The card page hands its pick over, and the helper it calls is the one
     * that writes what checkout reads.
     */
    public function test_a_pay_currency_picked_on_a_card_page_is_handed_to_checkout(): void
    {
        $product = file_get_contents(resource_path('views/shop/product.blade.php'));
        $js = file_get_contents(resource_path('js/app.js'));

        // The picker moves selectedCrypto; that is now watched.
        $this->assertStringContainsString("\$watch('selectedCrypto', (code) => this.handOffPayCurrency(code))", $product);
        $this->assertStringContainsString('window.rshopSetPayCurrency?.(code, picked.symbol, this.checkoutCurrencies)', $product);

        // The two statements the removed chooser made, in one place now.
        $this->assertStringContainsString("localStorage.setItem('locale.currency', code)", $js);
        $this->assertStringContainsString("new CustomEvent('currency-changed'", $js);

        // And the cart, which checkout prices from, still listens for it.
        $this->assertStringContainsString(
            "window.addEventListener('currency-changed', () => window.Alpine.store('cart').refresh())",
            $js
        );
    }

    /**
     * Only a currency checkout can charge in is handed over.
     *
     * Two things ride on the guard. Coin codes are three letters like a
     * currency, and the display-currency middleware would take one, so without
     * it a customer previewing a price in bitcoin would find the nav pill
     * reading BTC and a checkout that could not price anything. And the rate
     * table carries fiat with no rails at all - the guard keeps those a preview
     * rather than a trip to a checkout offering a card and nothing else.
     */
    public function test_only_currencies_checkout_has_rails_for_are_handed_over(): void
    {
        $product = file_get_contents(resource_path('views/shop/product.blade.php'));
        $js = file_get_contents(resource_path('js/app.js'));

        $this->assertStringContainsString("picked.type !== 'fiat'", $product);
        $this->assertStringContainsString('SupportedPaymentMethods::knownCurrencies()', $product);
        $this->assertStringContainsString('! supported.includes(code)', $js);

        // The list itself: rails AND a country that uses them.
        $allowed = array_values(array_intersect(
            SupportedPaymentMethods::knownCurrencies(),
            array_values(config('country_currency.map', []))
        ));

        $this->assertContains('NGN', $allowed);
        $this->assertContains('USD', $allowed);
        $this->assertNotContains('RCOIN', $allowed);
        $this->assertNotContains('BTC', $allowed);

        foreach ($allowed as $code) {
            $this->assertNotSame(
                ['card'],
                SupportedPaymentMethods::forCurrency($code),
                $code.' is handed over but checkout has no rails for it.'
            );
        }
    }

    /** The list reaches the page, without a coin in it. */
    public function test_the_card_page_publishes_the_currencies_it_may_hand_over(): void
    {
        $product = file_get_contents(resource_path('views/shop/product.blade.php'));

        $this->assertStringContainsString('checkoutCurrencies: @js($checkoutCurrencies)', $product);
        $this->assertStringContainsString('checkoutCurrencies: checkoutCurrencies || []', $product);
    }
}

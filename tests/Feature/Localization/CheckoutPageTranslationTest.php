<?php

namespace Tests\Feature\Localization;

use App\Models\SiteSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Js;
use Tests\TestCase;

/**
 * The cart and checkout pages go through the translator.
 *
 * Both were written in English straight into the blade, the payment wizard's
 * script messages included, so a French customer arrived at the one screen
 * where money changes hands and could not read it. The markup is rendered in
 * full whatever the cart holds (Alpine decides what shows), so an empty cart
 * is enough to see every string.
 */
class CheckoutPageTranslationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
    }

    /**
     * A French visitor's request. SetLocale resolves the locale from the
     * session on every request, so App::setLocale() alone is overwritten
     * before the view renders.
     */
    private function french(string $url): string
    {
        return $this->withSession(['locale' => 'fr'])->get($url)->assertOk()->getContent();
    }

    /** A string exactly as @js() writes it into the page script. */
    private function js(string $key): string
    {
        return Js::from(__($key, [], 'fr'))->toHtml();
    }

    public function test_the_cart_page_renders_french_copy(): void
    {
        $html = $this->french('/cart');

        $this->assertStringContainsString(__('checkout.proceed_to_checkout', [], 'fr'), $html);
        $this->assertStringContainsString(__('checkout.continue_shopping', [], 'fr'), $html);
        $this->assertStringContainsString(__('checkout.cart_fees_note', [], 'fr'), $html);
        $this->assertStringContainsString(__('checkout.decrease_quantity', [], 'fr'), $html);

        // The item count is chosen in the browser, so it ships as script strings.
        $this->assertStringContainsString($this->js('checkout.cart_item_count_other'), $html);
    }

    public function test_the_cart_page_drops_its_english_copy_in_french(): void
    {
        $html = $this->french('/cart');

        $this->assertStringNotContainsString('Proceed to checkout', $html);
        $this->assertStringNotContainsString('Continue shopping', $html);
        $this->assertStringNotContainsString('Transaction fees are added by the payment provider', $html);
        $this->assertStringNotContainsString("' item' + (", $html);
    }

    public function test_the_checkout_page_renders_french_copy(): void
    {
        $html = $this->french('/checkout');

        $this->assertStringContainsString(__('checkout.select_payment_method', [], 'fr'), $html);
        $this->assertStringContainsString(__('checkout.delivery_section', [], 'fr'), $html);
        $this->assertStringContainsString(__('checkout.delivery_as_gift', [], 'fr'), $html);
        $this->assertStringContainsString(__('checkout.secure_checkout_note', [], 'fr'), $html);

        // Delivery asks whose address it is, and the labels reach the page
        // through @js() into Alpine, so they are matched as they are encoded.
        $this->assertStringContainsString($this->js('checkout.your_delivery_email_label'), $html);
        $this->assertStringContainsString($this->js('checkout.recipient_email_label'), $html);
        $this->assertStringContainsString($this->js('checkout.sign_in_to_continue_amount'), $html);
    }

    /** The wizard is hidden until payment starts, but its copy ships with the page. */
    public function test_the_payment_wizard_renders_french_copy(): void
    {
        $html = $this->french('/checkout');

        $this->assertStringContainsString(__('checkout.card_pin_title', [], 'fr'), $html);
        $this->assertStringContainsString(__('checkout.crypto_send_exactly', [], 'fr'), $html);
        $this->assertStringContainsString(__('checkout.underpaid_title', [], 'fr'), $html);
        $this->assertStringContainsString(__('checkout.payment_failed_title', [], 'fr'), $html);

        // Messages the page script assigns, and the method tile labels.
        $this->assertStringContainsString(__('checkout.cancel_prompt_title', [], 'fr'), $html);
        $this->assertStringContainsString($this->js('checkout.cancel_prompt_cancelling'), $html);
        $this->assertStringContainsString($this->js('checkout.network_failed'), $html);
        $this->assertStringContainsString($this->js('checkout.method_bank_transfer'), $html);
    }

    /**
     * The live Rcoin figure is a markup placeholder: the French sentence wraps
     * around the same Alpine binding the English one did.
     */
    public function test_markup_placeholders_are_substituted_in_french(): void
    {
        $html = $this->french('/checkout');

        $this->assertStringContainsString(
            __('checkout.rcoin_apply_up_to_order', [
                'percent' => '<span class="font-semibold" x-text="rcoinConfig.redemption_max_pct + \'%\'"></span>',
            ], 'fr'),
            $html
        );
        $this->assertStringNotContainsString(':percent', $html, 'a placeholder reached the page unsubstituted');
        $this->assertStringNotContainsString(':coin', $html, 'a placeholder reached the page unsubstituted');
    }

    public function test_the_checkout_page_drops_its_english_copy_in_french(): void
    {
        $html = $this->french('/checkout');

        $this->assertStringNotContainsString('Select payment method', $html);
        $this->assertStringNotContainsString('Send as a gift', $html);
        $this->assertStringNotContainsString('Your delivery email', $html);
        $this->assertStringNotContainsString('Recipient email', $html);
        $this->assertStringNotContainsString('Sign in to continue', $html);
        $this->assertStringNotContainsString('Card PIN Required', $html);
        $this->assertStringNotContainsString('Cancel this payment?', $html);
        $this->assertStringNotContainsString("label: 'Bank Transfer'", $html);
    }

    public function test_a_signed_in_french_customer_sees_the_french_pay_button(): void
    {
        $html = $this->actingAs(User::factory()->create())
            ->withSession(['locale' => 'fr'])
            ->get('/checkout')
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString($this->js('checkout.continue_with_method'), $html);
        $this->assertStringNotContainsString('Continue with', $html);
    }

    /** The outage notice is assembled from rail names, so each piece is translated. */
    public function test_the_outage_notice_renders_in_french(): void
    {
        SiteSetting::put('features.gateway_nowpayments_enabled', 'off', 'features');

        $html = $this->french('/checkout');

        $this->assertStringContainsString(
            __('checkout.outage_one_unavailable', ['rails' => __('checkout.outage_rail_crypto', [], 'fr')], 'fr'),
            $html
        );
        $this->assertStringNotContainsString('temporarily unavailable', $html);
    }

    /** The English source still reads exactly as it did before extraction. */
    public function test_the_cart_page_is_unchanged_in_english(): void
    {
        $html = $this->get('/cart')->assertOk()->getContent();

        $this->assertStringContainsString('Proceed to checkout', $html);
        $this->assertStringContainsString('Continue shopping', $html);
        $this->assertStringContainsString('Transaction fees are added by the payment provider at checkout.', $html);
        $this->assertStringContainsString('aria-label="Decrease quantity"', $html);
        $this->assertStringContainsString("':count items'", $html);
    }

    public function test_the_checkout_page_is_unchanged_in_english(): void
    {
        $html = $this->get('/checkout')->assertOk()->getContent();

        $this->assertStringContainsString('Checkout', $html);
        $this->assertStringContainsString('Delivery', $html);
        $this->assertStringContainsString('Send as a gift', $html);
        $this->assertStringContainsString('Order subtotal', $html);
        $this->assertStringContainsString('Total due today', $html);
        $this->assertStringContainsString(Js::from('Your delivery email')->toHtml(), $html);
        $this->assertStringContainsString(Js::from('The gift codes will be sent directly to this address after payment.')->toHtml(), $html);
        $this->assertStringContainsString(Js::from('Sign in to continue · :amount')->toHtml(), $html);
        $this->assertStringContainsString('Card PIN Required', $html);

        // The currency chooser left this page; CheckoutCurrencyHandoffTest
        // holds that claim and the handoff that replaces it.
        $this->assertStringNotContainsString('checkout_currency_choice', $html);
        $this->assertStringContainsString('Cancel this payment?', $html);
        $this->assertStringContainsString(
            'Apply up to <span class="font-semibold" x-text="rcoinConfig.redemption_max_pct + \'%\'"></span> of your order',
            $html
        );
        $this->assertStringContainsString(
            'Sending a <span class="font-bold">different coin</span> or on a <span class="font-bold">different network</span> will result in <span class="font-bold">loss of your funds</span>.',
            $html
        );
    }

    /** Every key the English checkout file defines exists in French, and back. */
    public function test_the_french_checkout_file_is_complete(): void
    {
        $en = require lang_path('en/checkout.php');
        $fr = require lang_path('fr/checkout.php');

        $this->assertSame([], array_keys(array_diff_key($en, $fr)), 'French is missing checkout keys');
        $this->assertSame([], array_keys(array_diff_key($fr, $en)), 'French defines checkout keys English does not');
    }

    /**
     * A key copied across untranslated passes the completeness check but
     * still shows a French customer English. Only words French shares with
     * English may match.
     */
    public function test_french_checkout_copy_is_translated_not_copied(): void
    {
        $en = require lang_path('en/checkout.php');
        $fr = require lang_path('fr/checkout.php');

        $shared = ['total', 'currency_eur', 'method_mobile_money', 'method_crypto'];

        $copied = array_keys(array_filter(
            $en,
            fn ($value, $key) => ! in_array($key, $shared, true) && ($fr[$key] ?? null) === $value,
            ARRAY_FILTER_USE_BOTH
        ));

        $this->assertSame([], $copied, 'These French checkout strings are still English');
    }
}

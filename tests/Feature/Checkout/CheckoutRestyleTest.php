<?php

namespace Tests\Feature\Checkout;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The checkout's look changed and nothing it does may have.
 *
 * These are source checks on purpose. The page renders its cart from the Alpine
 * store after hydration, so a server render shows none of the item row, and the
 * Rcoin panel only appears for a signed-in customer with a balance. What matters
 * here is that each restyled control is still the same control underneath: the
 * same input, the same binding, the same value posted to the same form.
 */
class CheckoutRestyleTest extends TestCase
{
    use RefreshDatabase;

    private function source(): string
    {
        return file_get_contents(resource_path('views/shop/checkout.blade.php'));
    }

    /**
     * The currency chips were here and are not any more: the page does not ask
     * which currency to pay in at all. CheckoutCurrencyHandoffTest carries that
     * claim and the handoff from the card page that makes it safe.
     */

    /**
     * The payment-method tiles keep the card radius, and must not be swept
     * into pills with the buttons around them.
     *
     * A tile is 74px tall and holds two lines (label over "Visa, Mastercard",
     * "MTN, Orange, Vodafone, M-Pesa"). rounded-full on a box that tall is not
     * a pill: the radius resolves to half the height, so it draws an ellipse -
     * the label wraps against the curve and the selected tick floats off the
     * corner. 12px is what the coin tiles in the crypto panel below it use,
     * which is the selector it sits beside.
     */
    public function test_the_payment_method_tiles_are_cards_not_pills(): void
    {
        $source = $this->source();

        $this->assertStringContainsString(
            'class="relative flex min-h-[74px] items-start gap-2.5 rounded-[12px] border bg-white px-3 py-3 text-left',
            $source
        );
        $this->assertStringNotContainsString('min-h-[74px] items-start gap-2.5 rounded-full', $source);

        // The selector directly below it, for the same reason and at the same radius.
        $this->assertStringContainsString('class="flex flex-col items-center gap-1 rounded-[12px] border bg-white px-2 py-2.5 text-center', $source);
    }

    /** Primary checkout actions use the same compact pill treatment. */
    public function test_the_primary_checkout_actions_are_pills(): void
    {
        $source = $this->source();

        $this->assertStringContainsString('class="flex h-11 w-full items-center justify-center gap-2 rounded-full bg-blue-600', $source);
        $this->assertStringContainsString('class="flex h-11 w-full items-center justify-center rounded-full bg-blue-600', $source);
        $this->assertStringNotContainsString('rounded-[12px] bg-blue-600 px-4 py-3.5 text-base font-bold', $source);

        // The submit is still the submit, held by the same three conditions.
        $this->assertStringContainsString(
            '<button type="submit" :disabled="!canContinue() || submitting || (backoffApplies() && ! backoffLocked)"',
            $source
        );
    }

    /** Mobile money cannot be continued without a number: the button waits for one. */
    public function test_the_continue_button_waits_for_a_mobile_money_number(): void
    {
        $source = $this->source();

        $this->assertStringContainsString('name="momo_phone" type="tel" autocomplete="tel" x-model="momoPhone"', $source);
        $this->assertStringContainsString("if (this.method === 'mobile_money') {", $source);
        $this->assertStringContainsString("return this.momoPhone.replace(/\D/g, '').length >= 8;", $source);
    }

    /** The dialling code sits beside the number: a chip for one country, a picker where the franc serves several. */
    public function test_the_number_field_shows_its_dialling_code(): void
    {
        $source = $this->source();

        $this->assertStringContainsString('dialsByCurrency: @js($momoDialByCurrency)', $source);
        // One country: a plain pill. Several: a styled listbox, not the browser's select.
        $this->assertStringContainsString('<span x-show="momoDials.length === 1" x-text="\'+\' + (momoDials[0]?.dial ?? \'\')"', $source);
        $this->assertStringContainsString('<div x-show="momoDials.length > 1" x-cloak x-data="{ open: false }"', $source);
        $this->assertStringNotContainsString('<select x-model="momoCountry"', $source);
        $this->assertStringContainsString('aria-haspopup="listbox"', $source);

        // Plain awareness under the payment method heading, whatever method is picked:
        // the currency is chosen in Preferences. Not inside the mobile money panel.
        $this->assertStringContainsString("{{ __('checkout.payment_method') }}</h3>", $source);
        $this->assertStringContainsString("{{ __('checkout.currency_note') }}", $source);
        $this->assertStringNotContainsString("{{ __('checkout.currency_note') }}", $mobileMoneyPanel = str($source)->between('{{-- Mobile money --}}', '{{-- Crypto --}}')->toString());
        $this->assertStringNotContainsString('momoHasOtherMarkets', $source);
        $this->assertSame('You can select your preferred currency in Preferences.', __('checkout.currency_note'));
        $this->assertNotSame('checkout.currency_note', __('checkout.currency_note', [], 'fr'));
        $this->assertStringContainsString('@click="momoCountry = d.country; open = false"', $source);
        $this->assertStringContainsString('@keydown.arrow-down.prevent="$focus.wrap().next()"', $source);
        $this->assertStringContainsString("x-text=\"'+' + d.dial\"", $source);
        $this->assertStringNotContainsString("'+' + d.dial + ' ' + d.name", $source, 'codes only, no country names');
        $this->assertStringContainsString("country: this.momoCountry || ''", $source);

        // The network chips follow the country picked, from the same coverage the server checks.
        $this->assertStringContainsString('coverageByCurrency: @js($momoCoverageByCurrency)', $source);
        $this->assertStringContainsString('this.coverageByCurrency[this.$store.cart.currency]?.[this.momoCountry] ?? []', $source);
    }

    /**
     * A gateway popup closes our payment sheet while it has the screen. When
     * the popup reports success and our verification confirms it, the sheet
     * must reopen for the success state, or the customer sees nothing and the
     * page appears to jump straight to the order.
     */
    public function test_the_success_state_reopens_the_sheet_after_a_popup_payment(): void
    {
        $source = $this->source();
        $successBranch = str($source)->after("if (verifyData.status === 'confirmed') {")->before('} else {')->toString();

        $this->assertStringContainsString("self.paymentState = 'success';", $successBranch);
        $this->assertStringContainsString('self.open = true;', $successBranch);
        $this->assertStringContainsString('window.location.href = self.redirectUrl;', $successBranch);
    }

    /** Remove is an X now, which means it has to say what it is some other way. */
    public function test_remove_is_an_icon_that_still_names_itself(): void
    {
        $source = $this->source();

        $this->assertStringContainsString('@click="$store.cart.remove(item.id)"', $source);
        $this->assertStringContainsString('aria-label="{{ __(\'checkout.remove\') }}"', $source);
        $this->assertStringNotContainsString('text-xs font-semibold text-red-600', $source);
    }

    /**
     * The switch is drawn over the same checkbox. If the input, its binding or
     * the hidden field it feeds ever drifted, a customer's Rcoin would silently
     * stop being applied, and nothing on screen would say so.
     */
    public function test_the_rcoin_switch_is_the_same_control_underneath(): void
    {
        $source = $this->source();

        $this->assertStringContainsString('<input type="hidden" name="apply_rcoin" :value="applyRcoin" form="checkout-form">', $source);
        $this->assertStringContainsString('role="switch"', $source);
        $this->assertStringContainsString(":checked=\"applyRcoin !== ''\"", $source);
        $this->assertStringContainsString("@change=\"applyRcoin = \$event.target.checked ? '1' : ''\"", $source);
        $this->assertStringContainsString('class="peer sr-only"', $source);
        $this->assertStringContainsString('peer-checked:bg-blue-600', $source);
        $this->assertStringContainsString('peer-checked:translate-x-5', $source);

        // The partial / full picker under it is untouched.
        $this->assertStringContainsString("@click=\"applyRcoin = 'full'\"", $source);
    }

    /** The coupon row is a row, and it says whether it is open. */
    public function test_the_coupon_row_is_a_row_that_reports_its_state(): void
    {
        $source = $this->source();

        $this->assertStringContainsString(':aria-expanded="open.toString()"', $source);
        $this->assertStringContainsString(":class=\"open && 'scale-y-0'\"", $source);
        $this->assertStringContainsString("{{ __('checkout.coupon_toggle') }}", $source);

        // What it opens is what it always opened.
        $this->assertStringContainsString('x-model="couponCode"', $source);
        $this->assertStringContainsString('applyCoupon()', $source);
    }

    /**
     * A chosen tile used to be marked with bg-blue-50, which this app remaps to
     * a neutral grey, so "selected" read as "greyed out". Method and coin tiles
     * now share the value tiles' state: a blue edge and ring, no fill change.
     */
    public function test_a_chosen_tile_is_ringed_not_greyed(): void
    {
        $source = $this->source();

        $this->assertStringNotContainsString("'border-blue-600 bg-blue-50 ring-1 ring-blue-500/20'", $source);

        // The peer-checked form of this lived on the currency chip's span and
        // went with it; what remains is the method and coin tiles' own state.
        $this->assertStringContainsString("'border-blue-600 ring-1 ring-blue-600 dark:border-blue-500 dark:ring-blue-500'", $source);
    }

    /**
     * The coin mark is round, and the network line under it stays: that line is
     * what stops a customer sending on the wrong chain.
     */
    public function test_coin_tiles_keep_their_network_line(): void
    {
        $source = $this->source();

        $this->assertStringContainsString('class="h-8 w-8 rounded-full"', $source);
        $this->assertStringContainsString('{{ $network }}', $source);
        $this->assertStringContainsString("coinUsable('{{ \$coin->code }}') && (crypto = '{{ \$coin->code }}')", $source);
    }

    /** Method artwork is vector, and mobile networks are visible branded chips. */
    public function test_payment_methods_use_svg_assets_and_mobile_network_brand_chips(): void
    {
        $source = $this->source();

        foreach ([
            'payment-card.svg',
            'payment-mobile-money.svg',
            'payment-bank-transfer.svg',
            'payment-pay-with-bank.svg',
            'payment-ussd.svg',
            'payment-bank-qr.svg',
            'payment-mobile-wallet.svg',
            'payment-apple-pay.svg',
            'mtn.svg',
            'orange.svg',
        ] as $asset) {
            $this->assertFileExists(public_path('assets/'.$asset));
        }

        $this->assertStringContainsString("networkIcons: { MTN: '/assets/mtn.svg', Orange: '/assets/orange.svg' }", $source);
        $this->assertStringContainsString('role="radiogroup" aria-labelledby="momo-network-label"', $source);
        $this->assertStringContainsString(':aria-checked="(value === net).toString()"', $source);
        $this->assertStringContainsString('@click="value = net"', $source);
        $this->assertStringContainsString('name="momo_network" :value="value"', $source);
        $this->assertStringContainsString(':src="networkIcon(net)"', $source);

        // Networks stay chips; the only dropdown in the section is the dialling code beside the number.
        $mobileMoney = str($source)->between('{{-- Mobile money --}}', '{{-- Crypto --}}')->toString();
        $this->assertStringNotContainsString('@click="open = !open"', $mobileMoney);
        $this->assertSame(1, substr_count($mobileMoney, 'role="listbox"'));
        $this->assertMatchesRegularExpression('/role="listbox"\s+aria-label="\{\{ __\(\x27checkout\.momo_number_label\x27\) \}\}"/', $mobileMoney);
        $this->assertStringNotContainsString('methodGlyphs', $source);
        $this->assertStringNotContainsString('credit%20card%20payment.webp', $source);
        $this->assertStringNotContainsString('Bank%20transfer.webp', $source);
        $this->assertStringNotContainsString('apply%20pay.webp', $source);
    }

    /** The approved audit refinements preserve the form while clarifying its flow. */
    public function test_the_checkout_refinement_keeps_critical_controls_and_adds_clear_hierarchy(): void
    {
        $source = $this->source();

        $this->assertStringContainsString('lg:grid-cols-5 lg:items-start', $source);
        $this->assertStringContainsString('lg:sticky lg:top-6 lg:col-span-2', $source);
        $this->assertStringContainsString('sm:p-6 lg:col-span-3', $source);
        $this->assertStringContainsString('lg:sticky lg:top-6', $source);
        $this->assertStringNotContainsString('gift_region_title', $source);
        $this->assertStringContainsString("deliveryMode = 'self'", $source);
        $this->assertStringContainsString("deliveryMode = 'gift'", $source);
        $this->assertStringContainsString('transition-transform duration-300 ease-out', $source);
        $this->assertStringContainsString("deliveryMode === 'gift' ? 'translate-x-full' : 'translate-x-0'", $source);
        $this->assertStringContainsString('id="delivery_email" name="delivery_email" type="email" required', $source);
        $this->assertStringContainsString('x-text="feeLabel()"', $source);
        $this->assertStringContainsString('aria-live="polite"', $source);
        $this->assertStringContainsString("replace(':method', () => methodLabel()).replace(':amount', () => totalLabel())", $source);
    }
}

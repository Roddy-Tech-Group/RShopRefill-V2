<?php

namespace Tests\Feature\Catalog;

use App\Models\Category;
use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A gift card page is a wide column of writing with a narrow buy rail beside it.
 * The writing carries the art and the values it belongs to side by side; the rail
 * carries nothing but the purchase card: the brand mark with the chosen value,
 * the quantity, the price, and the two buttons. The plate, the amount fields and
 * the buy actions are shared partials, so top-ups and bills keep their own
 * two-column panel from the same markup.
 */
class GiftCardPurchaseCardTest extends TestCase
{
    use RefreshDatabase;

    private function page(bool $available = true): string
    {
        $category = Category::create(['name' => 'Gift Cards', 'slug' => 'gift-cards', 'type' => 'digital']);

        $product = Product::create([
            'category_id' => $category->id,
            'provider_name' => 'zendit',
            'brand_key' => 'Acme',
            'country_code' => 'US',
            'currency_code' => 'USD',
            'name' => 'Acme Gift Card',
            'slug' => 'acme-gift-card',
            'is_active' => true,
        ]);

        foreach ([10, 25] as $value) {
            ProductVariant::factory()->for($product)->create([
                'currency' => 'USD',
                'face_value' => $value,
                'retail_price' => $value,
                'manual_retail_price_usd' => $value,
                'is_available' => $available,
                'is_variable' => false,
            ]);
        }

        return $this->withoutVite()->get(route('shop.brand', ['brandSlug' => 'acme']))->assertOk()->getContent();
    }

    /** The purchase card, open tag of the aside through its close. */
    private function card(string $html): string
    {
        preg_match('/<aside class="pure-card w-full rounded-\[20px\][^>]*>.*?<\/aside>/s', $html, $m);
        $this->assertNotEmpty($m, 'The purchase card did not render.');

        return $m[0];
    }

    public function test_the_page_is_a_wide_column_with_a_narrow_buy_rail(): void
    {
        $html = $this->page();

        $this->assertStringContainsString('lg:grid-cols-[minmax(0,1fr)_360px]', $html);
        // The rail starts on the second row, level with the art rather than the heading.
        $this->assertStringContainsString('max-lg:contents lg:col-start-2 lg:row-start-2', $html);
        $this->assertStringContainsString('max-lg:order-1 lg:col-start-1 lg:row-start-1', $html);
        $this->assertStringContainsString('max-lg:order-2 lg:col-start-1 lg:row-start-2', $html);
        $this->assertStringContainsString('max-lg:order-4 lg:col-start-1 lg:row-start-3', $html);

        // Narrower than the nav and the listing pages, which run the full 1550px.
        $this->assertStringContainsString('mx-auto w-full max-w-7xl px-4 py-6', $html);
        $this->assertStringNotContainsString('max-w-[1550px] px-4 py-6', $html);

        // The other kinds keep the even split; a source check, because building a
        // top-up fixture here would test the fixture.
        $source = file_get_contents(resource_path('views/shop/product.blade.php'));
        $this->assertStringContainsString("'lg:grid-cols-[minmax(0,1fr)_360px]' : 'lg:grid-cols-2'", $source);
    }

    public function test_the_rail_holds_the_card_and_nothing_else(): void
    {
        $html = $this->page();

        $railStart = strpos($html, 'lg:sticky lg:top-[156px]');
        $this->assertNotFalse($railStart, 'The rail did not render.');

        // Between the rail opening and the card there is nothing but whitespace.
        $cardStart = strpos($html, '<aside class="pure-card w-full rounded-[20px]');
        $between = substr($html, strpos($html, '>', $railStart) + 1, $cardStart - strpos($html, '>', $railStart) - 1);
        $this->assertSame('', trim($between), 'Something else is standing in the buy rail.');

        // And the art is not in it: that belongs beside the values.
        $this->assertStringNotContainsString('rounded-[24px] bg-[#e8e8f7]', $this->card($html));
    }

    public function test_the_art_and_the_values_stand_side_by_side_in_the_writing(): void
    {
        $html = $this->page();

        $this->assertStringContainsString('grid gap-5 sm:grid-cols-[minmax(0,300px)_minmax(0,1fr)]', $html);

        $subGrid = strpos($html, 'grid gap-5 sm:grid-cols-[minmax(0,300px)_minmax(0,1fr)]');
        $plate = strpos($html, 'rounded-[24px] bg-[#e8e8f7]');
        $values = strpos($html, 'role="radiogroup" aria-label="Amount"');

        // The plate opens the pair, the values follow it, both inside the sub-grid.
        $this->assertGreaterThan($subGrid, $plate);
        $this->assertGreaterThan($plate, $values);

        // The heading leads the page, and where the card can be redeemed is read
        // before any value is chosen, in place of the old kind badge.
        $this->assertLessThan($subGrid, strpos($html, '<h1 class="text-[24px]'));
        $this->assertLessThan($subGrid, strpos($html, 'Redeemable only in'));
        $this->assertStringNotContainsString('bg-blue-600 px-3 py-1 text-xs font-bold uppercase', $html);

        // The tiles speak for themselves, so the field carries no label.
        $this->assertStringNotContainsString('>Amount</label>', $html);
    }

    /** The art itself is not part of this change: same plate, same image classes. */
    public function test_the_plate_is_one_shared_partial_and_is_untouched(): void
    {
        $html = $this->page();

        $this->assertStringContainsString('pure-card flex items-center justify-center rounded-[24px] bg-[#e8e8f7]', $html);
        $this->assertStringContainsString('relative flex aspect-[8/5] w-full items-center justify-center overflow-hidden rounded-[20px] keep-light bg-[#ffffff]', $html);

        // Drawn once on the page, from the partial the other kinds also use.
        $this->assertSame(1, substr_count($html, 'rounded-[24px] bg-[#e8e8f7]'));
        $source = file_get_contents(resource_path('views/shop/product.blade.php'));
        $this->assertSame(3, substr_count($source, "@include('shop._brand-plate'"));
        $this->assertStringNotContainsString('rounded-[24px] bg-[#e8e8f7]', $source);
    }

    /** On a phone: the heading, the art with its values, then the card, then the rest. */
    public function test_the_phone_order_puts_the_card_after_the_values(): void
    {
        $html = $this->page();

        // The rail dissolves so its card can be ordered among the writing's regions.
        $this->assertSame(2, substr_count($html, 'max-lg:contents'));
        $this->assertStringContainsString('max-lg:order-3', $this->card($html));
    }

    public function test_the_card_holds_the_brand_the_chosen_value_the_quantity_and_the_price(): void
    {
        $html = $this->page();
        $card = $this->card($html);

        // Brand, and the chosen value read from the selected variant.
        $this->assertStringContainsString('>Acme<', $card);
        $this->assertStringContainsString('variants.find(v => v.id === selectedVariantId)', $card);
        $this->assertStringContainsString('Gift Card', $card);

        // The estimated price, large, from the same formatter as everywhere else.
        // The tiles carry face values only, so this is the one price on the page.
        $this->assertStringContainsString('Estimated price', $card);
        $this->assertMatchesRegularExpression('/text-\[32px\] font-bold[^>]*x-text="formatCrypto\(\)"/', $card);
        $this->assertSame(1, substr_count($html, 'Estimated price'));
    }

    /** Quantity is the site's own dropdown, not the browser's. */
    public function test_the_quantity_picker_is_not_a_native_select(): void
    {
        $html = $this->page();
        $card = $this->card($html);

        $this->assertStringNotContainsString('<select', $card);
        $this->assertStringContainsString('id="gift-quantity"', $card);
        $this->assertStringContainsString('aria-haspopup="listbox"', $card);
        $this->assertStringContainsString('<span x-text="quantity">1</span>', $card);

        // One to ten, each setting the page's own quantity. Counted inside the
        // quantity picker's own listbox: the card holds a second listbox, the
        // pay currency, and its options carry the role too.
        $quantityList = substr($card, strpos($card, 'id="gift-quantity"'));
        $quantityList = substr($quantityList, 0, strpos($quantityList, '</div>', strpos($quantityList, 'role="listbox"')));

        $this->assertSame(10, substr_count($quantityList, 'role="option"'));
        $this->assertStringContainsString('@click="quantity = 10; open = false"', $card);
    }

    /**
     * The pay currency is chosen in the card, directly above the price it is
     * quoted in, and it names the currency rather than repeating the total.
     */
    public function test_the_pay_currency_stands_above_the_price_in_the_card(): void
    {
        $html = $this->page();
        $card = $this->card($html);

        $this->assertStringContainsString("(cryptos[selectedCrypto]?.name || selectedCrypto) + ' (' + selectedCrypto + ')'", $card);
        $this->assertStringContainsString('@click="selectedCrypto = code; open = false"', $card);

        // Above the estimated price, and one picker on the page (the locale modal
        // names the same thing, hence the label markup rather than the words).
        $label = '<label class="mb-2 block text-base font-bold text-zinc-900 dark:text-zinc-200">Payment currency</label>';
        $this->assertSame(1, substr_count($html, $label));
        $this->assertLessThan(strpos($card, 'Estimated price'), strpos($card, $label));
    }

    /**
     * The currency list opens because someone asked it to.
     *
     * It used to open on hover, which put it on screen unasked and, worse, made
     * the button argue with itself: the hover had already opened it, so the
     * click that followed read as a toggle and shut it again. Pointing at the
     * control and clicking it did nothing at all.
     *
     * Asserted on the partial rather than the page because the listeners are
     * what is being removed, and an absent attribute cannot be measured from
     * rendered output.
     */
    public function test_the_pay_currency_list_opens_on_click_and_never_on_hover(): void
    {
        $picker = file_get_contents(resource_path('views/shop/_pay-currency-picker.blade.php'));

        $this->assertStringNotContainsString('@mouseenter', $picker);
        $this->assertStringNotContainsString('@mouseleave', $picker);

        // Opens, closes the same way, and closes on the two escapes a menu owes.
        $this->assertStringContainsString('@click="open = ! open"', $picker);
        $this->assertStringContainsString('@click.outside="open = false"', $picker);
        $this->assertStringContainsString('@keydown.escape="open = false"', $picker);

        // And it says what it is, which the hover version never did.
        $card = $this->card($this->page());
        $this->assertStringContainsString('aria-haspopup="listbox"', $card);
        $this->assertStringContainsString(':aria-expanded="open.toString()"', $card);
    }

    public function test_the_buy_actions_are_drawn_once_inside_the_card(): void
    {
        $html = $this->page();
        $card = $this->card($html);

        $this->assertStringContainsString('<div class="grid grid-cols-1 gap-3">', $card);
        $this->assertStringContainsString('@click="addToCart()"', $card);
        $this->assertStringContainsString('@click="buyNow()"', $card);
        $this->assertStringContainsString('Points you earn', $card);

        // Add to cart leads as the filled button with a cart mark; Buy now is quiet.
        $this->assertStringContainsString('border-blue-600 bg-blue-600 text-white hover:bg-blue-700', $card);
        $this->assertStringContainsString('bg-zinc-100 text-zinc-900 hover:bg-zinc-200', $card);

        // One copy on the whole page.
        $this->assertSame(1, substr_count($html, '@click="addToCart()"'));
        $this->assertSame(1, substr_count($html, '@click="buyNow()"'));
    }

    /** The other kinds include the same partials from the buy panel, never a fork. */
    public function test_the_buy_actions_and_amount_fields_are_shared_partials(): void
    {
        $source = file_get_contents(resource_path('views/shop/product.blade.php'));

        $this->assertSame(2, substr_count($source, "@include('shop._buy-actions')"));
        $this->assertSame(2, substr_count($source, "@include('shop._amount-fields')"));
        $this->assertStringNotContainsString('@click="addToCart()"', $source);
        $this->assertStringContainsString('@click="addToCart()"', file_get_contents(resource_path('views/shop/_buy-actions.blade.php')));

        // The panel's own pair keeps the outline-plus-filled shape it has always had.
        $partial = file_get_contents(resource_path('views/shop/_buy-actions.blade.php'));
        $this->assertStringContainsString("'border-blue-600 bg-white text-blue-600 hover:bg-blue-600 hover:text-white'", $partial);
        $this->assertStringContainsString("'bg-blue-600 text-white hover:bg-blue-700 disabled:hover:bg-blue-600'", $partial);
        $this->assertStringContainsString("\$isGiftCard ? 'grid-cols-1' : 'grid-cols-2'", $partial);
    }

    public function test_quantity_price_and_the_range_line_left_the_writing(): void
    {
        $html = $this->page();

        // The panel's own quantity dropdown is not drawn for a gift card.
        $this->assertStringNotContainsString('x-effect="quantity; flash()"', $html);

        // Every value is a tile, so the "Between x and y" line is dropped.
        $this->assertStringNotContainsString('Between', $html);

        // The amount cell has the row to itself.
        $this->assertStringContainsString('<div class="grid gap-3 max-w-[400px] grid-cols-1"', $html);
    }

    public function test_with_nothing_to_buy_the_plate_still_stands_and_there_is_no_card(): void
    {
        $html = $this->page(available: false);

        $this->assertStringNotContainsString('<aside class="pure-card w-full rounded-[20px]', $html);
        $this->assertStringNotContainsString('@click="addToCart()"', $html);
        $this->assertStringContainsString('Out of stock', $html);

        // The art is still on the page, and all three writing rows still open.
        // Only the card's own row (order-3) is missing, because there is no card.
        $this->assertSame(1, substr_count($html, 'rounded-[24px] bg-[#e8e8f7]'));
        $this->assertStringContainsString('max-lg:order-1 lg:col-start-1 lg:row-start-1', $html);
        $this->assertStringContainsString('max-lg:order-2 lg:col-start-1 lg:row-start-2', $html);
        $this->assertStringContainsString('max-lg:order-4 lg:col-start-1 lg:row-start-3', $html);
        $this->assertStringNotContainsString('max-lg:order-3', $html);
    }
}

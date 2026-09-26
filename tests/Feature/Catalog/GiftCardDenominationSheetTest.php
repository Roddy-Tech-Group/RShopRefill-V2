<?php

namespace Tests\Feature\Catalog;

use App\Models\Category;
use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A gift card with more than four values folds its grid into a sheet on a
 * phone.
 *
 * The catalogue carries cards with twenty-five values. Twenty-five squares is
 * a screen and a half of scrolling on a phone before the customer reaches the
 * button that buys one, so past four the phone gets the chosen value and its
 * price on a single row, and the grid moves behind it. Four or fewer is
 * already short enough to leave alone, and no width above sm ever hides the
 * grid: this is a phone layout, not a redesign.
 *
 * The tiles themselves live in one partial, included once. The sheet and the
 * desktop grid are the same element under two sets of rules, so the tile a
 * customer taps in the sheet is the tile they would have tapped on a desktop.
 */
class GiftCardDenominationSheetTest extends TestCase
{
    use RefreshDatabase;

    /** @param list<int|float> $values */
    private function giftCardPage(array $values, string $brand = 'Acme'): string
    {
        $category = Category::firstOrCreate(
            ['slug' => 'gift-cards'],
            ['name' => 'Gift Cards', 'type' => 'digital']
        );

        $product = Product::create([
            'category_id' => $category->id,
            'provider_name' => 'zendit',
            'brand_key' => $brand,
            'country_code' => 'US',
            'currency_code' => 'USD',
            'name' => $brand.' Gift Card',
            'slug' => strtolower($brand).'-gift-card',
            'is_active' => true,
        ]);

        foreach ($values as $value) {
            ProductVariant::factory()->for($product)->create([
                'currency' => 'USD',
                'face_value' => $value,
                'retail_price' => $value,
                'manual_retail_price_usd' => $value,
                'is_available' => true,
                'is_variable' => false,
            ]);
        }

        return $this->withoutVite()
            ->get(route('shop.brand', ['brandSlug' => strtolower($brand)]))
            ->assertOk()
            ->getContent();
    }

    public function test_more_than_four_values_fold_behind_a_summary_row(): void
    {
        $html = $this->giftCardPage([2, 5, 10, 25, 50]);

        // The row that opens it, and the sheet it opens.
        $this->assertTrue(str_contains($html, '@click="denomSheet = true"'), 'no way to open the values');
        $this->assertTrue(str_contains($html, 'class="denom-sheet"'), 'the values did not fold into a sheet');
        $this->assertTrue(str_contains($html, 'aria-modal="true"'), 'the sheet is not announced as one');
    }

    public function test_four_or_fewer_values_are_left_exactly_as_they_were(): void
    {
        $html = $this->giftCardPage([5, 10, 25, 50], 'Short');

        $this->assertFalse(str_contains($html, 'denomSheet = true'), 'a short card should not fold');
        $this->assertFalse(str_contains($html, 'class="denom-sheet"'), 'a short card should have no sheet');

        // And the grid is still right there on the page.
        $this->assertTrue(str_contains($html, 'grid grid-cols-4 gap-2'), 'the grid should be on the page');
    }

    /**
     * One copy of the tiles, not one for the sheet and one for the desktop.
     * Two would drift, and a customer would get whichever the breakpoint
     * happened to show them.
     */
    public function test_the_tiles_are_rendered_once(): void
    {
        $html = $this->giftCardPage([2, 5, 10, 25, 50, 100]);

        $this->assertSame(1, substr_count($html, 'grid grid-cols-4 gap-2'), 'the tile grid is duplicated');

        // Counted by the tile's own handler rather than by role=radio, which
        // the quantity and currency pickers on the same page also use.
        $this->assertSame(6, substr_count($html, 'customMode = false; denomSheet = false'), 'one tile per value, once');
    }

    /**
     * Closed is the default, written into the stylesheet rather than into a
     * binding. A bound class is not on the element until Alpine has run, so
     * the sheet would be fully open for the first paint of every long card.
     */
    public function test_the_sheet_starts_closed_before_any_javascript_runs(): void
    {
        $html = $this->giftCardPage([2, 5, 10, 25, 50]);

        // The attribute on the element, not the selector in the stylesheet
        // that names the open state.
        $this->assertFalse(str_contains($html, 'class="denom-sheet" data-open="true"'), 'the served page opens the sheet');
        preg_match('/<div\s[^>]*class="denom-sheet"[^>]*>/', $html, $sheetTag);
        $this->assertNotEmpty($sheetTag, 'the sheet element is missing');
        $this->assertStringNotContainsString('data-open="true"', $sheetTag[0], 'the sheet is rendered open');
        $this->assertTrue(str_contains($html, ':data-open="denomSheet ? \'true\' : \'false\'"'), 'nothing opens the sheet');
        $this->assertMatchesRegularExpression(
            '/\.denom-sheet \{[^}]*transform: translateY\(100%\);[^}]*visibility: hidden;/s',
            $html,
            'the closed state is not the default'
        );
        $this->assertMatchesRegularExpression(
            '/\.denom-sheet\[data-open="true"\] \{\s*transform: translateY\(0\);\s*visibility: visible;/',
            $html
        );
    }

    /** Above sm the sheet rules simply do not apply, so the desktop is untouched. */
    public function test_the_sheet_is_a_phone_layout_only(): void
    {
        $html = $this->giftCardPage([2, 5, 10, 25, 50]);

        $this->assertMatchesRegularExpression(
            '/@media \(max-width: 639\.98px\) \{\s*\.denom-sheet \{/',
            $html,
            'the sheet must be scoped to phone widths'
        );
        // The row that summarises it, and the sheet's own head, are phone-only too.
        $this->assertTrue(str_contains($html, 'sm:hidden'), 'the summary row should not show on a desktop');
    }

    /** Choosing a value is the whole interaction: pick, and the sheet goes. */
    public function test_choosing_a_value_closes_the_sheet(): void
    {
        $html = $this->giftCardPage([2, 5, 10, 25, 50]);

        $this->assertSame(
            5,
            substr_count($html, 'customMode = false; denomSheet = false'),
            'every tile should close the sheet when it is chosen'
        );
    }

    /** There is always a way out that is not choosing something. */
    public function test_the_sheet_can_be_dismissed_without_choosing(): void
    {
        $html = $this->giftCardPage([2, 5, 10, 25, 50]);

        $this->assertSame(2, substr_count($html, '@click="denomSheet = false"'), 'expected a backdrop and a close button');
        $this->assertTrue(str_contains($html, '@keydown.escape.window="denomSheet = false"'), 'escape should close it');
    }

    /** The row says what is chosen and what it costs, not just that something is. */
    public function test_the_summary_row_names_the_value_and_prices_it(): void
    {
        $html = $this->giftCardPage([2, 5, 10, 25, 50]);

        $this->assertTrue(str_contains($html, 'denomSummaries[selectedVariantId]'), 'the row does not name the chosen value');
        $this->assertTrue(str_contains($html, 'formatUsdIn(selectedCrypto, unitPriceUsd())'), 'the row does not price it');

        // Falls back to the prompt when nothing is chosen yet.
        $this->assertTrue(str_contains($html, e(__('products.detail_select_amount'))), 'no prompt before a choice');
    }

    /** The page state the sheet rides on exists. */
    public function test_the_page_carries_the_flag_the_sheet_reads(): void
    {
        $html = $this->giftCardPage([2, 5, 10, 25, 50]);

        $this->assertTrue(str_contains($html, 'denomSheet: false,'), 'the page state is missing the flag');
    }
}

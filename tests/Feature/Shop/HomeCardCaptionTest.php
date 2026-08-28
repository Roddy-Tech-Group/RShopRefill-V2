<?php

namespace Tests\Feature\Shop;

use Tests\TestCase;

/**
 * The home page's brand cards caption the same way the listing's tiles do.
 *
 * x-shop.brand-tile is the reference. x-home.brand-card had drifted: its
 * price range was muted grey (text-zinc-600 / dark:text-zinc-400) at a size
 * LARGER than the brand name above it, so the one number a shopper is
 * looking for was the faintest thing on the card while also being the
 * loudest. Both now carry name, rating and price at the same type.
 */
class HomeCardCaptionTest extends TestCase
{
    private function homeCard(): string
    {
        return file_get_contents(resource_path('views/components/home/brand-card.blade.php'));
    }

    private function listingTile(): string
    {
        return file_get_contents(resource_path('views/components/shop/brand-tile.blade.php'));
    }

    public function test_the_price_reads_as_full_strength_ink_on_both_surfaces(): void
    {
        $priceClasses = 'mt-0.5 truncate text-[14px] font-bold text-zinc-900 dark:text-white';

        $this->assertStringContainsString($priceClasses, $this->listingTile());
        $this->assertStringContainsString($priceClasses, $this->homeCard());
    }

    /** The muted treatment is gone, not merely overridden further down. */
    public function test_the_home_price_is_no_longer_muted_grey(): void
    {
        $this->assertStringNotContainsString(
            'text-[16px] text-zinc-600 dark:text-zinc-400',
            $this->homeCard()
        );
    }

    public function test_the_name_and_rating_match_the_listing_tile(): void
    {
        $name = 'min-w-0 flex-1 truncate text-[16px] font-bold leading-tight text-zinc-900 dark:text-white';
        $rating = 'inline-flex shrink-0 items-center gap-1 text-[16px] font-bold leading-tight text-zinc-900 dark:text-white';

        foreach ([$this->homeCard(), $this->listingTile()] as $markup) {
            $this->assertStringContainsString($name, $markup);
            $this->assertStringContainsString($rating, $markup);
        }
    }

    /** The row subtitle carries more ink than the lightest grey on the page. */
    public function test_the_row_subtitle_is_legible(): void
    {
        $row = file_get_contents(resource_path('views/components/home/brand-row.blade.php'));

        $this->assertStringContainsString('text-base text-zinc-700 dark:text-zinc-300', $row);
        $this->assertStringNotContainsString('text-base text-zinc-600 dark:text-zinc-400', $row);
    }
}

<?php

namespace Tests\Feature\Shop;

use App\Models\Category;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * How it works, step 1: the fanned cards are real catalogue artwork (gift
 * cards, top-up operators, eSIMs) instead of abstract shapes, and fall back to
 * the original drawing when there is no catalogue to show.
 */
class CardFanIllustrationTest extends TestCase
{
    use RefreshDatabase;

    private function render(): string
    {
        Cache::flush();

        return Blade::render('<x-illo name="cardFan" />');
    }

    private function product(Category $category, string $brandKey, string $country = 'US'): void
    {
        Product::factory()->create([
            'category_id' => $category->id,
            'brand_key' => $brandKey,
            'country_code' => $country,
            'is_active' => true,
        ]);
    }

    public function test_the_cards_show_real_gift_card_and_top_up_art(): void
    {
        config()->set('popular_brands.keys', ['Amazon', 'Netflix']);

        $gifts = Category::factory()->create(['name' => 'Gift Cards', 'slug' => 'gift-cards']);
        $topups = Category::factory()->create(['name' => 'Mobile Airtime', 'slug' => 'mobile-airtime']);

        $this->product($gifts, 'Amazon');
        $this->product($gifts, 'Netflix');
        $this->product($topups, 'MTN', 'CM');

        $svg = $this->render();

        foreach (['amazon.webp', 'netflix.webp', 'mtn.webp'] as $file) {
            $this->assertStringContainsString('href="'.asset('assets/'.$file).'"', $svg, $file.' is not on a card.');
        }

        // Seven cards whatever the catalogue holds, so the GSAP stagger is unchanged.
        $this->assertSame(7, substr_count($svg, 'class="gc-card"'));
        $this->assertSame(3, substr_count($svg, 'data-fan-kind="art"'));

        // The front card (painted last) is the first gift card.
        $front = strrpos($svg, 'class="gc-card"');
        $this->assertStringContainsString('amazon.webp', substr($svg, $front));

        $this->assertStringContainsString('aria-label="Gift cards, mobile top-ups and eSIMs, including Amazon', $svg);
    }

    /** A brand with no hand-made artwork is left out rather than shown as a grey placeholder. */
    public function test_brands_without_local_artwork_are_skipped(): void
    {
        config()->set('popular_brands.keys', ['Amazon', 'NoArtBrand']);

        $gifts = Category::factory()->create(['name' => 'Gift Cards', 'slug' => 'gift-cards']);
        $this->product($gifts, 'Amazon');
        $this->product($gifts, 'NoArtBrand');

        $svg = $this->render();

        $this->assertSame(1, substr_count($svg, 'data-fan-kind="art"'));
        $this->assertStringNotContainsString('NoArtBrand', $svg);
    }

    /** No catalogue at all: the original abstract fan, not seven blank cards. */
    public function test_an_empty_catalogue_keeps_the_original_drawing(): void
    {
        $svg = $this->render();

        $this->assertStringContainsString('aria-label="Choose your gift card"', $svg);
        $this->assertSame(7, substr_count($svg, 'class="gc-card"'));
        $this->assertStringNotContainsString('data-fan-kind', $svg);
    }
}

<?php

namespace Tests\Feature\Dashboard;

use App\Models\Category;
use App\Models\Product;
use App\Models\User;
use App\Support\CatalogFigures;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The four PURCHASE cards on the dashboard say what the catalogue actually
 * holds. Every figure is counted from the products table, never typed into the
 * template, so a card can not go on advertising a country the store stopped
 * selling.
 *
 * The eSIM card names both halves of what the packages carry: the data and the
 * number. A plan with a number is the reason to pick one over a data-only
 * plan, and the card is where that choice starts.
 *
 * The overview is a lazy component, so every case here disables lazy loading.
 * Left on, the test would be reading the skeleton placeholder rather than the
 * cards. Assertions are made against a substring check rather than assertSee
 * for the same reason the rest of this suite does: a failed assertSee prints
 * the whole page, which takes minutes and reads as a hung run.
 */
class PurchaseCardFiguresTest extends TestCase
{
    use RefreshDatabase;

    /** The overview's rendered HTML, with the lazy placeholder skipped. */
    private function overview(): string
    {
        return Livewire::withoutLazyLoading()->test('dashboard.overview')->html();
    }

    /** Products in a category, each in its own country. */
    private function stock(string $slug, array $countryCodes): void
    {
        $category = Category::firstOrCreate(
            ['slug' => $slug],
            ['name' => ucfirst($slug), 'type' => 'digital', 'is_active' => true]
        );

        foreach ($countryCodes as $i => $cc) {
            Product::factory()->create([
                'category_id' => $category->id,
                'country_code' => $cc,
                'brand_key' => $slug.'-brand-'.$cc.'-'.$i,
                'is_active' => true,
            ]);
        }

        Cache::forget('dashboard.purchase-stats');
    }

    public function test_the_esim_card_names_data_and_calls_for_the_countries_on_sale(): void
    {
        $this->stock('esims', ['US', 'GB', 'NG']);

        $this->assertSame(3, CatalogFigures::purchaseStats()['esims']['countries'] ?? 0);

        $this->actingAs(User::factory()->create());
        $html = $this->overview();

        $this->assertTrue(str_contains($html, 'Data, Calls for 3 countries'), 'the eSIM card does not name data and calls');
        $this->assertFalse(str_contains($html, 'Data plans for'), 'the old data-only wording is still there');
    }

    /** One country is one country, not "1 countries". */
    public function test_a_single_country_reads_in_the_singular(): void
    {
        $this->stock('esims', ['US']);
        $this->actingAs(User::factory()->create());

        $this->assertTrue(str_contains($this->overview(), 'Data, Calls for 1 country'), 'the singular is wrong');
    }

    /**
     * An empty store says nothing about countries at all, rather than boasting
     * of nought. A fresh install has no catalogue yet.
     */
    public function test_an_empty_catalogue_falls_back_to_a_plain_description(): void
    {
        Cache::forget('dashboard.purchase-stats');
        $this->actingAs(User::factory()->create());
        $html = $this->overview();

        $this->assertTrue(str_contains($html, 'Travel data plans'), 'an empty store should fall back');
        $this->assertFalse(str_contains($html, 'Data, Calls for 0'), 'an empty store should not count to zero out loud');
    }

    /** The figure is counted, not written down. */
    public function test_the_count_follows_the_catalogue_rather_than_the_template(): void
    {
        $this->stock('esims', ['US', 'GB']);
        $this->actingAs(User::factory()->create());

        $this->assertTrue(str_contains($this->overview(), 'Data, Calls for 2 countries'), 'two countries should read as two');

        // A third goes on sale and the card says so without anyone editing it.
        $this->stock('esims', ['KE']);

        $this->assertTrue(str_contains($this->overview(), 'Data, Calls for 3 countries'), 'the card did not follow the catalogue');
    }
}

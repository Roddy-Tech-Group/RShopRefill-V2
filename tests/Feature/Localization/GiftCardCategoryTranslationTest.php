<?php

namespace Tests\Feature\Localization;

use App\Models\Category;
use App\Models\Subcategory;
use App\Support\GiftCardCategories;
use App\Support\TaggedCache;
use Database\Seeders\CategoryTranslationsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * Gift card categories render in the visitor's language.
 *
 * Two sources feed the category list. The curated ones come from config and
 * are translated through lang/{locale}/giftcards.php. The synced subcategories
 * are database rows and are translated through the translations table.
 */
class GiftCardCategoryTranslationTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_curated_category_is_named_in_the_active_language(): void
    {
        App::setLocale('fr');
        $this->assertSame('Jeux vidéo', GiftCardCategories::label('gaming', 'Gaming'));

        App::setLocale('en');
        $this->assertSame('Gaming', GiftCardCategories::label('gaming', 'Gaming'));
    }

    public function test_a_slug_without_a_translation_falls_back_to_the_config_label(): void
    {
        App::setLocale('fr');

        $this->assertSame('Something New', GiftCardCategories::label('something-new', 'Something New'));
    }

    /** English carries the config label word for word, so English is unchanged. */
    public function test_every_curated_category_has_an_english_name_matching_its_config_label(): void
    {
        foreach (config('gift_card_categories') as $slug => $category) {
            $this->assertSame($category['label'], __("giftcards.categories.{$slug}", [], 'en'), "{$slug} differs from its config label");
        }
    }

    /**
     * The nav menu is cached by region, not language. A French visitor's
     * request can be the one that fills the cache, and the next visitor must
     * still read their own language.
     */
    public function test_a_cached_nav_menu_is_named_in_the_readers_language(): void
    {
        TaggedCache::for(['catalog'])->put('gift_card_nav_menu_US', [
            'categories' => collect([['slug' => 'gaming', 'name' => 'Jeux vidéo', 'icon' => 'default']]),
            'popular' => collect(),
            'regionName' => 'United States',
        ], 3600);

        App::setLocale('en');

        $this->assertSame('Gaming', GiftCardCategories::navMenu('US')['categories'][0]['name']);
    }

    public function test_the_seeder_translates_subcategories_and_categories(): void
    {
        $giftCards = Category::factory()->create(['slug' => 'gift-cards', 'name' => 'Gift Cards']);
        $gaming = Subcategory::factory()->create([
            'category_id' => $giftCards->id,
            'slug' => 'gaming-entertainment',
            'name' => 'Gaming & Entertainment',
        ]);

        $this->seed(CategoryTranslationsSeeder::class);

        App::setLocale('fr');
        $this->assertSame('Jeux vidéo et divertissement', $gaming->fresh()->name);
        $this->assertSame('Cartes cadeaux', $giftCards->fresh()->name);

        App::setLocale('en');
        $this->assertSame('Gaming & Entertainment', $gaming->fresh()->name);
    }

    /** A second run updates in place rather than duplicating rows. */
    public function test_the_seeder_is_idempotent(): void
    {
        $giftCards = Category::factory()->create(['slug' => 'gift-cards', 'name' => 'Gift Cards']);

        $this->seed(CategoryTranslationsSeeder::class);
        $this->seed(CategoryTranslationsSeeder::class);

        $this->assertSame(1, $giftCards->translations()->where('locale', 'fr')->where('field', 'name')->count());
    }

    /** Every translated locale has a data file, and each file names the same slugs. */
    public function test_every_translated_locale_has_the_same_slugs(): void
    {
        $dir = database_path('seeders/data/category_translations');
        $reference = require "{$dir}/fr.php";

        foreach (array_diff(array_keys(config('localization.supported')), ['en']) as $locale) {
            $path = "{$dir}/{$locale}.php";
            $this->assertTrue(File::exists($path), "{$locale} has no category translation file");

            $data = require $path;
            $this->assertSame(array_keys($reference['categories']), array_keys($data['categories']), "{$locale} categories differ");
            $this->assertSame(array_keys($reference['subcategories']), array_keys($data['subcategories']), "{$locale} subcategories differ");
        }
    }

    public function test_the_home_page_names_its_subcategory_rows_in_french(): void
    {
        $giftCards = Category::factory()->create(['slug' => 'gift-cards', 'name' => 'Gift Cards']);
        Subcategory::factory()->create(['category_id' => $giftCards->id, 'slug' => 'gaming-entertainment', 'name' => 'Gaming & Entertainment']);
        $this->seed(CategoryTranslationsSeeder::class);

        App::setLocale('fr');

        $this->assertSame('Jeux vidéo et divertissement', Subcategory::where('slug', 'gaming-entertainment')->withTranslations()->first()->name);
    }
}

<?php

namespace Tests\Feature\Localization;

use App\Models\BlogPost;
use App\Models\Category;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Per-locale copy for database-backed content.
 *
 * Lang files translate the interface; they cannot translate what the admin
 * types. HasTranslations puts a side table in front of a model's own text
 * columns, so a French visitor reads French copy where it exists and the
 * original everywhere else - never a blank field, and never a raw key.
 */
class ModelTranslationTest extends TestCase
{
    use RefreshDatabase;

    private function article(): BlogPost
    {
        return BlogPost::factory()->create([
            'title' => 'How gift cards work',
            'excerpt' => 'A short primer.',
        ]);
    }

    public function test_the_source_column_is_what_renders_in_the_default_locale(): void
    {
        $post = $this->article();
        $post->setTranslation('title', 'fr', 'Comment fonctionnent les cartes cadeaux');

        App::setLocale('en');

        $this->assertSame('How gift cards work', $post->fresh()->title);
    }

    public function test_a_translated_field_renders_in_that_locale(): void
    {
        $post = $this->article();
        $post->setTranslation('title', 'fr', 'Comment fonctionnent les cartes cadeaux');

        App::setLocale('fr');

        $this->assertSame('Comment fonctionnent les cartes cadeaux', $post->fresh()->title);
    }

    /**
     * The important half: a half-translated record degrades field by field.
     * A missing translation must read as the original, never as an empty
     * string - an untranslated site is better than a blank one.
     */
    public function test_an_untranslated_field_falls_back_to_the_source(): void
    {
        $post = $this->article();
        $post->setTranslation('title', 'fr', 'Comment fonctionnent les cartes cadeaux');

        App::setLocale('fr');
        $fresh = $post->fresh();

        $this->assertSame('Comment fonctionnent les cartes cadeaux', $fresh->title);
        $this->assertSame('A short primer.', $fresh->excerpt, 'untranslated fields keep the source copy');
    }

    /** Clearing a field means "use the original", not "show nothing". */
    public function test_clearing_a_translation_restores_the_source(): void
    {
        $post = $this->article();
        $post->setTranslation('title', 'fr', 'Titre français');
        $post->setTranslation('title', 'fr', '   ');

        App::setLocale('fr');

        $this->assertSame('How gift cards work', $post->fresh()->title);
        $this->assertDatabaseCount('translations', 0);
    }

    /** One row per field per locale, however many times it is saved. */
    public function test_saving_the_same_field_twice_updates_rather_than_duplicates(): void
    {
        $post = $this->article();
        $post->setTranslation('title', 'fr', 'Premier titre');
        $post->setTranslation('title', 'fr', 'Titre corrigé');

        App::setLocale('fr');

        $this->assertSame('Titre corrigé', $post->fresh()->title);
        $this->assertDatabaseCount('translations', 1);
    }

    /** Non-translatable attributes are untouched - the slug must never move. */
    public function test_only_declared_fields_are_translatable(): void
    {
        $post = $this->article();
        $slug = $post->slug;
        $post->setTranslation('slug', 'fr', 'un-slug-francais');

        App::setLocale('fr');

        $this->assertSame($slug, $post->fresh()->slug, 'a translated slug would break every existing link');
    }

    /**
     * Rendering a record that shows several translated fields must be one
     * query, not one per field - the memo is what keeps a listing viable.
     */
    public function test_reading_several_fields_costs_one_query(): void
    {
        $post = $this->article();
        $post->setTranslation('title', 'fr', 'Titre');
        $post->setTranslation('excerpt', 'fr', 'Extrait');

        App::setLocale('fr');
        $fresh = BlogPost::find($post->id);

        $queries = 0;
        DB::listen(function ($q) use (&$queries) {
            if (str_contains($q->sql, 'translations')) {
                $queries++;
            }
        });

        $fresh->title;
        $fresh->excerpt;
        $fresh->title;

        $this->assertSame(1, $queries);
    }

    /** A listing eager-loads, or a 40-tile page pays 40 queries. */
    public function test_a_listing_can_eager_load_translations(): void
    {
        foreach (range(1, 3) as $i) {
            BlogPost::factory()->create(['title' => 'Post '.$i])
                ->setTranslation('title', 'fr', 'Article '.$i);
        }

        App::setLocale('fr');

        $queries = 0;
        DB::listen(function ($q) use (&$queries) {
            if (str_contains($q->sql, 'translations')) {
                $queries++;
            }
        });

        $titles = BlogPost::withTranslations()->get()->map->title->all();

        $this->assertSame(1, $queries, 'eager loading is one query for the whole page');
        $this->assertSame(['Article 1', 'Article 2', 'Article 3'], $titles);
    }

    /** Products carry the catalogue's own prose. */
    public function test_product_copy_is_translatable(): void
    {
        $product = Product::factory()->create([
            'category_id' => Category::factory()->create(['slug' => 'gift-cards'])->id,
            'name' => 'Amazon Gift Card',
            'description' => 'Spend on anything.',
        ]);
        $product->setTranslation('description', 'fr', 'À dépenser sur tout le site.');

        App::setLocale('fr');
        $fresh = $product->fresh();

        $this->assertSame('À dépenser sur tout le site.', $fresh->description);
        $this->assertSame('Amazon Gift Card', $fresh->name, 'a brand name is not translated unless asked');
    }
}

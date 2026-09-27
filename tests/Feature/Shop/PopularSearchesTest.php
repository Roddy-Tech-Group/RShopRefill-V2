<?php

namespace Tests\Feature\Shop;

use App\Models\Category;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * Focusing a storefront search field with nothing typed opens the panel on
 * "Popular searches" instead of staying shut.
 *
 * The list is NOT a static set of names: an empty q on /api/search/brands
 * answers with Product::popularForRegion(), the same curated, region-aware row
 * the home page and the dashboard already render. One source, so the search
 * panel can never advertise a brand the catalogue has dropped.
 */
class PopularSearchesTest extends TestCase
{
    use RefreshDatabase;

    private function seedPopularBrand(string $brandKey): Product
    {
        $category = Category::firstOrCreate(
            ['slug' => 'gift-cards'],
            ['name' => 'Gift Cards', 'type' => 'digital']
        );

        return Product::create([
            'category_id' => $category->id,
            'provider_name' => 'test',
            'country_code' => 'US',
            'currency_code' => 'USD',
            'name' => 'Amazon US',
            'slug' => 'amazon-us',
            'brand_key' => $brandKey,
            'is_active' => true,
        ]);
    }

    public function test_an_empty_query_returns_the_curated_popular_row(): void
    {
        $key = (string) collect(config('popular_brands.keys', []))->first();
        $this->assertNotSame('', $key, 'config/popular_brands.php has no keys to test against.');

        $this->seedPopularBrand($key);

        $items = $this->getJson('/api/search/brands?q=')->assertOk()->json();

        $this->assertNotEmpty($items, 'An empty query should answer with Popular, not nothing.');

        // Same row shape as a real search hit, so the panel renders it unchanged.
        foreach (['type', 'name', 'logo', 'url', 'slug', 'country'] as $field) {
            $this->assertArrayHasKey($field, $items[0]);
        }
    }

    /** It is the curated list, not "whatever is in the catalogue". */
    public function test_popular_comes_from_the_config_not_the_whole_catalogue(): void
    {
        $key = (string) collect(config('popular_brands.keys', []))->first();
        $this->seedPopularBrand($key);

        // A live product that is NOT in the curated list must not appear.
        $category = Category::firstOrCreate(['slug' => 'gift-cards'], ['name' => 'Gift Cards', 'type' => 'digital']);
        Product::create([
            'category_id' => $category->id,
            'provider_name' => 'test',
            'country_code' => 'US',
            'currency_code' => 'USD',
            'name' => 'Not Curated',
            'slug' => 'not-curated',
            'brand_key' => 'zzz-not-in-config',
            'is_active' => true,
        ]);

        $names = collect($this->getJson('/api/search/brands?q=')->assertOk()->json())->pluck('name');

        $this->assertNotContains('Not Curated', $names->all());
    }

    /** A one-character query is still too short to search, and still shows Popular. */
    public function test_a_single_character_also_returns_popular(): void
    {
        $this->seedPopularBrand((string) collect(config('popular_brands.keys', []))->first());

        $this->assertNotEmpty($this->getJson('/api/search/brands?q=a')->assertOk()->json());
    }

    public function test_every_search_panel_opens_on_focus(): void
    {
        foreach ([
            'components/nav/main-nav.blade.php',
            'components/shop/product-search.blade.php',
        ] as $view) {
            $blade = File::get(resource_path('views/'.$view));

            $this->assertStringContainsString('@focus="onFocus()"', $blade, $view.' should open on focus.');
            $this->assertStringNotContainsString('@focus="if (query.length >= 2) open = true"', $blade);
        }

        // The heading itself lives in the shared body, not in each mount.
        $this->assertStringContainsString(
            'x-show="showingPopular && results.length > 0"',
            File::get(resource_path('views/components/nav/search-panel.blade.php'))
        );
    }

    /**
     * "Show all results" links to ?q=, which means nothing on the Popular list -
     * it would submit an empty search.
     */
    public function test_the_show_all_footer_is_hidden_on_the_popular_list(): void
    {
        $body = File::get(resource_path('views/components/nav/search-panel.blade.php'));

        $this->assertStringContainsString('x-show="results.length > 0 && ! showingPopular"', $body);

        // And no mount kept its own copy when the body was extracted - two
        // footers would mean two "Show all results" links on one panel.
        foreach ([
            'components/nav/main-nav.blade.php',
            'components/shop/product-search.blade.php',
        ] as $view) {
            $this->assertStringNotContainsString('Show all results', File::get(resource_path('views/'.$view)));
        }
    }

    public function test_the_factory_fetches_popular_once_and_keeps_it(): void
    {
        $js = File::get(resource_path('js/app.js'));

        $this->assertStringContainsString('popularLoaded: false,', $js);
        $this->assertStringContainsString('if (this.popularLoaded) {', $js);

        // Backspacing below two characters falls back to Popular rather than
        // closing the panel, which would read as a flicker.
        $this->assertStringContainsString('this.showPopular();', $js);
    }

    /**
     * The panel is a solid surface, not glass.
     *
     * bg-white and NOT bg-[#ffffff]: the plain class is remapped to the card
     * surface in dark, which is what a panel wants. The literal-hex form opts
     * out of that flip and would leave a white panel on the dark ground.
     */
    public function test_the_panel_is_solid_not_glass(): void
    {
        foreach ([
            'components/nav/main-nav.blade.php',
            'components/shop/product-search.blade.php',
        ] as $view) {
            $blade = File::get(resource_path('views/'.$view));

            $this->assertStringContainsString(
                'rounded-[20px] border border-zinc-200 bg-white shadow-2xl',
                $blade,
                $view.' should use a solid panel.'
            );
            $this->assertStringNotContainsString('bg-white/80', $blade);
            $this->assertStringNotContainsString('backdrop-blur-xl', $blade);
            $this->assertStringNotContainsString('glass-panel', $blade);
        }

        $html = $this->withoutVite()->get('/')->assertOk()->getContent();
        $this->assertStringNotContainsString('backdrop-blur-xl', $html);

        // The shared .glass-panel rule stays - other modals still use it.
        $this->assertStringContainsString('.glass-panel {', File::get(resource_path('css/app.css')));
    }

    /**
     * The row hover is an inset pill, rounded on all four corners.
     *
     * Full-bleed it reached the panel's edge, where the panel's own radius
     * clipped it - so the highlight came out rounded at one end and square at
     * the other. Inset by mx-1.5 it cannot touch that edge at all.
     */
    public function test_the_row_hover_is_a_symmetric_inset_pill(): void
    {
        $body = File::get(resource_path('views/components/nav/search-panel.blade.php'));

        $this->assertStringContainsString('rounded-full px-3.5 py-2.5', $body);

        // No single-side radius may creep back in on these rows.
        foreach (['rounded-l-', 'rounded-r-', 'rounded-t-[10px]', 'rounded-b-[10px]'] as $oneSided) {
            $this->assertStringNotContainsString($oneSided, $body);
        }
    }

    /** The page must not scroll behind an open panel. */
    public function test_an_open_panel_locks_page_scroll(): void
    {
        foreach ([
            'components/nav/main-nav.blade.php',
            'components/shop/product-search.blade.php',
        ] as $view) {
            $this->assertStringContainsString(
                'x-effect="open ? window.rshopScrollLock?.lock() : window.rshopScrollLock?.unlock()"',
                File::get(resource_path('views/'.$view))
            );
        }

        // The lock is reference-counted, which is what lets three search
        // instances (plus any modal) hold it at once without fighting.
        $partial = File::get(resource_path('views/partials/scroll-lock.blade.php'));
        $this->assertStringContainsString('let counter = 0;', $partial);
        $this->assertStringContainsString('counter++;', $partial);

        // The release used to clamp with Math.max(0, counter - 1), which let an
        // unbalanced unlock() run the restore branch at zero and throw the page
        // to the top. It now returns early instead, so the decrement behind
        // that guard cannot underflow and needs no clamp. ScrollLockTest covers
        // the guard itself.
        $this->assertStringContainsString('counter -= 1;', $partial);
        $this->assertStringNotContainsString('Math.max(0, counter - 1)', $partial);
    }

    /** The mobile sheet opens its result box for Popular too, not only typed queries. */
    public function test_the_phone_field_shows_popular_before_typing(): void
    {
        $nav = File::get(resource_path('views/components/nav/main-nav.blade.php'));

        // Both fields - the logo line's and the phone row's - open on focus,
        // which is what loads the popular list.
        $this->assertSame(2, substr_count($nav, '@focus="onFocus()"'));

        // And the panel is gated on that, not on a minimum query length.
        $this->assertStringNotContainsString('x-show="query.length >= 2">', $nav);
        $this->assertStringNotContainsString('x-show="results.length > 0 || loading">', $nav);
    }

    /**
     * Typing a place offers to SWITCH region, not just find products there.
     *
     * Restricted to countries the catalogue stocks - the same rule the locale
     * modal applies - so a switch can never land on an empty catalogue. Names,
     * codes and currency come from config/countries.php and
     * config/country_currency.php, the lookups the modal already uses, so the
     * two can never disagree about what a country maps to.
     */
    public function test_searching_a_place_offers_to_change_country(): void
    {
        $this->seedPopularBrand((string) collect(config('popular_brands.keys', []))->first());

        $rows = collect($this->getJson('/api/search/brands?q=United')->assertOk()->json())
            ->where('type', 'Country');

        $this->assertNotEmpty($rows, 'A country name should offer a region switch.');

        $us = $rows->firstWhere('country', 'US');
        $this->assertNotNull($us, 'The seeded US catalogue should be switchable to.');
        $this->assertSame('United States', $us['name']);
        $this->assertSame('USD', $us['currency']);
        $this->assertNull($us['url'], 'A country switches in place - it must not navigate.');
        $this->assertNotEmpty($us['flag']);
        $this->assertNotEmpty($us['currencySymbol']);
    }

    /** A country with no stock must not be offered - switching would empty the store. */
    public function test_unstocked_countries_are_not_offered(): void
    {
        $this->seedPopularBrand((string) collect(config('popular_brands.keys', []))->first());

        $codes = collect($this->getJson('/api/search/brands?q=a')->assertOk()->json())
            ->where('type', 'Country')
            ->pluck('country');

        // Only US was seeded, so nothing else may appear.
        foreach ($codes as $code) {
            $this->assertSame('US', $code);
        }
    }

    public function test_the_panel_body_is_one_shared_partial(): void
    {
        $partial = resource_path('views/components/nav/search-panel.blade.php');
        $this->assertFileExists($partial);

        // All three mounts use it, so the sections cannot drift apart.
        $nav = File::get(resource_path('views/components/nav/main-nav.blade.php'));
        $shop = File::get(resource_path('views/components/shop/product-search.blade.php'));

        $this->assertSame(2, substr_count($nav, '<x-nav.search-panel'), 'Desktop and mobile should both use it.');
        $this->assertSame(1, substr_count($shop, '<x-nav.search-panel'));

        $body = File::get($partial);
        $this->assertStringContainsString('Recent searches', $body);
        $this->assertStringContainsString('Popular searches', $body);
        $this->assertStringContainsString('x-for="g in groups"', $body);
        $this->assertStringContainsString('@click="pickCountry(r)"', $body);
    }

    /**
     * Country rows are buttons, not links: they switch region in place. Rendering
     * them as an <a href> would navigate to nothing, since they carry no url.
     */
    public function test_country_rows_switch_in_place_rather_than_navigate(): void
    {
        $body = File::get(resource_path('views/components/nav/search-panel.blade.php'));

        $this->assertStringContainsString('x-if="r.type === \'Country\'"', $body);
        $this->assertStringContainsString('x-if="r.type !== \'Country\'"', $body);
    }

    public function test_recent_searches_are_local_and_capped(): void
    {
        $js = File::get(resource_path('js/app.js'));

        $this->assertStringContainsString("localStorage.setItem('rshop.recentSearches'", $js);
        $this->assertStringContainsString('.slice(0, 5)', $js);

        // Only queries that actually returned something become history - the
        // half-typed prefixes on the way to a real search are not searches.
        $this->assertStringContainsString('if (this.results.length > 0) this.rememberQuery(q);', $js);
    }

    /**
     * pickCountry writes the five shared locale properties. The search scope is
     * nested inside storefrontLocale(), and Alpine's merged scope assigns
     * through to whichever object owns the key - so these land on the shared
     * locale state and its watchers persist and reload from there.
     *
     * This applies IMMEDIATELY, unlike the locale modal's own picker, which
     * stages a draft behind a Save button. The difference is deliberate: a
     * "Change country" row in a list of search results is an action you took,
     * with nothing else on screen to confirm it against. The modal has three
     * settings and one Save.
     */
    public function test_picking_a_country_from_search_writes_the_shared_locale_state(): void
    {
        $js = File::get(resource_path('js/app.js'));

        foreach (['this.country =', 'this.countryFlag =', 'this.countryCode =', 'this.currency =', 'this.currencySymbol ='] as $assignment) {
            $this->assertStringContainsString($assignment, $js);
        }

        // The modal sets the same five, staged: draftCountry and friends, which
        // only reach the shared state when Save submits.
        $modal = File::get(resource_path('views/components/nav/locale-modal.blade.php'));

        foreach ([
            'this.draftCountry =',
            'this.draftCountryFlag =',
            'this.draftCountryCode =',
            'this.draftCurrency =',
            'this.draftCurrencySymbol =',
        ] as $assignment) {
            $this->assertStringContainsString($assignment, $modal);
        }
    }

    /**
     * "Show all results" is the panel's one action, so it reads as a filled pill
     * inset from the edge rather than a text link across a rule.
     *
     * The inset lives on a wrapper: a full-bleed pill would sit against the
     * panel's own rounded corner and the two radii would fight.
     */
    public function test_the_show_all_action_is_a_filled_pill(): void
    {
        $body = File::get(resource_path('views/components/nav/search-panel.blade.php'));

        $this->assertStringContainsString(
            'block rounded-full bg-[#f4f6f5] px-5 py-3 text-center text-sm font-bold text-zinc-900',
            $body
        );
        $this->assertStringContainsString('<div x-show="results.length > 0 && ! showingPopular" class="p-3">', $body);

        // The old bordered text link is gone, rule and all.
        $this->assertStringNotContainsString('block border-t border-zinc-100 px-5 py-3', $body);
        $this->assertStringNotContainsString('text-blue-600 transition-colors hover:bg-zinc-100/70', $body);
    }

    /**
     * Result rows carry a square mark, not a wide card thumbnail.
     *
     * 44px, up from 36px, and with no hairline around it. The brands these
     * rows list are mostly wordmarks, and at 36px inside a ring the artwork
     * had very little of the row to be read in.
     *
     * object-contain rather than cover: a cover crop cuts a wide wordmark in
     * half, which is exactly what these marks mostly are.
     */
    public function test_result_marks_are_small_squares(): void
    {
        $body = File::get(resource_path('views/components/nav/search-panel.blade.php'));

        $this->assertSame(3, substr_count($body, 'h-11 w-11 shrink-0'));
        $this->assertStringContainsString('object-contain', $body);

        // No hairline around the mark. A brand logo is already a mark on its own
        // plate, and a ring drew a second box inside the row that made the
        // artwork read smaller than the space it was given.
        $this->assertStringNotContainsString('ring-1 ring-zinc-200 dark:bg-white/5', $body);

        $this->assertStringNotContainsString('aspect-[16/10] w-20', $body);
        $this->assertStringNotContainsString('object-cover', $body);
    }

    /**
     * The cart popup's two actions are a matched pair of pills.
     *
     * Checkout keeps its blue fill rather than going grey with its neighbour:
     * greyed, the cart would have no primary action left on a purchase path.
     * Shape, padding and weight match so they still read as one control group.
     */
    public function test_the_cart_actions_are_matched_pills(): void
    {
        $cart = File::get(resource_path('views/components/nav/account-cart.blade.php'));

        $this->assertStringContainsString('rounded-full bg-[#f4f6f5] px-4 py-3.5 text-sm font-bold text-zinc-900', $cart);
        $this->assertStringContainsString('rounded-full bg-blue-600 px-4 py-3.5 text-sm font-bold text-white', $cart);

        // Neither kept the old 12px corner.
        $this->assertStringNotContainsString('rounded-[12px] bg-blue-600 px-4 py-3.5', $cart);
        $this->assertStringNotContainsString('rounded-[12px] bg-white px-4 py-3.5', $cart);
    }
}

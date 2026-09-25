<?php

namespace Tests\Feature\Localization;

use App\Models\Review;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Blade;
use Tests\TestCase;

/**
 * The home page's lower sections, the reviews page and the theme picker render
 * in the visitor's language, while the reviews themselves do not.
 *
 * "How it works", "What we bring" and "What our customers say" were written
 * straight into their blades, so a French visitor read French above them and
 * English inside them. Customer reviews are the one thing on these pages that
 * stays as written: translating a customer's words would put words in their
 * mouth.
 */
class ReviewsAndHomeSectionsTranslationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
    }

    /** SetLocale reads the session, which is what a real switch writes. */
    private function french(string $url): string
    {
        return $this->withSession(['locale' => 'fr'])->get($url)->assertOk()->getContent();
    }

    public function test_the_home_sections_render_french_copy(): void
    {
        $html = $this->french('/');

        foreach (['home.how_heading', 'home.how_step1_title', 'home.how_step3_body', 'home.promo_heading', 'home.promo_tagline_one', 'reviews.heading'] as $key) {
            $this->assertStringContainsString(e(__($key, [], 'fr')), $html, "{$key} is not rendered in French");
        }

        $this->assertStringNotContainsString('How it works</h2>', $html);
        $this->assertStringNotContainsString('What we bring</h2>', $html);
        $this->assertStringNotContainsString('What our customers say</h2>', $html);
        $this->assertStringNotContainsString(':offerings', $html, 'a placeholder reached the page unsubstituted');
        $this->assertStringNotContainsString(':services', $html, 'a placeholder reached the page unsubstituted');
    }

    /** The English source reads exactly as it did before extraction. */
    public function test_the_home_sections_are_unchanged_in_english(): void
    {
        $html = $this->get('/')->assertOk()->getContent();

        $this->assertStringContainsString('How it works', $html);
        $this->assertStringContainsString('1. Pick a product or service', $html);
        $this->assertStringContainsString('What Rshop has to offer you', $html);
        $this->assertStringContainsString('One platform. Endless possibilities.', $html);
        $this->assertStringContainsString('What our customers say', $html);
    }

    public function test_the_reviews_page_renders_french_copy(): void
    {
        $html = $this->french('/reviews');

        foreach (['reviews.badge', 'reviews.recent_heading', 'reviews.form_title', 'reviews.your_review', 'reviews.submit'] as $key) {
            $this->assertStringContainsString(e(__($key, [], 'fr')), $html, "{$key} is not rendered in French");
        }

        $this->assertStringNotContainsString('Recent reviews</h2>', $html);
        $this->assertStringNotContainsString('Share your experience</h2>', $html);
    }

    public function test_a_review_body_is_never_translated(): void
    {
        Review::factory()->create([
            'author_name' => 'Brenda Nfor',
            'body' => 'First time buying here and the code worked straight away.',
        ]);

        $home = $this->french('/');
        $page = $this->french('/reviews');

        $this->assertStringContainsString('First time buying here and the code worked straight away.', $home);
        $this->assertStringContainsString('First time buying here and the code worked straight away.', $page);
        $this->assertStringContainsString('Brenda Nfor', $page);
    }

    public function test_the_review_date_follows_the_locale(): void
    {
        Review::factory()->create(['reviewed_at' => '2026-08-26']);

        // English first: the French request leaves its locale in the session.
        $this->assertStringContainsString('Aug 26, 2026', $this->get('/reviews')->assertOk()->getContent());
        $this->assertStringContainsString('26 août 2026', $this->french('/reviews'));
    }

    public function test_the_theme_picker_renders_french_labels(): void
    {
        $html = $this->french('/');

        $this->assertStringContainsString('aria-label="'.__('common.theme', [], 'fr').'"', $html);
        $this->assertStringContainsString('aria-label="'.__('common.theme_light', [], 'fr').'"', $html);
        $this->assertStringContainsString('aria-label="'.__('common.theme_dark', [], 'fr').'"', $html);
        $this->assertStringNotContainsString('aria-label="Light"', $html);
        $this->assertStringNotContainsString('aria-label="Dark"', $html);
    }

    /** Every row pill and arrow uses the translated defaults. */
    public function test_the_row_controls_default_to_translated_labels(): void
    {
        App::setLocale('fr');

        $html = Blade::render('<x-ui.row-controls href="/gift-cards" prev="nudge(-1)" next="nudge(1)" />');

        $this->assertStringContainsString(e(__('common.see_more', [], 'fr')), $html);
        $this->assertStringContainsString('aria-label="'.e(__('common.previous', [], 'fr')).'"', $html);
        $this->assertStringContainsString('aria-label="'.e(__('common.next', [], 'fr')).'"', $html);
        $this->assertStringNotContainsString('aria-label="Previous"', $html);
        $this->assertStringNotContainsString('See more', $html);
    }
}

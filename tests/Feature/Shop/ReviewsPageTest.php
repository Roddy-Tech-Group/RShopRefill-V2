<?php

namespace Tests\Feature\Shop;

use App\Models\Review;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The Customer Reviews page.
 *
 * The wall shows only the reviews our own form collected. The Trustpilot and
 * Google collection surfaces (the Review Collector widget, the Google CTA and
 * QR, the profile links) all stay - this page is where we send customers to
 * leave a review on those platforms, not where we reprint what they said there.
 */
class ReviewsPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_wall_shows_our_own_reviews_only(): void
    {
        Review::factory()->create(['author_name' => 'Our Own Author']);
        Review::factory()->external('Trustpilot')->create(['author_name' => 'Trustpilot Author']);
        Review::factory()->external('Google')->create(['author_name' => 'Google Author']);

        $this->withoutVite()->get(route('shop.reviews'))
            ->assertOk()
            ->assertSee('Our Own Author')
            ->assertDontSee('Trustpilot Author')
            ->assertDontSee('Google Author');
    }

    public function test_the_collection_surfaces_are_still_offered(): void
    {
        config()->set('services.trustpilot.profile_url', 'https://www.trustpilot.com/review/example.com');
        config()->set('services.google_reviews.review_url', 'https://g.page/r/example/review');

        $html = $this->withoutVite()->get(route('shop.reviews'))->assertOk()->getContent();

        // Trustpilot Review Collector widget + its no-JS fallback link.
        $this->assertStringContainsString('trustpilot-widget', $html);
        $this->assertStringContainsString('https://www.trustpilot.com/review/example.com', $html);

        // Google review CTA.
        $this->assertStringContainsString('https://g.page/r/example/review', $html);
        $this->assertStringContainsString('Review on Google', $html);

        // And our own form, which is what actually fills the wall above.
        $this->assertStringContainsString('Share your experience', $html);
        $this->assertStringContainsString(route('reviews.store'), $html);
    }

    /**
     * The wall used to be described as a mix of Google Business, Trustpilot and
     * our own entries. It is not one any more, and the copy must not say it is.
     */
    public function test_the_wall_is_not_described_as_carrying_platform_reviews(): void
    {
        $html = $this->withoutVite()->get(route('shop.reviews'))->assertOk()->getContent();

        $this->assertStringNotContainsString('Reviews collected from Google Business', $html);
    }
}

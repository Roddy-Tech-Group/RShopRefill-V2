<?php

namespace Tests\Feature;

use App\Models\Review;
use Database\Seeders\ReviewSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The homepage reviews row.
 *
 * Only reviews our own form collected are rendered. Trustpilot and Google host
 * and display their own reviews on their own surfaces, so entries attributed to
 * either platform stay in the table (admin can still manage them) but never
 * reach a customer - the carousel leads with a card that sends people to
 * Trustpilot to leave one instead.
 */
class CustomerReviewsTest extends TestCase
{
    use RefreshDatabase;

    public function test_homepage_renders_our_own_reviews(): void
    {
        $this->withoutVite();
        Review::factory()->create(['author_name' => 'Ada Nwosu', 'body' => 'Codes landed instantly.']);

        $this->get('/')
            ->assertOk()
            ->assertSee('What our customers say')
            ->assertSee('Ada Nwosu');
    }

    public function test_reviews_attributed_to_external_platforms_are_never_rendered(): void
    {
        $this->withoutVite();
        Review::factory()->external('Trustpilot')->create(['author_name' => 'Trustpilot Author']);
        Review::factory()->external('Google')->create(['author_name' => 'Google Author']);
        // Casing is editor-entered, so the exclusion has to be case-insensitive.
        Review::factory()->external('trustpilot')->create(['author_name' => 'Lowercase Author']);
        Review::factory()->create(['author_name' => 'Our Own Author']);

        $this->get('/')
            ->assertOk()
            ->assertSee('Our Own Author')
            ->assertDontSee('Trustpilot Author')
            ->assertDontSee('Google Author')
            ->assertDontSee('Lowercase Author');
    }

    /**
     * The seeder ships 20 curated Trustpilot/Google entries. None of them are
     * ours, so none of them may appear on the storefront.
     */
    public function test_the_seeded_platform_reviews_stay_off_the_homepage(): void
    {
        $this->withoutVite();
        $this->seed(ReviewSeeder::class);

        $this->get('/')
            ->assertOk()
            ->assertDontSee('Adaeze O.')
            ->assertDontSee('Daniel Owusu');
    }

    /** The slot that used to flip aggregate scores now collects for Trustpilot. */
    public function test_the_carousel_leads_with_a_trustpilot_collector_card(): void
    {
        $this->withoutVite();
        config()->set('services.trustpilot.profile_url', 'https://www.trustpilot.com/review/example.com');

        $this->get('/')
            ->assertOk()
            ->assertSee('Review us on Trustpilot')
            ->assertSee('https://www.trustpilot.com/review/example.com');
    }

    public function test_homepage_falls_back_gracefully_when_no_reviews_exist(): void
    {
        $this->withoutVite();

        // No reviews + no aggregate settings - should still render without errors.
        $this->get('/')->assertOk();
    }

    public function test_unpublished_reviews_are_hidden_from_homepage(): void
    {
        $this->withoutVite();
        Review::factory()->create([
            'author_name' => 'Visible Author',
            'is_published' => true,
        ]);
        Review::factory()->create([
            'author_name' => 'Hidden Author',
            'is_published' => false,
        ]);

        $this->get('/')
            ->assertOk()
            ->assertSee('Visible Author')
            ->assertDontSee('Hidden Author');
    }
}

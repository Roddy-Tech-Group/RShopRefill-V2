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
     * reviews.source defaults to 'Trustpilot' in the schema, so anything that
     * writes a review without naming a source picks up a platform label it never
     * came from. A review the customer left through our own form is ours however
     * it ended up labelled, and filtering on the label alone would silently bury
     * real feedback.
     */
    public function test_a_customer_submission_is_ours_whatever_its_source_says(): void
    {
        $this->withoutVite();
        Review::factory()->external('Trustpilot')->create([
            'author_name' => 'Mislabelled Buyer',
            'is_customer_submitted' => true,
        ]);

        $html = $this->get('/')->assertOk()->getContent();

        $this->assertStringContainsString('Mislabelled Buyer', $html);
        // And it must not wear Trustpilot's name: they did not collect it. This
        // targets the review card's own source label, not the collector card
        // further up the row, which says Trustpilot legitimately.
        $this->assertStringNotContainsString('dark:text-white">Trustpilot</span>', $html);
    }

    /**
     * The seeder used to manufacture 20 reviews attributed to Trustpilot and
     * Google. They were deleted for good, and because it wrote them with
     * updateOrCreate, every db:seed brought all 20 straight back. Nothing may
     * invent a review again: they come from a customer or from an admin.
     */
    public function test_the_seeder_manufactures_no_reviews(): void
    {
        $this->seed(ReviewSeeder::class);

        $this->assertSame(0, Review::count());
        // It still seeds the aggregate the nav badge and score card read.
        $this->assertNotNull(\App\Models\SiteSetting::get('reviews.aggregate.rating'));
    }

    /**
     * ownCollected() and externalSourced() must partition the table: every row
     * belongs to exactly one. The cleanup that prunes platform rows runs off
     * externalSourced(), so a gap between the two would delete something the
     * storefront was still showing, and an overlap would delete a real review.
     */
    public function test_the_shown_and_prunable_sets_partition_the_table(): void
    {
        Review::factory()->create(['author_name' => 'Ours']);
        Review::factory()->external('Trustpilot')->create(['author_name' => 'Theirs']);
        Review::factory()->external('google')->create(['author_name' => 'Theirs Lowercase']);
        Review::factory()->external('Trustpilot')->create(['author_name' => 'Mislabelled', 'is_customer_submitted' => true]);

        $shown = Review::ownCollected()->pluck('id');
        $prunable = Review::externalSourced()->pluck('id');

        $this->assertCount(4, Review::all());
        $this->assertSame(4, $shown->count() + $prunable->count(), 'Every review must fall in exactly one set.');
        $this->assertEmpty($shown->intersect($prunable), 'No review may be both shown and prunable.');
        // The mislabelled customer submission belongs to the shown set.
        $this->assertContains(Review::where('author_name', 'Mislabelled')->value('id'), $shown->all());
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

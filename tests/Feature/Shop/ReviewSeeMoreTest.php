<?php

namespace Tests\Feature\Shop;

use App\Models\Review;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Review card bodies clamp at 5 lines; every card carries a "See more"
 * toggle (shown client-side only when the text actually overflows) so long
 * reviews can be read in full on the homepage carousel and /reviews page.
 */
class ReviewSeeMoreTest extends TestCase
{
    use RefreshDatabase;

    public function test_homepage_review_cards_carry_the_see_more_toggle(): void
    {
        Review::factory()->create([
            'body' => str_repeat('A long and detailed customer review body. ', 20),
        ]);

        $this->withoutVite()->get('/')
            ->assertOk()
            ->assertSee('What our customers say')
            ->assertSee('See more');
    }

    public function test_homepage_reviews_header_links_to_the_reviews_page_with_both_arrows(): void
    {
        Review::factory()->create();

        // Order inside the header row: the "See more" link to /reviews, then
        // the back arrow, then the forward arrow.
        $this->withoutVite()->get('/')
            ->assertOk()
            ->assertSeeInOrder([
                'What our customers say',
                route('shop.reviews'),
                'Show previous reviews',
                'Show more reviews',
            ], false);
    }

    public function test_reviews_page_cards_carry_the_see_more_toggle(): void
    {
        Review::factory()->create([
            'body' => str_repeat('A long and detailed customer review body. ', 20),
        ]);

        $this->withoutVite()->get(route('shop.reviews'))
            ->assertOk()
            ->assertSee('See more');
    }
}

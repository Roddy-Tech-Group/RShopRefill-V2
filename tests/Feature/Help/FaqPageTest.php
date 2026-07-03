<?php

namespace Tests\Feature\Help;

use App\Models\Faq;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The standalone FAQ page: its sticky title column must clear the storefront
 * header (lg:top-[156px], not the old lg:top-28 that tucked under the nav), and
 * the page carries explicit dark-mode styling.
 */
class FaqPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_faq_page_sticky_clears_the_nav_and_supports_dark_mode(): void
    {
        Faq::factory()->create([
            'is_published' => true,
            'topic' => 'Orders & Delivery',
            'question' => 'How fast is delivery?',
            'answer' => 'Digital items deliver instantly once payment clears.',
        ]);

        $response = $this->get('/faq')->assertOk();

        // Sticky title parks below the header instead of under the nav.
        $response->assertSee('lg:top-[156px]', false);
        $response->assertDontSee('lg:top-28', false);

        // Explicit dark-mode styling is present, and the content renders.
        $response->assertSee('dark:text-white', false);
        $response->assertSee('How fast is delivery?');
    }
}

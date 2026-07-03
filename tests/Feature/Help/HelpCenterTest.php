<?php

namespace Tests\Feature\Help;

use App\Models\Faq;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The Help Center FAQ search filters server-rendered rows via their data-faq
 * haystack (the eSIM-list pattern) instead of the old Alpine getter, which had
 * stopped filtering. Topics render as square cards beside a sticky heading.
 */
class HelpCenterTest extends TestCase
{
    use RefreshDatabase;

    public function test_faqs_render_as_self_filtering_rows_with_a_working_search(): void
    {
        Faq::factory()->create([
            'is_published' => true,
            'topic' => 'Payments & Wallet',
            'question' => 'How do I fund my wallet?',
            'answer' => 'Use card or mobile money to add funds.',
        ]);

        $response = $this->get('/help')->assertOk();

        // Search box is wired and FAQ rows carry the data-faq haystack they filter on.
        $response->assertSee('x-model="q"', false);
        $response->assertSee('data-faq=', false);
        $response->assertSee('How do I fund my wallet?');

        // The old getter-based filtering (which had stopped working) is gone.
        $response->assertDontSee('in filtered', false);

        // Topics render as square cards beside the sticky heading...
        $response->assertSee('aspect-square', false);
        // ...with a per-topic article count so the square cards don't read empty.
        $response->assertSee('1 article', false);
    }
}

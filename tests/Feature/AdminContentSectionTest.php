<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\BlogPost;
use App\Models\PressArticle;
use App\Models\Review;
use Database\Seeders\AdminSeeder;
use Database\Seeders\BlogPostSeeder;
use Database\Seeders\FaqSeeder;
use Database\Seeders\PressArticleSeeder;
use Database\Seeders\ReviewSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Tests\TestCase;

class AdminContentSectionTest extends TestCase
{
    use RefreshDatabase;

    protected function adminLogin(): self
    {
        $this->seed(AdminSeeder::class);
        $admin = Admin::first();
        $this->actingAs($admin, 'admin');

        return $this;
    }

    public function test_blog_admin_page_lists_seeded_posts(): void
    {
        $this->withoutVite()->adminLogin();
        $this->seed(BlogPostSeeder::class);

        $this->get(route('admin.content.blog'))
            ->assertOk()
            ->assertSee('Blog Posts')
            ->assertSee('Getting started: your first purchase on RshopRefills');
    }

    public function test_press_admin_page_lists_seeded_articles(): void
    {
        $this->withoutVite()->adminLogin();
        $this->seed(PressArticleSeeder::class);

        $this->get(route('admin.content.press'))
            ->assertOk()
            ->assertSee('Press Articles')
            ->assertSee('RshopRefills launches the Rcoin rewards program');
    }

    /**
     * The seeder no longer manufactures reviews (see ReviewSeeder), so the row
     * this asserts on is created here. It still seeds the aggregate, which the
     * page prints above the list.
     */
    public function test_reviews_admin_page_lists_reviews_and_aggregate(): void
    {
        $this->withoutVite()->adminLogin();
        $this->seed(ReviewSeeder::class);
        Review::factory()->create(['author_name' => 'Adaeze O.']);

        $this->get(route('admin.content.reviews'))
            ->assertOk()
            ->assertSee('Reviews')
            ->assertSee('Adaeze O.')
            ->assertSeeText('4.5 / 5');
    }

    public function test_admin_reviews_search_filters_by_customer_name(): void
    {
        $this->withoutVite()->adminLogin();

        $base = [
            'body' => 'Great experience using the service.',
            'rating' => 5.0,
            'reviewed_at' => now()->toDateString(),
            'is_published' => true,
            'is_customer_submitted' => false,
        ];
        Review::create($base + ['author_name' => 'Alice Traveller', 'initials' => 'AT', 'source' => 'Trustpilot']);
        Review::create($base + ['author_name' => 'Bob Shopper', 'initials' => 'BS', 'source' => 'Google']);

        Volt::test('admin.content.reviews')
            ->assertSee('Alice Traveller')
            ->assertSee('Bob Shopper')
            ->set('search', 'Alice')
            ->assertSee('Alice Traveller')
            ->assertDontSee('Bob Shopper');
    }

    public function test_admin_blog_page_paginates_at_25_and_badge_shows_true_total(): void
    {
        $this->withoutVite()->adminLogin();

        foreach (range(0, 29) as $index) {
            $label = 'Paginated post '.str_pad((string) $index, 2, '0', STR_PAD_LEFT);
            BlogPost::factory()->create([
                'title' => $label,
                'slug' => 'paginated-post-'.$index,
                'published_at' => now()->subDays($index)->toDateString(),
            ]);
        }

        Volt::test('admin.content.blog')
            ->assertSee('30 posts')
            ->assertSee('Paginated post 00')
            ->assertDontSee('Paginated post 29')
            ->call('gotoPage', 2)
            ->assertSee('30 posts')
            ->assertSee('Paginated post 29');
    }

    public function test_admin_press_page_paginates_at_25_and_badge_shows_true_total(): void
    {
        $this->withoutVite()->adminLogin();

        foreach (range(0, 29) as $index) {
            $label = 'Paginated article '.str_pad((string) $index, 2, '0', STR_PAD_LEFT);
            PressArticle::factory()->create([
                'title' => $label,
                'slug' => 'paginated-article-'.$index,
                'published_at' => now()->subDays($index)->toDateString(),
            ]);
        }

        Volt::test('admin.content.press')
            ->assertSee('30 articles')
            ->assertSee('Paginated article 00')
            ->assertDontSee('Paginated article 29')
            ->call('gotoPage', 2)
            ->assertSee('30 articles')
            ->assertSee('Paginated article 29');
    }

    public function test_admin_reviews_page_paginates_and_search_returns_to_first_page(): void
    {
        $this->withoutVite()->adminLogin();

        foreach (range(0, 29) as $index) {
            Review::factory()->create([
                'author_name' => 'Bulk Reviewer '.str_pad((string) $index, 2, '0', STR_PAD_LEFT),
                'reviewed_at' => now()->subDays($index + 1)->toDateString(),
            ]);
        }
        Review::factory()->create([
            'author_name' => 'Zedaiah Uniqueperson',
            'reviewed_at' => now()->subDays(40)->toDateString(),
        ]);

        // Page 2 holds the tail of the list; searching from there must land the
        // admin back on page 1 or the single match would render on an empty page.
        Volt::test('admin.content.reviews')
            ->assertSee('Bulk Reviewer 00')
            ->assertDontSee('Zedaiah Uniqueperson')
            ->call('gotoPage', 2)
            ->assertSee('Zedaiah Uniqueperson')
            ->set('search', 'Zedaiah')
            ->assertSee('Zedaiah Uniqueperson')
            ->assertSee('1 match')
            ->assertDontSee('Bulk Reviewer 00');
    }

    public function test_faq_admin_page_lists_seeded_questions_grouped_by_topic(): void
    {
        $this->withoutVite()->adminLogin();
        $this->seed(FaqSeeder::class);

        $this->get(route('admin.content.faqs'))
            ->assertOk()
            ->assertSee('FAQs')
            ->assertSee('Orders & Delivery')
            ->assertSee('How fast is delivery?');
    }

    public function test_admin_content_pages_require_admin_auth(): void
    {
        $this->get(route('admin.content.blog'))->assertNotFound();
        $this->get(route('admin.content.press'))->assertNotFound();
        $this->get(route('admin.content.reviews'))->assertNotFound();
        $this->get(route('admin.content.faqs'))->assertNotFound();
    }
}

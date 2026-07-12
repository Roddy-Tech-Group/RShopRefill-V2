<?php

namespace Tests\Feature;

use App\Models\Admin;
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

    public function test_reviews_admin_page_lists_seeded_reviews_and_aggregate(): void
    {
        $this->withoutVite()->adminLogin();
        $this->seed(ReviewSeeder::class);

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

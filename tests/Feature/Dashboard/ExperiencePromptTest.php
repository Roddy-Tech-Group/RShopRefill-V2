<?php

namespace Tests\Feature\Dashboard;

use App\Domain\Admin\Enums\AdminRole;
use App\Domain\Fulfillment\Enums\FulfillmentStatus;
use App\Domain\Order\Enums\OrderStatus;
use App\Domain\Payment\Enums\PaymentStatus;
use App\Domain\Reviews\Services\ExperiencePromptService;
use App\Models\Admin;
use App\Models\Category;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Review;
use App\Models\SiteSetting;
use App\Models\Subcategory;
use App\Models\User;
use App\Support\FeatureFlag;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Volt\Volt;
use Tests\TestCase;

/**
 * The rate-your-experience prompt. The rules pinned here are the business's:
 * signed-in customers only, asked at most once a day, never again once they
 * have answered, and the answer queues as a pending review that reaches the
 * carousel only when an admin approves it and only when it has something to
 * say. It ships switched off.
 */
class ExperiencePromptTest extends TestCase
{
    use RefreshDatabase;

    /** Through put(), which busts the hour-long read cache; a raw row update would leave the flag reader seeing "off". */
    private function enable(bool $on = true): void
    {
        SiteSetting::put('features.experience_prompt_enabled', $on ? 'on' : 'off', 'features');
    }

    private function setDelayMinutes(int $minutes): void
    {
        SiteSetting::put('features.experience_prompt_delay_minutes', (string) $minutes, 'features');
    }

    private function customer(): User
    {
        return User::factory()->create(['name' => 'Divine Ofeh']);
    }

    /**
     * The home page's reviews carousel, cut out of the page. Assertions about a
     * review's author must not see the rest of the page: the signed-in
     * customer's own name sits in the nav's account button.
     */
    private function carousel(string $html): string
    {
        $start = strpos($html, 'aria-label="What our customers say"');
        $this->assertNotFalse($start, 'The reviews carousel did not render.');

        return substr($html, $start, strpos($html, '</section>', $start) - $start);
    }

    /** A delivered order holding one item in a category with the given name. */
    private function deliveredOrderIn(User $user, string $categoryName): Order
    {
        $order = Order::create([
            'user_id' => $user->id,
            'order_number' => 'ORD-'.strtoupper(fake()->bothify('????##')),
            'settlement_currency' => 'USD',
            'display_currency' => 'USD',
            'subtotal_amount' => 10,
            'markup_amount' => 0,
            'total_amount' => 10,
            'payment_method' => 'wallet',
            'payment_status' => PaymentStatus::Paid,
            'fulfillment_status' => FulfillmentStatus::Fulfilled,
            'order_status' => OrderStatus::Completed,
        ]);

        $category = Category::factory()->create(['name' => $categoryName]);
        $subcategory = Subcategory::factory()->create(['category_id' => $category->id]);
        $product = Product::factory()->create(['category_id' => $category->id, 'subcategory_id' => $subcategory->id]);
        $variant = ProductVariant::factory()->for($product)->create();

        OrderItem::create([
            'order_id' => $order->id,
            'product_id' => $product->id,
            'product_variant_id' => $variant->id,
            'category_id' => $category->id,
            'subcategory_id' => $subcategory->id,
            'provider_name' => 'test_provider',
            'quantity' => 1,
            'display_currency' => 'USD',
            'display_amount' => 10,
            'provider_cost_usd' => 8,
            'markup_amount' => 2,
            'subtotal_amount' => 10,
            'fulfillment_status' => FulfillmentStatus::Fulfilled,
        ]);

        return $order;
    }

    public function test_it_ships_switched_off_and_the_delay_is_an_admin_setting(): void
    {
        // Through the flag reader, not the raw row: a boolean setting may come
        // back as a real bool, and the reader is what the service asks.
        $this->assertFalse(FeatureFlag::on('experience_prompt', false));
        $this->assertSame(3, (int) SiteSetting::get('features.experience_prompt_delay_minutes'));

        $service = app(ExperiencePromptService::class);
        $this->assertFalse($service->isEnabled());
        $this->assertSame(3, $service->delayMinutes());

        $this->setDelayMinutes(7);
        $this->assertSame(7, app(ExperiencePromptService::class)->delayMinutes());
    }

    public function test_the_dashboard_mounts_it_only_when_the_flag_is_on(): void
    {
        $user = $this->customer();

        $this->actingAs($user)->withoutVite()->get('/dashboard')->assertOk()->assertDontSeeLivewire('experience-prompt');

        $this->enable();
        $this->actingAs($user)->withoutVite()->get('/dashboard')->assertOk()->assertSeeLivewire('experience-prompt');
    }

    public function test_the_page_gets_the_delay_in_seconds_and_a_clock_that_survives_navigation(): void
    {
        $this->enable();
        $this->setDelayMinutes(2);

        $this->actingAs($this->customer());

        $component = Volt::test('experience-prompt');
        $this->assertSame(120, $component->get('delaySeconds'));

        $html = $component->html();
        $this->assertStringContainsString("sessionStorage.getItem('experience.start')", $html);
        $this->assertStringContainsString('x-on:livewire:navigated.window="start()"', $html);
    }

    public function test_it_opens_once_a_day_until_answered(): void
    {
        $this->enable();
        $user = $this->customer();
        $this->actingAs($user);

        Carbon::setTestNow('2026-09-18 10:00:00');
        Volt::test('experience-prompt')->call('tryOpen')->assertSet('open', true);
        $this->assertNotNull($user->fresh()->experience_prompted_at);

        // Later the same day: closed and not re-stamped.
        Carbon::setTestNow('2026-09-18 18:00:00');
        Volt::test('experience-prompt')->call('tryOpen')->assertSet('open', false);
        $this->assertSame('2026-09-18 10:00:00', $user->fresh()->experience_prompted_at->toDateTimeString());

        // The next day it asks again.
        Carbon::setTestNow('2026-09-19 08:00:00');
        Volt::test('experience-prompt')->call('tryOpen')->assertSet('open', true);

        Carbon::setTestNow();
    }

    public function test_it_never_opens_once_the_customer_has_answered_or_while_switched_off(): void
    {
        $user = $this->customer();
        $this->actingAs($user);

        // Off: nothing, and no stamp.
        Volt::test('experience-prompt')->call('tryOpen')->assertSet('open', false);
        $this->assertNull($user->fresh()->experience_prompted_at);

        $this->enable();
        Volt::test('experience-prompt')->call('tryOpen')->call('choose', 8)->call('submit')->assertSet('submitted', true);
        $this->assertNotNull($user->fresh()->experience_rated_at);

        Carbon::setTestNow(now()->addDays(3));
        Volt::test('experience-prompt')->call('tryOpen')->assertSet('open', false);
        Carbon::setTestNow();
    }

    public function test_a_score_becomes_a_pending_review_named_for_the_last_thing_delivered(): void
    {
        $this->enable();
        $user = $this->customer();
        $this->deliveredOrderIn($user, 'eSIMs');
        $this->actingAs($user);

        Volt::test('experience-prompt')
            ->call('tryOpen')
            ->assertSet('subject', 'eSIMs')
            ->assertSee('How would you rate our eSIMs?')
            ->assertSee('Question 1 of 2')
            ->call('choose', 9)
            ->assertSet('step', 2)
            ->assertSee('Question 2 of 2')
            ->set('comment', 'Landed and it just worked.')
            ->call('submit')
            ->assertHasNoErrors()
            ->assertSet('submitted', true);

        $review = Review::sole();
        $this->assertSame($user->id, $review->user_id);
        $this->assertSame(9, $review->score);
        $this->assertSame(4.5, $review->rating);
        $this->assertSame('eSIMs', $review->category);
        $this->assertSame('Landed and it just worked.', $review->body);
        $this->assertSame('Divine Ofeh', $review->author_name);
        $this->assertSame('DO', $review->initials);
        $this->assertFalse($review->is_anonymous);
        $this->assertFalse($review->is_published);
        $this->assertTrue($review->is_customer_submitted);
        $this->assertSame('RshopRefill', $review->source);
        $this->assertSame(1, Review::pending()->count());
    }

    public function test_with_no_order_it_asks_about_the_experience_in_general(): void
    {
        $this->enable();
        $this->actingAs($this->customer());

        Volt::test('experience-prompt')
            ->call('tryOpen')
            ->assertSet('subject', null)
            ->assertSee('How would you rate your experience?');
    }

    public function test_the_score_is_bounded_and_the_comment_capped(): void
    {
        $this->enable();
        $this->actingAs($this->customer());

        $component = Volt::test('experience-prompt')->call('tryOpen')->call('choose', 14);
        $this->assertSame(10, $component->get('score'));

        $component->set('comment', str_repeat('x', 301))->call('submit')->assertHasErrors(['comment']);
        $this->assertSame(0, Review::count());
    }

    public function test_answering_again_updates_the_same_pending_review(): void
    {
        $this->enable();
        $user = $this->customer();
        $this->actingAs($user);

        Volt::test('experience-prompt')->call('tryOpen')->call('choose', 3)->call('submit');

        // A re-answer (say the admin re-enabled asking) replaces, not duplicates.
        app(ExperiencePromptService::class)->submit($user, 10, 'Changed my mind', false);

        $this->assertSame(1, Review::where('user_id', $user->id)->count());
        $this->assertSame(10, Review::sole()->score);
    }

    public function test_a_score_alone_counts_but_never_reaches_the_carousel(): void
    {
        $this->enable();
        $user = $this->customer();
        $this->actingAs($user);

        Volt::test('experience-prompt')->call('tryOpen')->call('choose', 10)->call('submit')->assertHasNoErrors();

        $review = Review::sole();
        $this->assertNull($review->body);

        // Admin approves it.
        $review->update(['is_published' => true]);

        $this->assertSame(1, Review::published()->ownCollected()->count());
        $this->assertSame(0, Review::published()->ownCollected()->withText()->count());

        $carousel = $this->carousel($this->withoutVite()->get('/')->assertOk()->getContent());
        $this->assertStringNotContainsString('Divine Ofeh', $carousel);
    }

    public function test_an_anonymous_review_hides_the_author_from_customers_but_not_from_admin(): void
    {
        $this->enable();
        $user = $this->customer();
        $this->actingAs($user);

        Volt::test('experience-prompt')
            ->call('tryOpen')
            ->call('choose', 9)
            ->set('comment', 'Great service, would rather not say who I am.')
            ->set('anonymous', true)
            ->call('submit')
            ->assertHasNoErrors();

        $review = Review::sole();
        $this->assertTrue($review->is_anonymous);
        $this->assertSame('Divine Ofeh', $review->author_name, 'Admin keeps the real name on the row.');
        $this->assertSame('Anonymous customer', $review->displayName());
        $this->assertSame('A', $review->displayInitials());

        $review->update(['is_published' => true]);

        $carousel = $this->carousel($this->withoutVite()->get('/')->assertOk()->getContent());
        $this->assertStringContainsString('Anonymous customer', $carousel);
        $this->assertStringNotContainsString('Divine Ofeh', $carousel);
        $this->assertStringNotContainsString('>DO<', $carousel);
    }

    public function test_the_anonymous_label_is_translated_in_every_locale(): void
    {
        foreach (['en', 'fr', 'es', 'de', 'pt', 'it', 'ar', 'sw', 'ha', 'zh'] as $locale) {
            $lines = include lang_path("{$locale}/common.php");
            $this->assertArrayHasKey('anonymous_customer', $lines, "{$locale} is missing anonymous_customer.");
        }
    }

    public function test_the_admin_queue_shows_the_score_the_subject_and_anonymity(): void
    {
        $this->enable();
        $user = $this->customer();
        $this->deliveredOrderIn($user, 'Gift Cards');
        $this->actingAs($user);

        Volt::test('experience-prompt')->call('tryOpen')->call('choose', 7)->set('anonymous', true)->call('submit');
        auth()->logout();

        // No admin factory exists; the seeder's shape, minus the env lookup.
        $admin = Admin::create([
            'name' => 'Moderator',
            'email' => 'moderator@example.com',
            'password' => 'not-used-by-this-test',
            'role' => AdminRole::SuperAdmin,
            'is_active' => true,
        ]);

        Volt::actingAs($admin, 'admin')
            ->test('admin.content.reviews')
            ->assertSee('Scored 7 / 10 on Gift Cards')
            ->assertSee('Anonymous')
            ->assertSee('Pending')
            ->assertSee('Divine Ofeh');
    }

    public function test_the_panel_is_a_bottom_sheet_on_phones_and_centred_from_lg(): void
    {
        $this->enable();
        $this->actingAs($this->customer());

        $html = Volt::test('experience-prompt')->call('tryOpen')->html();

        $this->assertStringContainsString('absolute inset-x-0 bottom-0 rounded-t-[12px]', $html);
        $this->assertStringContainsString('lg:inset-auto lg:left-1/2 lg:top-1/2', $html);
        $this->assertStringContainsString('lg:rounded-[12px]', $html);
        $this->assertStringContainsString('x-transition:enter-start="max-lg:translate-y-full lg:scale-95 lg:opacity-0"', $html);

        // Ten circles, brand blue when chosen, and the scale's two anchors.
        $this->assertSame(10, substr_count($html, 'wire:click="choose('));
        $this->assertStringContainsString('>Poor<', $html);
        $this->assertStringContainsString('>Excellent<', $html);
        $this->assertStringNotContainsString('border-purple', $html);
    }
}

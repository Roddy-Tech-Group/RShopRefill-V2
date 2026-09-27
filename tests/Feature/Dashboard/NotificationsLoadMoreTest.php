<?php

namespace Tests\Feature\Dashboard;

use App\Domain\Communication\Enums\CommunicationChannel;
use App\Domain\Communication\Enums\DeliveryStatus;
use App\Domain\Communication\Enums\Priority;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Volt\Volt;
use Tests\TestCase;

/**
 * The notifications page has no page numbers, same as the order history.
 * Ten notifications render, and the sentinel under them asks for ten more
 * each time it scrolls into view, until the last one is on the page.
 */
class NotificationsLoadMoreTest extends TestCase
{
    use RefreshDatabase;

    private function seedNotifications(User $user, int $count): void
    {
        for ($i = 1; $i <= $count; $i++) {
            $user->notifications()->create([
                'type' => 'order.update',
                'title' => 'Order update '.$i,
                'message' => 'Your order moved on.',
                'channel' => CommunicationChannel::InApp,
                'status' => DeliveryStatus::Delivered,
                'priority' => Priority::Normal,
                'created_at' => now()->subMinutes($count - $i),
            ]);
        }
    }

    public function test_the_first_chunk_shows_ten_notifications_and_a_scroll_sentinel(): void
    {
        $user = User::factory()->create();
        $this->seedNotifications($user, 25);
        $this->actingAs($user);

        Volt::test('dashboard.notifications')
            ->assertViewHas('notifications', fn ($notifications) => $notifications->count() === 10 && $notifications->hasMorePages())
            ->assertSeeHtml('wire:intersect.margin.400px="loadMore"')
            ->assertSee('Order update 25')
            ->assertDontSee('Order update 15')
            ->assertDontSeeHtml('wire:click="nextPage"')
            ->assertDontSeeHtml('wire:click="gotoPage(');
    }

    public function test_reaching_the_bottom_loads_ten_more_until_the_last_notification(): void
    {
        $user = User::factory()->create();
        $this->seedNotifications($user, 25);
        $this->actingAs($user);

        $page = Volt::test('dashboard.notifications')->call('loadMore');

        $page->assertSet('visible', 20)
            ->assertViewHas('notifications', fn ($notifications) => $notifications->count() === 20 && $notifications->hasMorePages());

        $page->call('loadMore')
            ->assertViewHas('notifications', fn ($notifications) => $notifications->count() === 25 && ! $notifications->hasMorePages())
            ->assertSee('Order update 1')
            ->assertDontSeeHtml('wire:intersect');
    }

    public function test_a_short_inbox_has_no_sentinel(): void
    {
        $user = User::factory()->create();
        $this->seedNotifications($user, 3);
        $this->actingAs($user);

        Volt::test('dashboard.notifications')
            ->assertViewHas('notifications', fn ($notifications) => $notifications->count() === 3 && ! $notifications->hasMorePages())
            ->assertDontSeeHtml('wire:intersect');
    }

    public function test_the_page_renders_through_the_dashboard_layout_with_the_sentinel(): void
    {
        $user = User::factory()->create();
        $this->seedNotifications($user, 11);

        $this->actingAs($user)
            ->get(route('dashboard.notifications'))
            ->assertOk()
            ->assertSee('Loading more notifications')
            ->assertDontSee('wire:click="nextPage"', false);
    }

    public function test_the_browser_cannot_set_the_loaded_count_itself(): void
    {
        $user = User::factory()->create();
        $this->seedNotifications($user, 3);
        $this->actingAs($user);

        $this->expectException(CannotUpdateLockedPropertyException::class);

        Volt::test('dashboard.notifications')->set('visible', 100000);
    }
}

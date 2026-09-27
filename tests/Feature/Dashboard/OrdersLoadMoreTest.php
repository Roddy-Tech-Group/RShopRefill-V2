<?php

namespace Tests\Feature\Dashboard;

use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Volt\Volt;
use Tests\TestCase;

/**
 * The order history has no page numbers. Ten orders render, and the sentinel
 * under them asks for ten more each time it scrolls into view, until the last
 * order is on the page. The newest order starts expanded.
 */
class OrdersLoadMoreTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_first_chunk_shows_ten_orders_and_a_scroll_sentinel(): void
    {
        $user = User::factory()->create();
        Order::factory()->count(25)->create(['user_id' => $user->id]);
        $this->actingAs($user);

        Volt::test('dashboard.orders')
            ->assertViewHas('orders', fn ($orders) => $orders->count() === 10 && $orders->hasMorePages())
            ->assertSeeHtml('wire:intersect.margin.400px="loadMore"')
            ->assertDontSeeHtml('wire:click="nextPage"')
            ->assertDontSeeHtml('wire:click="gotoPage(');
    }

    public function test_reaching_the_bottom_loads_ten_more_until_the_last_order(): void
    {
        $user = User::factory()->create();
        Order::factory()->count(25)->create(['user_id' => $user->id]);
        $this->actingAs($user);

        $page = Volt::test('dashboard.orders')->call('loadMore');

        $page->assertSet('visible', 20)
            ->assertViewHas('orders', fn ($orders) => $orders->count() === 20 && $orders->hasMorePages());

        $page->call('loadMore')
            ->assertViewHas('orders', fn ($orders) => $orders->count() === 25 && ! $orders->hasMorePages())
            ->assertDontSeeHtml('wire:intersect');
    }

    public function test_a_new_search_or_filter_starts_from_the_first_chunk_again(): void
    {
        $user = User::factory()->create();
        Order::factory()->count(25)->create(['user_id' => $user->id]);
        $this->actingAs($user);

        Volt::test('dashboard.orders')
            ->call('loadMore')
            ->assertSet('visible', 20)
            ->set('search', 'ORD-')
            ->assertSet('visible', 10)
            ->call('loadMore')
            ->assertSet('visible', 20)
            ->set('expired', true)
            ->assertSet('visible', 10);
    }

    public function test_the_newest_order_starts_open(): void
    {
        $user = User::factory()->create();
        $older = Order::factory()->create([
            'user_id' => $user->id,
            'created_at' => now()->subDay(),
            'placed_at' => now()->subDay(),
        ]);
        $newest = Order::factory()->create(['user_id' => $user->id]);
        $this->actingAs($user);

        Volt::test('dashboard.orders')
            ->assertSeeHtml("x-data=\"{ openId: '{$newest->id}' }\"")
            ->assertDontSeeHtml("x-data=\"{ openId: '{$older->id}' }\"");
    }

    public function test_the_page_renders_through_the_dashboard_layout_with_the_sentinel(): void
    {
        $user = User::factory()->create();
        Order::factory()->count(11)->create(['user_id' => $user->id]);

        $this->actingAs($user)
            ->get(route('dashboard.orders'))
            ->assertOk()
            ->assertSee('Loading more orders')
            ->assertDontSee('wire:click="nextPage"', false);
    }

    public function test_the_browser_cannot_set_the_loaded_count_itself(): void
    {
        $user = User::factory()->create();
        Order::factory()->count(3)->create(['user_id' => $user->id]);
        $this->actingAs($user);

        $this->expectException(CannotUpdateLockedPropertyException::class);

        Volt::test('dashboard.orders')->set('visible', 100000);
    }
}

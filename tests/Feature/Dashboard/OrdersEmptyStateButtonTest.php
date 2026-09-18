<?php

namespace Tests\Feature\Dashboard;

use Tests\TestCase;

/**
 * "Explore everything" on the empty dashboard orders page is a button in the
 * shop tiles' #f4f6f5 surface: not a text link, not blue, no arrow.
 */
class OrdersEmptyStateButtonTest extends TestCase
{
    public function test_explore_everything_is_a_tile_coloured_button(): void
    {
        $view = file_get_contents(resource_path('views/livewire/dashboard/orders.blade.php'));

        $at = strpos($view, 'Explore everything');
        $this->assertNotFalse($at);

        $anchor = substr($view, strrpos(substr($view, 0, $at), '<a'), $at - strrpos(substr($view, 0, $at), '<a'));

        $this->assertStringContainsString("route('dashboard.shop.gift-cards')", $anchor);
        $this->assertStringContainsString('rounded-[12px] bg-[#f4f6f5] px-5 py-3', $anchor);
        $this->assertStringContainsString('text-zinc-900', $anchor);
        $this->assertStringNotContainsString('bg-blue-600', $anchor);
        $this->assertStringNotContainsString('text-blue-600', $anchor);
        $this->assertStringNotContainsString('<svg', $anchor);
    }
}

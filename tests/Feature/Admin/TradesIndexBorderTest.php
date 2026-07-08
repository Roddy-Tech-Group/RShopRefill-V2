<?php

namespace Tests\Feature\Admin;

use Tests\TestCase;

/**
 * The gift-card trades table uses the standard admin-table card wrapper (white
 * bordered card + inner p-3 padding), so the blue header "pill" has breathing
 * room instead of butting flush against the container border (box-in-a-box).
 */
class TradesIndexBorderTest extends TestCase
{
    public function test_trades_table_uses_the_standard_admin_card_wrapper(): void
    {
        $blade = file_get_contents(resource_path('views/livewire/admin/gift-cards/trades/index.blade.php'));

        // Standard admin-table card + the inner padding the blue header pill
        // needs. The card border is the hero-card ring style (zinc ring in
        // light, soft white hairline in dark) - the old solid white border
        // was retired across the admin.
        $this->assertStringContainsString('rounded-[12px] bg-white shadow-sm shadow-zinc-900/[0.04] ring-1 ring-zinc-200 dark:bg-[#1d3252] dark:ring-white/10', $blade);
        $this->assertStringContainsString('<div class="overflow-x-auto p-3">', $blade);

        // The old bare wrapper that let the header pill sit flush is gone.
        $this->assertStringNotContainsString('rounded-xl border border-zinc-200 bg-white shadow-sm dark:border-white/10 dark:bg-zinc-900/50', $blade);
    }

    public function test_cards_use_the_system_12px_radius_not_the_20px_rounded_xl(): void
    {
        // In this project rounded-xl is 20px (app.css radius scale); cards should
        // use the rounded-[12px] house standard so they match the table + admin.
        $index = file_get_contents(resource_path('views/livewire/admin/gift-cards/trades/index.blade.php'));
        $show = file_get_contents(resource_path('views/livewire/admin/gift-cards/trades/show.blade.php'));

        // Stat cards on the index.
        $this->assertStringContainsString('rounded-[12px] border border-zinc-200 bg-white p-5', $index);
        $this->assertStringNotContainsString('rounded-xl border border-zinc-200', $index);

        // Detail-page cards.
        $this->assertStringNotContainsString('rounded-xl border border-zinc-200', $show);
    }
}

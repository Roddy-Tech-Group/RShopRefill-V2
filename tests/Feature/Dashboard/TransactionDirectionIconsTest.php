<?php

namespace Tests\Feature\Dashboard;

use Tests\TestCase;

/**
 * Money-in / money-out icons: small dark blue arrows in a small rounded tile,
 * not the old green and red circles.
 */
class TransactionDirectionIconsTest extends TestCase
{
    public function test_the_icons_are_dark_blue_arrows_not_green_and_red(): void
    {
        foreach (['txn-credit', 'txn-debit'] as $icon) {
            $svg = file_get_contents(resource_path("views/components/icons/{$icon}.blade.php"));

            $this->assertStringContainsString('text-[#0c1337] dark:text-zinc-200', $svg);
            $this->assertStringContainsString('stroke="currentColor"', $svg);
            $this->assertStringNotContainsStringIgnoringCase('#2BD576', $svg);
            $this->assertStringNotContainsStringIgnoringCase('#FF5C5C', $svg);
        }
    }

    public function test_every_use_sits_in_a_small_rounded_tile(): void
    {
        $tile = '<span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-[10px] bg-[#f4f6f5]">';

        $overview = file_get_contents(resource_path('views/livewire/dashboard/overview.blade.php'));
        $transactions = file_get_contents(resource_path('views/livewire/dashboard/transactions.blade.php'));

        $this->assertSame(2, substr_count($overview, $tile));
        $this->assertSame(1, substr_count($transactions, $tile));
        $this->assertSame(0, substr_count($overview.$transactions, 'rounded-full bg-[#f4f6f5] shadow-sm shadow-zinc-900/5'));
        $this->assertSame(3, substr_count($overview.$transactions, '<x-icons.txn-credit class="h-4 w-4" />'));
    }
}

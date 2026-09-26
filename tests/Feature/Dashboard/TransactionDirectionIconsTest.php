<?php

namespace Tests\Feature\Dashboard;

use Tests\TestCase;

/**
 * Money-in and money-out icons: small arrows in a small rounded tile, green
 * for in and red for out.
 *
 * They were drawn in the dashboard navy, so the direction was carried by which
 * way the arrow pointed and nothing else, while the amount beside it was
 * already coloured. One row said the same thing two ways and only one of them
 * could be read at a glance.
 */
class TransactionDirectionIconsTest extends TestCase
{
    public function test_the_arrows_carry_the_direction_as_colour(): void
    {
        $credit = file_get_contents(resource_path('views/components/icons/txn-credit.blade.php'));
        $debit = file_get_contents(resource_path('views/components/icons/txn-debit.blade.php'));

        $this->assertStringContainsString('text-emerald-600 dark:text-emerald-400', $credit);
        $this->assertStringContainsString('text-red-600 dark:text-red-400', $debit);

        // Still one path in currentColor, so the tile it sits in and the colour
        // it takes stay separable.
        foreach ([$credit, $debit] as $svg) {
            $this->assertStringContainsString('stroke="currentColor"', $svg);
            $this->assertStringNotContainsString('text-[#0c1337] dark:text-zinc-200', $svg);
        }
    }

    /** The amount says it too, and says the same thing. */
    public function test_the_amount_beside_it_agrees(): void
    {
        $overview = file_get_contents(resource_path('views/livewire/dashboard/overview.blade.php'));
        $transactions = file_get_contents(resource_path('views/livewire/dashboard/transactions.blade.php'));

        $both = $overview.$transactions;

        $this->assertSame(3, substr_count($both, "\$txn->is_credit ? 'text-emerald-600' : 'text-red-600'"));
        $this->assertSame(0, substr_count($both, "\$txn->is_credit ? 'text-emerald-600' : 'text-zinc-900'"));

        // And the sign, which is the part that reads without colour at all.
        $this->assertGreaterThanOrEqual(3, substr_count($both, "\$txn->is_credit ? '+' : '-'"));
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

<?php

namespace Tests\Feature\Admin;

use Tests\TestCase;

/**
 * The finance page says each figure once.
 *
 * Revenue and supplier cost are net of refunds and supplier credits. The page used
 * to repeat that under every number ("USD 107.69 gross, less refunds" under the
 * tile, "less USD 36.00 refunded" inside every table cell) while the Refunds tile
 * beside them already states what came off. The repeats made table rows uneven
 * and were removed; the figures and their basis did not change.
 */
class FinancePageCaptionsTest extends TestCase
{
    public function test_the_finance_page_does_not_repeat_the_refund_and_credit_captions(): void
    {
        $source = file_get_contents(resource_path('views/livewire/admin/management/finances.blade.php'));

        $this->assertStringNotContainsString('gross, less refunds', $source);
        $this->assertStringNotContainsString('gross, less credits', $source);
        $this->assertStringNotContainsString("'refunded'", $source);
        $this->assertStringNotContainsString("'credited'", $source);
        $this->assertStringNotContainsString('$netCell', $source);

        // The one place the deduction is still named: the Refunds tile.
        $this->assertStringContainsString("'label' => 'Refunds'", $source);
    }

    /**
     * Headers sit over their numbers. The shared table stylesheet forces every
     * header cell left while the figures beneath are right-aligned, so the
     * numeric headers carry an important right alignment, and both tables share
     * one fixed column plan so they line up with each other down the page.
     */
    public function test_the_two_tables_share_one_column_plan_with_right_aligned_numeric_headers(): void
    {
        $source = file_get_contents(resource_path('views/livewire/admin/management/finances.blade.php'));

        $this->assertSame(2, substr_count($source, '<table class="w-full table-fixed admin-table">'));
        $this->assertSame(2, substr_count($source, '<colgroup>'));
        // Six numeric headers per table, every one right-aligned over its column.
        $this->assertSame(12, substr_count($source, '!text-right'));
        $this->assertStringNotContainsString('{{ $th }} text-right', $source);
        // Both tables close with the same company total row.
        $this->assertSame(2, substr_count($source, 'Company total'));
    }
}

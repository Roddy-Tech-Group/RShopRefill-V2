<?php

namespace Tests\Feature\Admin;

use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * The admin tables' row divider is one line.
 *
 * Middle cells draw it as a bottom border; the first and last cells draw it
 * as a gradient that stops short of their horizontal padding, with their own
 * border kept transparent. Dark mode recolours every cell's border in one
 * rule, which had put that border back under the end cells' gradient and
 * doubled the line at both ends of every row. The gradient also paints in the
 * border band, so the end segments sit on the same pixel row as the middle
 * ones rather than one above.
 */
class AdminTableInsetDividerTest extends TestCase
{
    public function test_the_end_cells_keep_a_transparent_border_in_dark_mode(): void
    {
        $css = File::get(resource_path('css/app.css'));

        foreach (['first-child', 'last-child'] as $end) {
            $this->assertMatchesRegularExpression(
                '/html\.dark \.admin-table tbody tr:not\(:last-child\) td:'.$end.' \{[^}]*border-bottom-color: transparent;/s',
                $css,
                "the dark {$end} cell must not draw a border under its gradient divider"
            );
        }
    }

    public function test_the_end_cells_paint_their_line_in_the_border_band(): void
    {
        $css = File::get(resource_path('css/app.css'));

        foreach (['first-child', 'last-child'] as $end) {
            $this->assertMatchesRegularExpression(
                '/^\.admin-table tbody tr:not\(:last-child\) td:'.$end.' \{[^}]*background-origin: border-box;[^}]*border-bottom-color: transparent;/ms',
                $css,
                "the {$end} cell's gradient should align with the middle cells' border"
            );
        }
    }
}

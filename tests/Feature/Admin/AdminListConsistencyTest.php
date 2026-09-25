<?php

namespace Tests\Feature\Admin;

use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * The admin lists look like one product.
 *
 * Admin grew two kinds of list: `.admin-table` (14 views) and a div-grid built
 * from `*-row` / `*-body` pairs (customers, orders, products, transactions).
 * They drifted apart in two visible ways:
 *
 *  - Headers. BOTH wore blue, by two different routes: the div-grid lists via
 *    `bg-blue-50 ... ring-2 ring-blue-500` utilities, the table lists via a
 *    hardcoded rule in app.css. Fixing one left the other untouched, which is
 *    how wallets/reports/newsletter/campaigns/pricing-rules kept their blue
 *    header after the four grid lists went neutral.
 *  - Row hover. The table rule hardcoded rgb(239 246 255) plus a blue ring,
 *    which bypassed the palette override; the div-grid rows write
 *    `hover:bg-blue-50`, which that override remaps to grey. The same gesture
 *    painted blue or grey depending only on which markup a page used.
 *
 * Both now land on the neutral page surface, with no ring.
 */
class AdminListConsistencyTest extends TestCase
{
    /** The div-grid lists, by view name. */
    private const GRID_LISTS = ['customers', 'orders', 'products', 'transactions'];

    private function listView(string $name): string
    {
        return File::get(resource_path("views/admin/{$name}.blade.php"));
    }

    public function test_no_list_header_wears_the_blue_pill(): void
    {
        foreach (self::GRID_LISTS as $name) {
            $view = $this->listView($name);

            // Matched on the header pill's own signature. A bare
            // `ring-2 ring-blue-500` check would be too broad: an open
            // dropdown trigger legitimately wears `ring-2 ring-blue-500/15`
            // as its focus state, and that is not a list header.
            foreach (['bg-blue-50 px-6 py-3', 'tracking-wider text-blue-700'] as $blue) {
                $this->assertStringNotContainsString(
                    $blue,
                    $view,
                    "{$name} still has a blue list header"
                );
            }
        }
    }

    /** All four grid headers use the same neutral pill. */
    public function test_every_grid_header_uses_the_same_neutral_pill(): void
    {
        foreach (self::GRID_LISTS as $name) {
            $this->assertStringContainsString(
                'rounded-[12px] bg-zinc-50 px-6 py-3 text-[10px] font-bold uppercase tracking-wider text-zinc-600 dark:bg-white/5 dark:text-zinc-300',
                $this->listView($name),
                "{$name} header does not match the shared neutral pill"
            );
        }
    }

    /**
     * The table header is the same neutral chip as the div-grid header pill.
     *
     * `.admin-table thead th` hardcoded a blue pill - #eff6ff fill, blue-700
     * text, a 2px blue-500 frame - written as raw CSS rather than utilities, so
     * it sidestepped the palette override and stayed blue on all fourteen table
     * pages (wallets, reports, newsletter, campaigns, pricing rules and the
     * rest) while the four div-grid lists went neutral.
     */
    public function test_the_table_header_matches_the_grid_header(): void
    {
        $css = File::get(resource_path('css/app.css'));

        preg_match('/\.admin-table thead th \{([^}]*)\}/', $css, $m);
        $this->assertNotEmpty($m, 'the table header rule is missing');

        $this->assertStringContainsString('background-color: #fafafa', $m[1]);
        $this->assertStringContainsString('color: #243049', $m[1]);

        foreach (['rgb(239 246 255)', 'rgb(29 78 216)', 'rgb(59 130 246)'] as $blue) {
            $this->assertStringNotContainsString($blue, $m[1], 'the blue header should be gone');
        }

        // The 2px frame went with it.
        $this->assertStringNotContainsString('border-top: 2px', $m[1]);
    }

    /** No page overrides the shared header colour with its own cell class. */
    public function test_no_table_header_cell_sets_its_own_colour(): void
    {
        $hits = [];

        foreach (['views/admin', 'views/livewire/admin'] as $dir) {
            foreach (File::allFiles(resource_path($dir)) as $file) {
                if (preg_match('/<th class="[^"]*text-zinc-\d+/', File::get($file->getPathname()))) {
                    $hits[] = $file->getFilename();
                }
            }
        }

        $this->assertSame([], $hits, 'these views colour their own <th>, breaking the shared header');
    }

    /**
     * Table rows hover to the same neutral surface as the div-grid rows, and
     * without the blue ring. The literal is what the palette remaps
     * `hover:bg-blue-50` to, which is what the div-grid rows resolve to.
     */
    public function test_table_row_hover_is_neutral_and_ringless(): void
    {
        $css = File::get(resource_path('css/app.css'));

        $this->assertMatchesRegularExpression(
            '/\.admin-table tbody tr:hover \{[^}]*background-color: #f4f6f5/',
            $css,
            'the table row hover should use the neutral page surface'
        );

        preg_match('/\.admin-table tbody tr:hover \{([^}]*)\}/', $css, $m);
        $this->assertNotEmpty($m);
        $this->assertStringNotContainsString('rgb(59 130 246)', $m[1], 'the blue hover ring should be gone');
        $this->assertStringNotContainsString('rgb(239 246 255)', $m[1], 'the blue hover fill should be gone');
    }

    /**
     * The last row clears the card's rounded corner, on every kind of list.
     *
     * Rows are inset `mx-3` (a hovered row is a band inside the card, not edge
     * to edge), and the list needs the same 12px below its last row: flush to
     * the bottom edge, the corner curve clipped the final row's band. The
     * div-grid cards carry it on the card, the settings-style lists on the
     * `.divide-inset` container itself.
     */
    public function test_the_last_row_clears_the_card_bottom(): void
    {
        foreach (['orders', 'transactions', 'customers'] as $name) {
            $view = $this->listView($name);

            $this->assertStringContainsString('overflow-hidden rounded-[12px] pb-3 bg-white', $view, "{$name}'s list card should leave room below the last row");
            $this->assertMatchesRegularExpression('/-body group relative mx-3\b/', $view, "{$name}'s rows should stay inset");
        }

        foreach ([
            'livewire/admin/system-settings.blade.php',
            'livewire/admin/api-settings.blade.php',
            'livewire/admin/content/faqs.blade.php',
            'livewire/admin/content/rewards.blade.php',
            'livewire/admin/content/rewards-analytics.blade.php',
        ] as $rel) {
            $this->assertMatchesRegularExpression(
                '/class="divide-inset pb-3"/',
                File::get(resource_path('views/'.$rel)),
                "{$rel}'s list should leave room below its last row"
            );
        }
    }

    /**
     * Rows are square - at rest and on hover, in both kinds of list.
     *
     * They used to round to 12px on hover (and the table rows carried a
     * resting radius too), so a hovered row read as a pill floating out of the
     * list. Flat keeps the row a band across the table, which is what the
     * hairline ::after dividers between rows assume.
     */
    public function test_rows_are_square_at_rest_and_on_hover(): void
    {
        foreach (self::GRID_LISTS as $name) {
            $view = $this->listView($name);

            $this->assertDoesNotMatchRegularExpression(
                '/-body:hover\s*\{\s*border-radius/',
                $view,
                "{$name} rows should not round on hover"
            );

            $this->assertDoesNotMatchRegularExpression(
                '/-row [a-z-]*body group relative mx-3 (cursor-pointer )?rounded-\[12px\]/',
                $view,
                "{$name} rows should not carry a resting radius"
            );
        }

        $css = File::get(resource_path('css/app.css'));

        foreach (['.admin-table tbody tr', '.admin-table tbody tr:hover'] as $selector) {
            preg_match('/'.preg_quote($selector, '/').' \{([^}]*)\}/', $css, $m);
            $this->assertNotEmpty($m, "{$selector} is missing");
            $this->assertStringNotContainsString(
                'border-radius',
                $m[1],
                "{$selector} should not round the row"
            );
        }
    }
}

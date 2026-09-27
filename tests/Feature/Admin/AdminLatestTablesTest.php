<?php

namespace Tests\Feature\Admin;

use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * The dashboard's Latest Users / Latest Transactions cards.
 *
 * Both tables are wider than their card on smaller viewports, and app.css
 * hides every in-page scrollbar by default - right for dropdowns and modals,
 * wrong here: with no bar there is nothing on screen saying more columns
 * exist. `.scroll-x-thin` opts these two containers back in.
 *
 * The skeleton row counts are checked against the real limits, because the
 * placeholder stands in until the metrics query returns; if they disagree the
 * card visibly changes height at the moment it loads.
 */
class AdminLatestTablesTest extends TestCase
{
    private const CONTENT = 'views/livewire/admin/dashboard-content.blade.php';

    private const SKELETON = 'views/components/skeletons/admin-dashboard.blade.php';

    /**
     * The two cards are the same height, filled rather than padded.
     *
     * Transaction rows are taller than user rows (status pills, two-line
     * amounts), so an equal row count on a shared height left the users card
     * padding out 119px of dead space. Latest Users renders a couple more rows
     * to fill the height that Latest Transactions sets.
     */
    public function test_the_two_cards_share_one_height(): void
    {
        $content = File::get(resource_path(self::CONTENT));

        // The grid keeps its default stretch: no items-start on this row.
        $this->assertStringContainsString('grid grid-cols-1 gap-4 xl:grid-cols-2', $content);

        $this->assertStringContainsString('$dashboardQuery->getLatestUsers(12)', $content);
        $this->assertStringContainsString('$dashboardQuery->getLatestTransactions()', $content);
    }

    /** The placeholder reserves the same number of rows the real tables fill. */
    public function test_the_skeleton_reserves_matching_rows(): void
    {
        $this->assertStringContainsString(
            '($table === 0 ? 12 : 10)',
            File::get(resource_path(self::SKELETON)),
            'the skeleton should reserve 12 user rows and 10 transaction rows'
        );
    }

    /**
     * Both scroll containers opt back into a visible bar, and fill their card
     * (`flex-1` inside a flex-column card) so the bar sits on the card's bottom
     * edge. Without that, the shorter table's bar floated above the card
     * bottom while the other card's bar sat on it.
     */
    public function test_both_table_containers_show_a_thin_scrollbar_at_the_card_bottom(): void
    {
        $content = File::get(resource_path(self::CONTENT));

        $this->assertSame(
            2,
            substr_count($content, 'class="scroll-x-thin flex-1 overflow-x-auto p-3"'),
            'both latest-* tables should carry the thin horizontal scrollbar and fill the card'
        );
        $this->assertSame(
            2,
            substr_count($content, 'class="flex min-w-0 flex-col overflow-hidden rounded-[12px] bg-white shadow-sm shadow-zinc-900/5'),
            'both latest-* cards should be flex columns so the scroll wrapper can fill them'
        );
    }

    /**
     * The container must be EXCLUDED from the blanket hide, not un-hidden
     * afterwards.
     *
     * Once `*::-webkit-scrollbar { display: none }` matches an element,
     * Chromium drops the scrollbar for good: a later, more specific
     * `::-webkit-scrollbar { display: block }` neither lays it out nor paints
     * it. Confirmed against a reduced test case - the first attempt at this
     * feature did exactly that and produced no bar at all.
     */
    public function test_the_opt_in_is_excluded_from_the_global_hide(): void
    {
        $css = File::get(resource_path('css/app.css'));

        // Two opt-outs: .scroll-x-thin (the shared 6px bar) and .scroll-y-thin
        // (containers that style their own bar with utilities, such as the
        // admin sidebar nav). Raising the hide's specificity with :not() had
        // taken those self-styled bars away.
        $this->assertStringContainsString(
            ':where(*:not(.scroll-x-thin):not(.scroll-y-thin))::-webkit-scrollbar',
            $css,
            'the blanket hide must skip both opt-in classes'
        );

        $this->assertStringContainsString(
            ':where(*:not(.scroll-x-thin):not(.scroll-y-thin)) {',
            $css,
            'the scrollbar-width hide must skip both opt-in classes too'
        );

        // The sidebar nav scrolls with no bar at all (on request); the list
        // pages' scroll wrappers are what use the self-styled opt-out.
        $this->assertStringNotContainsString(
            'scroll-y-thin',
            File::get(resource_path('views/components/layouts/admin.blade.php')),
            'the admin sidebar nav should not opt back into a scrollbar'
        );
        $this->assertStringContainsString(
            'scroll-y-thin overflow-x-auto',
            File::get(resource_path('views/admin/orders.blade.php')),
            'the list wrappers keep their self-styled bar'
        );

        $this->assertStringNotContainsString(
            'display: none;
}
*::-webkit-scrollbar',
            $css,
            'the unscoped hide should be gone'
        );
    }

    /**
     * scrollbar-width belongs inside the @supports guard only: setting it in
     * Chromium makes it draw its native Windows bar and ignore the
     * ::-webkit-scrollbar styling entirely.
     */
    public function test_the_bar_is_sized_without_tripping_the_chromium_quirk(): void
    {
        $css = File::get(resource_path('css/app.css'));

        $this->assertMatchesRegularExpression(
            '/\.scroll-x-thin::-webkit-scrollbar \{\s*height: 6px;\s*\}/',
            $css,
            'the bar sets its height and nothing else'
        );

        $this->assertMatchesRegularExpression(
            '/@supports not selector\(::-webkit-scrollbar\) \{\s*\.scroll-x-thin \{\s*scrollbar-width: thin/',
            $css,
            'scrollbar-width belongs inside the @supports guard, not on the bare class'
        );
    }
}

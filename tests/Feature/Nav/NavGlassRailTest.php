<?php

namespace Tests\Feature\Nav;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * The category rail and the two pills at its right end are glass: frosted
 * white on a #dee3e1 hairline, the material the hero chips wear. The rail's
 * glass is a layer BEHIND the scroll track, never on it: a backdrop filter
 * makes its element the containing block for fixed descendants, and the Gift
 * Cards mega-menu panel is fixed to the viewport to escape the track's
 * overflow. Ahead of Invite a Friend sits a Certified reseller badge, on the
 * category line from md and in the menu sheet's foot below it, and from md
 * both pills take the category links' size so the row reads at one height.
 */
class NavGlassRailTest extends TestCase
{
    use RefreshDatabase;

    private const GLASS = 'bg-white/50 backdrop-blur-md backdrop-saturate-150 ring-1 ring-[#dee3e1]';

    private const DARK_GLASS = 'dark:bg-white/5 dark:ring-white/10';

    /** The category links' padding from md; the pills beside them match it. */
    private const LINE_SIZE = 'md:px-4 md:py-2';

    private function nav(): string
    {
        return File::get(resource_path('views/components/nav/main-nav.blade.php'));
    }

    private function badge(): string
    {
        return File::get(resource_path('views/components/nav/certified-badge.blade.php'));
    }

    private function invite(): string
    {
        return File::get(resource_path('views/components/nav/invite-link.blade.php'));
    }

    public function test_the_rail_glass_is_a_layer_behind_the_track_not_on_it(): void
    {
        $nav = $this->nav();

        $this->assertStringContainsString("\$glass = '".self::GLASS.' '.self::DARK_GLASS."';", $nav);
        $this->assertStringContainsString('<span aria-hidden="true" class="pointer-events-none absolute inset-0 hidden rounded-full md:block {{ $glass }}"></span>', $nav);

        // The track that follows the layer carries scroll and shape, no blur.
        preg_match('/\{\{ \$glass \}\}"><\/span>\s*<div class="([^"]+)"/', $nav, $track);
        $this->assertNotEmpty($track, 'The scroll track no longer follows the glass layer.');
        $this->assertStringContainsString('overflow-x-auto', $track[1]);
        $this->assertStringContainsString('relative', $track[1]);
        $this->assertStringNotContainsString('backdrop', $track[1]);

        // The category links' own metrics from md, which the pills follow.
        $this->assertStringContainsString('md:px-4 md:py-2 md:text-[16px] md:leading-4', $nav);
    }

    /**
     * From md the rail is only as wide as the categories in it. It used to
     * grow to the whole row, which left the glass running on past the last
     * category as an empty pill.
     *
     * flex-1 below md (full width, scrolls), flex-initial from md (0 1 auto:
     * content-sized, still allowed to shrink). Both boxes need it - the outer
     * is sized from the inner's content, which only holds while the inner is
     * content-sized too - and both keep min-w-0 so a long catalogue scrolls
     * inside the rail instead of overflowing the row.
     */
    public function test_the_rail_hugs_its_categories_from_md(): void
    {
        $nav = $this->nav();

        $this->assertSame(2, substr_count($nav, 'flex min-w-0 flex-1 md:flex-initial'), 'Both the rail and its track must be content-sized from md.');

        // The pills hold the right end themselves now that nothing pushes them.
        $this->assertStringContainsString('class="relative ml-auto hidden shrink-0 items-center gap-2 md:flex"', $nav);
    }

    /**
     * The rail can never be wider than the row it sits in, at any width and
     * whatever the categories are called. Three things hold that, and each is
     * a one-word edit away from being lost:
     *
     *   - min-w-0 on BOTH rail boxes. A flex item's automatic minimum size is
     *     its content, so without this the rail refuses to shrink below the
     *     width of its tabs and pushes straight off the screen. This is the
     *     whole reason a long category label could ever widen the pill.
     *   - shrink-0 on the pills beside it, so the rail is what absorbs the
     *     shrinking rather than the badge and the invite collapsing.
     *   - overflow-x-auto on the track, so what no longer fits scrolls inside
     *     the rail instead of overflowing it.
     *
     * With those, label length is a scrolling question, never a layout one.
     */
    public function test_the_rail_can_never_be_wider_than_the_row(): void
    {
        $nav = $this->nav();

        // Both boxes: the outer one, and the track inside it.
        $this->assertSame(2, substr_count($nav, 'flex min-w-0 flex-1 md:flex-initial'), 'A rail box lost min-w-0 and can now push past the row.');

        preg_match('/\{\{ \$glass \}\}"><\/span>\s*<div class="([^"]+)"/', $nav, $track);
        $this->assertNotEmpty($track);
        $this->assertStringContainsString('overflow-x-auto', $track[1], 'The track must scroll what does not fit.');

        // The pills hold their size, so the rail is what gives way.
        $this->assertStringContainsString('ml-auto hidden shrink-0 items-center gap-2 md:flex', $nav);

        // Nothing in the nav forces a box wider than its parent.
        foreach (['w-max', 'min-w-max', 'flex-nowrap'] as $forced) {
            $this->assertStringNotContainsString($forced, $nav, "{$forced} would defeat the shrink.");
        }
    }

    /**
     * A phone is left exactly as it was before the pill: plain links on the
     * page, no padding, no radius, and the tabs' own page-tone wash. Every
     * part the pill brought is md-scoped.
     *
     * The radius is the one that is not merely cosmetic: this box scrolls, and
     * a border radius on a scroll container clips its content to the rounded
     * corners, which shaves the first and last tab as the row moves.
     *
     * The two pills beside the rail keep their glass at every width; they are
     * controls, not a container around the row.
     */
    public function test_a_phone_keeps_the_row_it_had_before_the_pill(): void
    {
        $nav = $this->nav();

        preg_match('/<span aria-hidden="true" class="([^"]+)\{\{ \$glass \}\}"><\/span>/', $nav, $layer);
        $this->assertNotEmpty($layer, 'The glass layer is gone entirely.');
        $this->assertStringContainsString('hidden', $layer[1], 'The glass still paints on a phone.');
        $this->assertStringContainsString('md:block', $layer[1], 'The glass no longer comes back from md.');

        // Padding and radius arrive with the pill, never before it.
        preg_match('/\{\{ \$glass \}\}"><\/span>\s*<div class="([^"]+)"/', $nav, $track);
        $this->assertStringContainsString('md:rounded-full md:px-1.5 md:py-1', $track[1]);
        $this->assertDoesNotMatchRegularExpression('/(^|\s)(px|py)-[0-9]/', $track[1], 'Phone padding would inset the tabs from the page gutter.');
        $this->assertDoesNotMatchRegularExpression('/(^|\s)rounded-/', $track[1], 'A radius on this scrolling box clips the end tabs on a phone.');

        // The tabs' phone wash is the page tone they had before the pill; the
        // white one belongs to the glass and is md-scoped.
        preg_match('/\$catLinkState = fn.*?;\n/s', $nav, $state);
        $this->assertNotEmpty($state);
        $this->assertStringContainsString("'bg-[#f4f6f5] px-3.5 font-bold text-zinc-900 md:bg-white", $state[0]);
        $this->assertStringContainsString('hover:bg-[#f4f6f5] md:hover:bg-white/70', $state[0]);
    }

    public function test_both_pills_wear_the_glass_at_the_category_links_size(): void
    {
        foreach (['badge' => $this->badge(), 'invite' => $this->invite()] as $name => $markup) {
            $this->assertStringContainsString(self::GLASS, $markup, "{$name} lost the glass");
            $this->assertStringContainsString(self::DARK_GLASS, $markup, "{$name} lost the dark glass");
            $this->assertStringContainsString('px-3.5 py-1.5 nav-text', $markup, "{$name} lost the sheet size");
            $this->assertStringContainsString(self::LINE_SIZE, $markup, "{$name} does not follow the category links from md");
        }

        // The invite pill lights under a pointer; the badge is not a control.
        $this->assertStringContainsString('hover:bg-white/80 hover:ring-[#c9d0cd]', $this->invite());
        $this->assertStringNotContainsString('hover:', $this->badge());
        $this->assertStringNotContainsString('<a', $this->badge());
        $this->assertStringContainsString('d="M6.633 10.25', $this->badge(), 'the thumbs-up is gone');

        // The page-tone wash the invite pill used to wear is gone.
        $this->assertStringNotContainsString('bg-[#f4f6f5]', $this->invite());
        $this->assertStringNotContainsString('hover:bg-[#e8ecea]', $this->invite());
    }

    /** Ahead of the invite pill in both places: the category line, and the sheet's foot. */
    public function test_the_badge_sits_before_invite_a_friend_on_the_line_and_in_the_sheet(): void
    {
        $nav = $this->nav();

        $this->assertSame(2, substr_count($nav, '<x-nav.certified-badge />'));
        $this->assertSame(2, substr_count($nav, '<x-nav.invite-link'));

        $offset = 0;
        for ($copy = 1; $copy <= 2; $copy++) {
            $badge = strpos($nav, '<x-nav.certified-badge />', $offset);
            $invite = strpos($nav, '<x-nav.invite-link', $offset);

            $this->assertNotFalse($badge);
            $this->assertNotFalse($invite);
            $this->assertLessThan($invite, $badge, "copy {$copy}: the badge does not lead the invite pill");

            $offset = $invite + 1;
        }

        // The sheet copy sits in the foot, next to the invite, and only the
        // invite closes the sheet.
        $foot = substr($nav, strpos($nav, 'mt-auto flex flex-col gap-1 pt-6'), 600);
        $this->assertStringContainsString('<x-nav.certified-badge />', $foot);
        $this->assertStringContainsString("<div @click=\"menuOpen = false\">\n                            <x-nav.invite-link />", $foot);
    }

    public function test_the_badge_is_worded_in_every_locale(): void
    {
        $checked = 0;

        foreach (File::directories(lang_path()) as $dir) {
            $file = $dir.'/common.php';

            if (! File::exists($file)) {
                continue;
            }

            $this->assertArrayHasKey('certified_reseller', require $file, basename($dir).' has no wording for the badge.');
            $checked++;
        }

        $this->assertGreaterThanOrEqual(10, $checked);
    }

    public function test_the_badge_renders_on_the_storefront_twice(): void
    {
        $html = $this->withoutVite()->get('/')->assertOk()->getContent();

        $this->assertSame(2, substr_count($html, '>Certified reseller</span>'));
        $this->assertSame(2, substr_count($html, 'd="M6.633 10.25'));
    }
}

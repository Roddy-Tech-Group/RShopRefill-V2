<?php

namespace Tests\Feature\Nav;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * The category rail is plain text that washes on hover, not filled pills.
 *
 * They were pills - a #f4f6f5 chip at rest, a dark fill when active. The rest
 * of the nav is hover-only, so a row of standing chips made the categories
 * read as the loudest thing on the bar. Only Invite a Friend is a pill now.
 *
 * Two things then changed again: the per-category marks were dropped, so the
 * rail is words alone, and the hover underline became Invite a Friend's wash.
 * That pill shares the line, so a second kind of hover on it read as two
 * kinds of control.
 *
 * The open page marks itself with that same wash held down, not an underline.
 * The row already becomes a row of washed shapes under the cursor, so the
 * page you are on should wear the shape the others take rather than a
 * different kind of marker altogether. Hover still cannot say it: the wash is
 * standing on the open tab and momentary on every other, and aria-current is
 * what carries it where colour cannot.
 *
 * The class name is the history, kept so the file keeps its place in the
 * suite: this is where the pill rules lived and where their replacement is
 * asserted.
 */
class CategoryPillsTest extends TestCase
{
    use RefreshDatabase;

    private function nav(): string
    {
        return File::get(resource_path('views/components/nav/main-nav.blade.php'));
    }

    public function test_categories_wash_on_hover_and_never_carry_a_standing_fill(): void
    {
        $nav = $this->nav();

        // The open page: on a phone the hover wash, held down, plus bold; from
        // md, inside the frosted rail, solid white lit against the glass. px-3.5
        // rides with it because the base class only pads from md, and a fill
        // with no padding hugs the text.
        $this->assertStringContainsString(
            "'bg-[#f4f6f5] px-3.5 font-bold text-zinc-900 md:bg-white md:px-4 md:shadow-sm dark:bg-white/12 dark:text-white'",
            $nav
        );

        // Every other tab: the same wash, but only under the cursor.
        $this->assertStringContainsString('hover:bg-[#f4f6f5]', $nav);
        $this->assertStringContainsString('dark:hover:bg-white/8', $nav);

        // No underline anywhere on the rail now - not on hover, not on the
        // open page.
        $this->assertStringNotContainsString('hover:underline hover:decoration-2', $nav);
        $this->assertStringNotContainsString('underline-offset-[6px]', $nav);

        // No standing fill on a tab that is not the open page, and none of the
        // old inverted chip either.
        $this->assertStringNotContainsString('bg-zinc-900 text-white dark:bg-white', $nav);
        $this->assertStringNotContainsString('bg-[#f4f6f5] text-zinc-900 hover:bg-[#e8ecea]', $nav);

        // And no outline either - a tab is carried by its fill and label alone.
        // Measured on the shared tab class: the search field's shortcut <kbd>
        // elsewhere in the file wears a border of its own.
        preg_match('/\$catLinkClass = "([^"]*)"/', $nav, $tab);
        $this->assertNotEmpty($tab, 'The shared tab class is missing.');
        $this->assertStringNotContainsString('border', $tab[1]);
    }

    /**
     * The tabs and the invite pill share one material. Both are glass now -
     * frosted white on a hairline (NavGlassRailTest) - and the wash inside the
     * rail is white rather than the page tone it used to be: a #f4f6f5 fill on
     * a white-over-#f4f6f5 rail was a shade nobody could see. They sit on the
     * same line, so drifting apart is still the regression worth catching.
     */
    public function test_the_hover_wash_is_the_invite_pills_own_material(): void
    {
        $invite = File::get(resource_path('views/components/nav/invite-link.blade.php'));

        $this->assertStringContainsString('bg-white/50', $invite);
        $this->assertStringContainsString('dark:bg-white/5', $invite);

        // The tabs' own state string. The white wash is md-scoped: on a phone
        // the tabs sit on the bar rather than in the rail and keep the page
        // tone they had before it (NavGlassRailTest).
        preg_match('/\$catLinkState = fn.*?;\n/s', $this->nav(), $state);
        $this->assertNotEmpty($state, 'The category tabs no longer carry a state string.');
        $this->assertStringContainsString('md:hover:bg-white/70', $state[0]);

        // The page-tone fill the invite pill used to wear.
        $this->assertStringNotContainsString('bg-[#f4f6f5]', $invite);
    }

    /**
     * A phone gets no horizontal padding on a tab: four chips at 390px leave
     * room for two, and the reference reads as plain links. It is also what
     * keeps the hover wash from reading as a pill anywhere a pointer is not.
     */
    public function test_the_tabs_lose_their_padding_on_a_phone(): void
    {
        $nav = $this->nav();

        $this->assertStringContainsString('rounded-full px-0 py-1.5 text-[13px] leading-none', $nav);
        $this->assertStringContainsString('md:px-4', $nav);
    }

    public function test_primary_navigation_uses_one_request_per_click(): void
    {
        $nav = $this->nav();

        foreach (['home', 'shop.gift-cards', 'shop.esims', 'shop.topups', 'shop.bills'] as $route) {
            $this->assertStringContainsString("route('{$route}') }}\" wire:navigate", $nav);
        }

        $this->assertStringNotContainsString('wire:navigate.hover', $nav);
    }

    public function test_the_rail_no_longer_fixes_a_height(): void
    {
        // The links used to be h-full inside a 44px rail. A tab sets its own
        // height from padding, so a fixed rail would clip or letterbox it.
        $nav = $this->nav();

        $this->assertStringNotContainsString('flex h-[44px]', $nav);
        $this->assertStringNotContainsString('flex h-full shrink-0 items-center gap-2 px-3', $nav);
    }

    /** The open page is the filled tab, and it follows the route. */
    public function test_only_the_open_page_fills_a_tab(): void
    {
        $active = 'bg-[#f4f6f5] px-3.5 font-bold text-zinc-900';

        // Home opens the row now, so it fills its own tab exactly as a
        // category page does. Counted inside the header, where a tab may
        // render more than once (the Gift Cards tab is its menu's trigger,
        // echoed once per breakpoint): every filled tab is the open page, so
        // the fill and aria-current always come in equal numbers, and never
        // none.
        foreach (['/', '/gift-cards', '/esims'] as $url) {
            $html = $this->withoutVite()->get($url)->assertOk()->getContent();
            $start = strpos($html, '<header class="nav-surface');
            $this->assertNotFalse($start, $url.' has no storefront header.');
            $header = substr($html, $start, strpos($html, '</header>', $start) - $start);

            $filled = substr_count($header, $active);
            $this->assertGreaterThanOrEqual(1, $filled, $url.' should fill its tab.');
            $this->assertSame($filled, substr_count($header, 'aria-current="page"'), $url.' fills a tab that is not the open page.');
        }
    }

    /**
     * The rail is words, no marks. They were a desktop-only affordance whose
     * whole job was to sit beside the label; with the label carrying the tab
     * on its own, a 22px icon per category was decoration the row paid for in
     * width. The state helpers that dressed them went with them.
     */
    public function test_the_rail_carries_no_marks(): void
    {
        $nav = $this->nav();

        $this->assertStringNotContainsString("'hidden md:block w-[22px] h-[22px] shrink-0", $nav);
        $this->assertStringNotContainsString('catImgClass', $nav);
        $this->assertStringNotContainsString('catIconState', $nav);
        $this->assertStringNotContainsString("'opacity-60 group-hover:opacity-100'", $nav);

        // And nothing renders one on a category page either.
        $html = $this->withoutVite()->get('/gift-cards')->assertOk()->getContent();
        $rail = strpos($html, 'aria-label="Product categories"');
        $this->assertNotFalse($rail);
        $railEnd = strpos($html, '</nav>', $rail);
        $this->assertStringNotContainsString('<img', substr($html, $rail, $railEnd - $rail));
    }

    public function test_a_disabled_category_stays_visible_as_a_muted_tab(): void
    {
        // Convention: a category whose flag is off is greyed and non-clickable
        // rather than removed, so the strip does not silently change shape.
        $nav = $this->nav();

        $this->assertStringContainsString('cursor-not-allowed text-zinc-400', $nav);
        $this->assertStringContainsString('aria-disabled="true"', $nav);
    }

    /**
     * Invite a Friend stands on the bar as a filled pill - the exception the
     * hover-only rule is measured against. It is glass now, and it is no
     * longer alone: the Certified reseller badge beside it wears the same
     * (NavGlassRailTest).
     */
    public function test_invite_a_friend_stands_as_a_filled_pill(): void
    {
        $invite = File::get(resource_path('views/components/nav/invite-link.blade.php'));

        $this->assertStringContainsString('rounded-full', $invite);
        $this->assertStringContainsString('bg-white/50 backdrop-blur-md', $invite);
    }
}

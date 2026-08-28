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
 * kinds of control. The open page still underlines - it is the one thing a
 * hover state cannot also say.
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

        // The open page: bold and underlined.
        $this->assertStringContainsString(
            "'font-bold text-zinc-900 underline decoration-2 underline-offset-[6px] dark:text-white'",
            $nav
        );

        // Every other tab: Invite a Friend's wash, in both themes.
        $this->assertStringContainsString('hover:bg-[#f4f6f5]', $nav);
        $this->assertStringContainsString('dark:hover:bg-white/8', $nav);

        // The underline is the open page's alone - hover no longer draws one.
        $this->assertStringNotContainsString('hover:underline hover:decoration-2', $nav);

        // No fill on any of them, resting or active.
        $this->assertStringNotContainsString('bg-zinc-900 text-white dark:bg-white', $nav);
        $this->assertStringNotContainsString('bg-[#f4f6f5] text-zinc-900 hover:bg-[#e8ecea]', $nav);

        // And no outline either - a tab is carried by its fill and label alone.
        $this->assertStringNotContainsString('rounded-full border ', $nav);
    }

    /**
     * The wash is the invite pill's, to the shade. They sit on the same line,
     * so drifting apart is the regression worth catching.
     */
    public function test_the_hover_wash_is_the_invite_pills_own_fill(): void
    {
        $invite = File::get(resource_path('views/components/nav/invite-link.blade.php'));

        $this->assertStringContainsString('bg-[#f4f6f5]', $invite);
        $this->assertStringContainsString('dark:bg-white/8', $invite);
        $this->assertStringContainsString('hover:bg-[#f4f6f5]', $this->nav());
    }

    /**
     * A phone gets no horizontal padding on a tab: four chips at 390px leave
     * room for two, and the reference reads as plain links. It is also what
     * keeps the hover wash from reading as a pill anywhere a pointer is not.
     */
    public function test_the_tabs_lose_their_padding_on_a_phone(): void
    {
        $nav = $this->nav();

        $this->assertStringContainsString('rounded-full px-0 py-2 nav-text', $nav);
        $this->assertStringContainsString('md:px-4', $nav);
    }

    public function test_the_rail_no_longer_fixes_a_height(): void
    {
        // The links used to be h-full inside a 44px rail. A tab sets its own
        // height from padding, so a fixed rail would clip or letterbox it.
        $nav = $this->nav();

        $this->assertStringNotContainsString('flex h-[44px]', $nav);
        $this->assertStringNotContainsString('flex h-full shrink-0 items-center gap-2 px-3', $nav);
    }

    /** The open page is the underlined tab, and it follows the route. */
    public function test_only_a_category_page_underlines_a_tab(): void
    {
        $active = 'font-bold text-zinc-900 underline decoration-2 underline-offset-[6px]';

        $home = $this->withoutVite()->get('/')->assertOk()->getContent();
        $this->assertSame(0, substr_count($home, $active), 'Home is not a category page.');
        $this->assertStringNotContainsString('aria-current="page"', $home);

        foreach (['/gift-cards', '/esims'] as $url) {
            $html = $this->withoutVite()->get($url)->assertOk()->getContent();
            $this->assertGreaterThanOrEqual(1, substr_count($html, $active), $url.' should underline its tab.');
            $this->assertStringContainsString('aria-current="page"', $html);
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
     * Invite a Friend is the one standing pill on the bar - the exception the
     * hover-only rule is measured against.
     */
    public function test_invite_a_friend_is_the_only_standing_pill(): void
    {
        $invite = File::get(resource_path('views/components/nav/invite-link.blade.php'));

        $this->assertStringContainsString('rounded-full', $invite);
        $this->assertStringContainsString('bg-[#f4f6f5]', $invite);
    }
}

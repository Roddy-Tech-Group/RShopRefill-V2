<?php

namespace Tests\Feature\Nav;

use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * The storefront's region + currency pill is the dashboard's language +
 * currency pill: 36px tall, a 2px outline, a hover wash, the flag at 28px on
 * the left, the label in 13px semibold. The bars differ only in what they sit
 * on, so the light ink is adapted (navy on the #f4f6f5 band where the
 * dashboard draws white on navy) and the dark classes are identical. What
 * the pill says and does is unchanged: UtilityLinksLocaleTest pins that.
 */
class RegionPillChipTest extends TestCase
{
    private const DARK = 'dark:border-white/10 dark:text-zinc-200 dark:hover:bg-white/5';

    private function pill(): string
    {
        return File::get(resource_path('views/components/nav/region-pill.blade.php'));
    }

    private function dashboard(): string
    {
        return File::get(resource_path('views/components/layouts/dashboard.blade.php'));
    }

    public function test_the_pill_is_the_dashboards_adapted_to_the_light_bar(): void
    {
        $pill = $this->pill();

        foreach (['h-9', 'rounded-full border-2 border-[#0c1337]/30', 'text-[13px] font-semibold text-zinc-700', 'hover:bg-[#0c1337]/10', self::DARK] as $class) {
            $this->assertStringContainsString($class, $pill, "Missing {$class}.");
        }

        // The old kit: the nav face, a page-tone hover fill, a 24px flag.
        foreach (['nav-text', 'hover:bg-[#f4f6f5]', 'dark:hover:bg-white/10', 'h-6 w-6 shrink-0 rounded-full object-cover'] as $old) {
            $this->assertStringNotContainsString($old, $pill, "{$old} is still on the pill.");
        }
    }

    public function test_the_flag_the_padding_the_type_and_the_dark_classes_are_the_dashboards_exactly(): void
    {
        $pill = $this->pill();
        $dashboard = $this->dashboard();

        foreach ([
            'h-7 w-7 shrink-0 rounded-full object-cover ring-1',
            'pl-1 pr-3',
            'text-[13px] font-semibold',
            'rounded-full border-2',
            self::DARK,
        ] as $shared) {
            $this->assertStringContainsString($shared, $pill);
            $this->assertStringContainsString($shared, $dashboard, 'The dashboard pill changed; the storefront copy must follow.');
        }
    }

    /** On the phone row the label hides and the padding closes, so the flag sits in a round chip. */
    public function test_compact_is_a_round_flag_chip_below_md(): void
    {
        $pill = $this->pill();

        $this->assertStringContainsString("\$compact ? 'px-1 md:pl-1 md:pr-3' : 'pl-1 pr-3'", $pill);
        $this->assertStringContainsString("\$compact ? 'hidden md:inline' : ''", $pill);
    }

    public function test_what_it_says_and_does_is_unchanged(): void
    {
        $pill = $this->pill();

        // Opens the same sheet, names everything for assistive tech, and
        // paints language and currency from the same state with the same
        // server fallback (all pinned in detail by UtilityLinksLocaleTest).
        $this->assertStringContainsString('@click="localeModalOpen = true"', $pill);
        $this->assertStringContainsString(":aria-label=\"'Region and currency: ' + country + ', ' + (language || 'English') + ', ' + currency + '. Change'\"", $pill);
        $this->assertStringContainsString("x-text=\"(language || 'English').slice(0, 2).toUpperCase()\"", $pill);
        $this->assertStringContainsString("x-text=\"currency || 'USD'\"", $pill);
        $this->assertStringContainsString('<div class="relative flex shrink-0 items-center">', $pill);
    }
}

<?php

namespace Tests\Feature\Nav;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The storefront cart is the dashboard's cart chip: a round outlined button
 * holding the shared glyph, no fill, no label, the count in a small square
 * badge. The two bars differ only in what they sit on, so the light-mode ink
 * is adapted (navy on the #f4f6f5 band where the dashboard draws white on
 * navy) and the dark classes are identical.
 */
class CartChipTest extends TestCase
{
    use RefreshDatabase;

    private const DARK = 'dark:border-white/10 dark:text-zinc-200 dark:hover:bg-white/5';

    private const BADGE = 'absolute -top-1 -right-1 inline-flex h-4 min-w-[16px] items-center justify-center rounded-[5px] bg-blue-600 px-1 text-[9px] font-bold text-white ring-2 ring-white dark:ring-0';

    /** 24px glyph in the 36px chip: the chip did not grow, the mark did. */
    private const GLYPH = '<span class="anim-icon anim-icon--cart inline-flex h-6 w-6 shrink-0" aria-hidden="true"><x-icons.cart /></span>';

    private function storefront(): string
    {
        return file_get_contents(resource_path('views/components/nav/account-cart.blade.php'));
    }

    private function dashboard(): string
    {
        return file_get_contents(resource_path('views/components/layouts/dashboard.blade.php'));
    }

    /** The storefront cart button's opening tag. */
    private function cartTag(string $html): string
    {
        preg_match('/<button[^>]*aria-label="Cart"[^>]*>/', $html, $m);
        $this->assertNotEmpty($m, 'The cart button did not render.');

        return $m[0];
    }

    public function test_the_storefront_cart_is_the_dashboard_chip_adapted_to_the_light_bar(): void
    {
        $tag = $this->cartTag($this->actingAs(User::factory()->create())->withoutVite()->get('/')->assertOk()->getContent());

        foreach (['h-9 w-9', 'rounded-full', 'border-2', 'border-[#0c1337]/30', 'hover:bg-[#0c1337]/10', 'text-zinc-700', self::DARK] as $class) {
            $this->assertStringContainsString($class, $tag, "Missing {$class}.");
        }

        // The old kit: a grey fill on the phone, a labelled 10px pill from md.
        foreach (['bg-[#f4f6f5]', 'hover:bg-[#e8ecea]', 'md:rounded-[10px]', 'md:px-3', 'md:h-auto', 'nav-text'] as $old) {
            $this->assertStringNotContainsString($old, $tag, "{$old} is still on the cart.");
        }
    }

    public function test_it_is_icon_only_with_the_glyph_in_the_roll_wrapper(): void
    {
        $source = $this->storefront();

        $this->assertStringContainsString(self::GLYPH, $source);
        $this->assertStringNotContainsString('<x-icons.cart class="h-5 w-5" />', $source);

        // No visible label: the sign-in control keeps its own, so the count is one.
        $this->assertStringNotContainsString("<span class=\"hidden sm:inline\">{{ __('navigation.cart') }}</span>", $source);
        $this->assertSame(1, substr_count($source, 'class="hidden sm:inline"'));

        // Still named for assistive tech.
        $this->assertStringContainsString("aria-label=\"{{ __('navigation.cart') }}\"", $source);
    }

    public function test_the_badge_and_the_dark_classes_are_the_dashboards_exactly(): void
    {
        $storefront = $this->storefront();
        $dashboard = $this->dashboard();

        foreach ([self::BADGE, self::DARK, self::GLYPH] as $shared) {
            $this->assertStringContainsString($shared, $storefront);
            $this->assertStringContainsString($shared, $dashboard, 'The dashboard chip changed; the storefront copy must follow.');
        }

        // The old rounded badge is gone from the storefront.
        $this->assertStringNotContainsString('min-w-[18px] h-[18px] px-1 rounded-[12px] bg-blue-600 text-[10px]', $storefront);
    }

    public function test_the_glyph_rolls_on_hover_from_the_same_keyframes(): void
    {
        $css = file_get_contents(resource_path('css/app.css'));

        $this->assertStringContainsString('@keyframes anim-icon-cart ', $css);
        $this->assertStringContainsString(':is(a, button):is(:hover, :focus-visible) .anim-icon--cart', $css);
    }
}

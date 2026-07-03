<?php

namespace Tests\Feature\Theme;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Extra Dark (pure black) is now the DEFAULT dark palette on the customer side;
 * the navy palette is the opt-in "Soft dark" appearance. These assert the
 * server-side first-paint logic in partials/head.blade.php, which adds the
 * `pure-dark` class so the default never flashes navy first.
 *
 * The head conditional renders `document.documentElement.classList.add('pure-dark')`
 * (distinct from the theme engine's `root.classList.toggle('pure-dark', ...)`),
 * so we assert on that exact, server-only line.
 */
class DefaultDarkPaletteTest extends TestCase
{
    use RefreshDatabase;

    private const HEAD_PURE_DARK = "document.documentElement.classList.add('pure-dark')";

    // The theme cookies are excepted from encryption (they are written plain by
    // JS), so the test must send them UNencrypted to mirror a real browser -
    // withCookie() would encrypt them and the excepted middleware would skip
    // decryption, reading an unusable blob.
    public function test_customer_dark_defaults_to_pure_black(): void
    {
        // Dark resolved via the cookie hint, no Soft dark opt-out → pure black.
        $this->withUnencryptedCookie('theme_web_dark', '1')
            ->get('/')
            ->assertOk()
            ->assertSee(self::HEAD_PURE_DARK, false);
    }

    public function test_customer_can_opt_into_soft_navy_dark(): void
    {
        // Soft dark opt-in writes the pure-dark cookie '0' → navy, no pure-dark class.
        $this->withUnencryptedCookies(['theme_web_dark' => '1', 'theme_web_puredark' => '0'])
            ->get('/')
            ->assertOk()
            ->assertDontSee(self::HEAD_PURE_DARK, false);
    }

    public function test_pure_black_is_not_applied_in_light_mode(): void
    {
        // pure-dark is a dark sub-palette; it must never paint while light.
        $this->withUnencryptedCookie('theme_web_dark', '0')
            ->get('/')
            ->assertOk()
            ->assertDontSee(self::HEAD_PURE_DARK, false);
    }
}

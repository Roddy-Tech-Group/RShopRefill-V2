<?php

namespace Tests\Feature\Nav;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class AuthPagesTest extends TestCase
{
    use RefreshDatabase;

    /**
     * /login and /register used to 302 to the home page with an ?auth= param so
     * a modal could open over it. They are real pages now - a bookmark or an
     * email link lands on the page those URLs always claimed to be.
     */
    public function test_the_auth_urls_render_pages_instead_of_bouncing_to_a_modal(): void
    {
        $this->withoutVite()->get('/login')->assertOk();
        $this->withoutVite()->get('/register')->assertOk();

        $routes = File::get(base_path('routes/auth.php'));
        $this->assertStringNotContainsString("redirect()->to('/?auth=login')", $routes);
        $this->assertStringNotContainsString("redirect()->to('/?auth=register')", $routes);
    }

    /**
     * Standalone: the storefront nav is stripped on purpose - one job per page.
     * The trade is that a bookmark landing here must not be a dead end, so the
     * layout carries a logo that links home.
     */
    public function test_they_are_standalone_but_never_a_dead_end(): void
    {
        foreach (['/login', '/register'] as $url) {
            $this->withoutVite()->get($url)
                ->assertOk()
                ->assertDontSee('Product categories', false)
                ->assertDontSee('Shopping cart', false)
                ->assertSee('home', false);
        }

        $layout = File::get(resource_path('views/components/layouts/auth.blade.php'));

        // At every width: the brand card that carried the logo beside the form
        // on desktop is gone, so this header is the only brand mark and the
        // only exit on the page.
        $this->assertStringContainsString('<header>', $layout);
        $this->assertStringNotContainsString('<header class="lg:hidden">', $layout);
        $this->assertStringContainsString("route('home')", $layout);
    }

    public function test_the_layout_leaves_room_for_that_header(): void
    {
        // The card wrapper was min-h-screen while it was the only child. With a
        // header as a sibling, a full-viewport minimum pushes the card off the
        // bottom by exactly the header's height.
        $layout = File::get(resource_path('views/components/layouts/auth.blade.php'));

        $this->assertStringContainsString('<body class="flex min-h-screen flex-col', $layout);
        $this->assertStringContainsString('<div class="flex flex-1 items-center justify-center p-4', $layout);

        // Whatever the cards look like, a second full-viewport minimum here is
        // always the bug - it stacks with the header rather than sharing it.
        $this->assertStringNotContainsString('<div class="flex min-h-screen items-', $layout);
    }

    /**
     * Direction carries meaning - sign-in from the left, create-account from the
     * right - so the pair reads as two panels either side of the page.
     */
    public function test_each_page_enters_from_its_own_side(): void
    {
        $this->withoutVite()->get('/login')->assertOk()->assertSee('auth-slide-left', false);
        $this->withoutVite()->get('/register')->assertOk()->assertSee('auth-slide-right', false);

        $css = File::get(resource_path('css/app.css'));
        $this->assertStringContainsString('@keyframes auth-slide-in-left', $css);
        $this->assertStringContainsString('@keyframes auth-slide-in-right', $css);
    }

    public function test_the_entrance_respects_reduced_motion(): void
    {
        // Decoration, not information: the page is identical without it.
        $css = File::get(resource_path('css/app.css'));

        $this->assertMatchesRegularExpression(
            '/@media \(prefers-reduced-motion: reduce\) \{\s*\.auth-slide-left,\s*\.auth-slide-right \{ animation: none; \}/',
            $css
        );
    }

    public function test_the_nav_links_straight_to_the_sign_in_page(): void
    {
        // The guest dropdown that dispatched open-auth-modal is gone; it was a
        // click of indirection in front of a link. The login control moved to
        // the top utility bar with the rest of the account cluster.
        $nav = File::get(resource_path('views/components/nav/account-cart.blade.php'));
        $markup = preg_replace('/\{\{--.*?--\}\}/s', '', $nav);

        $this->assertStringContainsString("route('login')", $markup);
        $this->assertStringNotContainsString('open-auth-modal', $markup);
    }

    /**
     * One card: the form, centred on the page tone.
     *
     * A brand identity card used to sit offset to its left with the form
     * overlapping its right edge. It was dropped, and the scaffolding that
     * existed only to stack the pair - the isolate/max-w-5xl row and the
     * z-0/z-20 pairing - went with it. What is asserted here is that none of
     * it came back: a lone card needs no stacking context.
     */
    public function test_the_auth_page_is_a_single_form_card(): void
    {
        $layout = File::get(resource_path('views/components/layouts/auth.blade.php'));

        // The form card, on its own and unstacked.
        $this->assertStringContainsString('flex w-full max-w-xl flex-col rounded-[20px] bg-[#ffffff]', $layout);

        // The brand card and every trace of the overlap that carried it.
        foreach ([
            '<aside',
            'w-[330px] shrink-0',
            'lg:mt-20',
            'isolate flex w-full max-w-5xl',
            'items-start justify-center',
            'relative z-20 flex w-full max-w-xl',
        ] as $gone) {
            $this->assertStringNotContainsString($gone, $layout, $gone.' belongs to the removed brand card.');
        }

        // The dark marketing panel this all replaced is still gone too.
        foreach (['bg-blue-950', 'Secure &amp; Trusted', 'Instant Delivery', 'Global Access', '24/7 Support'] as $gone) {
            $this->assertStringNotContainsString($gone, $layout);
        }
    }

    /**
     * Sign In / Sign Up are links between the two routes, not a client-side
     * toggle: they are separate Livewire components, and merging them would put
     * both forms in one page on a security-sensitive flow.
     */
    public function test_the_tabs_link_between_the_two_routes(): void
    {
        $layout = File::get(resource_path('views/components/layouts/auth.blade.php'));

        $this->assertStringContainsString("route('login')", $layout);
        $this->assertStringContainsString("route('register')", $layout);
        $this->assertStringContainsString("request()->routeIs('login')", $layout);
        $this->assertStringContainsString("request()->routeIs('register')", $layout);

        // Exactly one tab is active per page, and it is the page you are on.
        $login = $this->withoutVite()->get('/login')->assertOk()->getContent();
        $register = $this->withoutVite()->get('/register')->assertOk()->getContent();

        foreach ([$login, $register] as $html) {
            $this->assertSame(1, substr_count($html, 'border-zinc-900 font-bold text-zinc-900'));
            $this->assertStringContainsString(__('auth.sign_in'), $html);
            $this->assertStringContainsString(__('auth.sign_up'), $html);
        }
    }

    /**
     * No "Already have an account? Log in" / "Don't have an account? Sign up"
     * line on either page. The Sign In / Sign Up tabs at the top of the card do
     * that job, and a second cross-link under the form only repeats it.
     *
     * Both removed, not one: leaving the mirror on the other page would make the
     * pair inconsistent for no reason.
     */
    public function test_neither_page_repeats_the_tabs_as_a_cross_link(): void
    {
        foreach (['login', 'register'] as $page) {
            $blade = File::get(resource_path('views/livewire/auth/'.$page.'.blade.php'));

            $this->assertStringNotContainsString('Already have an account', $blade);
            $this->assertStringNotContainsString("Don't have an account", $blade);
        }

        // The tabs are still how you get between them.
        foreach (['/login', '/register'] as $url) {
            $html = $this->withoutVite()->get($url)->assertOk()->getContent();

            $this->assertStringNotContainsString('Already have an account', $html);
            $this->assertStringContainsString(__('auth.sign_in'), $html);
            $this->assertStringContainsString(__('auth.sign_up'), $html);
        }
    }

    /**
     * The security note names the Privacy Policy and links to it. "We never
     * share your data" was a claim with nothing behind it on the page; this
     * points at the document that actually states the terms.
     */
    public function test_the_security_note_links_the_privacy_policy(): void
    {
        foreach (['login', 'register'] as $page) {
            $blade = File::get(resource_path('views/livewire/auth/'.$page.'.blade.php'));

            $this->assertStringContainsString('Your information is protected and handled according to our', $blade);
            $this->assertStringContainsString("route('shop.privacy')", $blade);
            $this->assertStringNotContainsString('We never share your data', $blade);
        }

        // A real link, not just the words - the policy has to be reachable.
        foreach (['/login', '/register'] as $url) {
            $html = $this->withoutVite()->get($url)->assertOk()->getContent();

            $this->assertStringContainsString('Your information is protected', $html);
            $this->assertStringContainsString('href="'.route('shop.privacy').'"', $html);
        }
    }

    /**
     * A way out of auth without using the browser back button.
     *
     * It has moved twice: pinned to the single card's corner, then into the
     * brand panel's corner when the layout became two cards. With that panel
     * gone it lives in the header, at every width, beside the logo.
     */
    public function test_both_pages_offer_a_way_back_to_the_site(): void
    {
        $layout = File::get(resource_path('views/components/layouts/auth.blade.php'));

        $this->assertStringContainsString("__('common.back_to_website')", $layout);

        // A labelled pill in the header, not pinned inside a card that no
        // longer exists.
        $this->assertStringContainsString('inline-flex shrink-0 items-center gap-1.5 rounded-full bg-[#ffffff]', $layout);
        $this->assertStringNotContainsString('absolute left-6 top-6 inline-flex items-center gap-1.5', $layout);

        foreach (['/login', '/register'] as $url) {
            $html = $this->withoutVite()->get($url)->assertOk()->getContent();

            $this->assertStringContainsString('Back to website', $html);
            $this->assertStringContainsString('href="'.route('home').'"', $html);
        }
    }

    /**
     * Fields carry no glyph inside the box.
     *
     * Every input had a leading icon and pl-10 to clear it, which is what made
     * the form look busy next to the reference. The icons are gone and the left
     * padding with them - it existed only to make room.
     */
    public function test_the_form_fields_carry_no_leading_icons(): void
    {
        foreach (['login', 'register'] as $page) {
            $blade = File::get(resource_path('views/livewire/auth/'.$page.'.blade.php'));

            $this->assertStringNotContainsString('pointer-events-none absolute left-3 top-1/2', $blade);
            $this->assertStringNotContainsString('pl-10', $blade);

            // And the fields sit at the larger scale, not the old compact one.
            $this->assertStringNotContainsString('py-2.5', $blade);
            $this->assertStringContainsString('px-4 py-3 text-base', $blade);
        }
    }

    /**
     * The four product marks went with the brand card that tiled them.
     *
     * The assets stay in public/assets - the storefront's own category
     * illustrations, still used on the home page - but the auth layout no
     * longer draws them, and nothing is left of the grid that did.
     */
    public function test_the_layout_no_longer_tiles_the_product_marks(): void
    {
        $layout = File::get(resource_path('views/components/layouts/auth.blade.php'));

        foreach ([
            'digital-gift-cards.svg',
            'esims.svg',
            'mobile-top-ups.svg',
            'prepaid-utilities.svg',
        ] as $mark) {
            $this->assertStringNotContainsString($mark, $layout);
            $this->assertFileExists(public_path('assets/'.$mark), 'the mark is still the storefront\'s.');
        }

        $this->assertStringNotContainsString('grid w-full grid-cols-4 gap-2', $layout);
        $this->assertStringNotContainsString('no-dark-invert h-full w-full object-contain', $layout);
    }

    /**
     * Exactly one way back, shown at every width.
     *
     * There were two while the brand panel existed: its own corner link on
     * desktop and the lg:hidden header's below. With the panel gone a second
     * copy would be the bug - two "Back to website" links on one screen.
     */
    public function test_there_is_exactly_one_way_back_at_every_width(): void
    {
        $layout = File::get(resource_path('views/components/layouts/auth.blade.php'));

        // Blade comments stripped - they name the link in prose.
        $markup = preg_replace('/\{\{--.*?--\}\}/s', '', $layout);
        $this->assertSame(1, substr_count($markup, "__('common.back_to_website')"));
        $this->assertStringContainsString('<header>', $layout);

        $html = $this->withoutVite()->get('/login')->assertOk()->getContent();
        $this->assertSame(1, substr_count($html, 'Back to website'));
    }

    /** Neither submit button carries the hand glyph any more. */
    public function test_the_submit_buttons_carry_no_hand_icon(): void
    {
        // The path data is the marker - the same waving-hand glyph sat on both.
        $hand = 'M9 4.5a1.5 1.5 0 0 1 3 0V10h.5V6a1.5 1.5 0 0 1 3 0v4h.5V7.5';

        foreach (['login', 'register'] as $page) {
            $this->assertStringNotContainsString(
                $hand,
                File::get(resource_path('views/livewire/auth/'.$page.'.blade.php')),
                $page.' still has the hand icon on its submit button.'
            );
        }

        // The buttons themselves are still there.
        $this->assertStringContainsString('<span>Sign In</span>', File::get(resource_path('views/livewire/auth/login.blade.php')));
        $this->assertStringContainsString('<span>Create Account</span>', File::get(resource_path('views/livewire/auth/register.blade.php')));
    }
}

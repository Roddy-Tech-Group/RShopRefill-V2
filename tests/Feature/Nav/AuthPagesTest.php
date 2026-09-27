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
     * Standalone, and now standalone all the way: the storefront nav is
     * stripped on purpose - one job per page - and so is everything that used
     * to sit above the card. The card is the whole page.
     */
    public function test_they_are_standalone(): void
    {
        foreach (['/login', '/register'] as $url) {
            $this->withoutVite()->get($url)
                ->assertOk()
                ->assertDontSee('Product categories', false)
                ->assertDontSee('Shopping cart', false);
        }

        $layout = File::get(resource_path('views/components/layouts/auth.blade.php'));

        // No header row at any width, and no logo in it - it was a second brand
        // mark on a page that already says where you are.
        $this->assertStringNotContainsString('<header', $layout);
        $this->assertStringNotContainsString('Rshoprefillslogo', $layout);
    }

    public function test_the_card_wrapper_never_stacks_a_second_viewport_minimum(): void
    {
        // The body owns the viewport minimum and the wrapper grows into what is
        // left. A second min-h-screen here stacks with it rather than sharing
        // it, which pushed the card off the bottom the last time anything sat
        // above it.
        $layout = File::get(resource_path('views/components/layouts/auth.blade.php'));

        $this->assertStringContainsString('<body class="flex min-h-screen flex-col', $layout);
        $this->assertStringContainsString('<div class="flex flex-1 items-center justify-center p-4', $layout);
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

        // The form card, on its own and unstacked. max-w-md: at max-w-xl the
        // fields ran wider than anything typed into them.
        $this->assertStringContainsString('flex w-full max-w-md flex-col rounded-[20px] bg-[#ffffff]', $layout);
        $this->assertStringNotContainsString(
            'max-w-xl',
            preg_replace('/\{\{--.*?--\}\}/s', '', $layout),
            'the comment above the card may name the old width; the markup may not.'
        );

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
     * There is no "Back to website" control on the auth pages.
     *
     * It moved four times looking for a home - the single card's corner, the
     * brand panel's corner, a header row beside the logo, the card's top right
     * - and was dropped instead. The browser's own back is the way out; the
     * card carries nothing but the form.
     *
     * Asserted at the source, so none of the four old placements can quietly
     * come back with it.
     */
    public function test_the_pages_carry_no_back_to_website_control(): void
    {
        $layout = File::get(resource_path('views/components/layouts/auth.blade.php'));

        $this->assertStringNotContainsString('common.back_to_website', $layout);
        $this->assertStringNotContainsString('absolute left-6 top-6 inline-flex items-center gap-1.5', $layout);
        $this->assertStringNotContainsString('<div class="mb-5 flex justify-end">', $layout);
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
     * And nothing renders it either - the source check above cannot see a copy
     * that reaches the page from a partial or a shared component.
     */
    public function test_no_back_to_website_link_renders_on_either_page(): void
    {
        foreach (['/login', '/register'] as $url) {
            $html = $this->withoutVite()->get($url)->assertOk()->getContent();

            $this->assertStringNotContainsString('Back to website', $html);
            $this->assertStringNotContainsString(__('common.back_to_website'), $html);
        }
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

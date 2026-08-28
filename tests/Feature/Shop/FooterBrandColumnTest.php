<?php

namespace Tests\Feature\Shop;

use App\Models\SiteSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The footer on the Roddy Technologies layout: the logo, five link columns that
 * collapse to disclosures on mobile, a social band, the utility row, and the
 * legal bar. The brand strapline that used to sit under the logo was removed.
 */
class FooterBrandColumnTest extends TestCase
{
    use RefreshDatabase;

    /**
     * The brand column is the logo alone now. Every strapline this footer has
     * carried is asserted gone, current one included, so none of them can come
     * back through an old copy edit.
     */
    public function test_the_brand_column_carries_no_strapline(): void
    {
        $footer = file_get_contents(resource_path('views/components/footer.blade.php'));

        $this->assertStringNotContainsString('Digital Marketplace', $footer);
        $this->assertStringNotContainsString('Access global digital services', $footer);
        $this->assertStringNotContainsString('Your everyday digital marketplace', $footer);

        $html = $this->withoutVite()->get('/')->assertOk()->getContent();
        $this->assertStringNotContainsString('Your everyday digital marketplace', $html);
    }

    /** The logo still opens the brand column, directly above the link grid. */
    public function test_the_logo_still_opens_the_brand_column(): void
    {
        $html = $this->withoutVite()->get('/')->assertOk()->getContent();

        $logo = strrpos($html, 'Rshoprefillslogo.webp');
        $getTheApp = strpos($html, 'Get the app');

        $this->assertNotFalse($logo);
        $this->assertNotFalse($getTheApp);
        $this->assertLessThan($getTheApp, $logo);
    }

    public function test_bottom_bar_names_the_operator_and_registered_address(): void
    {
        $html = $this->withoutVite()->get('/')->assertOk()->getContent();

        // The standalone "Roddy Technologies 2024 - YYYY" line was removed. The
        // legal paragraph below still carries the copyright and the operator, so
        // nothing legally required left the page with it.
        $this->assertStringNotContainsString('Roddy Technologies 2024 - '.date('Y'), $html);
        $this->assertStringContainsString('&copy; '.date('Y'), $html);
        $this->assertStringContainsString('All rights reserved', $html);

        $this->assertStringContainsString('This website is owned and operated by Roddy Technologies LTD, RC - (9385422)', $html);
        $this->assertStringContainsString('5 Tetlow Road, Njemenze, Owerri, Imo State, Nigeria', $html);

        // The CAC verification link stays alongside it.
        $this->assertStringContainsString('https://icrp.cac.gov.ng/public-search', $html);
    }

    public function test_link_columns_are_disclosures_on_mobile_and_open_on_desktop(): void
    {
        $html = $this->withoutVite()->get('/')->assertOk()->getContent();

        // 'app' is not in this list any more: the badges moved under the logo,
        // out of the grid, so they are no longer a disclosure at any width.
        foreach (['shop', 'company', 'help', 'legal'] as $slug) {
            $this->assertStringContainsString('aria-controls="footer-col-'.$slug.'"', $html);
            $this->assertStringContainsString('id="footer-col-'.$slug.'"', $html);
        }

        // Closed by a class, not x-show: the panel is already collapsed on the
        // first paint, and `sm:block` still opens it when Alpine never runs.
        $this->assertStringContainsString('hidden space-y-3 pb-5 sm:mt-4 sm:block sm:pb-0', $html);
        $this->assertStringContainsString(":class=\"{ 'hidden': ! open }\"", $html);

        // Every link is in the markup either way, so crawlers still see them.
        $this->assertStringContainsString('>Gift cards</a>', $html);
        $this->assertStringContainsString('>Refund policy</a>', $html);
    }

    public function test_core_networks_render_in_order(): void
    {
        $html = $this->withoutVite()->get('/')->assertOk()->getContent();

        $positions = [];
        foreach (['Visit our Facebook page', 'Follow us on X', 'Follow us on TikTok', 'Follow us on Instagram'] as $label) {
            $at = strpos($html, 'aria-label="'.$label.'"');
            $this->assertNotFalse($at, $label.' is missing from the footer.');
            $positions[] = $at;
        }

        $sorted = $positions;
        sort($sorted);
        $this->assertSame($sorted, $positions, 'Order should be Facebook, X, TikTok, Instagram.');
    }

    public function test_linkedin_hides_itself_again_if_the_setting_is_cleared(): void
    {
        SiteSetting::put('social.linkedin', '', 'social');

        $html = $this->withoutVite()->get('/')->assertOk()->getContent();

        $this->assertStringNotContainsString('aria-label="Follow us on LinkedIn"', $html);
    }

    public function test_linkedin_slots_in_after_instagram_once_its_setting_is_filled(): void
    {
        SiteSetting::put('social.linkedin', 'https://www.linkedin.com/company/example', 'social');

        $html = $this->withoutVite()->get('/')->assertOk()->getContent();

        $this->assertStringContainsString('aria-label="Follow us on LinkedIn"', $html);

        // The href is the stored value, never a baked-in fallback.
        $this->assertStringContainsString('href="https://www.linkedin.com/company/example"', $html);

        $instagram = strpos($html, 'aria-label="Follow us on Instagram"');
        $linkedin = strpos($html, 'aria-label="Follow us on LinkedIn"');
        $whatsapp = strpos($html, 'aria-label="Chat with us on WhatsApp"');

        $this->assertGreaterThan($instagram, $linkedin);
        $this->assertLessThan($whatsapp, $linkedin, 'WhatsApp follows LinkedIn.');
    }

    public function test_whatsapp_dials_the_shared_support_number(): void
    {
        $number = preg_replace('/[^0-9]/', '', (string) SiteSetting::get('contact.whatsapp_number'));
        $this->assertNotSame('', $number, 'contact.whatsapp_number should be populated.');

        $html = $this->withoutVite()->get('/')->assertOk()->getContent();

        // Reuses the contact page's number rather than carrying its own copy.
        $this->assertStringContainsString('href="https://wa.me/'.$number.'"', $html);
        $this->assertStringContainsString('aria-label="Chat with us on WhatsApp"', $html);
    }

    public function test_whatsapp_is_hidden_when_the_number_is_blank(): void
    {
        SiteSetting::put('contact.whatsapp_number', '', 'contact');

        $html = $this->withoutVite()->get('/')->assertOk()->getContent();

        $this->assertStringNotContainsString('aria-label="Chat with us on WhatsApp"', $html);
        $this->assertStringNotContainsString('https://wa.me/"', $html);
    }

    public function test_discord_is_hidden_while_blank_and_closes_the_row_when_filled(): void
    {
        SiteSetting::put('social.discord', '', 'social');

        $html = $this->withoutVite()->get('/')->assertOk()->getContent();
        $this->assertStringNotContainsString('aria-label="Join us on Discord"', $html);

        SiteSetting::put('social.discord', 'https://discord.gg/example', 'social');

        $html = $this->withoutVite()->get('/')->assertOk()->getContent();
        $this->assertStringContainsString('href="https://discord.gg/example"', $html);

        $whatsapp = strpos($html, 'aria-label="Chat with us on WhatsApp"');
        $discord = strpos($html, 'aria-label="Join us on Discord"');
        $this->assertGreaterThan($whatsapp, $discord, 'Discord closes the row.');
    }

    public function test_seeded_social_urls_point_at_the_real_profiles(): void
    {
        $this->assertSame('https://x.com/roddytech237', SiteSetting::get('social.x'));
        $this->assertSame('https://www.linkedin.com/company/roddy-technologies/', SiteSetting::get('social.linkedin'));
        $this->assertSame('https://discord.gg/nppG3GAt9v', SiteSetting::get('social.discord'));

        // The retired placeholder handle is gone from every view that reads the
        // setting, fallbacks included, and from the auth pages that used to
        // hardcode it outright.
        foreach ([
            'components/footer.blade.php',
            'components/emails/layout.blade.php',
            'partials/head.blade.php',
            'shop/contact.blade.php',
            'shop/help.blade.php',
            'livewire/auth/login.blade.php',
            'livewire/auth/register.blade.php',
        ] as $view) {
            $this->assertStringNotContainsString(
                'x.com/rshoprefills',
                file_get_contents(resource_path('views/'.$view)),
                $view.' still references the placeholder X handle.'
            );
        }
    }

    /**
     * The auth pages carry no social links at all now - the "Connect with us on
     * our socials" divider and its four icons were removed from both.
     *
     * The rule they used to prove still matters, so it is asserted the other
     * way round: nothing there may hardcode a social URL. The footer is what
     * reads social.* from settings.
     */
    public function test_auth_pages_carry_no_social_links(): void
    {
        foreach (['login', 'register'] as $page) {
            $blade = file_get_contents(resource_path('views/livewire/auth/'.$page.'.blade.php'));

            $this->assertStringNotContainsString('Connect with us on our socials', $blade);
            $this->assertStringNotContainsString("SiteSetting::get('social.", $blade);

            foreach (['facebook.com', 'x.com', 'tiktok.com', 'instagram.com'] as $host) {
                $this->assertStringNotContainsString($host, $blade, $page.' still hardcodes '.$host.'.');
            }
        }

        // And nothing survives into the rendered pages.
        foreach (['/login', '/register'] as $url) {
            $this->withoutVite()->get($url)
                ->assertOk()
                ->assertDontSee('Connect with us on our socials');
        }
    }

    public function test_newsletter_signup_is_gone_from_the_footer(): void
    {
        $footer = file_get_contents(resource_path('views/components/footer.blade.php'));

        $this->assertStringNotContainsString('footer-newsletter', $footer);
        $this->assertStringNotContainsString('Stay in the loop', $footer);

        $html = $this->withoutVite()->get('/')->assertOk()->getContent();
        $this->assertStringNotContainsString('wire:submit="subscribe"', $html);

        // The band it shared is now the social row alone, still sitting
        // between the link columns and the legal bar.
        $followUs = strpos($html, 'Follow us');
        $copyright = strpos($html, 'All rights reserved');
        $this->assertNotFalse($followUs);
        $this->assertLessThan($copyright, $followUs);
    }

    public function test_social_links_sit_above_the_divider_not_in_the_copyright_bar(): void
    {
        $html = $this->withoutVite()->get('/')->assertOk()->getContent();

        $getTheApp = strpos($html, 'Get the app');
        $facebook = strpos($html, 'aria-label="Visit our Facebook page"');
        $copyright = strpos($html, 'All rights reserved');

        $this->assertNotFalse($facebook);
        $this->assertNotFalse($copyright);

        $this->assertGreaterThan($getTheApp, $facebook, 'Social links follow the link columns.');
        $this->assertLessThan($copyright, $facebook, 'Social links belong above the legal bar.');
    }

    public function test_theme_picker_carries_a_visible_label(): void
    {
        $html = $this->withoutVite()->get('/')->assertOk()->getContent();

        $label = strpos($html, '>Theme</span>');
        $group = strpos($html, 'role="radiogroup"');

        $this->assertNotFalse($label, 'The theme picker should have a visible "Theme" label.');
        $this->assertNotFalse($group);
        $this->assertLessThan($group, $label, 'The label sits to the left of the control.');

        // The control is already named "Theme" for screen readers, so the visible
        // text is hidden from them rather than announced a second time. The
        // label is a translation key now, so the source carries the key and the
        // rendered page carries the English it produces under the default locale.
        $footer = file_get_contents(resource_path('views/components/footer.blade.php'));
        $this->assertStringContainsString('aria-hidden="true">{{ __(\'footer.theme\') }}</span>', $footer);
    }

    public function test_cookie_settings_button_does_not_stretch_in_the_stacked_row(): void
    {
        $footer = file_get_contents(resource_path('views/components/footer.blade.php'));

        // The utility row is a flex column on mobile, where a bare <button>
        // stretches full width and browsers centre its label - unlike the <p>
        // beside it. self-start keeps it shrink-wrapped and left-aligned.
        preg_match('/<button[^>]*open-cookie-settings[^>]*class="([^"]*)"/', $footer, $m);
        $this->assertNotEmpty($m, 'The cookie settings button is missing.');
        $this->assertStringContainsString('self-start', $m[1]);
    }

    public function test_logos_use_the_reduced_size_in_footer_and_nav(): void
    {
        $footer = file_get_contents(resource_path('views/components/footer.blade.php'));
        $nav = file_get_contents(resource_path('views/components/nav/main-nav.blade.php'));

        $this->assertStringContainsString('<span class="flex h-9 items-center">', $footer);
        $this->assertStringNotContainsString('<span class="flex h-12 items-center">', $footer);

        $this->assertStringContainsString('<span class="flex items-center h-8 md:h-9">', $nav);
        $this->assertStringNotContainsString('<span class="flex items-center h-10 md:h-12">', $nav);
    }

    /**
     * The app badges sit under the logo in the brand column, and the link
     * column headings start on the same left edge the logo does.
     *
     * That edge needed a fix: the wordmark carries a 20px transparent margin,
     * which renders as ~4.4px at h-9, but the anchor pulled -ml-3 (12px) - so
     * the logo hung ~8px left of every heading beside it. -ml-1 matches the
     * real margin instead of overshooting it.
     */
    public function test_the_app_badges_sit_under_the_logo_on_the_heading_edge(): void
    {
        $footer = file_get_contents(resource_path('views/components/footer.blade.php'));

        $this->assertStringContainsString('class="-ml-1 flex rounded-[12px] group', $footer);
        $this->assertStringNotContainsString('-ml-3', $footer);

        // Out of the grid entirely - no disclosure wiring left on it.
        $this->assertStringNotContainsString('footer-col-app', $footer);

        // Four link columns, two-up on small tablets.
        $this->assertStringContainsString('sm:grid-cols-2', $footer);
        $this->assertStringNotContainsString('lg:grid-cols-5', $footer);

        // Rendered: the badges follow the logo and precede the first column.
        $html = $this->withoutVite()->get('/')->assertOk()->getContent();

        $logo = strrpos($html, 'Rshoprefillslogo.webp');
        $getTheApp = strpos($html, 'Get the app');
        $shopCol = strpos($html, 'id="footer-col-shop"');

        $this->assertNotFalse($getTheApp);
        $this->assertGreaterThan($logo, $getTheApp);
        $this->assertLessThan($shopCol, $getTheApp);
    }

    /**
     * From lg the logo and the four column headings share ONE row - the logo on
     * the left, headings running across on its line.
     *
     * Two pieces make that work and both have to hold. The link wrapper goes
     * display:contents at lg so its navs become direct children of the brand
     * row; without it they lay out inside their own box below the logo, which
     * is the stacked layout this replaced. And the headings take the wordmark's
     * own 36px band (lg:h-9) so they centre against it rather than merely
     * sharing its left edge.
     */
    public function test_the_headings_share_the_logos_row_from_lg(): void
    {
        $footer = file_get_contents(resource_path('views/components/footer.blade.php'));

        $this->assertStringContainsString(
            'lg:grid lg:grid-cols-[1.4fr_repeat(4,minmax(0,1fr))] lg:gap-8',
            $footer
        );
        $this->assertStringContainsString('lg:contents', $footer);
        $this->assertStringContainsString('sm:pointer-events-none sm:py-0 lg:h-9', $footer);

        // The logo's band is what lg:h-9 matches - if one moves the other must.
        $this->assertStringContainsString('<span class="flex h-9 items-center">', $footer);

        // Rendered: brand still precedes every column in the DOM, so the row
        // reads left-to-right and screen readers keep the same order.
        $html = $this->withoutVite()->get('/')->assertOk()->getContent();

        $logo = strrpos($html, 'Rshoprefillslogo.webp');
        $shop = strpos($html, 'id="footer-col-shop"');
        $legal = strpos($html, 'id="footer-col-legal"');

        $this->assertNotFalse($shop);
        $this->assertGreaterThan($logo, $shop);
        $this->assertLessThan($legal, $shop);
    }

    /**
     * The Help column no longer links to Order status. It pointed at
     * dashboard.orders - an authenticated route - from a footer that is mostly
     * public pages, so a guest clicking it landed on the login wall.
     */
    public function test_the_help_column_has_no_order_status_link(): void
    {
        $footer = file_get_contents(resource_path('views/components/footer.blade.php'));

        $this->assertStringNotContainsString('Order status', $footer);
        $this->assertStringNotContainsString("route('dashboard.orders')", $footer);

        $html = $this->withoutVite()->get('/')->assertOk()->getContent();
        $this->assertStringNotContainsString('>Order status</a>', $html);

        // The rest of the column is untouched.
        foreach (['Help center', 'FAQs', 'Contact us', 'How it works', 'Earn points'] as $link) {
            $this->assertStringContainsString('>'.$link.'</a>', $html);
        }
    }

    /**
     * The three horizontal rules are gone from the footer.
     *
     * Their vertical spacing was NOT: it lived on the rules' own my-8/lg:my-10,
     * so it had to move onto the blocks below them. Leaving an empty spacer div
     * would not have worked - with no height its top and bottom margins collapse
     * into one, halving the gap.
     */
    public function test_the_horizontal_rules_are_gone_but_the_spacing_is_not(): void
    {
        $footer = file_get_contents(resource_path('views/components/footer.blade.php'));

        $this->assertStringNotContainsString('h-px bg-zinc-200', $footer);
        $this->assertStringNotContainsString('aria-hidden="true"></div>', $footer);

        // Each block the rule used to precede carries the gap itself now.
        $this->assertStringContainsString('mt-8 flex flex-wrap items-center gap-3 lg:mt-10', $footer);
        $this->assertStringContainsString('mt-8 flex flex-col gap-4 text-sm text-zinc-600', $footer);
        $this->assertStringContainsString('mt-8 flex flex-col gap-4 text-base text-zinc-600', $footer);

        $html = $this->withoutVite()->get('/')->assertOk()->getContent();
        $this->assertStringNotContainsString('h-px bg-zinc-200', $html);

        // Everything the rules separated still renders.
        foreach (['Follow us', 'Theme', 'All rights reserved'] as $section) {
            $this->assertStringContainsString($section, $html);
        }
    }
}

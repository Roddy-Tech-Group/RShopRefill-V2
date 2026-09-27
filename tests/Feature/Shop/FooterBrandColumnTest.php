<?php

namespace Tests\Feature\Shop;

use App\Models\SiteSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The footer on the Roddy Technologies layout: the logo, four link columns that
 * collapse to disclosures on mobile, a social band, the utility row, and the
 * legal bar. The brand strapline that used to sit under the logo was removed.
 *
 * Four columns, not five: the fifth was the app badge column, and the badges
 * themselves are gone. FooterAppBadgesTest is where that decision is written
 * down and held.
 */
class FooterBrandColumnTest extends TestCase
{
    use RefreshDatabase;

    /**
     * The brand column carries one line under the logo, from the translation
     * file so it follows the visitor's language. Every strapline this footer
     * carried before is still asserted gone, so none of them can come back
     * through an old copy edit.
     */
    public function test_the_brand_column_carries_the_translated_tagline_only(): void
    {
        $footer = file_get_contents(resource_path('views/components/footer.blade.php'));

        $this->assertStringContainsString("__('footer.tagline')", $footer);
        $this->assertStringNotContainsString('Digital Marketplace', $footer);
        $this->assertStringNotContainsString('Access global digital services', $footer);
        $this->assertStringNotContainsString('Your everyday digital marketplace', $footer);

        $html = $this->withoutVite()->get('/')->assertOk()->getContent();
        $this->assertStringContainsString('Your marketplace for gift cards, eSIMs, mobile top-ups and bill payments.', $html);
        $this->assertStringNotContainsString('Your everyday digital marketplace', $html);
    }

    /**
     * The logo still opens the brand column, directly above the link grid.
     *
     * Both halves of what this used to measure moved. The footer wears the mark
     * alone now, assets/PWAicon.webp, the wordmark staying in the header where
     * the name is read, and the "Get the app" heading the logo was measured
     * against left with the store badges. The first link column is what follows
     * the brand now, so that is the marker: the claim is unchanged, that the logo
     * opens the block rather than trailing the links.
     *
     * The search starts at the footer element rather than taking the last match
     * on the page, because the same mark is the nav's phone logo, and "last
     * occurrence" would quietly follow whichever component happens to render
     * after the footer.
     */
    public function test_the_logo_still_opens_the_brand_column(): void
    {
        $html = $this->withoutVite()->get('/')->assertOk()->getContent();

        $footerAt = strpos($html, 'footer-ground');
        $this->assertNotFalse($footerAt, 'The footer slab is missing.');

        $logo = strpos($html, 'assets/PWAicon.webp', $footerAt);
        $shopCol = strpos($html, 'id="footer-col-shop"');

        $this->assertNotFalse($logo, 'The footer carries no logo.');
        $this->assertNotFalse($shopCol);
        $this->assertLessThan($shopCol, $logo);
    }

    public function test_bottom_bar_names_the_operator_and_registered_address(): void
    {
        $html = $this->withoutVite()->get('/')->assertOk()->getContent();

        // Two lines now: the copyright on its own, then the operator. The year
        // is written out (2026), not read from the clock.
        $this->assertStringNotContainsString('Roddy Technologies 2024 - ', $html);
        $this->assertStringContainsString('&copy; 2026', $html);
        $this->assertStringContainsString('All rights reserved.', $html);

        $this->assertStringContainsString('Owned and operated by Roddy Technologies Ltd, RC 9385422, registered at 5 Tetlow Road, Njemenze, Owerri, Imo State, Nigeria.', $html);

        // The CAC verification link stays alongside it.
        $this->assertStringContainsString('https://icrp.cac.gov.ng/public-search', $html);
        $this->assertStringContainsString('>Verify company</a>', $html);
    }

    public function test_link_columns_are_disclosures_on_mobile_and_open_on_desktop(): void
    {
        $html = $this->withoutVite()->get('/')->assertOk()->getContent();

        // 'app' is not in this list any more. The badges first came out of the
        // grid to sit under the logo, which already ended their life as a
        // disclosure, and then came out of the footer altogether.
        foreach (['shop', 'company', 'help', 'legal'] as $slug) {
            $this->assertStringContainsString('aria-controls="footer-col-'.$slug.'"', $html);
            $this->assertStringContainsString('id="footer-col-'.$slug.'"', $html);
        }

        // Closed by a class, not x-show: the panel is already collapsed on the
        // first paint, and `sm:block` still opens it when Alpine never runs.
        $this->assertStringContainsString('hidden space-y-3 pb-5 sm:mt-4 sm:block sm:pb-0', $html);
        $this->assertStringContainsString(":class=\"{ 'hidden': ! open }\"", $html);

        // Every link is in the markup either way, so crawlers still see them.
        $this->assertStringContainsString('>Gift Cards</a>', $html);
        $this->assertStringContainsString('>Refund Policy</a>', $html);
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

    public function test_the_newsletter_is_its_own_row_at_the_top_of_the_footer(): void
    {
        $footer = file_get_contents(resource_path('views/components/footer.blade.php'));

        // The old inline block, with its own heading, is gone; the signup is
        // the shared component on one ruled-off line above everything else
        // the footer holds.
        $this->assertStringNotContainsString('footer-newsletter', $footer);
        $this->assertStringNotContainsString('Stay in the loop', $footer);
        $this->assertStringContainsString('<livewire:newsletter-signup />', $footer);

        $html = $this->withoutVite()->get('/')->assertOk()->getContent();
        $footerAt = strpos($html, 'class="footer-ground');
        $form = strpos($html, 'wire:submit="subscribe"');
        $this->assertNotFalse($footerAt);
        $this->assertNotFalse($form);
        $this->assertSame(1, substr_count($html, 'wire:submit="subscribe"'), 'the signup renders once, in the footer');
        $this->assertGreaterThan($footerAt, $form);

        // Then the link columns, the social row and the legal bar, in order.
        $followUs = strpos($html, 'Follow us');
        $copyright = strpos($html, 'All rights reserved');
        $this->assertNotFalse($followUs);
        $this->assertLessThan($followUs, $form);
        $this->assertLessThan($copyright, $followUs);
    }

    /**
     * The social icons belong to the brand column: directly under the tagline
     * on desktop. In the DOM they sit between the logo and the first link
     * group, and never in the legal lines.
     *
     * On a phone they are shown AFTER the link groups instead. That is done
     * with CSS order rather than a second copy of the icons, so the brand
     * wrapper is display:contents below lg and the icon block carries order-3
     * against the link groups' order-2.
     */
    public function test_social_links_sit_in_the_brand_column_and_follow_the_links_on_mobile(): void
    {
        $html = $this->withoutVite()->get('/')->assertOk()->getContent();

        $footerAt = strpos($html, 'footer-ground');
        $logo = strpos($html, 'assets/PWAicon.webp', $footerAt);
        $facebook = strpos($html, 'aria-label="Visit our Facebook page"');
        $shopCol = strpos($html, 'id="footer-col-shop"');
        $copyright = strpos($html, 'All rights reserved');

        $this->assertNotFalse($facebook);
        $this->assertGreaterThan($logo, $facebook, 'Social links sit under the logo.');
        $this->assertLessThan($shopCol, $facebook, 'Social links belong to the brand column.');
        $this->assertLessThan($copyright, $facebook, 'Social links stay out of the legal lines.');

        // One copy of each icon, reordered by CSS on mobile.
        $this->assertSame(1, substr_count($html, 'aria-label="Visit our Facebook page"'));

        $footer = file_get_contents(resource_path('views/components/footer.blade.php'));
        $this->assertStringContainsString('<div class="contents lg:block">', $footer);
        $this->assertStringContainsString('<div class="order-3 mt-8 lg:mt-6">', $footer);
        $this->assertStringContainsString('class="order-2 mt-10 grid grid-cols-1', $footer);
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

    /**
     * The footer and the nav give the wordmark the taller band the replaced logo
     * asset needs, and they do it in step with each other.
     *
     * This used to pin a reduced band - h-9 in the footer, h-8/md:h-9 in the nav
     * - and that was right for the file it was written against. The old
     * Rshoprefillslogo.webp was 701x165, near enough 4.25:1, so 36px of height
     * still bought about 153px of wordmark. The replacement, assets/logo.webp,
     * is 419x140, close to 3:1: in the old band it drew about 108px wide, the
     * same brand rendered visibly smaller than the one it replaced. Both bands
     * went up together, which is why this test covers both views rather than one
     * - the footer drifting away from the nav is the failure worth catching.
     *
     * The sizes are still pinned, and the superseded reduced values are asserted
     * gone, so a revert of the band that does not also revert the asset cannot
     * pass here.
     */
    public function test_logos_use_the_taller_band_the_new_wordmark_needs(): void
    {
        $footer = file_get_contents(resource_path('views/components/footer.blade.php'));
        $nav = file_get_contents(resource_path('views/components/nav/main-nav.blade.php'));

        $this->assertStringContainsString('<span class="flex h-12 items-center">', $footer);
        $this->assertStringNotContainsString('<span class="flex h-9 items-center">', $footer);

        $this->assertStringContainsString('<span class="flex items-center h-11 md:h-12">', $nav);
        $this->assertStringNotContainsString('<span class="flex items-center h-8 md:h-9">', $nav);

        // The nav reads the wordmark and the footer the mark cut from it, so
        // neither view can be pointed back at the retired file on its own and
        // take its old band with it. The mark keeps the 48px band: it is the
        // wordmark's own R, drawn at the height the wordmark draws it.
        $this->assertStringContainsString("asset('assets/logo.webp')", $nav, 'nav does not use the current wordmark.');
        $this->assertStringContainsString("asset('assets/PWAicon.webp')", $footer, 'footer does not use the current mark.');

        foreach (['footer' => $footer, 'nav' => $nav] as $name => $view) {
            $this->assertStringNotContainsString('Rshoprefillslogo', $view, $name.' still points at the retired wordmark.');
        }
    }

    /**
     * The brand column is the logo and nothing under it, and the link column
     * headings start on the same left edge the logo does.
     *
     * The app badges used to be what sat under the logo here, which is what this
     * test was named for. They are gone, so the subject is now the other half of
     * the same claim: the brand column ends at the wordmark, with no block
     * standing between it and the first link column. FooterAppBadgesTest carries
     * the reasoning for the removal - in short, there is no app, so a pair of
     * "coming soon" store pills advertised a download nobody could get.
     *
     * The left edge is the fiddly part and still worth pinning. The mark's file
     * carries transparent margin down its left side, 18px of a 186x167 canvas,
     * which lands at about 5px once the image is drawn in the footer's 48px band.
     * So the anchor pulls -ml-1 (4px) to put the ink itself on the headings'
     * edge, the same pull the wordmark needed for its own 4px. The -ml-3 (12px)
     * it used to pull overshot that and hung the logo clear of every heading
     * beside it.
     */
    public function test_the_brand_column_is_the_logo_alone_on_the_heading_edge(): void
    {
        $footer = file_get_contents(resource_path('views/components/footer.blade.php'));

        $this->assertStringContainsString('class="-ml-1 flex rounded-[12px] group', $footer);
        $this->assertStringNotContainsString('-ml-3', $footer);

        // Out of the grid entirely - no disclosure wiring left on it.
        $this->assertStringNotContainsString('footer-col-app', $footer);

        // Four link columns, two-up on small tablets.
        $this->assertStringContainsString('sm:grid-cols-2', $footer);
        $this->assertStringNotContainsString('lg:grid-cols-5', $footer);

        // Rendered: the first link column follows the logo directly, with no
        // badge block left in the gap between them.
        $html = $this->withoutVite()->get('/')->assertOk()->getContent();

        $footerAt = strpos($html, 'footer-ground');
        $this->assertNotFalse($footerAt, 'The footer slab is missing.');

        $logo = strpos($html, 'assets/PWAicon.webp', $footerAt);
        $shopCol = strpos($html, 'id="footer-col-shop"');

        $this->assertNotFalse($logo, 'The footer carries no logo.');
        $this->assertNotFalse($shopCol);
        $this->assertLessThan($shopCol, $logo);

        $betweenLogoAndColumns = substr($html, $logo, $shopCol - $logo);
        $this->assertStringNotContainsString('Get the app', $betweenLogoAndColumns);
        $this->assertStringNotContainsString('App Store', $betweenLogoAndColumns);
        $this->assertStringNotContainsString('Google Play', $betweenLogoAndColumns);
    }

    /**
     * From lg the logo and the four column headings share ONE row - the logo on
     * the left, headings running across on its line.
     *
     * Two pieces make that work and both have to hold. The link wrapper goes
     * display:contents at lg so its navs become direct children of the brand
     * row; without it they lay out inside their own box below the logo, which
     * is the stacked layout this replaced. And the headings take the wordmark's
     * own band (lg:h-12) so they centre against it rather than merely sharing
     * its left edge.
     *
     * That band is 48px now, not the 36px this test was written against. The
     * logo went from h-9 to h-12 when the wordmark asset was replaced, and the
     * heading band did not follow it, so the headings were centring in a 36px
     * box against a 48px logo and sitting 6px high of it. The markup was what
     * was wrong there, and lg:h-9 became lg:h-12. The pairing is the point of
     * the test, so both numbers are asserted together below: if one moves the
     * other has to move with it.
     */
    public function test_the_headings_share_the_logos_row_from_lg(): void
    {
        $footer = file_get_contents(resource_path('views/components/footer.blade.php'));

        $this->assertStringContainsString(
            'lg:grid lg:grid-cols-[1.4fr_repeat(4,minmax(0,1fr))] lg:gap-8',
            $footer
        );
        $this->assertStringContainsString('lg:contents', $footer);
        $this->assertStringContainsString('sm:pointer-events-none sm:py-0 lg:h-12', $footer);

        // The logo's band is what lg:h-12 matches - if one moves the other must.
        $this->assertStringContainsString('<span class="flex h-12 items-center">', $footer);

        // Rendered: brand still precedes every column in the DOM, so the row
        // reads left-to-right and screen readers keep the same order.
        $html = $this->withoutVite()->get('/')->assertOk()->getContent();

        $footerAt = strpos($html, 'footer-ground');
        $this->assertNotFalse($footerAt, 'The footer slab is missing.');

        $logo = strpos($html, 'assets/PWAicon.webp', $footerAt);
        $shop = strpos($html, 'id="footer-col-shop"');
        $legal = strpos($html, 'id="footer-col-legal"');

        $this->assertNotFalse($logo, 'The footer carries no logo.');
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

        // The Help & Support group, in its order.
        $help = $this->column($html, 'help');
        $this->assertLinksInOrder($help, ['Help Center', 'FAQs', 'Contact Support', 'How It Works', 'Refund Policy', 'eSIM Installation Guide']);

        // Earn points left Support. The Shop column lists what a customer can
        // BUY, so the referral link moved on from it: what belongs there is the
        // way to ask us to stock something we do not carry. Invite a Friend
        // still ships, one column over under Company.
        $this->assertStringNotContainsString('Earn points', $help);
        $this->assertStringContainsString('>Request a product</a>', $this->column($html, 'shop'));
        $this->assertStringNotContainsString('>Invite a Friend</a>', $this->column($html, 'shop'));
        $this->assertStringContainsString('>Invite a Friend</a>', $this->column($html, 'company'));
    }

    /**
     * Each group holds exactly the links it was specified with, in order, and
     * no link appears twice anywhere in the footer. Privacy and Terms used to
     * render in the Legal column AND a bottom link bar.
     */
    public function test_every_group_holds_its_links_once(): void
    {
        $html = $this->withoutVite()->get('/')->assertOk()->getContent();

        $this->assertLinksInOrder($this->column($html, 'shop'), ['Gift Cards', 'eSIMs', 'Mobile Top-Ups', 'Data Bundles', 'Bill Payments']);
        $this->assertLinksInOrder($this->column($html, 'company'), ['About Us', 'Blog', 'Customer Reviews', 'Influencers and Affiliates', 'Press &amp; Media']);
        $this->assertLinksInOrder($this->column($html, 'legal'), ['Terms of Service', 'Privacy Policy', 'Cookie Policy', 'Compliance', 'Security', 'Accessibility']);

        // The groups run Shop, Help & Support, Company, Legal & Trust.
        $positions = array_map(fn ($slug) => strpos($html, 'id="footer-col-'.$slug.'"'), ['shop', 'help', 'company', 'legal']);
        $sorted = $positions;
        sort($sorted);
        $this->assertSame($sorted, $positions);

        // Bounded by </footer>: the cookie banner renders after it and links the
        // privacy policy on its own, which is not a footer duplicate.
        $start = strpos($html, 'footer-ground');
        $footer = substr($html, $start, strpos($html, '</footer>', $start) - $start);
        foreach ([route('shop.terms'), route('shop.privacy'), route('shop.accessibility')] as $url) {
            $this->assertSame(1, substr_count($footer, 'href="'.$url.'"'), $url.' is linked more than once in the footer.');
        }
        $this->assertStringNotContainsString('href="'.route('shop.sitemap').'"', $footer);
    }

    /**
     * The settings row runs theme, language and currency, cookie settings,
     * version - and sits above the legal lines.
     */
    public function test_the_settings_row_order(): void
    {
        $html = $this->withoutVite()->get('/')->assertOk()->getContent();
        $footer = substr($html, strpos($html, 'footer-ground'));

        $theme = strpos($footer, '>Theme</span>');
        $locale = strpos($footer, 'localeModalOpen = true');
        $cookies = strpos($footer, '>Cookie Settings</button>');
        $version = strpos($footer, 'Version 2.0.0');
        $copyright = strpos($footer, '&copy; 2026');

        foreach ([$theme, $locale, $cookies, $version, $copyright] as $at) {
            $this->assertNotFalse($at);
        }

        $this->assertLessThan($locale, $theme);
        $this->assertLessThan($cookies, $locale);
        $this->assertLessThan($version, $cookies);
        $this->assertLessThan($copyright, $version);
    }

    /** One link group's list markup, from its id to the end of the list. */
    private function column(string $html, string $slug): string
    {
        $start = strpos($html, 'id="footer-col-'.$slug.'"');
        $this->assertNotFalse($start, 'Missing footer group '.$slug);

        return substr($html, $start, strpos($html, '</ul>', $start) - $start);
    }

    /** @param  array<int, string>  $labels */
    private function assertLinksInOrder(string $column, array $labels): void
    {
        $last = -1;

        foreach ($labels as $label) {
            $at = strpos($column, '>'.$label.'</a>');
            $this->assertNotFalse($at, $label.' is missing from its footer group.');
            $this->assertGreaterThan($last, $at, $label.' is out of order.');
            $last = $at;
        }
    }

    /**
     * The three horizontal rules are gone from the footer.
     *
     * Their vertical spacing was NOT: it lived on the rules' own my-8/lg:my-10,
     * so it had to move onto the blocks below them. Leaving an empty spacer div
     * would not have worked - with no height its top and bottom margins collapse
     * into one, halving the gap.
     *
     * The social band's share of that spacing is smaller now, mt-6/lg:mt-8 in
     * place of mt-8/lg:mt-10, and it moved off the icon row onto a wrapper that
     * right-aligns the row from lg. Both changes came with the redesign that
     * tightened the footer's padding throughout, so the reduction is the new
     * spec and not a leak. What this test defends is unchanged and is still
     * asserted the same way: the gap belongs to the block, so removing a rule
     * cannot take a block's top margin with it.
     */
    public function test_the_horizontal_rules_are_gone_but_the_spacing_is_not(): void
    {
        $footer = file_get_contents(resource_path('views/components/footer.blade.php'));

        $this->assertStringNotContainsString('h-px bg-zinc-200', $footer);
        $this->assertStringNotContainsString('aria-hidden="true"></div>', $footer);

        // Each block the rule used to precede carries the gap itself now: the
        // social icons, the settings row and the legal lines.
        $this->assertStringContainsString('order-3 mt-8 lg:mt-6', $footer);
        $this->assertStringContainsString('mt-8 flex flex-col gap-4 text-sm text-zinc-600', $footer);
        $this->assertStringContainsString('<div class="mt-8 space-y-2">', $footer);

        // The bottom link bar that repeated Terms and Privacy is gone.
        $this->assertStringNotContainsString('mt-8 flex flex-col gap-4 text-base text-zinc-600', $footer);

        $html = $this->withoutVite()->get('/')->assertOk()->getContent();
        $this->assertStringNotContainsString('h-px bg-zinc-200', $html);

        // Everything the rules separated still renders. The social band is
        // checked by an icon label rather than by the words "Follow us": that
        // heading was dropped from the row in the redesign, and the phrase only
        // still appears on the page inside the icons' own aria-labels, so
        // matching on it would have read like a check on a visible heading that
        // is not there any more.
        foreach ([
            'aria-label="Visit our Facebook page"',
            '>Theme</span>',
            'All rights reserved',
        ] as $section) {
            $this->assertStringContainsString($section, $html);
        }
    }
}

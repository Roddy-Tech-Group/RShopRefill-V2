<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The Security page (first published as Digital Assets Security, whose URL
 * now redirects here) and the eSIM Installation Guide, both linked from the
 * footer and both sitemaps.
 */
class DigitalAssetsSecurityPageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
    }

    public function test_the_security_page_renders_for_guests(): void
    {
        $this->get(route('shop.security'))
            ->assertOk()
            ->assertSee('>Security</h1>', false)
            ->assertSee('Your codes stay in your account')
            ->assertSee('Your transaction PIN')
            ->assertSee('href="'.route('shop.contact').'"', false)
            ->assertDontSee('Digital Assets Security');
    }

    public function test_the_old_url_redirects_to_security(): void
    {
        $this->get('/digital-assets-security')
            ->assertStatus(301)
            ->assertRedirect('/security');
    }

    /**
     * The new-device hold is read from config, so the page can never state a
     * different length from the one the middleware enforces.
     */
    public function test_the_new_device_hold_follows_config(): void
    {
        config()->set('security.device_restriction_hours', 48);

        $this->get(route('shop.security'))
            ->assertOk()
            ->assertSee('For the first 48 hours on a new device');
    }

    public function test_the_esim_installation_guide_renders_for_guests(): void
    {
        $this->get(route('shop.esim-guide'))
            ->assertOk()
            ->assertSee('eSIM Installation Guide')
            ->assertSee('Install on iPhone')
            ->assertSee('Install on Android')
            ->assertSee('href="'.route('shop.help').'"', false);
    }

    public function test_the_footer_links_both_pages(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertSee('Legal &amp; Trust', false)
            ->assertSee('href="'.route('shop.security').'"', false)
            ->assertSee('href="'.route('shop.esim-guide').'"', false);
    }

    public function test_both_sitemaps_list_both_pages(): void
    {
        $html = $this->get(route('shop.sitemap'))->assertOk();
        $xml = $this->get('/sitemap.xml')->assertOk();

        foreach ([route('shop.security'), route('shop.esim-guide')] as $url) {
            $html->assertSee('href="'.$url.'"', false);
            $xml->assertSee($url, false);
        }

        $xml->assertDontSee(url('/digital-assets-security'), false);
    }
}

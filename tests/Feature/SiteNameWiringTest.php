<?php

namespace Tests\Feature;

use App\Domain\Communication\Enums\MailDomain;
use App\Domain\Communication\Mail\Security\WelcomeMail;
use App\Models\SiteSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The public-facing website name lives in one admin setting (site.name) and is
 * shared to every view by SiteIdentityComposer as $siteName. These tests pin
 * the wiring end to end: the storefront chrome, the transactional email body +
 * subject, and the outgoing sender name must all follow the setting rather than
 * a hardcoded "RshopRefills".
 */
class SiteNameWiringTest extends TestCase
{
    use RefreshDatabase;

    public function test_storefront_chrome_uses_site_name_setting(): void
    {
        SiteSetting::put('site.name', 'BrandX Market', 'site');
        $this->withoutVite();

        $this->get('/')
            ->assertOk()
            ->assertSee('alt="BrandX Market"', false)
            ->assertDontSee('alt="RshopRefills"', false);
    }

    public function test_welcome_email_body_and_subject_use_site_name_setting(): void
    {
        SiteSetting::put('site.name', 'BrandX Market', 'site');
        $user = User::factory()->create(['name' => 'Sam']);

        $mailable = new WelcomeMail($user, false);

        // Subject (an email header) follows the setting.
        $mailable->assertHasSubject('Welcome to BrandX Market!');
        // Body prose + the shared email layout footer both render the brand.
        $mailable->assertSeeInHtml('Thanks for joining BrandX Market');
        $mailable->assertSeeInHtml('BrandX Market, your digital marketplace');
    }

    public function test_mailbox_sender_names_follow_site_name_setting(): void
    {
        SiteSetting::put('site.name', 'BrandX Market', 'site');

        $this->assertSame('BrandX Market Orders', MailDomain::Orders->senderName());
        $this->assertSame('BrandX Market Security', MailDomain::Security->senderName());
        $this->assertSame(
            'BrandX Market Orders <orders@rshoprefill.com>',
            MailDomain::Orders->fromHeader()
        );
    }
}

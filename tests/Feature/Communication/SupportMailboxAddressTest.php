<?php

namespace Tests\Feature\Communication;

use App\Domain\Communication\Enums\MailDomain;
use App\Domain\Communication\Mail\Security\AccountBannedMail;
use App\Domain\Communication\Mail\Security\AccountSuspendedMail;
use App\Domain\Communication\Mail\Security\DeviceRemovedMail;
use App\Domain\Communication\Mail\Security\EmailChangedMail;
use App\Models\SiteSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * support@rshoprefill.com is the only mailbox that receives mail. info@ has
 * no inbox, so every page and email that told a customer to write there sent
 * them to an address that bounced.
 */
class SupportMailboxAddressTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_reply_to_mailbox_is_support(): void
    {
        $this->assertSame('support@rshoprefill.com', MailDomain::replyTo());
    }

    /** The security emails are the ones a locked-out customer can only answer by email. */
    public function test_security_emails_point_customers_at_support(): void
    {
        foreach ([
            new AccountBannedMail('Ada'),
            new AccountSuspendedMail('Ada', 'Routine review'),
            new DeviceRemovedMail('Ada', 'Chrome on Windows', '203.0.113.7'),
            new EmailChangedMail('Ada', 'a***@example.test'),
        ] as $mail) {
            $html = $mail->render();

            $this->assertStringContainsString('support@rshoprefill.com', $html, $mail::class.' does not name support@.');
            $this->assertStringNotContainsString('info@rshoprefill.com', $html, $mail::class.' still names info@.');
        }
    }

    public function test_the_press_pages_point_at_support(): void
    {
        $this->withoutVite()
            ->get(route('shop.press'))
            ->assertOk()
            ->assertDontSee('info@rshoprefill.com');
    }

    /** No view, mailable or config file names the dead inbox any more. */
    public function test_no_application_file_names_info(): void
    {
        foreach ([app_path(), resource_path('views'), config_path()] as $dir) {
            foreach (File::allFiles($dir) as $file) {
                $this->assertStringNotContainsString(
                    'info@rshoprefill.com',
                    File::get($file->getPathname()),
                    $file->getRelativePathname().' still names info@rshoprefill.com.'
                );
            }
        }
    }

    /**
     * The original settings seed misspelled the domain (rshoprefills.com).
     * The fix migration corrects the support address only while it still
     * holds that exact seed value, so an address an admin typed stays put.
     */
    public function test_the_migration_corrects_only_the_misspelled_seed_value(): void
    {
        $migration = require database_path('migrations/2026_09_15_180000_fix_misspelled_support_email_setting.php');

        SiteSetting::put('contact.email_support', 'support@rshoprefills.com', 'contact');
        $migration->up();
        Cache::flush();
        $this->assertSame('support@rshoprefill.com', SiteSetting::get('contact.email_support'));

        SiteSetting::put('contact.email_support', 'help@example.test', 'contact');
        $migration->up();
        Cache::flush();
        $this->assertSame('help@example.test', SiteSetting::get('contact.email_support'));
    }
}

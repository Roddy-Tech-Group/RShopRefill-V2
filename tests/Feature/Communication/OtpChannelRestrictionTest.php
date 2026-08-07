<?php

namespace Tests\Feature\Communication;

use App\Domain\Admin\Enums\AdminRole;
use App\Domain\Communication\Enums\CommunicationCategory;
use App\Domain\Communication\Enums\CommunicationChannel;
use App\Domain\Communication\Mail\Security\EmailOtpMail;
use App\Domain\Communication\Mail\System\AdminTwoFactorOtpMail;
use App\Domain\Communication\Services\ChannelResolver;
use App\Domain\Communication\Services\CommunicationManager;
use App\Domain\Communication\Services\PreferenceManager;
use App\Models\Admin;
use App\Models\CommunicationLog;
use App\Models\Notification;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * A one-time code must leave the system by email and nothing else.
 *
 * The bell feed and web push both render the message body verbatim, and that
 * body contains the code. Delivering it there hands the code to anyone already
 * holding a session, or to any device whose push subscription was never revoked
 * (nothing clears them on logout) - neither of which proves control of the
 * inbox the code exists to verify.
 *
 * Asserted at the ChannelResolver boundary because that is the single choke
 * point every caller passes through.
 */
class OtpChannelRestrictionTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return CommunicationChannel[]
     */
    private function resolve(object $recipient, CommunicationCategory $category, bool $hasMailable = true): array
    {
        return app(ChannelResolver::class)->resolve(
            recipient: $recipient,
            category: $category,
            hasMailable: $hasMailable,
            hasOverrideEmail: false,
            preferenceManager: app(PreferenceManager::class),
        );
    }

    public function test_a_customer_otp_resolves_to_email_only(): void
    {
        $channels = $this->resolve(User::factory()->create(), CommunicationCategory::Otp);

        $this->assertSame([CommunicationChannel::Email], $channels);
        $this->assertNotContains(CommunicationChannel::InApp, $channels);
        $this->assertNotContains(CommunicationChannel::Push, $channels);
    }

    public function test_an_admin_two_factor_code_resolves_to_email_only(): void
    {
        $admin = Admin::create([
            'name' => 'Two Factor Admin',
            'email' => '2fa-admin@example.test',
            'password' => 'password',
            'role' => AdminRole::SuperAdmin,
            'is_active' => true,
        ]);

        // Admin has a pushSubscriptions() relation, so before this restriction
        // the mandatory-category rule pushed the 2FA code to every device.
        $channels = $this->resolve($admin, CommunicationCategory::TwoFactor);

        $this->assertSame([CommunicationChannel::Email], $channels);
    }

    /**
     * The restriction is scoped to codes, not to security mail in general -
     * a "new device signed in" alert is exactly the thing that should reach
     * the bell and the user's phone.
     */
    public function test_other_security_categories_still_reach_the_bell_and_push(): void
    {
        $channels = $this->resolve(User::factory()->create(), CommunicationCategory::NewDevice);

        $this->assertContains(CommunicationChannel::InApp, $channels);
        $this->assertContains(CommunicationChannel::Email, $channels);
        $this->assertContains(CommunicationChannel::Push, $channels);
    }

    /**
     * No silent downgrade: with no mailable there is no email channel, and the
     * code must not fall back to the bell or push to get delivered somehow.
     */
    public function test_an_otp_without_a_mailable_resolves_to_no_channels(): void
    {
        $channels = $this->resolve(User::factory()->create(), CommunicationCategory::Otp, hasMailable: false);

        $this->assertSame([], $channels);
    }

    public function test_sending_an_otp_writes_no_in_app_notification_row(): void
    {
        Queue::fake();
        $user = User::factory()->create();

        app(CommunicationManager::class)->send(
            recipient: $user,
            category: CommunicationCategory::Otp,
            title: 'Your Security Code',
            message: 'Your OTP is 123456. Do not share this code.',
            mailable: new EmailOtpMail('123456', 'login'),
            correlationId: 'otp_login_'.$user->id,
        );

        $this->assertDatabaseCount('notifications', 0);

        $this->assertSame([CommunicationChannel::Email], CommunicationLog::query()->pluck('channel')->all());
    }

    public function test_sending_an_admin_two_factor_code_queues_email_only(): void
    {
        Queue::fake();
        $admin = Admin::create([
            'name' => 'Queued Admin',
            'email' => 'queued-admin@example.test',
            'password' => 'password',
            'role' => AdminRole::SuperAdmin,
            'is_active' => true,
        ]);

        app(CommunicationManager::class)->send(
            recipient: $admin,
            category: CommunicationCategory::TwoFactor,
            title: 'Your Admin 2FA Code',
            message: 'Your admin 2FA code is 654321.',
            mailable: new AdminTwoFactorOtpMail($admin, '654321'),
            correlationId: 'admin_2fa_'.$admin->id,
        );

        $this->assertSame(
            [CommunicationChannel::Email],
            CommunicationLog::query()->pluck('channel')->all()
        );
    }

    /**
     * The code itself must never be persisted where it can be read back. The
     * bell feed stored the message body verbatim; nothing may reintroduce that.
     */
    public function test_the_code_is_not_persisted_in_any_readable_row(): void
    {
        Queue::fake();
        $user = User::factory()->create();

        app(CommunicationManager::class)->send(
            recipient: $user,
            category: CommunicationCategory::Otp,
            title: 'Your Security Code',
            message: 'Your OTP is 998877. Do not share this code.',
            mailable: new EmailOtpMail('998877', 'login'),
        );

        $this->assertSame(0, Notification::query()->where('message', 'like', '%998877%')->count());
        $this->assertSame(0, CommunicationLog::query()->where('subject', 'like', '%998877%')->count());
    }
}

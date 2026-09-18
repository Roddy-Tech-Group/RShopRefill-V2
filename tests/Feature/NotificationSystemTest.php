<?php

namespace Tests\Feature;

use App\Domain\Admin\Enums\AdminRole;
use App\Domain\Communication\Channels\EmailChannel;
use App\Domain\Communication\Channels\InAppChannel;
use App\Domain\Communication\Channels\WebPushChannel;
use App\Domain\Communication\DTOs\DeliveryResult;
use App\Domain\Communication\Enums\CommunicationCategory;
use App\Domain\Communication\Enums\CommunicationChannel;
use App\Domain\Communication\Enums\DeliveryStatus;
use App\Domain\Communication\Jobs\DispatchCommunicationJob;
use App\Domain\Communication\Mail\Marketing\NewsletterBroadcastMail;
use App\Domain\Communication\Mail\Security\WelcomeMail;
use App\Domain\Communication\Mail\System\AdminTwoFactorOtpMail;
use App\Domain\Communication\Providers\WebPushProvider;
use App\Domain\Communication\Services\CommunicationLogger;
use App\Domain\Communication\Services\CommunicationManager;
use App\Domain\Communication\Services\DeviceManager;
use App\Domain\Communication\Services\PreferenceManager;
use App\Models\Admin;
use App\Models\CommunicationLog;
use App\Models\Notification;
use App\Models\PushSubscription;
use App\Models\User;
use Illuminate\Auth\Events\Registered;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class NotificationSystemTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Registration fires the Registered event; the WelcomeListener routes the
     * welcome email through the Communication Platform on the critical queue.
     */
    public function test_user_registration_queues_welcome_communication(): void
    {
        Queue::fake();

        $user = User::factory()->create([
            'name' => 'Jane Doe',
            'email' => 'jane@example.com',
        ]);

        event(new Registered($user));

        Queue::assertPushed(DispatchCommunicationJob::class, function (DispatchCommunicationJob $job) {
            return $job->payload->category === CommunicationCategory::AccountActivation
                && $job->queue === 'comm-critical';
        });

        // Preferences are provisioned lazily with everything defaulted on.
        app(PreferenceManager::class)->getPreferences($user);

        $this->assertDatabaseHas('communication_preferences', [
            'user_id' => $user->id,
            'email_enabled' => true,
            'marketing_email' => true,
        ]);
    }

    /**
     * The manager respects per-category preference toggles: with marketing
     * email disabled, a newsletter enlists in-app but not the email channel.
     */
    public function test_manager_respects_user_preference_toggles(): void
    {
        Queue::fake();

        $user = User::factory()->create();
        app(PreferenceManager::class)->updatePreferences($user, ['marketing_email' => false]);

        app(CommunicationManager::class)->send(
            recipient: $user,
            category: CommunicationCategory::Newsletter,
            title: 'Weekly deals',
            message: 'Up to 50% off.',
            mailable: new NewsletterBroadcastMail('Weekly deals', '<p>Up to 50% off.</p>', true),
        );

        Queue::assertPushed(DispatchCommunicationJob::class, function (DispatchCommunicationJob $job) {
            $channelValues = array_map(fn ($channel) => $channel->value, $job->payload->channels);

            return $job->payload->category === CommunicationCategory::Newsletter
                && ! in_array('email', $channelValues, true)
                && in_array('in_app', $channelValues, true);
        });
    }

    /**
     * The delivery job processes each channel and records the unified audit
     * trail in communication_logs plus the in-app bell feed.
     */
    public function test_communication_delivers_and_logs(): void
    {
        $user = User::factory()->create(['email' => 'jane@example.com']);

        app(CommunicationManager::class)->send(
            recipient: $user,
            category: CommunicationCategory::AccountActivation,
            title: 'Welcome to RshopRefills!',
            message: 'Your account was successfully registered.',
            mailable: new WelcomeMail($user),
            idempotencyKey: 'test_welcome_job_key',
        );

        // In-app bell feed entry
        $this->assertDatabaseHas('notifications', [
            'user_id' => $user->id,
            'title' => 'Welcome to RshopRefills!',
            'status' => 'sent',
        ]);

        // Unified audit trail: email delivered from the verify@ mailbox
        $this->assertDatabaseHas('communication_logs', [
            'user_id' => $user->id,
            'channel' => 'email',
            'recipient' => 'jane@example.com',
            'sender' => 'verify@rshoprefill.com',
            'status' => 'sent',
        ]);
        $this->assertDatabaseHas('communication_logs', [
            'user_id' => $user->id,
            'channel' => 'in_app',
            'status' => 'sent',
        ]);
    }

    /**
     * Most users have no push subscriptions. That is an expected skip
     * (Suppressed), never a failure - the old behaviour hard-failed the whole
     * delivery job after the email had already sent, flooding failed_jobs.
     */
    public function test_push_without_subscriptions_is_suppressed_not_failed(): void
    {
        $user = User::factory()->create(['email' => 'no-push@example.com']);

        app(CommunicationManager::class)->send(
            recipient: $user,
            category: CommunicationCategory::AccountActivation,
            title: 'Welcome to RshopRefills!',
            message: 'Your account was successfully registered.',
            mailable: new WelcomeMail($user),
            idempotencyKey: 'test_push_suppressed_key',
        );

        $this->assertDatabaseHas('communication_logs', [
            'idempotency_key' => 'test_push_suppressed_key',
            'channel' => 'push',
            'status' => 'suppressed',
        ]);
        $this->assertDatabaseHas('communication_logs', [
            'idempotency_key' => 'test_push_suppressed_key',
            'channel' => 'email',
            'status' => 'sent',
        ]);
        $this->assertSame(
            0,
            CommunicationLog::where('idempotency_key', 'test_push_suppressed_key')->where('status', 'failed')->count()
        );
    }

    /**
     * A genuinely failing channel must throw (so $tries/$backoff retry the
     * job), and the retry must skip channels that already sent - never
     * double-posting the bell feed or re-sending the email.
     */
    public function test_a_failing_channel_triggers_retry_without_resending_sent_channels(): void
    {
        Queue::fake();

        $user = User::factory()->create();

        app(CommunicationManager::class)->send(
            recipient: $user,
            category: CommunicationCategory::AccountActivation,
            title: 'Welcome to RshopRefills!',
            message: 'Your account was successfully registered.',
            mailable: new WelcomeMail($user),
            idempotencyKey: 'test_failing_channel_key',
        );

        $job = null;
        Queue::assertPushed(DispatchCommunicationJob::class, function (DispatchCommunicationJob $pushed) use (&$job) {
            $job = $pushed;

            return true;
        });

        $failingEmail = $this->createMock(EmailChannel::class);
        $failingEmail->method('send')->willReturn(new DeliveryResult(
            channel: CommunicationChannel::Email,
            status: DeliveryStatus::Failed,
            errorMessage: 'provider 500',
        ));

        $run = fn () => $job->handle(
            $failingEmail,
            app(InAppChannel::class),
            app(WebPushChannel::class),
            app(CommunicationLogger::class),
        );

        try {
            $run();
            $this->fail('Expected RuntimeException for the failed email channel.');
        } catch (\RuntimeException) {
            // The throw is the retry trigger.
        }

        $this->assertDatabaseHas('communication_logs', [
            'idempotency_key' => 'test_failing_channel_key',
            'channel' => 'email',
            'status' => 'failed',
        ]);
        $this->assertDatabaseHas('communication_logs', [
            'idempotency_key' => 'test_failing_channel_key',
            'channel' => 'in_app',
            'status' => 'sent',
        ]);
        $this->assertSame(1, Notification::where('user_id', $user->id)->count());

        // Retry: sent/suppressed channels are skipped, only email is retried.
        try {
            $run();
        } catch (\RuntimeException) {
            // Still failing - fine, we only care that nothing double-sends.
        }

        $this->assertSame(1, Notification::where('user_id', $user->id)->count());
    }

    /**
     * When the push service reports every endpoint expired, the dead
     * subscriptions are pruned and the delivery records as suppressed
     * (subscription rot), not failed.
     */
    public function test_expired_push_subscriptions_are_pruned_and_recorded_as_suppressed(): void
    {
        Queue::fake();

        $user = User::factory()->create();
        $user->pushSubscriptions()->create([
            'endpoint' => 'https://push.example/ep1',
            'p256dh_key' => 'test-p256dh',
            'auth_token' => 'test-auth',
        ]);

        app(CommunicationManager::class)->send(
            recipient: $user,
            category: CommunicationCategory::AccountActivation,
            title: 'Welcome to RshopRefills!',
            message: 'Your account was successfully registered.',
            mailable: new WelcomeMail($user),
            idempotencyKey: 'test_push_prune_key',
        );

        $job = null;
        Queue::assertPushed(DispatchCommunicationJob::class, function (DispatchCommunicationJob $pushed) use (&$job) {
            $job = $pushed;

            return true;
        });

        $goneProvider = $this->createMock(WebPushProvider::class);
        $goneProvider->method('send')->willReturn([
            'success' => false,
            'expired_endpoints' => ['https://push.example/ep1'],
            'failed_count' => 1,
            'last_error' => 'Gone',
        ]);

        // No exception: an all-expired push is not a job failure.
        $job->handle(
            app(EmailChannel::class),
            app(InAppChannel::class),
            new WebPushChannel($goneProvider, app(DeviceManager::class)),
            app(CommunicationLogger::class),
        );

        $this->assertSame(0, PushSubscription::count());
        $this->assertDatabaseHas('communication_logs', [
            'idempotency_key' => 'test_push_prune_key',
            'channel' => 'push',
            'status' => 'suppressed',
        ]);
    }

    /**
     * notifications.user_id is an FK to users - an Admin recipient must never
     * produce a bell-feed row, or the customer whose users.id matches the
     * admin's admins.id reads the admin's 2FA code.
     */
    public function test_admin_recipients_never_write_to_the_customer_bell_feed(): void
    {
        $customer = User::factory()->create();

        // Force the id collision that exists in production (both tables
        // start their auto-increment at 1).
        $admin = new Admin([
            'name' => 'Test Admin',
            'email' => 'admin@example.test',
            'password' => 'password',
            'role' => AdminRole::SuperAdmin,
            'is_active' => true,
        ]);
        $admin->id = $customer->id;
        $admin->save();

        app(CommunicationManager::class)->send(
            recipient: $admin,
            category: CommunicationCategory::TwoFactor,
            title: 'Your Admin 2FA Code',
            message: 'Your admin 2FA code is 123456.',
            mailable: new AdminTwoFactorOtpMail($admin, '123456'),
            idempotencyKey: 'test_admin_otp_key',
        );

        $this->assertSame(0, Notification::where('user_id', $customer->id)->count());

        // The admin still receives the OTP by email.
        $this->assertDatabaseHas('communication_logs', [
            'idempotency_key' => 'test_admin_otp_key',
            'channel' => 'email',
            'recipient' => 'admin@example.test',
            'status' => 'sent',
        ]);
    }

    /**
     * The idempotency key blocks duplicate dispatches of the same
     * logical communication.
     */
    public function test_manager_blocks_duplicate_dispatch_by_idempotency_key(): void
    {
        $user = User::factory()->create();

        foreach ([1, 2] as $attempt) {
            app(CommunicationManager::class)->send(
                recipient: $user,
                category: CommunicationCategory::AccountActivation,
                title: 'Unique Event',
                message: 'Testing uniqueness.',
                mailable: new WelcomeMail($user),
                idempotencyKey: 'event_12345_key',
            );
        }

        $this->assertSame(
            1,
            CommunicationLog::where('idempotency_key', 'event_12345_key')
                ->where('channel', 'email')
                ->count()
        );
    }

    /**
     * Test Newsletter APIs (Subscription and Signed Unsub route).
     */
    public function test_newsletter_subscription_flow_and_signed_unsubscription(): void
    {
        // 1. Subscribe
        $response = $this->postJson(route('newsletter.subscribe'), [
            'email' => 'news@example.com',
            'source' => 'footer',
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'status' => 'success',
                'message' => 'Successfully subscribed to marketing newsletter.',
            ]);

        $this->assertDatabaseHas('newsletter_subscribers', [
            'email' => 'news@example.com',
            'status' => 'active',
        ]);

        $unsubscribeUrl = $response->json('unsubscribe_url');
        $this->assertNotNull($unsubscribeUrl);

        // 2. Try unsubscribing with INVALID signature (tampered)
        $tamperedUrl = $unsubscribeUrl.'tamper';
        $unsubResponse1 = $this->getJson($tamperedUrl);
        $unsubResponse1->assertStatus(403);

        // 3. Try unsubscribing with VALID signature
        $unsubResponse2 = $this->getJson($unsubscribeUrl);
        // The confirmation copy names the brand, and the brand is now written
        // "RshopRefill", singular, everywhere it is spoken to a customer: the
        // APP_NAME the application boots with, the rshoprefill.com domain, every
        // transactional mail and the admin surfaces. This expectation still held
        // the older plural "RshopRefills" and was left behind when the name was
        // corrected. Nothing about the route's behaviour changed with the
        // wording, which is the part worth guarding: a valid signature is still
        // required, the row is still flipped to unsubscribed, and the answer is
        // still a 200 carrying a success status.
        $unsubResponse2->assertStatus(200)
            ->assertJson([
                'status' => 'success',
                'message' => 'You have been successfully unsubscribed from RshopRefill newsletter.',
            ]);

        $this->assertDatabaseHas('newsletter_subscribers', [
            'email' => 'news@example.com',
            'status' => 'unsubscribed',
        ]);
    }

    /**
     * Test Storefront Notification APIs.
     */
    public function test_storefront_notification_apis(): void
    {
        $user = User::factory()->create();

        // Seed some notifications
        $n1 = Notification::create([
            'user_id' => $user->id,
            'type' => 'service_notice',
            'title' => 'Title 1',
            'message' => 'Msg 1',
            'channel' => 'in_app',
            'status' => 'sent',
        ]);

        $n2 = Notification::create([
            'user_id' => $user->id,
            'type' => 'service_notice',
            'title' => 'Title 2',
            'message' => 'Msg 2',
            'channel' => 'in_app',
            'status' => 'sent',
        ]);

        // 1. Get List
        $response = $this->actingAs($user)->getJson(route('api.notifications.index'));
        $response->assertStatus(200)
            ->assertJsonCount(2, 'data');

        // 2. Get Unread Count
        $unreadResponse = $this->actingAs($user)->getJson(route('api.notifications.unread-count'));
        $unreadResponse->assertStatus(200)
            ->assertJson(['unread_count' => 2]);

        // 3. Mark Single as Read
        $readResponse = $this->actingAs($user)->patchJson(route('api.notifications.read', ['id' => $n1->id]));
        $readResponse->assertStatus(200);
        $this->assertNotNull($n1->refresh()->read_at);

        // 4. Mark All as Read
        $readAllResponse = $this->actingAs($user)->patchJson(route('api.notifications.read-all'));
        $readAllResponse->assertStatus(200);
        $this->assertNotNull($n2->refresh()->read_at);
    }
}

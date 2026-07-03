<?php

namespace Tests\Feature;

use App\Domain\Notification\Channels\DatabaseChannel;
use App\Domain\Notification\Channels\EmailChannel;
use App\Domain\Notification\Channels\WebPushChannel;
use App\Domain\Notification\Enums\NotificationPriority;
use App\Domain\Notification\Jobs\SendAsynchronousNotificationJob;
use App\Domain\Notification\Mail\WelcomeMail;
use App\Domain\Notification\Services\NotificationDispatcher;
use App\Models\Notification;
use App\Models\NotificationDelivery;
use App\Models\NotificationPreference;
use App\Models\User;
use Illuminate\Auth\Events\Registered;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class NotificationSystemTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Test that user registration initiates default preferences and welcome mail.
     */
    public function test_user_registration_creates_preferences_and_welcomes_user(): void
    {
        Queue::fake();

        $user = User::factory()->create([
            'name' => 'Jane Doe',
            'email' => 'jane@example.com',
        ]);

        event(new Registered($user));

        // 1. Verify default preferences created
        $this->assertDatabaseHas('notification_preferences', [
            'user_id' => $user->id,
            'email_enabled' => true,
            'marketing_enabled' => true,
        ]);

        // 2. Verify Welcome Job was pushed to queue
        Queue::assertPushed(SendAsynchronousNotificationJob::class, function ($job) {
            return $job->queue === 'notifications';
        });
    }

    /**
     * Test dispatcher respects preference toggles.
     */
    public function test_dispatcher_respects_user_preference_toggles(): void
    {
        Queue::fake();

        $user = User::factory()->create();

        // Disable email notifications
        NotificationPreference::create([
            'user_id' => $user->id,
            'email_enabled' => false,
            'marketing_enabled' => true,
            'order_notifications' => true,
            'wallet_notifications' => true,
            'security_notifications' => true,
            'push_enabled' => false,
        ]);

        $dispatcher = app(NotificationDispatcher::class);
        $dispatcher->dispatch(
            user: $user,
            title: 'Refill Warning',
            message: 'Low Refill credits.',
            category: 'order'
        );

        // Verify that only Database channel is queued, Email is skipped!
        Queue::assertPushed(SendAsynchronousNotificationJob::class, function ($job) {
            // Verify reflected channels
            $reflector = new \ReflectionProperty(SendAsynchronousNotificationJob::class, 'channels');
            $reflector->setAccessible(true);
            $channels = $reflector->getValue($job);

            return count($channels) === 1 && $channels[0]->value === 'database';
        });
    }

    /**
     * Test notification queue job processes successfully and registers logs.
     */
    public function test_notification_job_processes_successfully_and_logs(): void
    {
        $user = User::factory()->create(['email' => 'jane@example.com']);
        $mailable = new WelcomeMail($user, false);

        // Execute the job directly
        $job = new SendAsynchronousNotificationJob(
            user: $user,
            title: 'Welcome to RshopRefills!',
            message: 'Your account was successfully registered.',
            mailable: $mailable,
            priority: NotificationPriority::Normal,
            idempotencyKey: 'test_welcome_job_key'
        );

        $job->handle(
            app(EmailChannel::class),
            app(DatabaseChannel::class),
            app(WebPushChannel::class)
        );

        // Verify notification saved in database
        $this->assertDatabaseHas('notifications', [
            'user_id' => $user->id,
            'title' => 'Welcome to RshopRefills!',
            'status' => 'sent',
        ]);

        // Verify successful delivery logs written to audit trail
        $this->assertDatabaseHas('notification_deliveries', [
            'provider' => 'resend',
            'channel' => 'email',
            'recipient' => 'jane@example.com',
            'status' => 'sent',
        ]);
        $this->assertDatabaseHas('notification_deliveries', [
            'provider' => 'database',
            'channel' => 'database',
            'status' => 'sent',
        ]);
    }

    /**
     * Test job level idempotency key protection.
     */
    public function test_job_blocks_duplicate_deliveries_by_idempotency_key(): void
    {
        $user = User::factory()->create();
        $mailable = new WelcomeMail($user, false);

        // 1. Process first time
        $job1 = new SendAsynchronousNotificationJob(
            user: $user,
            title: 'Unique Event',
            message: 'Testing uniqueness.',
            mailable: $mailable,
            priority: NotificationPriority::Normal,
            idempotencyKey: 'event_12345_key'
        );
        $job1->handle(
            app(EmailChannel::class),
            app(DatabaseChannel::class),
            app(WebPushChannel::class)
        );

        $initialDeliveryCount = NotificationDelivery::count();

        // 2. Process duplicate time
        $job2 = new SendAsynchronousNotificationJob(
            user: $user,
            title: 'Unique Event',
            message: 'Testing uniqueness.',
            mailable: $mailable,
            priority: NotificationPriority::Normal,
            idempotencyKey: 'event_12345_key'
        );
        $job2->handle(
            app(EmailChannel::class),
            app(DatabaseChannel::class),
            app(WebPushChannel::class)
        );

        // Verify no extra deliveries recorded
        $this->assertEquals($initialDeliveryCount, NotificationDelivery::count());
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
        $unsubResponse2->assertStatus(200)
            ->assertJson([
                'status' => 'success',
                'message' => 'You have been successfully unsubscribed from RshopRefills newsletter.',
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
            'type' => 'App\Domain\Notification\General',
            'title' => 'Title 1',
            'message' => 'Msg 1',
            'channel' => 'database',
            'status' => 'sent',
        ]);

        $n2 = Notification::create([
            'user_id' => $user->id,
            'type' => 'App\Domain\Notification\General',
            'title' => 'Title 2',
            'message' => 'Msg 2',
            'channel' => 'database',
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

        // 5. Get preferences
        $prefResponse = $this->actingAs($user)->getJson(route('api.notification-preferences.get'));
        $prefResponse->assertStatus(200);

        // 6. Update preferences
        $updatePrefResponse = $this->actingAs($user)->putJson(route('api.notification-preferences.update'), [
            'email_enabled' => false,
            'marketing_enabled' => false,
        ]);
        $updatePrefResponse->assertStatus(200);
        $this->assertFalse($user->notificationPreference->email_enabled);
    }
}

<?php

namespace Tests\Feature;

use App\Domain\Communication\Enums\CommunicationCategory;
use App\Domain\Communication\Jobs\DispatchCommunicationJob;
use App\Domain\Communication\Mail\Marketing\NewsletterBroadcastMail;
use App\Domain\Communication\Mail\Security\WelcomeMail;
use App\Domain\Communication\Services\CommunicationManager;
use App\Domain\Communication\Services\PreferenceManager;
use App\Models\CommunicationLog;
use App\Models\Notification;
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

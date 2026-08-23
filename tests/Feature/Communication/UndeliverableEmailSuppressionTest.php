<?php

namespace Tests\Feature\Communication;

use App\Domain\Communication\Enums\CommunicationCategory;
use App\Domain\Communication\Mail\Security\WelcomeMail;
use App\Domain\Communication\Services\CommunicationManager;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The undeliverable-domain guard born from a production incident: Resend 422s
 * RFC 2606 addresses (user@example.com and friends), the job retries three
 * times, failed_jobs fills up and Sentry pages, all for mail that can never
 * arrive. The blocked set lives in communication.undeliverable_domains and is
 * BLANKED in phpunit.xml (the suite's factories address everything to the
 * example.com domain), so these tests arm it explicitly per test.
 */
class UndeliverableEmailSuppressionTest extends TestCase
{
    use RefreshDatabase;

    public function test_email_to_a_blocked_domain_is_suppressed_not_sent(): void
    {
        config(['communication.undeliverable_domains' => ['example.com']]);

        $user = User::factory()->create(['email' => 'jane@example.com']);

        app(CommunicationManager::class)->send(
            recipient: $user,
            category: CommunicationCategory::AccountActivation,
            title: 'Welcome to RshopRefills!',
            message: 'Your account was successfully registered.',
            mailable: new WelcomeMail($user),
            idempotencyKey: 'suppression_test_key',
        );

        // The email leg is suppressed, never handed to the provider...
        $this->assertDatabaseHas('communication_logs', [
            'user_id' => $user->id,
            'channel' => 'email',
            'recipient' => 'jane@example.com',
            'status' => 'suppressed',
        ]);

        // ...while the in-app bell feed still delivers: suppression is
        // per-channel, not a veto on the whole communication.
        $this->assertDatabaseHas('communication_logs', [
            'user_id' => $user->id,
            'channel' => 'in_app',
            'status' => 'sent',
        ]);
    }

    public function test_newsletter_subscription_from_a_blocked_domain_is_a_422_not_a_500(): void
    {
        config(['communication.undeliverable_domains' => ['example.com']]);

        $this->postJson('/api/newsletter/subscribe', ['email' => 'jane@example.com'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('email');

        $this->assertDatabaseMissing('newsletter_subscribers', ['email' => 'jane@example.com']);
    }

    public function test_deliverable_domains_subscribe_normally_while_the_list_is_armed(): void
    {
        config(['communication.undeliverable_domains' => ['example.com']]);

        $this->postJson('/api/newsletter/subscribe', ['email' => 'maxwell@gmail.com'])
            ->assertOk();

        $this->assertDatabaseHas('newsletter_subscribers', [
            'email' => 'maxwell@gmail.com',
            'status' => 'active',
        ]);
    }
}

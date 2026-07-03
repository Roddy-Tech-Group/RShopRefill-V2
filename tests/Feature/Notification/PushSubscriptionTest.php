<?php

namespace Tests\Feature\Notification;

use App\Models\PushSubscription;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Web Push subscribe / unsubscribe endpoints + DeviceManager behaviour.
 * Covers: auth gate, validation, persistence, device-reassignment (endpoint is
 * globally unique → one current owner), and per-user scoping on unsubscribe.
 */
class PushSubscriptionTest extends TestCase
{
    use RefreshDatabase;

    private function payload(string $endpoint = 'https://fcm.googleapis.com/fcm/send/abc123'): array
    {
        return [
            'endpoint' => $endpoint,
            'keys' => ['p256dh' => 'p256dh-key-value', 'auth' => 'auth-token-value'],
        ];
    }

    public function test_guest_cannot_subscribe(): void
    {
        $this->postJson(route('push.subscribe'), $this->payload())
            ->assertUnauthorized();

        $this->assertDatabaseCount('push_subscriptions', 0);
    }

    public function test_authenticated_user_subscription_persists(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->postJson(route('push.subscribe'), $this->payload())
            ->assertOk();

        $sub = PushSubscription::sole();
        $this->assertSame('https://fcm.googleapis.com/fcm/send/abc123', $sub->endpoint);
        $this->assertSame('p256dh-key-value', $sub->p256dh_key);
        $this->assertSame('auth-token-value', $sub->auth_token);
        $this->assertSame($user->id, $sub->subscribable_id);
    }

    public function test_subscribe_validates_the_subscription_shape(): void
    {
        $this->actingAs(User::factory()->create())
            ->postJson(route('push.subscribe'), ['endpoint' => 'https://x/y']) // missing keys
            ->assertStatus(422);
    }

    public function test_same_endpoint_is_reassigned_to_the_new_user_not_duplicated(): void
    {
        $endpoint = 'https://fcm.googleapis.com/fcm/send/shared-device';
        $userA = User::factory()->create();
        $userB = User::factory()->create();

        $this->actingAs($userA)->postJson(route('push.subscribe'), $this->payload($endpoint))->assertOk();
        // Same browser, user A logs out, user B logs in and re-subscribes.
        $this->actingAs($userB)->postJson(route('push.subscribe'), $this->payload($endpoint))->assertOk();

        // One row for that endpoint, now owned by B (no stale cross-user duplicate).
        $this->assertSame(1, PushSubscription::where('endpoint', $endpoint)->count());
        $this->assertSame($userB->id, PushSubscription::where('endpoint', $endpoint)->sole()->subscribable_id);
    }

    public function test_unsubscribe_only_removes_the_current_users_subscription(): void
    {
        $userA = User::factory()->create();
        $userB = User::factory()->create();

        $this->actingAs($userA)->postJson(route('push.subscribe'), $this->payload('https://push/A'))->assertOk();
        $this->actingAs($userB)->postJson(route('push.subscribe'), $this->payload('https://push/B'))->assertOk();

        // User A tries to unsubscribe user B's endpoint — must NOT delete it.
        $this->actingAs($userA)->postJson(route('push.unsubscribe'), ['endpoint' => 'https://push/B'])->assertOk();
        $this->assertDatabaseHas('push_subscriptions', ['endpoint' => 'https://push/B', 'subscribable_id' => $userB->id]);

        // User A unsubscribes their own endpoint — gone.
        $this->actingAs($userA)->postJson(route('push.unsubscribe'), ['endpoint' => 'https://push/A'])->assertOk();
        $this->assertDatabaseMissing('push_subscriptions', ['endpoint' => 'https://push/A']);
    }
}

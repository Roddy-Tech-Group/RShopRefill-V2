<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The escalating resend cooldown for verification emails: each send stretches
 * the wait (30s -> 1m -> 2m -> 5m, capped at 10m), enforced server-side; the
 * checkout notice's countdown just mirrors these retry_after values.
 */
class ResendVerificationEscalationTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_cooldown_ladder_escalates_per_send(): void
    {
        $user = User::factory()->unverified()->create();

        // Send #1: allowed immediately, next wait 30s.
        $this->actingAs($user)->postJson(route('verification.send'))
            ->assertOk()
            ->assertJsonPath('retry_after', 30);

        // Inside the window: refused, told how long is left.
        $this->actingAs($user)->postJson(route('verification.send'))
            ->assertStatus(429)
            ->assertJsonStructure(['retry_after']);

        // Send #2 after 30s: next wait 60s.
        $this->travel(31)->seconds();
        $this->actingAs($user)->postJson(route('verification.send'))
            ->assertOk()
            ->assertJsonPath('retry_after', 60);

        // Send #3: next wait 2 minutes.
        $this->travel(61)->seconds();
        $this->actingAs($user)->postJson(route('verification.send'))
            ->assertOk()
            ->assertJsonPath('retry_after', 120);

        // Send #4: next wait 5 minutes.
        $this->travel(121)->seconds();
        $this->actingAs($user)->postJson(route('verification.send'))
            ->assertOk()
            ->assertJsonPath('retry_after', 300);

        // Send #5: capped at 10 minutes from here on.
        $this->travel(301)->seconds();
        $this->actingAs($user)->postJson(route('verification.send'))
            ->assertOk()
            ->assertJsonPath('retry_after', 600);
    }

    public function test_a_verified_user_is_a_no_op(): void
    {
        $user = User::factory()->create(['email_verified_at' => now()]);

        $this->actingAs($user)->postJson(route('verification.send'))
            ->assertOk()
            ->assertJsonPath('verified', true);
    }

    public function test_the_checkout_block_advances_the_same_schedule(): void
    {
        $user = User::factory()->unverified()->create();

        // Blocked money action: middleware auto-sends (send #1) and tells the
        // frontend where the countdown starts.
        $this->actingAs($user)->postJson('/api/checkout/place-order', [])
            ->assertStatus(403)
            ->assertJsonPath('email_unverified', true)
            ->assertJsonPath('resend_retry_after', 30);

        // The Resend button immediately after is inside that same window.
        $this->actingAs($user)->postJson(route('verification.send'))
            ->assertStatus(429);

        // After the window, the resend is send #2: next wait 60s.
        $this->travel(31)->seconds();
        $this->actingAs($user)->postJson(route('verification.send'))
            ->assertOk()
            ->assertJsonPath('retry_after', 60);
    }
}

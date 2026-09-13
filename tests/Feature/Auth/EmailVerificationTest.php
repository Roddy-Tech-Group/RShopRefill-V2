<?php

namespace Tests\Feature\Auth;

use App\Models\TrustedDevice;
use App\Models\User;
use Illuminate\Auth\Events\Verified;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

class EmailVerificationTest extends TestCase
{
    use RefreshDatabase;

    public function test_email_verification_screen_can_be_rendered(): void
    {
        $user = User::factory()->unverified()->create();

        $response = $this->actingAs($user)->get('/verify-email');

        $response->assertStatus(200);
    }

    public function test_email_can_be_verified(): void
    {
        $user = User::factory()->unverified()->create();

        Event::fake();

        $verificationUrl = URL::temporarySignedRoute(
            'verification.verify',
            now()->addMinutes(60),
            ['id' => $user->id, 'hash' => sha1($user->email)]
        );

        $response = $this->actingAs($user)->get($verificationUrl);

        Event::assertDispatched(Verified::class);

        $this->assertTrue($user->fresh()->hasVerifiedEmail());
        $response->assertRedirect(route('dashboard', absolute: false).'?verified=1');
    }

    public function test_email_is_not_verified_with_invalid_hash(): void
    {
        $user = User::factory()->unverified()->create();

        $verificationUrl = URL::temporarySignedRoute(
            'verification.verify',
            now()->addMinutes(60),
            ['id' => $user->id, 'hash' => sha1('wrong-email')]
        );

        $this->actingAs($user)->get($verificationUrl);

        $this->assertFalse($user->fresh()->hasVerifiedEmail());
    }

    // --- The link opened on another device -----------------------------------
    //
    // A registered user opens the link on a device that has never signed in
    // (register on the laptop, tap the link on the phone). The phone has no
    // session. Verification must complete right there, and the phone must NOT
    // be signed in, registered as a device, or challenged with an OTP.

    private function linkFor(User $user, ?string $email = null): string
    {
        return URL::temporarySignedRoute(
            'verification.verify',
            now()->addMinutes(60),
            ['id' => $user->id, 'hash' => sha1($email ?? $user->email)]
        );
    }

    public function test_a_signed_out_browser_can_verify_from_the_link_and_is_told_to_close_the_tab(): void
    {
        $user = User::factory()->unverified()->create();
        Event::fake([Verified::class]);

        $response = $this->get($this->linkFor($user));

        $response->assertOk()
            ->assertSee('Email verified')
            ->assertSee('You can close this tab.')
            ->assertSee($user->email)
            ->assertHeader('Cache-Control', 'max-age=0, must-revalidate, no-cache, no-store, private');

        $this->assertTrue($user->fresh()->hasVerifiedEmail());
        Event::assertDispatched(Verified::class, fn (Verified $event) => $event->user->is($user));

        // Not a login: no session, no device, no OTP hold.
        $this->assertGuest();
        $this->assertSame(0, TrustedDevice::count());
        $response->assertSessionMissing('pending_email_otp');
    }

    public function test_a_signed_out_browser_with_a_wrong_hash_is_refused(): void
    {
        $user = User::factory()->unverified()->create();

        $this->get($this->linkFor($user, 'wrong-email'))->assertForbidden();

        $this->assertFalse($user->fresh()->hasVerifiedEmail());
        $this->assertGuest();
    }

    public function test_a_signed_out_browser_with_a_tampered_signature_is_refused(): void
    {
        $user = User::factory()->unverified()->create();

        $this->get($this->linkFor($user).'&extra=1')->assertForbidden();

        $this->assertFalse($user->fresh()->hasVerifiedEmail());
    }

    public function test_an_expired_link_is_refused(): void
    {
        $user = User::factory()->unverified()->create();
        $url = URL::temporarySignedRoute('verification.verify', now()->subMinute(), ['id' => $user->id, 'hash' => sha1($user->email)]);

        $this->get($url)->assertForbidden();

        $this->assertFalse($user->fresh()->hasVerifiedEmail());
    }

    public function test_an_unknown_user_is_a_404(): void
    {
        $url = URL::temporarySignedRoute('verification.verify', now()->addMinutes(60), ['id' => 999999, 'hash' => sha1('nobody@example.test')]);

        $this->get($url)->assertNotFound();
    }

    public function test_an_already_verified_address_says_so_without_firing_the_event_again(): void
    {
        $user = User::factory()->create();
        Event::fake([Verified::class]);

        $this->get($this->linkFor($user))
            ->assertOk()
            ->assertSee('Already verified')
            ->assertSee('You can close this tab.');

        Event::assertNotDispatched(Verified::class);
    }

    /** A shared phone signed in as someone else: verify the link's owner, leave the session alone. */
    public function test_the_link_verifies_its_owner_even_when_another_account_is_signed_in(): void
    {
        $owner = User::factory()->unverified()->create();
        $other = User::factory()->create();

        $this->actingAs($other)->get($this->linkFor($owner))
            ->assertOk()
            ->assertSee('Email verified')
            ->assertSee($owner->email);

        $this->assertTrue($owner->fresh()->hasVerifiedEmail());
        $this->assertAuthenticatedAs($other);
    }
}

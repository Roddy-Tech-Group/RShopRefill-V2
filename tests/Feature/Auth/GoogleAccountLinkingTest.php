<?php

namespace Tests\Feature\Auth;

use App\Domain\Auth\Services\GoogleAuthService;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Laravel\Socialite\Two\User as SocialiteUser;
use Tests\TestCase;

/**
 * Attaching a Google login to an account rests on Google asserting the email
 * is verified. Once it has, the person signing in owns the inbox: a verified
 * local account is linked, an unverified one is claimed outright, with the
 * unproven password cleared and every session revoked so whoever
 * pre-registered the address keeps nothing. Linking used to match on the bare
 * address, force-mark the account verified and leave that password in place.
 */
class GoogleAccountLinkingTest extends TestCase
{
    use RefreshDatabase;

    private function googleUser(string $email, bool $emailVerified = true, string $id = 'google-123'): SocialiteUser
    {
        return (new SocialiteUser)->setRaw(['email_verified' => $emailVerified])->map([
            'id' => $id,
            'name' => 'Jane Doe',
            'nickname' => null,
            'email' => $email,
            'avatar' => 'https://lh3.googleusercontent.com/photo.jpg',
        ]);
    }

    public function test_a_verified_local_account_is_linked_when_google_verified_the_address(): void
    {
        $user = User::factory()->create(['email' => 'jane@example.com', 'google_id' => null]);

        $result = app(GoogleAuthService::class)->findOrCreateUser($this->googleUser('jane@example.com'));

        $this->assertFalse($result['is_new']);
        $this->assertSame('google-123', $user->fresh()->google_id);
    }

    public function test_the_inbox_owner_claims_an_unverified_account_and_the_pre_registerer_is_locked_out(): void
    {
        // The attacker's pre-registration of the victim's address, with a
        // password only the attacker knows and a session still open.
        $user = User::factory()->create([
            'email' => 'jane@example.com',
            'google_id' => null,
            'email_verified_at' => null,
            'password' => Hash::make('attackers-secret'),
            'session_version' => 3,
        ]);

        $result = app(GoogleAuthService::class)->findOrCreateUser($this->googleUser('jane@example.com'));

        $this->assertFalse($result['is_new']);
        $this->assertTrue($result['claimed']);

        $user->refresh();
        $this->assertSame('google-123', $user->google_id);
        $this->assertNotNull($user->email_verified_at, 'Google proved the inbox, so the account is now verified');
        $this->assertNull($user->password, 'the unproven password must not survive the claim');
        $this->assertSame(4, $user->session_version, 'every session the pre-registerer held is revoked');
        $this->assertFalse(Auth::attempt(['email' => 'jane@example.com', 'password' => 'attackers-secret']));
    }

    public function test_a_plain_link_leaves_the_password_and_sessions_alone(): void
    {
        $user = User::factory()->create([
            'email' => 'jane@example.com',
            'google_id' => null,
            'password' => Hash::make('janes-password'),
            'session_version' => 3,
        ]);

        $result = app(GoogleAuthService::class)->findOrCreateUser($this->googleUser('jane@example.com'));

        $this->assertFalse($result['claimed']);
        $user->refresh();
        $this->assertSame(3, $user->session_version);
        $this->assertTrue(Hash::check('janes-password', $user->password));
    }

    public function test_google_must_itself_assert_the_address_is_verified(): void
    {
        User::factory()->create(['email' => 'jane@example.com', 'google_id' => null]);

        try {
            app(GoogleAuthService::class)->findOrCreateUser($this->googleUser('jane@example.com', emailVerified: false));
            $this->fail('an unverified Google address must not link');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('Google has not verified', $e->getMessage());
        }

        try {
            app(GoogleAuthService::class)->findOrCreateUser($this->googleUser('newcomer@example.com', emailVerified: false));
            $this->fail('an unverified Google address must not create an account');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('Google has not verified', $e->getMessage());
        }

        $this->assertDatabaseMissing('users', ['email' => 'newcomer@example.com']);
    }

    public function test_a_payload_without_the_claim_is_treated_as_unverified(): void
    {
        User::factory()->create(['email' => 'jane@example.com', 'google_id' => null]);

        $bare = (new SocialiteUser)->map(['id' => 'google-123', 'name' => 'Jane', 'email' => 'jane@example.com', 'avatar' => null]);

        $this->expectException(\RuntimeException::class);

        app(GoogleAuthService::class)->findOrCreateUser($bare);
    }

    public function test_an_already_linked_account_signs_in_by_its_google_id(): void
    {
        $user = User::factory()->create(['email' => 'jane@example.com', 'google_id' => 'google-123']);

        // Returning users are identified by id; the claim is not re-checked.
        $result = app(GoogleAuthService::class)->findOrCreateUser($this->googleUser('jane@example.com', emailVerified: false));

        $this->assertSame($user->id, $result['user']->id);
    }
}

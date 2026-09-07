<?php

namespace Tests\Feature\Auth;

use App\Domain\Auth\Services\GoogleAuthService;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Socialite\Two\User as SocialiteUser;
use Tests\TestCase;

/**
 * A photo the user uploaded (stored under /storage/) must survive a Google
 * sign-in. Previously updateGoogleProfile() overwrote avatar_url with the Google
 * picture on every login, so a custom photo reverted on its own.
 */
class GoogleAvatarTest extends TestCase
{
    use RefreshDatabase;

    private function googleUser(string $avatar): SocialiteUser
    {
        // Google asserts the address is verified in the raw userinfo payload;
        // linking and sign-up both require that claim.
        return (new SocialiteUser)->setRaw(['email_verified' => true])->map([
            'id' => 'google-123',
            'name' => 'Jane Doe',
            'nickname' => null,
            'email' => 'jane@example.com',
            'avatar' => $avatar,
        ]);
    }

    public function test_a_custom_uploaded_photo_is_not_overwritten_by_google_on_sign_in(): void
    {
        $user = User::factory()->create([
            'google_id' => 'google-123',
            'email' => 'jane@example.com',
            'avatar_url' => '/storage/avatars/my-custom-photo.jpg',
        ]);

        app(GoogleAuthService::class)->findOrCreateUser(
            $this->googleUser('https://lh3.googleusercontent.com/new-google-photo.jpg')
        );

        $this->assertSame('/storage/avatars/my-custom-photo.jpg', $user->fresh()->avatar_url);
    }

    public function test_google_photo_still_refreshes_when_the_user_has_no_custom_upload(): void
    {
        $user = User::factory()->create([
            'google_id' => 'google-123',
            'email' => 'jane@example.com',
            'avatar_url' => 'https://lh3.googleusercontent.com/old-photo.jpg',
        ]);

        app(GoogleAuthService::class)->findOrCreateUser(
            $this->googleUser('https://lh3.googleusercontent.com/fresh-photo.jpg')
        );

        $this->assertSame('https://lh3.googleusercontent.com/fresh-photo.jpg', $user->fresh()->avatar_url);
    }

    public function test_linking_google_to_a_credentials_user_keeps_their_uploaded_photo(): void
    {
        $user = User::factory()->create([
            'google_id' => null,
            'email' => 'jane@example.com',
            'avatar_url' => '/storage/avatars/my-custom-photo.jpg',
        ]);

        app(GoogleAuthService::class)->findOrCreateUser(
            $this->googleUser('https://lh3.googleusercontent.com/google-photo.jpg')
        );

        $this->assertSame('/storage/avatars/my-custom-photo.jpg', $user->fresh()->avatar_url);
        $this->assertSame('google-123', $user->fresh()->google_id);
    }
}

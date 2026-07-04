<?php

namespace App\Domain\Auth\Services;

use App\Models\User;
use Illuminate\Auth\Events\Registered;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Laravel\Socialite\Contracts\User as SocialiteUser;

/**
 * Handles the Google OAuth authentication flow.
 *
 * This service encapsulates all Google sign-in business logic so that
 * controllers and routes remain thin. It supports three scenarios:
 *
 * 1. Returning user with google_id → log in directly.
 * 2. Existing user by email (registered with credentials) → link
 *    their Google account and log in.
 * 3. Brand-new user → create account with Google data (no password),
 *    fire the Registered event (which triggers wallet creation), log in.
 */
class GoogleAuthService
{
    /**
     * Find or create a user from Google OAuth data.
     *
     * Uses a database transaction to prevent race conditions when
     * two requests for the same Google account arrive simultaneously.
     *
     * @return array{user: User, is_new: bool}
     */
    public function findOrCreateUser(SocialiteUser $googleUser): array
    {
        return DB::transaction(function () use ($googleUser): array {
            $email = $googleUser->getEmail();

            if (empty($email)) {
                throw new \RuntimeException('Google account does not have an email address.');
            }

            // Scenario 1: User already linked their Google account
            $user = User::where('google_id', $googleUser->getId())->first();

            if ($user) {
                $this->updateGoogleProfile($user, $googleUser);

                return ['user' => $user, 'is_new' => false];
            }

            // Scenario 2: User exists by email (registered with credentials)
            $user = User::where('email', $email)->first();

            if ($user) {
                $this->linkGoogleAccount($user, $googleUser);

                return ['user' => $user, 'is_new' => false];
            }

            // Scenario 3: Brand-new user via Google
            $user = $this->createGoogleUser($googleUser);

            return ['user' => $user, 'is_new' => true];
        });
    }

    /**
     * Refresh the user's Google profile data on sign-in. Only adopts the Google
     * photo when the user hasn't uploaded their own - otherwise a custom avatar
     * would silently revert to the Google picture on every login.
     */
    private function updateGoogleProfile(User $user, SocialiteUser $googleUser): void
    {
        $updates = [];

        if ($googleUser->getAvatar() && ! $this->hasCustomAvatar($user)) {
            $updates['avatar_url'] = $googleUser->getAvatar();
        }

        if (! empty($updates)) {
            $user->update($updates);
        }
    }

    /**
     * A user-uploaded avatar lives under /storage/ (see the profile settings
     * upload, which writes Storage::url()); Google avatars are remote https URLs.
     * We never overwrite an uploaded photo with the Google one.
     */
    private function hasCustomAvatar(User $user): bool
    {
        return is_string($user->avatar_url) && str_starts_with($user->avatar_url, '/storage/');
    }

    /**
     * Link a Google account to an existing credentials-based user.
     *
     * This handles the case where someone registered with email/password
     * and later clicks "Sign in with Google" using the same email.
     */
    private function linkGoogleAccount(User $user, SocialiteUser $googleUser): void
    {
        $user->update([
            'google_id' => $googleUser->getId(),
            // Keep an already-uploaded photo; otherwise adopt the Google one.
            'avatar_url' => $this->hasCustomAvatar($user)
                ? $user->avatar_url
                : ($googleUser->getAvatar() ?? $user->avatar_url),
            // Google has already verified this email, so a credentials user who
            // links Google should not be asked to verify again.
            'email_verified_at' => $user->email_verified_at ?? now(),
        ]);

        Log::info('Google account linked to existing user', [
            'user_id' => $user->id,
            'google_id' => $googleUser->getId(),
        ]);
    }

    /**
     * Create a brand-new user from Google OAuth data.
     *
     * No password is set — user authenticated via Google. The Registered
     * event is fired to trigger any listeners (e.g., wallet creation).
     * Email is automatically marked as verified since Google already
     * verified it.
     */
    private function createGoogleUser(SocialiteUser $googleUser): User
    {
        $user = User::create([
            'name' => $googleUser->getName() ?? $googleUser->getNickname() ?? 'User',
            'email' => $googleUser->getEmail(),
            'google_id' => $googleUser->getId(),
            'avatar_url' => $googleUser->getAvatar(),
            'email_verified_at' => now(),
        ]);

        event(new Registered($user));

        Log::info('New user created via Google OAuth', [
            'user_id' => $user->id,
            'email' => $user->email,
        ]);

        return $user;
    }
}

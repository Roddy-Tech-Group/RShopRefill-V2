<?php

namespace App\Domain\Auth\Services;

use App\Domain\Security\Services\SessionRevocationService;
use App\Models\User;
use Illuminate\Auth\Events\Registered;
use Illuminate\Auth\Events\Verified;
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
 *
 * Scenarios 2 and 3 rest on Google asserting `email_verified` for the
 * address: an unverified Google account proves nothing about who owns the
 * inbox. Once Google has proven it, the person signing in IS the inbox
 * owner, so a local account that never verified the same address is theirs
 * to claim (scenario 2b). Whoever pre-registered it with a password loses
 * that foothold: the password is cleared and every session signed out.
 * Linking used to match on the bare email and force-mark the local account
 * verified while leaving the pre-registerer's password in place, which is
 * the takeover this closes without adding a step for the honest customer.
 */
class GoogleAuthService
{
    public function __construct(private readonly SessionRevocationService $sessions) {}

    /**
     * Find or create a user from Google OAuth data.
     *
     * Uses a database transaction to prevent race conditions when
     * two requests for the same Google account arrive simultaneously.
     *
     * `claimed` is true when an unverified local account was taken over by
     * the proven inbox owner (scenario 2b); the caller must start a fresh
     * login even if a session for that user already exists, because every
     * previous session of the account was just revoked.
     *
     * @return array{user: User, is_new: bool, claimed: bool}
     *
     * @throws \RuntimeException when the address cannot be trusted; the message is written for the customer
     */
    public function findOrCreateUser(SocialiteUser $googleUser): array
    {
        return DB::transaction(function () use ($googleUser): array {
            $email = $googleUser->getEmail();

            if (empty($email)) {
                throw new \RuntimeException('Google account does not have an email address.');
            }

            // Scenario 1: User already linked their Google account. Identity
            // is the Google id itself, so the email claim is not re-checked.
            $user = User::where('google_id', $googleUser->getId())->first();

            if ($user) {
                $this->updateGoogleProfile($user, $googleUser);

                return ['user' => $user, 'is_new' => false, 'claimed' => false];
            }

            if (! $this->googleVerifiedEmail($googleUser)) {
                throw new \RuntimeException(
                    'Google has not verified the email address on that Google account. Verify it with Google first, or sign in with your password.'
                );
            }

            // Scenario 2: User exists by email (registered with credentials)
            $user = User::where('email', $email)->first();

            if ($user) {
                if ($user->hasVerifiedEmail()) {
                    $this->linkGoogleAccount($user, $googleUser);

                    return ['user' => $user, 'is_new' => false, 'claimed' => false];
                }

                // Scenario 2b: the address was registered but never proven.
                $this->claimUnverifiedAccount($user, $googleUser);

                return ['user' => $user, 'is_new' => false, 'claimed' => true];
            }

            // Scenario 3: Brand-new user via Google
            $user = $this->createGoogleUser($googleUser);

            return ['user' => $user, 'is_new' => true, 'claimed' => false];
        });
    }

    /**
     * Google's own assertion that the address is verified. It rides in the
     * raw userinfo payload, not on the Socialite contract.
     */
    private function googleVerifiedEmail(SocialiteUser $googleUser): bool
    {
        $raw = method_exists($googleUser, 'getRaw') ? $googleUser->getRaw() : null;

        return filter_var(data_get($raw, 'email_verified', false), FILTER_VALIDATE_BOOLEAN);
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
     * Link a Google account to an existing, already-verified credentials user:
     * someone who registered with email/password and later clicks "Sign in
     * with Google" using the same email. Verification state is left exactly
     * as it was; a link never grants it.
     */
    private function linkGoogleAccount(User $user, SocialiteUser $googleUser): void
    {
        $user->update([
            'google_id' => $googleUser->getId(),
            // Keep an already-uploaded photo; otherwise adopt the Google one.
            'avatar_url' => $this->hasCustomAvatar($user)
                ? $user->avatar_url
                : ($googleUser->getAvatar() ?? $user->avatar_url),
        ]);

        Log::info('Google account linked to existing user', [
            'user_id' => $user->id,
            'google_id' => $googleUser->getId(),
        ]);
    }

    /**
     * Hand an unverified local account to the person Google just proved owns
     * its inbox. Nobody has proven that address before now, so the password
     * set at registration cannot be trusted (it may be a pre-registration by
     * someone else): it is cleared and every session signed out, leaving the
     * inbox owner as the only way back in. They can set a password later
     * through the reset flow, which lands in the inbox they hold. The account
     * is now verified, so the Verified event runs the usual welcome.
     */
    private function claimUnverifiedAccount(User $user, SocialiteUser $googleUser): void
    {
        $user->forceFill([
            'google_id' => $googleUser->getId(),
            'avatar_url' => $this->hasCustomAvatar($user)
                ? $user->avatar_url
                : ($googleUser->getAvatar() ?? $user->avatar_url),
            'email_verified_at' => now(),
            'password' => null,
        ])->save();

        $this->sessions->revokeAll($user);

        event(new Verified($user));

        Log::info('Unverified account claimed through Google sign-in', [
            'user_id' => $user->id,
            'google_id' => $googleUser->getId(),
        ]);
    }

    /**
     * Create a brand-new user from Google OAuth data.
     *
     * No password is set — user authenticated via Google. The Registered
     * event is fired to trigger any listeners (e.g., wallet creation).
     * Email is marked verified because Google asserted it is (checked by
     * the caller before we get here).
     *
     * Google returns no country, phone or gender, so profile_completed_at is
     * deliberately left null: EnsureProfileCompleted holds the new account on
     * the completion step until the customer supplies them and accepts the
     * terms. Returning and account-linking users (scenarios 1 and 2) never
     * reach here, so their sign-in is untouched.
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

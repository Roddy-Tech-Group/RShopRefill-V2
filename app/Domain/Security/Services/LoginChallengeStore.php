<?php

namespace App\Domain\Security\Services;

use App\Domain\Security\DTOs\PendingLoginChallenge;
use App\Models\TrustedDevice;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Holds a sign-in that has passed the password but still owes an emailed code.
 *
 * The website keeps this state in the session (`pending_email_otp`). A token API
 * has no session, so the app is handed a short-lived challenge token instead and
 * gives it back when it submits the code.
 *
 * Why a challenge token rather than an access token with restricted abilities:
 * an unverified sign-in should not hold an API token AT ALL. If it did, every
 * future /v2 route would have to remember to exclude it, and the failure when
 * somebody forgot would be silent and invisible in testing. A challenge token is
 * not a Sanctum token, so it authenticates nothing, no matter what anyone
 * forgets.
 *
 * Only the HASH is stored, for the same reason the tokens are: a cache dump must
 * not be a set of usable tickets.
 */
class LoginChallengeStore
{
    public function create(User $user, TrustedDevice $device, string $installationId): PendingLoginChallenge
    {
        $token = Str::random(64);
        $expiresAt = now()->addMinutes((int) config('api.v2.challenge_minutes'));

        Cache::put($this->key($token), [
            'user_id' => $user->id,
            'device_id' => $device->id,
            'installation_id' => $installationId,
        ], $expiresAt);

        return new PendingLoginChallenge($token, $expiresAt);
    }

    /**
     * @return array{user_id: int, device_id: int, installation_id: string}|null
     */
    public function find(string $token): ?array
    {
        $payload = Cache::get($this->key($token));

        if (! is_array($payload) || ! isset($payload['user_id'])) {
            return null;
        }

        return $payload;
    }

    /**
     * Burn the challenge. Called once the code has been accepted, so the same
     * ticket cannot be spent twice.
     */
    public function consume(string $token): void
    {
        Cache::forget($this->key($token));
    }

    private function key(string $token): string
    {
        return 'v2:login-challenge:'.hash('sha256', $token);
    }
}

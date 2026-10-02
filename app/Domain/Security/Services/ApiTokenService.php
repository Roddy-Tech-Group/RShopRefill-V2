<?php

namespace App\Domain\Security\Services;

use App\Domain\Security\DTOs\IssuedTokenPair;
use App\Domain\Security\Exceptions\RefreshTokenRejected;
use App\Models\ApiRefreshToken;
use App\Models\ExpoPushToken;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Laravel\Sanctum\PersonalAccessToken;
use Throwable;

/**
 * The mobile app's token lifecycle: issue, rotate, revoke.
 *
 * Two tokens, because one token cannot be both safe and convenient. The ACCESS
 * token is sent on every request, so it is the one most likely to leak, and it
 * lives about an hour. The REFRESH token is sent only when the access token runs
 * out, sits in the phone's encrypted storage the rest of the time, and lives
 * weeks so nobody is asked to sign in again every Tuesday.
 *
 * Refresh tokens are SINGLE USE. Exchanging one consumes it and issues a
 * successor in the same family, so a stolen copy stops working the moment the
 * real phone refreshes, and re-presenting a consumed token is evidence that two
 * parties hold it. That revokes the family: every token for that device dies at
 * once. OAuth 2.1 calls this refresh token rotation with reuse detection.
 *
 * The one softening is the retry grace, and it is there because of the networks
 * our customers are on rather than any theory. See api.v2.refresh_replay_seconds.
 */
class ApiTokenService
{
    /**
     * Issue a fresh pair. Pass $familyId to continue an existing device chain
     * (rotation); leave it null for a new sign-in, which starts a new chain.
     */
    public function issuePair(User $user, ?string $installationId = null, ?string $familyId = null): IssuedTokenPair
    {
        $familyId ??= (string) Str::uuid();

        $accessExpiresAt = now()->addMinutes((int) config('api.v2.access_token_minutes'));
        $refreshExpiresAt = now()->addDays((int) config('api.v2.refresh_token_days'));

        // Sanctum's PER-TOKEN expiry, never the global sanctum.expiration
        // ceiling: that one would cut the refresh token down to the same
        // lifetime as the access token. See config/sanctum.php.
        $access = $user->createToken(
            $this->accessTokenName($installationId),
            ['*'],
            $accessExpiresAt,
        );

        $refreshPlaintext = Str::random(64);

        ApiRefreshToken::create([
            'user_id' => $user->id,
            'family_id' => $familyId,
            'token_hash' => $this->hash($refreshPlaintext),
            'personal_access_token_id' => $access->accessToken->getKey(),
            'installation_id' => $installationId,
            'expires_at' => $refreshExpiresAt,
        ]);

        return new IssuedTokenPair(
            accessToken: $access->plainTextToken,
            accessTokenExpiresAt: $accessExpiresAt,
            refreshToken: $refreshPlaintext,
            refreshTokenExpiresAt: $refreshExpiresAt,
            familyId: $familyId,
        );
    }

    /**
     * Trade a refresh token for a new pair.
     *
     * @throws RefreshTokenRejected
     */
    public function exchange(string $refreshPlaintext, ?string $installationId = null): IssuedTokenPair
    {
        $hash = $this->hash($refreshPlaintext);

        // The DECISION is made inside a transaction, so two exchanges racing
        // with the same token cannot both mint a pair. The CONSEQUENCES of a
        // refusal are applied outside it, and that separation is not cosmetic:
        // throwing out of DB::transaction rolls the transaction back, so a
        // revocation performed in here would be undone on the way out. We would
        // detect a stolen token and then forget we ever had.
        //
        // Three attempts, because two phones refreshing at once can deadlock on
        // the row lock plus the inserts behind it, and a deadlock is a retry,
        // not a reason to sign anybody out.
        $outcome = DB::transaction(function () use ($hash, $installationId): array {
            // lockForUpdate serialises the race: the loser waits here, then
            // finds consumed_at set and goes down the replay path.
            $token = ApiRefreshToken::where('token_hash', $hash)->lockForUpdate()->first();

            if ($token === null) {
                return ['reject' => RefreshTokenRejected::invalid()];
            }

            if ($token->isRevoked()) {
                return ['reject' => RefreshTokenRejected::revoked()];
            }

            // A refresh token is BOUND to the installation it was issued to. One
            // presented from a different installation is a copy in somebody
            // else's hands, however it got there, and the whole family goes with
            // it. (A reinstalled app reports a new installation id and signs in
            // again, which is the right outcome too: the device is new to us.)
            $wrongDevice = $installationId !== null
                && $token->installation_id !== null
                && $token->installation_id !== $installationId;

            if ($token->isConsumed()) {
                $replay = $wrongDevice ? null : $this->cachedReplay($hash);

                if ($replay !== null) {
                    return ['pair' => $replay];
                }

                // Two parties hold this token, or one very confused client does.
                // Either way the safe reading is theft: cut the whole device
                // loose and make them sign in.
                return [
                    'reject' => RefreshTokenRejected::reused(),
                    'revoke_family' => $token->family_id,
                    'reused' => $token,
                ];
            }

            if ($wrongDevice) {
                return [
                    'reject' => RefreshTokenRejected::invalid(),
                    'revoke_family' => $token->family_id,
                    'wrong_device' => $token,
                ];
            }

            if ($token->isExpired()) {
                return ['reject' => RefreshTokenRejected::expired()];
            }

            $user = $token->user;

            if ($user === null || $user->banned_at !== null || $user->anonymised_at !== null) {
                // The account was banned or closed while the phone still held a
                // live token. Revoke rather than refresh.
                return [
                    'reject' => RefreshTokenRejected::revoked(),
                    'revoke_family' => $token->family_id,
                ];
            }

            $token->forceFill(['consumed_at' => now()])->save();

            // Retire the access token this refresh token was paired with. Its
            // remaining lifetime is otherwise still valid, which would leave two
            // usable access tokens for one device after every refresh.
            if ($token->personal_access_token_id !== null) {
                PersonalAccessToken::whereKey($token->personal_access_token_id)->delete();
            }

            $pair = $this->issuePair(
                $user,
                $installationId ?? $token->installation_id,
                $token->family_id,
            );

            // Written inside the transaction on purpose: by the time the lock is
            // released, a racing retry can already find it.
            $this->rememberReplay($hash, $pair);

            return ['pair' => $pair];
        }, 3);

        if (isset($outcome['reused'])) {
            /** @var ApiRefreshToken $reused */
            $reused = $outcome['reused'];

            Log::warning('api.v2.refresh_token_reused', [
                'user_id' => $reused->user_id,
                'family_id' => $reused->family_id,
                'installation_id' => $reused->installation_id,
                'consumed_at' => $reused->consumed_at?->toIso8601String(),
            ]);
        }

        if (isset($outcome['wrong_device'])) {
            /** @var ApiRefreshToken $token */
            $token = $outcome['wrong_device'];

            Log::warning('api.v2.refresh_token_presented_by_another_installation', [
                'user_id' => $token->user_id,
                'family_id' => $token->family_id,
                'issued_to_installation' => $token->installation_id,
                'presented_by_installation' => $installationId,
            ]);
        }

        if (isset($outcome['revoke_family'])) {
            $this->revokeFamily((string) $outcome['revoke_family']);
        }

        if (isset($outcome['reject'])) {
            throw $outcome['reject'];
        }

        return $outcome['pair'];
    }

    /**
     * Sign out one device: the access token presented, and the refresh family it
     * belongs to.
     */
    public function revokeCurrentDevice(PersonalAccessToken $accessToken): void
    {
        $refresh = ApiRefreshToken::where('personal_access_token_id', $accessToken->getKey())->first();

        if ($refresh !== null) {
            $this->revokeFamily($refresh->family_id);

            return;
        }

        // No paired row: an access token issued before this table existed, or
        // one whose refresh row has been pruned. Kill what we can see.
        $accessToken->delete();
    }

    /**
     * Sign out everywhere. Used by "sign out of all devices" and by anything
     * that invalidates every existing session, such as a password change.
     */
    public function revokeEverything(User $user): void
    {
        $user->tokens()->delete();

        ApiRefreshToken::where('user_id', $user->id)
            ->whereNull('revoked_at')
            ->update(['revoked_at' => now(), 'updated_at' => now()]);

        // A phone that has been signed out must not keep receiving this
        // account's wallet and order notifications on its lock screen.
        ExpoPushToken::where('user_id', $user->id)->delete();
    }

    /**
     * Sign out one phone, named by the installation id it reports.
     *
     * This is what makes "revoke this device" in the security centre mean
     * something to the app. trusted_devices.device_uuid holds exactly the
     * installation id the app sends in X-Installation-ID, which is the join.
     */
    public function revokeByInstallation(User $user, string $installationId): void
    {
        $families = ApiRefreshToken::where('user_id', $user->id)
            ->where('installation_id', $installationId)
            ->pluck('family_id')
            ->unique();

        foreach ($families as $familyId) {
            $this->revokeFamily((string) $familyId);
        }
    }

    /**
     * Kill one device's whole chain: every refresh token in the family, and
     * every access token they were issued with.
     */
    public function revokeFamily(string $familyId): void
    {
        $members = ApiRefreshToken::where('family_id', $familyId)->get();

        $accessTokenIds = $members->pluck('personal_access_token_id')->filter()->all();

        if ($accessTokenIds !== []) {
            PersonalAccessToken::whereKey($accessTokenIds)->delete();
        }

        ApiRefreshToken::where('family_id', $familyId)
            ->whereNull('revoked_at')
            ->update(['revoked_at' => now(), 'updated_at' => now()]);

        // The family IS the device, so its push registration goes with it: a
        // phone that has been signed out, or cut loose as stolen, must not keep
        // receiving this account's notifications.
        foreach ($members->groupBy('user_id') as $userId => $rows) {
            $installations = $rows->pluck('installation_id')->filter()->unique()->all();

            if ($installations !== []) {
                ExpoPushToken::where('user_id', $userId)->whereIn('installation_id', $installations)->delete();
            }
        }
    }

    /**
     * Remove rows that can no longer be used for anything. Expired and revoked
     * tokens are kept for a while after the fact, so a support question about a
     * surprise sign-out can still be answered.
     *
     * Access tokens are pruned here too: they are deleted on refresh and on
     * revocation, but a phone that stops refreshing leaves its last one behind,
     * expired and useless, forever.
     *
     * @return array{refresh: int, access: int}
     */
    public function prune(int $keepDays = 30): array
    {
        $cutoff = now()->subDays($keepDays);

        $refresh = ApiRefreshToken::where('expires_at', '<', $cutoff)
            ->where(function ($query) use ($cutoff) {
                $query->whereNull('revoked_at')->orWhere('revoked_at', '<', $cutoff);
            })
            ->delete();

        $access = PersonalAccessToken::where('expires_at', '<', $cutoff)->delete();

        return ['refresh' => $refresh, 'access' => $access];
    }

    private function accessTokenName(?string $installationId): string
    {
        return 'mobile:'.($installationId !== null && $installationId !== '' ? $installationId : 'unknown');
    }

    private function hash(string $plaintext): string
    {
        return hash('sha256', $plaintext);
    }

    private function replayKey(string $hash): string
    {
        return 'v2:refresh-replay:'.$hash;
    }

    /**
     * Park the pair a dropped response would have carried, so an innocent retry
     * gets the same answer instead of looking like a replay.
     *
     * Encrypted, because this is live token plaintext sitting in the cache, and
     * held for seconds rather than minutes for the same reason.
     */
    private function rememberReplay(string $hash, IssuedTokenPair $pair): void
    {
        $seconds = (int) config('api.v2.refresh_replay_seconds', 60);

        if ($seconds <= 0) {
            return;
        }

        try {
            Cache::put($this->replayKey($hash), Crypt::encrypt([
                'access_token' => $pair->accessToken,
                'access_expires_at' => $pair->accessTokenExpiresAt->toIso8601String(),
                'refresh_token' => $pair->refreshToken,
                'refresh_expires_at' => $pair->refreshTokenExpiresAt->toIso8601String(),
                'family_id' => $pair->familyId,
            ]), $seconds);
        } catch (Throwable $e) {
            // A cache that will not hold this only costs us the grace: an
            // innocent retry is then read as a replay, which is the safe
            // direction to fail.
            Log::warning('api.v2.refresh_replay_not_cached', ['error' => $e->getMessage()]);
        }
    }

    private function cachedReplay(string $hash): ?IssuedTokenPair
    {
        try {
            $payload = Cache::get($this->replayKey($hash));

            if ($payload === null) {
                return null;
            }

            $data = Crypt::decrypt($payload);

            return new IssuedTokenPair(
                accessToken: (string) $data['access_token'],
                accessTokenExpiresAt: Carbon::parse($data['access_expires_at']),
                refreshToken: (string) $data['refresh_token'],
                refreshTokenExpiresAt: Carbon::parse($data['refresh_expires_at']),
                familyId: (string) $data['family_id'],
            );
        } catch (Throwable $e) {
            Log::warning('api.v2.refresh_replay_unreadable', ['error' => $e->getMessage()]);

            return null;
        }
    }
}

<?php

namespace App\Domain\Security\DTOs;

use Illuminate\Support\Carbon;

/**
 * The two tokens handed to the app, and the wire shape they go out in.
 *
 * The plaintext exists only here and in the response. Nothing persists it: the
 * database holds a sha256 of the refresh token and Sanctum holds a sha256 of the
 * access token, so a database dump hands over no live sessions.
 */
final readonly class IssuedTokenPair
{
    public function __construct(
        public string $accessToken,
        public Carbon $accessTokenExpiresAt,
        public string $refreshToken,
        public Carbon $refreshTokenExpiresAt,
        public string $familyId,
    ) {}

    /**
     * @return array{access_token: string, token_type: string, expires_in: int, expires_at: string, refresh_token: string, refresh_expires_at: string}
     */
    public function toArray(): array
    {
        return [
            'access_token' => $this->accessToken,
            'token_type' => 'Bearer',
            // Seconds, because that is what OAuth-shaped HTTP clients expect and
            // what a countdown can be built from without parsing a date. The
            // absolute time is alongside it for a client whose clock is sound.
            //
            // Cast, because Carbon returns a float and json_encode would then
            // write 3600.0 where a typed client is expecting an integer.
            'expires_in' => (int) max(0, now()->diffInSeconds($this->accessTokenExpiresAt, false)),
            'expires_at' => $this->accessTokenExpiresAt->toIso8601ZuluString(),
            'refresh_token' => $this->refreshToken,
            'refresh_expires_at' => $this->refreshTokenExpiresAt->toIso8601ZuluString(),
        ];
    }
}

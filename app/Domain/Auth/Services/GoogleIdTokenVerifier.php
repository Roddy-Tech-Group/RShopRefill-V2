<?php

namespace App\Domain\Auth\Services;

use App\Domain\Auth\Exceptions\GoogleTokenRejectedException;
use App\Domain\Auth\Exceptions\GoogleUnavailableException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Laravel\Socialite\Two\User as SocialiteUser;
use Throwable;

/**
 * Checks a Google ID token from the mobile app and turns it into the same
 * Socialite user object the website's OAuth callback produces.
 *
 * Why this exists at all: the website completes an OAuth code exchange, which
 * ties the result to our client id and secret. A phone does not do that. It uses
 * the native Google sign-in and comes away with an ID token, and the server has
 * to decide for itself whether to believe it.
 *
 * Three checks, and the AUDIENCE one is the one that matters. An ID token proves
 * who the holder is to whoever the token was issued FOR. Without checking that,
 * a token issued to any other Google application in the world would sign that
 * person into RShopRefill, which is the standard way this integration is got
 * wrong. Google's own client ids for our iOS and Android builds go in
 * services.google.mobile_client_ids; nothing else is accepted.
 *
 * Verification is done by asking Google, rather than by validating the signature
 * here. That is a deliberate trade: one HTTPS round trip on sign-in, against
 * hand-rolling JWT signature checking and key rotation, which is exactly where
 * this kind of code goes wrong. If the round trip ever matters, it can be swapped
 * for local JWKS verification without changing anything the app sees.
 */
class GoogleIdTokenVerifier
{
    private const TOKENINFO_URL = 'https://oauth2.googleapis.com/tokeninfo';

    /** Google's two accepted issuer spellings. */
    private const ISSUERS = ['accounts.google.com', 'https://accounts.google.com'];

    /**
     * @throws GoogleTokenRejectedException when the token is not one we will accept
     * @throws GoogleUnavailableException when Google cannot be asked, or we are not configured to ask
     */
    public function verify(string $idToken): SocialiteUser
    {
        $audiences = $this->allowedAudiences();

        if ($audiences === []) {
            // Refusing beats accepting anything: with no allowlist there is
            // nothing to check the audience against.
            throw new GoogleUnavailableException('Google sign-in is not available right now.');
        }

        $claims = $this->fetchClaims($idToken);

        if (! in_array((string) ($claims['iss'] ?? ''), self::ISSUERS, true)) {
            throw new GoogleTokenRejectedException('That Google sign-in could not be verified.');
        }

        if (! in_array((string) ($claims['aud'] ?? ''), $audiences, true)) {
            // Someone else's app's token. Logged, because it is either a
            // misconfigured build of ours or somebody trying it on, and the two
            // look identical from here.
            Log::warning('api.v2.google_id_token_wrong_audience', [
                'aud' => $claims['aud'] ?? null,
            ]);

            throw new GoogleTokenRejectedException('That Google sign-in could not be verified.');
        }

        $email = (string) ($claims['email'] ?? '');

        if ($email === '') {
            throw new GoogleTokenRejectedException('That Google account has no email address we can use.');
        }

        // Google reports this as the string "true". An unverified Google address
        // must not be able to claim an account that owns it.
        if (! in_array((string) ($claims['email_verified'] ?? ''), ['true', '1'], true)) {
            throw new GoogleTokenRejectedException('Please verify your email address with Google first.');
        }

        if ((string) ($claims['sub'] ?? '') === '') {
            throw new GoogleTokenRejectedException('That Google sign-in could not be verified.');
        }

        return $this->toSocialiteUser($claims);
    }

    /**
     * @return array<string, mixed>
     */
    private function fetchClaims(string $idToken): array
    {
        try {
            $response = Http::asJson()
                ->timeout(8)
                ->connectTimeout(4)
                // Google occasionally answers 5xx; a sign-in is worth one retry.
                ->retry(2, 200, throw: false)
                ->get(self::TOKENINFO_URL, ['id_token' => $idToken]);
        } catch (Throwable $e) {
            Log::warning('api.v2.google_tokeninfo_unreachable', ['error' => $e->getMessage()]);

            throw new GoogleUnavailableException('We could not reach Google just now. Please try again.');
        }

        // Google answers 400 for an expired, malformed or unknown token. That is
        // an ordinary refusal, not an outage.
        if ($response->status() === 400) {
            throw new GoogleTokenRejectedException('That Google sign-in has expired. Please try again.');
        }

        if (! $response->successful()) {
            Log::warning('api.v2.google_tokeninfo_failed', ['status' => $response->status()]);

            throw new GoogleUnavailableException('We could not reach Google just now. Please try again.');
        }

        $claims = $response->json();

        if (! is_array($claims)) {
            throw new GoogleTokenRejectedException('That Google sign-in could not be verified.');
        }

        return $claims;
    }

    /**
     * The same object the website's callback hands to GoogleAuthService, so one
     * account-linking implementation serves both. GoogleAuthService reads
     * getRaw()['email_verified'], hence the raw claims going through untouched.
     *
     * @param  array<string, mixed>  $claims
     */
    private function toSocialiteUser(array $claims): SocialiteUser
    {
        $user = new SocialiteUser;

        return $user->setRaw($claims)->map([
            'id' => (string) $claims['sub'],
            'name' => $claims['name'] ?? null,
            'nickname' => $claims['given_name'] ?? null,
            'email' => (string) $claims['email'],
            'avatar' => $claims['picture'] ?? null,
        ]);
    }

    /**
     * @return list<string>
     */
    private function allowedAudiences(): array
    {
        $mobile = (array) config('services.google.mobile_client_ids', []);
        $web = (string) config('services.google.client_id', '');

        // The web client id is accepted too: Expo's auth-session flow can be
        // configured to use it, and it is unambiguously ours.
        if ($web !== '') {
            $mobile[] = $web;
        }

        return array_values(array_unique(array_filter(array_map('strval', $mobile))));
    }
}

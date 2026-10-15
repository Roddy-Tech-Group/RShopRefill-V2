<?php

namespace App\Http\Controllers\Api\Mobile;

use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Brings a Google sign-in the mobile app started back to the app. The app
 * opens the website's own Google sign-in in the phone's browser
 * (rshoprefill-mobileapp, src/lib/auth/google.ts); Google returns to the
 * website's callback (GoogleAuthController), which finds or creates the
 * account exactly as it does for the website and then, instead of signing the
 * browser in, sends the browser back to the app with a one-time code.
 *
 * The code alone signs nobody in. This is the PKCE handoff (RFC 7636): the app
 * sent the SHA-256 of a secret when it opened the browser, and only the holder
 * of that secret can redeem the code, so an app that catches the link learns
 * nothing it can use. A code lives two minutes and is gone after one try.
 */
final class AppGoogleHandoff
{
    private const TTL_SECONDS = 120;

    /** The app's own link, the one place a code is ever sent in production. */
    private const APP_RETURN = 'rshoprefill://auth/google';

    /**
     * Where the browser may take a code: the app's link, and while developing,
     * Expo Go's (exp://<host>:<port>/--/auth/google). Anything else is refused,
     * so the start link cannot be pointed at someone else's address.
     */
    public static function returnUrl(string $url): ?string
    {
        if ($url === self::APP_RETURN) {
            return $url;
        }

        if (! app()->isProduction() && preg_match('#^exps?://[A-Za-z0-9.\-]+(:\d{1,5})?/--/auth/google$#', $url) === 1) {
            return $url;
        }

        return null;
    }

    /** A SHA-256 in unpadded base64url, the only challenge the handoff takes. */
    public static function isChallenge(string $challenge): bool
    {
        return preg_match('/^[A-Za-z0-9_-]{43}$/', $challenge) === 1;
    }

    /**
     * The browser, sent back to the app with a code or an error.
     *
     * @param  array<string, string>  $query
     */
    public static function back(string $returnUrl, array $query): RedirectResponse
    {
        return redirect()->away($returnUrl.'?'.http_build_query($query));
    }

    public function issue(User $user, string $challenge): string
    {
        $code = Str::random(64);

        Cache::put($this->key($code), [
            'user_id' => $user->id,
            'challenge' => $challenge,
        ], now()->addSeconds(self::TTL_SECONDS));

        return $code;
    }

    /**
     * The account a code was issued for, to the holder of its secret. The code
     * is spent by the first try, right or wrong, so it cannot be guessed at.
     */
    public function redeem(string $code, string $verifier): ?User
    {
        $held = Cache::pull($this->key($code));

        if (! is_array($held)) {
            return null;
        }

        $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');

        if (! hash_equals((string) $held['challenge'], $challenge)) {
            return null;
        }

        return User::find($held['user_id']);
    }

    /** Only a digest of the code is stored, so the cache cannot be read back into a live one. */
    private function key(string $code): string
    {
        return 'mobile_google_code_'.hash('sha256', $code);
    }
}

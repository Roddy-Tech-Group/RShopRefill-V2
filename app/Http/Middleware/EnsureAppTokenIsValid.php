<?php

namespace App\Http\Middleware;

use App\Models\PersonalAccessToken;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The mobile app's side of EnsureAccountActive and AuthenticateSession. Those
 * read the browser session, so on their own they would let an app token
 * outlive a ban, a closure, a "sign out everywhere", a removed device or a
 * password change. Every request made with an app token is checked against
 * each of those here, and a token that fails is deleted, not only refused.
 *
 * A request signed in with a browser session (Sanctum hands it a
 * TransientToken) passes straight through: the web checks already cover it.
 */
class EnsureAppTokenIsValid
{
    /** What an unfinished Google sign-up may still reach. */
    private const OPEN_WHILE_UNFINISHED = [
        'api.mobile.me',
        'api.mobile.profile.complete',
        'api.mobile.auth.logout',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        /** @var User|null $user */
        $user = $request->user();
        $token = $user?->currentAccessToken();

        if (! $token instanceof PersonalAccessToken) {
            return $next($request);
        }

        if ($user->banned_at !== null) {
            return $this->refuse($token, 'Your account has been banned. Please contact support.', 403);
        }

        if ($user->anonymised_at !== null) {
            return $this->refuse($token, 'This account has been closed.', 403);
        }

        if (! $this->stillSignedIn($token, $user, $request)) {
            return $this->refuse($token, 'You were signed out of this device for security reasons. Please sign in again.', 401);
        }

        // The website's EnsureProfileCompleted, for the app: an account Google
        // created is not finished until it gives the country, phone and gender
        // sign-up asks for, so until then only finishing it, reading who is
        // signed in and signing out are open. Refused, not ended: the token stays.
        if ($user->needsProfileCompletion()
            && ! $request->routeIs(self::OPEN_WHILE_UNFINISHED)) {
            return response()->json([
                'message' => 'Please finish setting up your account first.',
                'profile_required' => true,
            ], 403);
        }

        return $next($request);
    }

    private function stillSignedIn(PersonalAccessToken $token, User $user, Request $request): bool
    {
        // The app sends its installation ID on every request; a token copied
        // to another phone arrives with the wrong one. A device removed in the
        // security centre has lost its trust, and the token goes with it.
        $device = $token->device;
        $installationId = (string) $request->header('X-Installation-ID', '');

        if ($device === null || ! $device->isTrusted() || ! hash_equals((string) $device->device_uuid, $installationId)) {
            return false;
        }

        // "Sign out everywhere" and "sign out this device" revoke the session.
        $session = $token->userSession;

        if ($session === null || $session->revoked_at !== null) {
            return false;
        }

        // A password changed or reset since the token was issued.
        return hash_equals((string) $token->password_fingerprint, PersonalAccessToken::fingerprint($user->password));
    }

    private function refuse(PersonalAccessToken $token, string $message, int $status): Response
    {
        $session = $token->userSession;

        if ($session !== null && $session->revoked_at === null) {
            $session->forceFill(['revoked_at' => now()])->save();
        }

        $token->delete();

        return response()->json(['message' => $message], $status);
    }
}

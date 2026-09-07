<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Signs out and blocks any customer (web guard) whose account has been
 * banned or closed, and any session that was revoked after it started.
 *
 * Runs on BOTH the web and api groups: the storefront's API routes
 * authenticate the same session cookie, so a banned customer used to keep
 * working every /api endpoint until they happened to load a web page.
 * Admins use the admin guard and are unaffected.
 */
class EnsureAccountActive
{
    public function handle(Request $request, Closure $next): Response
    {
        // Always the customer guard: on admin routes the default guard is
        // `admin`, whose sessions this check must never touch.
        /** @var User|null $user */
        $user = $request->user('web');

        if ($user === null) {
            return $next($request);
        }

        if ($user->banned_at !== null) {
            return $this->refuse($request, 'Your account has been banned. Please contact support.');
        }

        // A closed (anonymised) account has no password, no Google link and a
        // replaced email, so it cannot sign in again; this catches a session
        // that was still alive when the closure completed.
        if ($user->anonymised_at !== null) {
            return $this->refuse($request, 'This account has been closed.');
        }

        if ($this->sessionWasRevoked($request, $user)) {
            return $this->refuse($request, 'You were signed out of this device for security reasons. Please sign in again.');
        }

        return $next($request);
    }

    /**
     * A "sign out everywhere" bumps users.session_version; every login stamps
     * the version it started under into the session. A session with an older
     * stamp (or, for sessions predating this check, no stamp at all when the
     * user has since been revoked) is refused regardless of session driver.
     */
    private function sessionWasRevoked(Request $request, User $user): bool
    {
        if (! $request->hasSession()) {
            return false;
        }

        return (int) $request->session()->get('auth.session_version', 0) !== (int) $user->session_version;
    }

    private function refuse(Request $request, string $message): Response
    {
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        if ($request->expectsJson() || $request->is('api/*')) {
            return response()->json(['message' => $message], 403);
        }

        return redirect()->route('login')->withErrors(['email' => $message]);
    }
}

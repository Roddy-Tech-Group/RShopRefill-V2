<?php

namespace App\Listeners;

use Illuminate\Auth\Events\Login;

/**
 * Stamps the customer's current session generation into the session at
 * login. EnsureAccountActive compares it with users.session_version on
 * every request; a "sign out everywhere" bumps the user's counter and this
 * stamp is what lets the middleware tell an old session from a fresh one.
 *
 * Fires for remember-me revivals too (the guard raises Login for those), so
 * a revived session carries the version it was revived under.
 */
class RecordSessionVersionOnLogin
{
    public function handle(Login $event): void
    {
        if ($event->guard !== 'web') {
            return;
        }

        session()->put('auth.session_version', (int) ($event->user->session_version ?? 0));
    }
}

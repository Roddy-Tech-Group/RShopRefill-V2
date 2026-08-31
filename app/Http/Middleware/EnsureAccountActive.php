<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Signs out and blocks any customer (web guard) whose account has been banned.
 * Runs on the web group; admins use the admin guard so they are unaffected.
 */
class EnsureAccountActive
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user && $user->banned_at !== null) {
            $this->signOut($request);

            return redirect()->route('login')
                ->withErrors(['email' => 'Your account has been banned. Please contact support.']);
        }

        // A closed (anonymised) account has no password, no Google link and a
        // replaced email, so it cannot sign in again; this catches a session
        // that was still alive when the closure completed.
        if ($user && $user->anonymised_at !== null) {
            $this->signOut($request);

            return redirect()->route('login')
                ->withErrors(['email' => 'This account has been closed.']);
        }

        return $next($request);
    }

    private function signOut(Request $request): void
    {
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();
    }
}

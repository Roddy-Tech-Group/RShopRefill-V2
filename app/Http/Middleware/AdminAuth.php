<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class AdminAuth
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (! Auth::guard('admin')->check()) {
            abort(404);
        }

        // Additional check: ensure the admin account is still active
        // even if they hold a valid session token.
        $admin = Auth::guard('admin')->user();
        if (! $admin || ! $admin->isActive()) {
            Auth::guard('admin')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->route('admin.login')
                ->withErrors(['email' => __('Your admin account has been deactivated.')]);
        }

        // Role authorization: Moderators and Admins are scoped to a subset of
        // the panel (see AdminRole::canAccessAdminRoute). The dashboard, login
        // and logout are always reachable so a denied admin still lands
        // somewhere sane.
        $routeName = $request->route()?->getName() ?? '';
        if ($routeName !== '' && ! $admin->canAccessAdminRoute($routeName)) {
            if ($request->expectsJson() || $request->is('admin/api/*')) {
                abort(404, 'Your role does not have access to this area.');
            }

            abort(404);
        }

        return $next($request);
    }
}

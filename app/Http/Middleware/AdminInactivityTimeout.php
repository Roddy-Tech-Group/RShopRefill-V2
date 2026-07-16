<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;
use App\Support\FeatureFlag;
use App\Models\SiteSetting;

class AdminInactivityTimeout
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (Auth::guard('admin')->check() && FeatureFlag::on('admin_inactivity_timeout')) {
            $lastActivity = session('last_admin_activity');
            $timeoutMinutes = (int) SiteSetting::get('features.admin_inactivity_timeout_minutes', 30);
            $timeout = $timeoutMinutes * 60; // Convert to seconds

            if ($lastActivity && (time() - $lastActivity > $timeout)) {
                Auth::guard('admin')->logout();
                session()->invalidate();
                session()->regenerateToken();

                return redirect()->route('admin.login')->withErrors([
                    'email' => 'You have been logged out due to inactivity.',
                ]);
            }

            session(['last_admin_activity' => time()]);
        }

        return $next($request);
    }
}

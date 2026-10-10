<?php

namespace App\Domain\Admin\Support;

use App\Models\Admin;
use App\Models\User;
use Illuminate\Support\Facades\Auth;

/**
 * The one record that a Super Admin is signed in as a customer right now.
 *
 * Both guards being authenticated at once used to be read as an impersonation
 * everywhere: the "Viewing as" banner, the "return to admin" switch and the
 * device-verification bypass all keyed off the admin guard alone. An admin or
 * moderator who signed into their own customer account in the same browser
 * saw their own name in the banner, could log themselves out with it, and
 * skipped device verification. An impersonation is only what a Super Admin
 * started, for this customer, from this admin session; nothing else is.
 */
final class CustomerImpersonation
{
    public const SESSION_KEY = 'customer_impersonation';

    public static function start(Admin $admin, User $user): void
    {
        session()->put(self::SESSION_KEY, [
            'admin_id' => $admin->id,
            'user_id' => $user->id,
            'started_at' => now()->toIso8601String(),
        ]);
    }

    /**
     * True only while the admin who started it is still signed in on the admin
     * guard and the customer it was started for is the one on the web guard.
     */
    public static function active(): bool
    {
        $marker = session()->get(self::SESSION_KEY);

        if (! is_array($marker)) {
            return false;
        }

        return Auth::guard('admin')->check()
            && (int) Auth::guard('admin')->id() === (int) ($marker['admin_id'] ?? 0)
            && Auth::guard('web')->check()
            && (int) Auth::guard('web')->id() === (int) ($marker['user_id'] ?? 0);
    }

    /**
     * @return array{admin_id: int, user_id: int, started_at: string}|null
     */
    public static function end(): ?array
    {
        $marker = session()->pull(self::SESSION_KEY);

        return is_array($marker) ? $marker : null;
    }
}

<?php

namespace App\Domain\Security\Services;

use App\Domain\Communication\Enums\CommunicationCategory;
use App\Domain\Communication\Mail\Security\DeviceRemovedMail;
use App\Domain\Communication\Services\CommunicationManager;
use App\Models\TrustedDevice;
use App\Models\User;
use App\Models\UserSession;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Str;

/**
 * The one place that ends customer sessions, on every session driver.
 *
 * Two mechanisms, belt and braces:
 *
 *  1. Every session we know about (the user_sessions overlay written at
 *     login) is destroyed through the configured session handler, which is
 *     what makes this work on redis and file drivers as well as database.
 *     Deleting rows from the `sessions` table, the previous approach, was a
 *     silent no-op in production, where sessions live in redis.
 *  2. The user's `session_version` is bumped. Every login stores the version
 *     it started under, and EnsureAccountActive refuses any session carrying
 *     an older one, so a session the overlay never recorded (a remember-me
 *     revival, a login from before device tracking) is signed out on its
 *     very next request. A counter, not a timestamp, so there is no
 *     same-second race between a revoke and a fresh login.
 *
 * The remember token is rotated with every full revocation, which voids
 * every remember-me cookie in existence.
 */
class SessionRevocationService
{
    public function __construct(
        private readonly DeviceTrustManager $devices,
        private readonly CommunicationManager $communications,
    ) {}

    /**
     * Sign the customer out on every device and void every remember-me cookie.
     */
    public function revokeAll(User $user): void
    {
        $user->forceFill([
            'session_version' => (int) $user->session_version + 1,
            'remember_token' => Str::random(60),
        ])->save();

        UserSession::query()
            ->where('user_id', $user->id)
            ->whereNull('revoked_at')
            ->with('device')
            ->get()
            ->each(fn (UserSession $session) => $this->destroy($session));

        $this->purgeDatabaseSessions($user);
    }

    /**
     * Sign out one device, withdraw its trust, and tell the account owner.
     * The current device is never excluded here; callers decide.
     */
    public function revokeDevice(TrustedDevice $device, bool $notifyOwner = true): void
    {
        $device->sessions()
            ->whereNull('revoked_at')
            ->get()
            ->each(function (UserSession $session) use ($device): void {
                $session->setRelation('device', $device);
                $this->destroy($session);
            });

        $this->devices->revokeDevice($device);

        if ($notifyOwner && $device->user !== null) {
            $this->notifyDeviceRemoved($device);
        }
    }

    /**
     * End one recorded session: destroy the underlying Laravel session through
     * the active handler, mark the overlay row, and release the device's
     * session count.
     */
    private function destroy(UserSession $session): void
    {
        if ($session->laravel_session_id !== null) {
            Session::driver(config('session.driver'))->getHandler()->destroy($session->laravel_session_id);
        }

        $session->forceFill(['revoked_at' => now()])->save();

        if ($session->device !== null && (int) $session->device->current_session_count > 0) {
            $session->device->decrement('current_session_count');
        }
    }

    /**
     * On the database driver the `sessions` table carries user_id, so every
     * session, recorded or not, can be removed outright. On other drivers the
     * table is empty and this is a harmless no-op; the version bump above is
     * what signs those sessions out.
     */
    private function purgeDatabaseSessions(User $user): void
    {
        $table = (string) config('session.table', 'sessions');
        $connection = config('session.connection');

        if (! Schema::connection($connection)->hasTable($table)) {
            return;
        }

        DB::connection($connection)->table($table)->where('user_id', $user->id)->delete();
    }

    private function notifyDeviceRemoved(TrustedDevice $device): void
    {
        $label = trim(($device->os ?: 'Unknown OS').' · '.($device->browser ?: 'Unknown Browser'));

        $this->communications->send(
            recipient: $device->user,
            category: CommunicationCategory::DeviceRemoved,
            title: 'A device was signed out of your account',
            message: "{$label} was signed out and removed from your trusted devices.",
            mailable: new DeviceRemovedMail($device->user->name, $label, $device->ip_address),
            correlationId: 'device_removed_'.$device->id.'_'.now()->timestamp,
        );
    }
}

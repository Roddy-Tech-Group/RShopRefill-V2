<?php

namespace App\Domain\Security\Services;

use App\Models\TrustedDevice;
use App\Models\User;
use App\Models\UserSession;
use Illuminate\Support\Str;

class SessionSecurityManager
{
    public function createSession(User $user, TrustedDevice $device, string $laravelSessionId = null, string $riskLevel = 'LOW'): UserSession
    {
        $session = UserSession::create([
            'user_id' => $user->id,
            'trusted_device_id' => $device->id,
            'laravel_session_id' => $laravelSessionId,
            'access_token' => Str::random(60), // Can be swapped with actual Sanctum/Passport token hash
            'refresh_token' => Str::random(60),
            'ip_address' => $device->ip_address,
            'user_agent' => $device->user_agent,
            'risk_level' => $riskLevel,
            'expires_at' => now()->addDays(30),
        ]);

        $device->increment('current_session_count');

        return $session;
    }

    public function revokeSession(UserSession $session): void
    {
        $session->revoked_at = now();
        $session->save();

        if ($session->device) {
            $session->device->decrement('current_session_count');
        }

        // Actually destroy the underlying Laravel session
        \Illuminate\Support\Facades\DB::table('sessions')->where('id', $session->laravel_session_id)->delete();
    }

    public function revokeAllSessionsForUser(User $user): void
    {
        $sessions = UserSession::where('user_id', $user->id)->whereNull('revoked_at')->get();

        foreach ($sessions as $session) {
            $this->revokeSession($session);
        }

        // Physically destroy all underlying Laravel sessions for this user, guaranteeing full revocation
        // even if UserSession records were out of sync.
        \Illuminate\Support\Facades\DB::table('sessions')->where('user_id', $user->id)->delete();
    }
}

<?php

namespace App\Domain\Security\Services;

use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class SecurityIncidentManager
{
    public function __construct(
        private readonly SessionRevocationService $sessions,
    ) {}

    /**
     * Triggered when a user clicks "NO IT WASN'T ME" on a new device email.
     */
    public function handleCompromisedAccount(User $user, string $triggerIp, string $reason): string
    {
        $incidentId = 'INC-'.strtoupper(Str::random(10));

        DB::transaction(function () use ($user, $triggerIp, $reason, $incidentId) {
            // 1. Sign out every device and void every remember-me cookie, on
            //    whichever session driver production runs.
            $this->sessions->revokeAll($user);

            // 1b. If the current user is the compromised user, force logout so Laravel doesn't re-save the session
            if (Auth::id() === $user->id) {
                Auth::logout();
                session()->invalidate();
                session()->regenerateToken();
            }

            // 2. Revoke trust from all devices
            $user->trustedDevices()->update([
                'trusted_since' => null,
                'trust_score' => 0,
            ]);

            // 3. Lock the account into Protected Mode (Level 2) instead of full suspension
            $user->restricted_at = now();

            $user->save();

            // 4. Log the security event
            DB::table('security_events')->insert([
                'user_id' => $user->id,
                'event_type' => 'Account Locked',
                'ip_address' => $triggerIp,
                'metadata' => json_encode(['incident_id' => $incidentId, 'reason' => $reason]),
                'created_at' => now(),
            ]);

            // 5. Optionally, here we would freeze active virtual cards via an event/job
            // Event::dispatch(new AccountCompromised($user, $incidentId));

            Log::alert("Security Incident [{$incidentId}] declared for User {$user->id}. Account frozen.");
        });

        return $incidentId;
    }
}

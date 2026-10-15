<?php

namespace App\Domain\Security\Services;

use App\Domain\Risk\Services\RiskEngine;
use App\Domain\Security\DTOs\LoginAssessment;
use App\Models\User;

/**
 * Everything a sign-in decides about the device it came from, for the website
 * and the mobile app alike. The device is registered (or refreshed), the
 * account's very first device is trusted at birth, the sign-in is scored, and
 * the question "does this need the emailed code?" is answered. A sign-in that
 * needs no code proves its device and its country on the spot.
 *
 * Recording the session and sending the code stay with the caller: the website
 * keeps a pending sign-in in its session, the app in a sign-in challenge.
 */
final class LoginRiskEvaluator
{
    public function __construct(
        private readonly DeviceTrustManager $deviceTrustManager,
        private readonly RiskEngine $riskEngine,
        private readonly LoginCountryGuard $loginCountryGuard,
    ) {}

    /**
     * @param  array<string, string|null>  $deviceData  What the client says about itself (device_name, os, device_type)
     */
    public function assess(User $user, string $installationId, string $ipAddress, string $userAgent, array $deviceData = []): LoginAssessment
    {
        $device = $this->deviceTrustManager->registerOrUpdateDevice(
            $user,
            ['installation_id' => $installationId] + array_filter($deviceData),
            $ipAddress,
            $userAgent
        );

        // The account's FIRST device is trusted at birth: it belongs to the
        // person who just created the account (or to the account's owner on
        // the first login after this feature shipped). Without this, a brand
        // new registrant was greeted with "new device detected" and an OTP
        // for the very device they registered on, and the entire existing
        // user base would have been OTP-challenged at rollout.
        if (! $device->isTrusted()
            && $user->trustedDevices()->whereKeyNot($device->id)->doesntExist()) {
            $this->deviceTrustManager->markAsTrusted($device);
            $device->refresh();
        }

        $riskLevel = $this->riskEngine->evaluateLoginRisk($user, $device, $ipAddress);

        // The customer's preference first, then the risk: CRITICAL or HIGH
        // forces the code even for a customer who chose "never".
        $needsOtp = match (true) {
            $user->email_otp_preference === 'always' => true,
            $user->email_otp_preference === 'new_device' && ! $device->isTrusted() => true,
            $riskLevel === 'CRITICAL' || $riskLevel === 'HIGH' => true,
            default => false,
        };

        if (! $needsOtp) {
            // A device that signs in WITHOUT needing verification (preference
            // "never", or low risk) still becomes a known device: trust starts
            // now, and the 24-hour maturity clock with it. Leaving it untrusted
            // forever meant a "never" user could NEVER pass the new-device hold
            // on sensitive actions, on any device, for the life of the account.
            if (! $device->isTrusted()) {
                $this->deviceTrustManager->markAsTrusted($device);
            }

            // Same reasoning one level up: a login that needed no code also
            // proves the country it came from, so the customer's own home
            // country is proved from their very first session.
            $this->loginCountryGuard->markProven($user, $ipAddress);
        }

        return new LoginAssessment($device, $riskLevel, $needsOtp);
    }
}

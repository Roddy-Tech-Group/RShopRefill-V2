<?php

namespace App\Domain\Security\Services;

use App\Domain\Risk\Services\RiskEngine;
use App\Domain\Security\DTOs\LoginRiskOutcome;
use App\Domain\Security\Events\NewDeviceDetected;
use App\Models\TrustedDevice;
use App\Models\User;

/**
 * Decides whether a sign-in has to be verified with an emailed code, and
 * registers the device it came from.
 *
 * Lifted out of EvaluateLoginRiskListener without changing a rule, because the
 * mobile app has to reach the same verdict as the website. The alternative was
 * a second copy of these rules living in an API controller, and the failure mode
 * there is silent: the copies drift, and the one on the weaker side stops asking
 * for codes it should ask for.
 *
 * What stays with the CALLER is everything session-shaped. The website records
 * the pending challenge in the session and redirects; /v2 has no session, so it
 * returns a challenge instead of tokens. Only the decision is shared.
 */
class LoginRiskEvaluator
{
    public function __construct(
        private DeviceTrustManager $deviceTrustManager,
        private RiskEngine $riskEngine,
        private LoginCountryGuard $loginCountryGuard,
    ) {}

    /**
     * Register the device this sign-in came from, score the risk, and decide
     * whether an email code is needed.
     */
    public function evaluate(User $user, string $installationId, string $ipAddress, string $userAgent): LoginRiskOutcome
    {
        $device = $this->deviceTrustManager->registerOrUpdateDevice(
            $user,
            ['installation_id' => $installationId],
            $ipAddress,
            $userAgent,
        );

        // The account's FIRST device is trusted at birth: it belongs to the
        // person who just created the account (or to the account's owner on the
        // first login after this feature shipped). Without this, a brand new
        // registrant was greeted with "new device detected" and an OTP for the
        // very browser they registered on, and the entire existing user base
        // would have been OTP-challenged at rollout.
        if (! $device->isTrusted()
            && $user->trustedDevices()->whereKeyNot($device->id)->doesntExist()) {
            $this->deviceTrustManager->markAsTrusted($device);
            $device->refresh();
        }

        $riskLevel = $this->riskEngine->evaluateLoginRisk($user, $device, $ipAddress);

        $requiresOtp = $this->requiresOtp($user, $device, $riskLevel);

        if (! $requiresOtp) {
            // A device that signs in WITHOUT needing verification (preference
            // "never", or low risk) still becomes a known device: trust starts
            // now, and the 24-hour maturity clock with it. Leaving it untrusted
            // forever meant a "never" user could NEVER pass the new-device hold
            // on sensitive actions, on any device, for the life of the account.
            if (! $device->isTrusted()) {
                $this->deviceTrustManager->markAsTrusted($device);
                $device->refresh();
            }

            // Same reasoning one level up: a login that needed no code also
            // proves the country it came from, so the customer's own home
            // country is proved from their very first session. Without this
            // their first trip abroad would challenge them on the way out and
            // again on the way back.
            $this->loginCountryGuard->markProven($user, $ipAddress);
        }

        return new LoginRiskOutcome($device, $riskLevel, $requiresOtp);
    }

    /**
     * The side effects of a challenge that has just been passed, wherever it was
     * passed: the verification page or the app.
     */
    public function markChallengePassed(User $user, ?int $deviceId, string $ipAddress): void
    {
        // The customer has proved they can receive mail for this account from
        // wherever they are, which is exactly the proof the login country check
        // wants: this country stops being challenged until the proof expires.
        // Holds whatever sent them here, since any challenged login is a login
        // whose country is now vouched for.
        $this->loginCountryGuard->markProven($user, $ipAddress);

        if ($deviceId === null) {
            return;
        }

        $device = TrustedDevice::find($deviceId);

        // A device that is already trusted keeps the trust it has earned. It was
        // asked for a code because this login looked unusual (a country change,
        // or a customer who wants a code every time), not because the device is
        // new. Trusting it again would restart the 24-hour hold on sensitive
        // actions and email the customer about a "new" device they have been
        // using for months.
        if ($device !== null && ! $device->isTrusted()) {
            $this->deviceTrustManager->markAsTrusted($device);

            // Now that it is verified, the "new device signed in" email goes out
            // from the security mailbox.
            NewDeviceDetected::dispatch($device);
        }
    }

    private function requiresOtp(User $user, TrustedDevice $device, string $riskLevel): bool
    {
        if ($user->email_otp_preference === 'always') {
            return true;
        }

        if ($user->email_otp_preference === 'new_device' && ! $device->isTrusted()) {
            return true;
        }

        // Even a customer whose preference is "never" is challenged when the
        // sign-in itself looks bad.
        return $riskLevel === 'CRITICAL' || $riskLevel === 'HIGH';
    }
}

<?php

namespace App\Listeners;

use App\Domain\Risk\Services\RiskEngine;
use App\Domain\Security\Events\OtpRequested;
use App\Domain\Security\Services\DeviceTrustManager;
use App\Domain\Security\Services\LoginCountryGuard;
use App\Domain\Security\Services\SessionSecurityManager;
use App\Support\FeatureFlag;
use Illuminate\Auth\Events\Login;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\Request;
use Illuminate\Support\Str;

class EvaluateLoginRiskListener
{
    public function __construct(
        private DeviceTrustManager $deviceTrustManager,
        private SessionSecurityManager $sessionManager,
        private RiskEngine $riskEngine,
        private LoginCountryGuard $loginCountryGuard,
    ) {}

    public function handle(Login $event): void
    {
        // Avoid running this for admin guard since this is the customer security platform
        if ($event->guard === 'admin') {
            return;
        }

        // Bypasses device verification if an admin is impersonating a customer
        if (Auth::guard('admin')->check()) {
            return;
        }

        if (FeatureFlag::off('device_management')) {
            return;
        }

        $user = $event->user;
        $ipAddress = Request::ip();
        $userAgent = Request::userAgent() ?? 'Unknown';

        // Extract Expo installation ID if present in headers, else check persistent cookie
        $installationId = Request::header('X-Installation-ID', Request::cookie('device_uuid'));

        if (! $installationId) {
            $installationId = (string) Str::uuid();
            // Queue the cookie to persist on this browser for 10 years
            Cookie::queue('device_uuid', $installationId, 60 * 24 * 365 * 10);
        }

        $device = $this->deviceTrustManager->registerOrUpdateDevice(
            $user,
            ['installation_id' => $installationId],
            $ipAddress,
            $userAgent
        );

        // The account's FIRST device is trusted at birth: it belongs to the
        // person who just created the account (or to the account's owner on
        // the first login after this feature shipped). Without this, a brand
        // new registrant was greeted with "new device detected" and an OTP
        // for the very browser they registered on, and the entire existing
        // user base would have been OTP-challenged at rollout.
        if (! $device->isTrusted()
            && $user->trustedDevices()->whereKeyNot($device->id)->doesntExist()) {
            $this->deviceTrustManager->markAsTrusted($device);
            $device->refresh();
        }

        $riskLevel = $this->riskEngine->evaluateLoginRisk($user, $device, $ipAddress);

        // Create the unified session overlay
        $this->sessionManager->createSession($user, $device, session()->getId(), $riskLevel);

        // Based on user preference or risk level, determine if we need to enforce Email OTP
        $needsOtp = false;

        if ($user->email_otp_preference === 'always') {
            $needsOtp = true;
        } elseif ($user->email_otp_preference === 'new_device' && ! $device->isTrusted()) {
            $needsOtp = true;
        } elseif ($riskLevel === 'CRITICAL' || $riskLevel === 'HIGH') {
            // Even if preference is "never", CRITICAL/HIGH risk forces OTP
            $needsOtp = true;
        }

        // A device that signs in WITHOUT needing verification (preference
        // "never", or low risk) still becomes a known device: trust starts
        // now, and the 24-hour maturity clock with it. Leaving it untrusted
        // forever meant a "never" user could NEVER pass the new-device hold
        // on sensitive actions, on any device, for the life of the account.
        if (! $needsOtp && ! $device->isTrusted()) {
            $this->deviceTrustManager->markAsTrusted($device);
        }

        // Same reasoning one level up: a login that needed no code also proves
        // the country it came from, so the customer's own home country is
        // proved from their very first session. Without this their first trip
        // abroad would challenge them on the way out and again on the way back.
        if (! $needsOtp) {
            $this->loginCountryGuard->markProven($user, $ipAddress);
        }

        if ($needsOtp) {
            // Store intermediate state so middleware knows to restrict access
            // until the OTP is verified.
            session()->put('pending_email_otp', true);
            session()->put('pending_device_id', $device->id);

            // Automatically send the first OTP. random_int is a CSPRNG and the
            // (0, 999999) range covers the full six-digit space including 000000.
            $otp = sprintf('%06d', random_int(0, 999999));
            $throttleKey = 'otp_throttle_'.$user->id;
            $attemptsKey = 'otp_attempts_'.$user->id;

            Cache::put('device_otp_'.$user->id, $otp, now()->addMinutes(10));
            Cache::put($throttleKey, now()->addSeconds(60)->timestamp, now()->addSeconds(60));
            Cache::put($attemptsKey, 1, now()->addHours(24));

            // A fresh code gets a fresh guess budget (see DeviceVerificationController::verifyOtp).
            Cache::forget('otp_verify_fails_'.$user->id);

            OtpRequested::dispatch($user, $otp, 'login');
        }
    }
}

<?php

namespace App\Listeners;

use App\Domain\Risk\Services\RiskEngine;
use App\Domain\Security\Events\OtpRequested;
use App\Domain\Security\Services\DeviceTrustManager;
use App\Domain\Security\Services\SessionSecurityManager;
use App\Support\FeatureFlag;
use Illuminate\Auth\Events\Login;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\Request;
use Illuminate\Support\Str;

class EvaluateLoginRiskListener
{
    public function __construct(
        private DeviceTrustManager $deviceTrustManager,
        private SessionSecurityManager $sessionManager,
        private RiskEngine $riskEngine
    ) {}

    public function handle(Login $event): void
    {
        // Avoid running this for admin guard since this is the customer security platform
        if ($event->guard === 'admin') {
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

        if ($needsOtp) {
            // Store intermediate state so middleware knows to restrict access
            // until the OTP is verified.
            session()->put('pending_email_otp', true);
            session()->put('pending_device_id', $device->id);

            // Automatically send the first OTP
            $otp = sprintf('%06d', mt_rand(1, 999999));
            $throttleKey = 'otp_throttle_'.$user->id;
            $attemptsKey = 'otp_attempts_'.$user->id;

            Cache::put('device_otp_'.$user->id, $otp, now()->addMinutes(10));
            Cache::put($throttleKey, now()->addSeconds(60)->timestamp, now()->addSeconds(60));
            Cache::put($attemptsKey, 1, now()->addHours(24));

            OtpRequested::dispatch($user, $otp, 'login');
        }
    }
}

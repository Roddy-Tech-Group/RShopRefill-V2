<?php

namespace App\Listeners;

use App\Domain\Security\Services\DeviceOtpService;
use App\Domain\Security\Services\LoginRiskEvaluator;
use App\Domain\Security\Services\SessionSecurityManager;
use App\Support\FeatureFlag;
use Illuminate\Auth\Events\Login;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\Request;
use Illuminate\Support\Str;

class EvaluateLoginRiskListener
{
    public function __construct(
        private LoginRiskEvaluator $evaluator,
        private SessionSecurityManager $sessionManager,
        private DeviceOtpService $otp,
    ) {}

    public function handle(Login $event): void
    {
        // Avoid running this for admin guard since this is the customer security platform
        if ($event->guard === 'admin') {
            return;
        }

        // The mobile app's sign-in assesses its device itself, before it issues
        // a token, and keeps a pending sign-in in a challenge rather than a
        // session (Api\Mobile\MobileAuthController). Running here as well would
        // assess it twice.
        if ($event->guard === 'sanctum') {
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

        $assessment = $this->evaluator->assess($user, $installationId, $ipAddress, $userAgent);

        // Create the unified session overlay
        $this->sessionManager->createSession($user, $assessment->device, session()->getId(), $assessment->riskLevel);

        if ($assessment->needsOtp) {
            // Store intermediate state so middleware knows to restrict access
            // until the OTP is verified, then send the first code.
            session()->put('pending_email_otp', true);
            session()->put('pending_device_id', $assessment->device->id);

            $this->otp->issue($user);
        }
    }
}

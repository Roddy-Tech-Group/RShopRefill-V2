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

/**
 * The website's half of a sign-in's security handling.
 *
 * The DECISION (register the device, score the risk, decide whether an email
 * code is needed) lives in LoginRiskEvaluator, and the CODE itself in
 * DeviceOtpService, because the mobile API has to reach identical verdicts and
 * two copies of those rules would drift apart silently.
 *
 * What is left here is everything that only makes sense for a browser: the
 * long-lived device cookie, the session overlay, and recording the pending
 * challenge in the session for EnforceDeviceVerification to act on. /v2 is
 * stateless and answers a challenge with a challenge token instead.
 */
class EvaluateLoginRiskListener
{
    public function __construct(
        private SessionSecurityManager $sessionManager,
        private LoginRiskEvaluator $riskEvaluator,
        private DeviceOtpService $otp,
    ) {}

    public function handle(Login $event): void
    {
        // The `sanctum` guard is the mobile API, which does this work itself
        // through LoginRiskEvaluator: it has to answer a challenge with a
        // challenge token rather than with a session flag and a redirect.
        // Running here as well would register the device twice, stamp a session
        // overlay with a session id that does not exist, and email a second code.
        //
        // /v2 still fires the Login event, so the listeners that are NOT about
        // devices (guest cart, account-closure cancellation, and whatever is
        // added later) keep running for the app too.
        if ($event->guard === 'sanctum') {
            return;
        }

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

        $outcome = $this->riskEvaluator->evaluate($user, $installationId, $ipAddress, $userAgent);

        // Create the unified session overlay
        $this->sessionManager->createSession(
            $user,
            $outcome->device,
            session()->getId(),
            $outcome->riskLevel,
        );

        if ($outcome->requiresOtp) {
            // Store intermediate state so middleware knows to restrict access
            // until the OTP is verified.
            session()->put('pending_email_otp', true);
            session()->put('pending_device_id', $outcome->device->id);

            $this->otp->issueForLogin($user);
        }
    }
}

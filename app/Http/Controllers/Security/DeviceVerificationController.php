<?php

namespace App\Http\Controllers\Security;

use App\Domain\Security\Enums\OtpCheck;
use App\Domain\Security\Enums\OtpResend;
use App\Domain\Security\Services\DeviceOtpService;
use App\Domain\Security\Services\DeviceTrustManager;
use App\Domain\Security\Services\LoginCountryGuard;
use App\Domain\Security\Services\SecurityIncidentManager;
use App\Http\Controllers\Controller;
use App\Models\TrustedDevice;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class DeviceVerificationController extends Controller
{
    /**
     * How long a code stays valid. The rules for codes live in
     * DeviceOtpService, which the mobile app's sign-in shares.
     */
    public const OTP_TTL_MINUTES = DeviceOtpService::TTL_MINUTES;

    public function __construct(
        private DeviceTrustManager $trustManager,
        private SecurityIncidentManager $incidentManager,
        private LoginCountryGuard $loginCountryGuard,
        private DeviceOtpService $otp,
    ) {}

    public function show(Request $request)
    {
        if (! session('pending_email_otp')) {
            return redirect()->route('dashboard');
        }

        $user = Auth::user();
        $cooldown = $this->otp->secondsUntilResend($user);
        $expiresIn = $this->otp->secondsUntilExpiry($user);
        $maskedEmail = DeviceOtpService::maskEmail((string) $user->email);

        return response()
            ->view('auth.verify-device', compact('cooldown', 'expiresIn', 'maskedEmail'))
            ->header('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0')
            ->header('Pragma', 'no-cache')
            ->header('Expires', 'Sat, 01 Jan 2000 00:00:00 GMT');
    }

    public function sendOtp(Request $request)
    {
        if (! session('pending_email_otp')) {
            return back()->withErrors(['error' => 'No pending verification']);
        }

        return match ($this->otp->resend(Auth::user())) {
            OtpResend::TooSoon => back()->withErrors(['resend' => 'Please wait before requesting another code.']),
            OtpResend::Exhausted => back()->withErrors(['resend' => 'Too many attempts. Please contact support.']),
            OtpResend::Sent => back()->with('status', 'A new verification code has been sent to your email.'),
        };
    }

    public function verifyOtp(Request $request)
    {
        $request->validate(['otp' => 'required|string|size:6']);

        if (! session('pending_email_otp')) {
            return redirect()->route('dashboard');
        }

        $user = Auth::user();

        $check = $this->otp->check($user, (string) $request->otp);

        if ($check === OtpCheck::Burned) {
            return back()->withErrors(['otp' => 'Too many incorrect attempts. Please request a new code.']);
        }

        if ($check === OtpCheck::Invalid) {
            return back()->withErrors(['otp' => 'Invalid or expired code.']);
        }

        session()->forget('pending_email_otp');

        // The customer has proved they can receive mail for this account from
        // wherever they are, which is exactly the proof the login country check
        // wants: this country stops being challenged until the proof expires.
        // Holds whatever sent them here, since any challenged login is a login
        // whose country is now vouched for.
        $this->loginCountryGuard->markProven($user, $request->ip());

        $deviceId = session()->pull('pending_device_id');
        $device = $deviceId ? TrustedDevice::find($deviceId) : null;

        if ($device) {
            $this->trustManager->trustAfterVerification($device);
        }

        return redirect()->intended(route('dashboard'));
    }

    /**
     * NO IT WASN'T ME flow. Authenticity and expiry of the link are enforced
     * by the `signed` middleware on the route, so a request that reaches
     * here carries a valid, unexpired signature over the device id.
     */
    public function handleCompromise(Request $request)
    {
        $device = TrustedDevice::findOrFail($request->integer('device'));
        $user = $device->user;

        $incidentId = $this->incidentManager->handleCompromisedAccount(
            $user,
            $request->ip(),
            "User flagged unrecognized device: {$device->os} / {$device->browser}"
        );

        // Simple acknowledgement view
        return view('auth.compromise-acknowledged', ['incidentId' => $incidentId]);
    }
}

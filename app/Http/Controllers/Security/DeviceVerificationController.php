<?php

namespace App\Http\Controllers\Security;

use App\Domain\Security\Enums\OtpDispatchResult;
use App\Domain\Security\Enums\OtpVerificationResult;
use App\Domain\Security\Services\DeviceOtpService;
use App\Domain\Security\Services\LoginRiskEvaluator;
use App\Domain\Security\Services\SecurityIncidentManager;
use App\Http\Controllers\Controller;
use App\Models\TrustedDevice;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * The browser's device-verification page.
 *
 * The code's own behaviour (lifetime, escalating resend cooldown, guess budget,
 * constant-time compare) lives in DeviceOtpService, and the side effects of
 * passing a challenge in LoginRiskEvaluator, because the mobile app performs the
 * same challenge and a second copy of those rules would drift.
 *
 * What remains here is the browser's half: the session flag that gates the page,
 * the view, and the flash messages.
 */
class DeviceVerificationController extends Controller
{
    /**
     * Kept as an alias so callers and tests that already reference it keep
     * working. The number itself lives with the code.
     */
    public const OTP_TTL_MINUTES = DeviceOtpService::TTL_MINUTES;

    public function __construct(
        private SecurityIncidentManager $incidentManager,
        private DeviceOtpService $otp,
        private LoginRiskEvaluator $riskEvaluator,
    ) {}

    public function show(Request $request)
    {
        if (! session('pending_email_otp')) {
            return redirect()->route('dashboard');
        }

        $user = Auth::user();

        $cooldown = $this->otp->cooldownSeconds($user);
        $expiresIn = $this->otp->codeExpiresInSeconds($user);
        $maskedEmail = DeviceOtpService::maskedEmail((string) $user->email);

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
            OtpDispatchResult::CooldownActive => back()->withErrors([
                'resend' => 'Please wait before requesting another code.',
            ]),
            OtpDispatchResult::TooManyRequests => back()->withErrors([
                'resend' => 'Too many attempts. Please contact support.',
            ]),
            OtpDispatchResult::Sent => back()->with(
                'status',
                'A new verification code has been sent to your email.',
            ),
        };
    }

    public function verifyOtp(Request $request)
    {
        $request->validate(['otp' => 'required|string|size:6']);

        if (! session('pending_email_otp')) {
            return redirect()->route('dashboard');
        }

        $user = Auth::user();

        $result = $this->otp->verify($user, (string) $request->otp);

        if ($result === OtpVerificationResult::Burned) {
            return back()->withErrors([
                'otp' => 'Too many incorrect attempts. Please request a new code.',
            ]);
        }

        if ($result === OtpVerificationResult::Invalid) {
            return back()->withErrors(['otp' => 'Invalid or expired code.']);
        }

        session()->forget('pending_email_otp');

        $this->riskEvaluator->markChallengePassed(
            $user,
            session()->pull('pending_device_id'),
            $request->ip(),
        );

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

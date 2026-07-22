<?php

namespace App\Http\Controllers\Security;

use App\Domain\Security\Events\NewDeviceDetected;
use App\Domain\Security\Events\OtpRequested;
use App\Domain\Security\Services\DeviceTrustManager;
use App\Domain\Security\Services\SecurityIncidentManager;
use App\Http\Controllers\Controller;
use App\Models\TrustedDevice;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;

class DeviceVerificationController extends Controller
{
    public function __construct(
        private DeviceTrustManager $trustManager,
        private SecurityIncidentManager $incidentManager
    ) {}

    public function show(Request $request)
    {
        if (! session('pending_email_otp')) {
            return redirect()->route('dashboard');
        }

        $user = Auth::user();
        $throttleKey = 'otp_throttle_'.$user->id;
        $expiresAt = Cache::get($throttleKey, 0);

        // Ensure it's a timestamp; if it was previously stored as a boolean, this handles it gracefully.
        $cooldown = is_numeric($expiresAt) ? max(0, $expiresAt - time()) : 0;

        return response()
            ->view('auth.verify-device', compact('cooldown'))
            ->header('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0')
            ->header('Pragma', 'no-cache')
            ->header('Expires', 'Sat, 01 Jan 2000 00:00:00 GMT');
    }

    public function sendOtp(Request $request)
    {
        if (! session('pending_email_otp')) {
            return back()->withErrors(['error' => 'No pending verification']);
        }

        $user = Auth::user();

        $throttleKey = 'otp_throttle_'.$user->id;
        $attemptsKey = 'otp_attempts_'.$user->id;

        // Anti-spam with a 3-second grace period for frontend timer drift
        if (Cache::has($throttleKey)) {
            $val = Cache::get($throttleKey);
            if ($val === true || $val === 1 || $val < 1000) {
                Cache::forget($throttleKey);
            } else {
                $remaining = max(0, (int) $val - time());
                if ($remaining <= 3) {
                    Cache::forget($throttleKey);
                } else {
                    return back()->withErrors(['resend' => 'Please wait before requesting another code.']);
                }
            }
        }

        $attempts = Cache::get($attemptsKey, 0) + 1;

        $delays = [
            1 => 60,       // 1st request: 1 min
            2 => 180,      // 2nd request: 3 mins
            3 => 300,      // 3rd request: 5 mins
            4 => 1800,     // 4th request: 30 mins
            5 => 3600,     // 5th request: 1 hour
        ];

        if ($attempts > 5) {
            return back()->withErrors(['resend' => 'Too many attempts. Please contact support.']);
        }

        $delay = $delays[$attempts];

        $otp = sprintf('%06d', mt_rand(1, 999999));

        Cache::put('device_otp_'.$user->id, $otp, now()->addMinutes(10));
        Cache::put($throttleKey, now()->addSeconds($delay)->timestamp, now()->addSeconds($delay));
        Cache::put($attemptsKey, $attempts, now()->addHours(24));

        OtpRequested::dispatch($user, $otp, 'login');

        return back()->with('status', 'A new verification code has been sent to your email.');
    }

    public function verifyOtp(Request $request)
    {
        $request->validate(['otp' => 'required|string|size:6']);

        if (! session('pending_email_otp')) {
            return redirect()->route('dashboard');
        }

        $user = Auth::user();
        $storedOtp = Cache::get('device_otp_'.$user->id);

        if (! $storedOtp || $storedOtp !== $request->otp) {
            return back()->withErrors(['otp' => 'Invalid or expired code.']);
        }

        // OTP verified successfully
        Cache::forget('device_otp_'.$user->id);
        Cache::forget('otp_throttle_'.$user->id);
        Cache::forget('otp_attempts_'.$user->id);
        session()->forget('pending_email_otp');

        $deviceId = session()->pull('pending_device_id');
        if ($deviceId) {
            $device = TrustedDevice::find($deviceId);
            if ($device) {
                $this->trustManager->markAsTrusted($device);

                // Now that it's verified, send the "New device logged in" email
                // Dispatch NewDeviceDetected event so CommunicationManager sends from security@ mailbox
                NewDeviceDetected::dispatch($device);
            }
        }

        return redirect()->intended(route('dashboard'));
    }

    public function handleCompromise(Request $request)
    {
        // NO IT WASN'T ME Flow
        $deviceId = $request->get('device');
        $token = $request->get('token');

        if (! $deviceId || ! $token) {
            abort(400, 'Invalid compromise link.');
        }

        $expectedToken = hash_hmac('sha256', $deviceId, config('app.key'));
        if (! hash_equals($expectedToken, $token)) {
            abort(403, 'Invalid or expired compromise link.');
        }

        $device = TrustedDevice::findOrFail($deviceId);
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

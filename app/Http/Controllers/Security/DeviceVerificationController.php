<?php

namespace App\Http\Controllers\Security;

use App\Domain\Security\Events\NewDeviceDetected;
use App\Domain\Security\Events\OtpRequested;
use App\Domain\Security\Services\DeviceTrustManager;
use App\Domain\Security\Services\LoginCountryGuard;
use App\Domain\Security\Services\SecurityIncidentManager;
use App\Http\Controllers\Controller;
use App\Models\TrustedDevice;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;

class DeviceVerificationController extends Controller
{
    /**
     * How long a code stays valid. The page's "expires in" clock, the guess
     * budget window and the cache lifetime all read this one number, so the
     * customer is never told a time the server does not enforce.
     */
    public const OTP_TTL_MINUTES = 10;

    public function __construct(
        private DeviceTrustManager $trustManager,
        private SecurityIncidentManager $incidentManager,
        private LoginCountryGuard $loginCountryGuard,
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

        // The code's real remaining life, stamped when it was issued. Null when
        // nothing stamped it (a code issued before this key existed): the page
        // then shows no clock rather than a made-up one.
        $issuedExpiry = Cache::get('device_otp_expires_'.$user->id);
        $expiresIn = is_numeric($issuedExpiry) ? max(0, (int) $issuedExpiry - time()) : null;

        $maskedEmail = $this->maskedEmail((string) $user->email);

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

        // random_int is a CSPRNG (mt_rand is not) and the (0, 999999) range
        // covers the full six-digit space including 000000, which mt_rand(1, ...)
        // could never produce.
        $otp = sprintf('%06d', random_int(0, 999999));

        $expiresAt = now()->addMinutes(self::OTP_TTL_MINUTES);
        Cache::put('device_otp_'.$user->id, $otp, $expiresAt);
        // Stamped beside the code so the page can show the real time left.
        Cache::put('device_otp_expires_'.$user->id, $expiresAt->timestamp, $expiresAt);
        Cache::put($throttleKey, now()->addSeconds($delay)->timestamp, now()->addSeconds($delay));
        Cache::put($attemptsKey, $attempts, now()->addHours(24));

        // A fresh code gets a fresh guess budget (see verifyOtp).
        Cache::forget('otp_verify_fails_'.$user->id);

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
        $failsKey = 'otp_verify_fails_'.$user->id;

        // hash_equals is constant-time; a plain !== leaks how many leading
        // digits matched via response timing. Compare as strings so a numeric
        // cache value and the string input still line up.
        if (! $storedOtp || ! hash_equals((string) $storedOtp, (string) $request->otp)) {
            // Cap wrong guesses so the six-digit code cannot be brute-forced
            // inside its 10-minute life. After five misses the code is burned
            // and the user must request a new one.
            $fails = (int) Cache::get($failsKey, 0) + 1;

            if ($fails >= 5) {
                Cache::forget('device_otp_'.$user->id);
                Cache::forget('device_otp_expires_'.$user->id);
                Cache::forget($failsKey);

                return back()->withErrors(['otp' => 'Too many incorrect attempts. Please request a new code.']);
            }

            Cache::put($failsKey, $fails, now()->addMinutes(self::OTP_TTL_MINUTES));

            return back()->withErrors(['otp' => 'Invalid or expired code.']);
        }

        // OTP verified successfully
        Cache::forget('device_otp_'.$user->id);
        Cache::forget('device_otp_expires_'.$user->id);
        Cache::forget('otp_throttle_'.$user->id);
        Cache::forget('otp_attempts_'.$user->id);
        Cache::forget($failsKey);
        session()->forget('pending_email_otp');

        // The customer has proved they can receive mail for this account from
        // wherever they are, which is exactly the proof the login country check
        // wants: this country stops being challenged until the proof expires.
        // Holds whatever sent them here, since any challenged login is a login
        // whose country is now vouched for.
        $this->loginCountryGuard->markProven($user, $request->ip());

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

    /**
     * The address the code went to, with the local part hidden past its first
     * two characters. The page is reachable from a device that is not yet
     * trusted, so it confirms where to look without printing the address.
     */
    private function maskedEmail(string $email): string
    {
        [$local, $domain] = array_pad(explode('@', $email, 2), 2, '');
        $keep = min(2, strlen($local));

        return substr($local, 0, $keep).str_repeat('*', max(1, strlen($local) - $keep)).'@'.$domain;
    }
}

<?php

namespace App\Domain\Admin\Services;

use App\Mail\AdminTwoFactorOtpMail;
use App\Models\Admin;
use App\Support\FeatureFlag;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Handles admin authentication business logic.
 *
 * Keeps the controller thin — all credential validation,
 * rate limiting, session management, and login recording
 * logic lives here.
 */
class AdminAuthService
{
    /**
     * Maximum failed login attempts before throttling kicks in.
     */
    private const MAX_ATTEMPTS = 5;

    /**
     * Throttle decay time in seconds (2 minutes).
     */
    private const DECAY_SECONDS = 120;

    public function __construct(
        private readonly AdminTwoFactorService $twoFactorService
    ) {}

    /**
     * Attempt to authenticate an admin up to the 2FA step.
     *
     * @throws ValidationException
     */
    public function authenticate(string $email, string $password, bool $remember = false): void
    {
        $this->ensureIsNotRateLimited($email);

        if (! Auth::guard('admin')->validate(['email' => $email, 'password' => $password])) {
            $this->hitRateLimiters($email);

            Log::warning('Admin login failed', ['email' => $email]);

            throw ValidationException::withMessages([
                'email' => __('auth.failed'),
            ]);
        }

        /** @var Admin $admin */
        $admin = Admin::where('email', $email)->first();

        // Verify the admin account is active
        if (! $admin->isActive()) {
            Log::warning('Deactivated admin attempted login', [
                'admin_id' => $admin->id,
                'email' => $admin->email,
            ]);

            throw ValidationException::withMessages([
                'email' => __('Your admin account has been deactivated. Contact a super administrator.'),
            ]);
        }

        RateLimiter::clear('admin-login-ip:' . request()->ip());
        RateLimiter::clear('admin-login-email:' . Str::transliterate(Str::lower($email)));

        // Put the admin ID in the session for the 2FA challenge
        session([
            'admin_2fa_id' => $admin->id,
            'admin_2fa_remember' => $remember,
        ]);

        if ($admin->security_question && FeatureFlag::on('admin_security_question')) {
            session(['admin_needs_security_question' => true]);
            session(['admin_needs_2fa' => true]);
        } else {
            session(['admin_needs_2fa' => true]);
        }

        // If the admin doesn't have TOTP enabled, send an Email OTP
        if (! $admin->hasTotpEnabled()) {
            $otp = $this->twoFactorService->generateAndSaveEmailOtp($admin);
            Mail::to($admin->email)->send(new AdminTwoFactorOtpMail($admin, $otp));
        }
    }

    /**
     * Log the admin out and invalidate the session.
     */
    public function logout(): void
    {
        $admin = Auth::guard('admin')->user();

        Auth::guard('admin')->logout();

        // Invalidate the session and regenerate the CSRF token
        session()->invalidate();
        session()->regenerateToken();

        if ($admin) {
            Log::info('Admin logged out', [
                'admin_id' => $admin->id,
                'email' => $admin->email,
            ]);
        }
    }

    /**
     * Ensure the login request is not rate limited (Dual-throttle approach).
     *
     * @throws ValidationException
     */
    private function ensureIsNotRateLimited(string $email): void
    {
        $ipKey = 'admin-login-ip:' . request()->ip();
        $emailKey = 'admin-login-email:' . Str::transliterate(Str::lower($email));

        // Progressive delays based on failure count
        $maxIpAttempts = 10;
        $maxEmailAttempts = 3;

        if (RateLimiter::tooManyAttempts($ipKey, $maxIpAttempts)) {
            $this->throwThrottleException($ipKey, 'IP Address locked due to excessive failed attempts.');
        }

        if (RateLimiter::tooManyAttempts($emailKey, $maxEmailAttempts)) {
            // Require Cloudflare Turnstile if email is heavily targeted
            if (!request()->has('cf-turnstile-response')) {
                throw ValidationException::withMessages([
                    'email' => __('Additional security verification required. Please solve the CAPTCHA.'),
                ]);
            }
            // Trigger Turnstile verification...
            
            $this->throwThrottleException($emailKey, 'Account locked temporarily. Please try again later.');
        }

        // Calculate basic Risk Score (example placeholder for Phase 7)
        $riskScore = 0;
        if (!request()->header('User-Agent')) $riskScore += 50;
        // Device fingerprinting could be injected here
        
        if ($riskScore > 80) {
            Log::warning('High-risk admin login blocked', ['email' => $email, 'ip' => request()->ip()]);
            throw ValidationException::withMessages([
                'email' => __('Login request denied by security policy.'),
            ]);
        }
    }

    private function throwThrottleException(string $key, string $message): void
    {
        $seconds = RateLimiter::availableIn($key);
        Log::warning('Admin login rate limited', ['key' => $key, 'seconds' => $seconds]);

        throw ValidationException::withMessages([
            'email' => __($message . ' Try again in :seconds seconds.', ['seconds' => $seconds]),
        ]);
    }

    /**
     * Hit the rate limiters.
     */
    private function hitRateLimiters(string $email): void
    {
        $ipKey = 'admin-login-ip:' . request()->ip();
        $emailKey = 'admin-login-email:' . Str::transliterate(Str::lower($email));

        RateLimiter::hit($ipKey, 300); // IP decay is 5 minutes
        RateLimiter::hit($emailKey, 300); // Email decay is 5 minutes
    }
}

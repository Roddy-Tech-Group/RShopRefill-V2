<?php

namespace App\Http\Controllers\Admin\Auth;

use App\Domain\Admin\Services\AdminTwoFactorService;
use App\Domain\Communication\Enums\CommunicationCategory;
use App\Domain\Communication\Mail\System\AdminTwoFactorOtpMail;
use App\Domain\Communication\Services\CommunicationManager;
use App\Http\Controllers\Controller;
use App\Models\Admin;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

class AdminTwoFactorController extends Controller
{
    public function __construct(
        private readonly AdminTwoFactorService $twoFactorService
    ) {}

    public function create(Request $request)
    {
        if (! $request->session()->has('admin_2fa_id')) {
            return redirect()->route('admin.login');
        }

        $adminId = $request->session()->get('admin_2fa_id');
        $admin = Admin::find($adminId);

        if (! $admin || ! $admin->isActive()) {
            $request->session()->forget(['admin_2fa_id', 'admin_2fa_remember']);

            return redirect()->route('admin.login');
        }

        $cooldownKey = "admin_2fa_resend_cooldown_{$admin->id}";
        $remainingCooldown = 0;
        if (Cache::has($cooldownKey)) {
            $remainingCooldown = max(0, Cache::get($cooldownKey) - now()->timestamp);
        }

        return view('admin.auth.2fa-challenge', [
            'hasTotp' => $admin->hasTotpEnabled(),
            'remainingCooldown' => $remainingCooldown,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $request->validate([
            'code' => ['required', 'string'],
        ]);

        if (! $request->session()->has('admin_2fa_id')) {
            return redirect()->route('admin.login');
        }

        $adminId = $request->session()->get('admin_2fa_id');
        $admin = Admin::find($adminId);
        $code = $request->code;

        if (! $admin || ! $admin->isActive()) {
            $request->session()->forget(['admin_2fa_id', 'admin_2fa_remember']);

            return redirect()->route('admin.login');
        }

        // Five wrong codes end the challenge: the emailed code is burned and
        // the admin must sign in again for a fresh one. A six-digit code that
        // lives ten minutes is guessable online without this.
        $attemptKey = "admin-2fa:{$admin->id}";

        if (RateLimiter::tooManyAttempts($attemptKey, 5)) {
            $this->twoFactorService->clearEmailOtp($admin);
            $request->session()->forget(['admin_2fa_id', 'admin_2fa_remember']);
            RateLimiter::clear($attemptKey);

            Log::warning('Admin 2FA challenge abandoned after too many wrong codes', ['admin_id' => $admin->id]);

            return redirect()->route('admin.login')
                ->withErrors(['email' => __('Too many incorrect codes. Sign in again to receive a new one.')]);
        }

        $isValid = false;

        if ($admin->hasTotpEnabled()) {
            $isValid = $this->twoFactorService->verifyTotp($admin->two_factor_secret, $code);
        }

        // If TOTP wasn't enabled, or if they used the email code instead
        if (! $isValid) {
            $isValid = $this->twoFactorService->verifyEmailOtp($admin, $code);
        }

        if (! $isValid) {
            RateLimiter::hit($attemptKey, 600);

            throw ValidationException::withMessages([
                'code' => __('The provided code is invalid or expired.'),
            ]);
        }

        RateLimiter::clear($attemptKey);

        // Clear OTP since it's used
        $this->twoFactorService->clearEmailOtp($admin);

        // Login
        $remember = $request->session()->get('admin_2fa_remember', false);
        Auth::guard('admin')->login($admin, $remember);

        // Regenerate session to prevent fixation attacks
        $request->session()->regenerate();
        $request->session()->forget(['admin_2fa_id', 'admin_2fa_remember']);

        // Clear resend rate limit cache
        Cache::forget("admin_2fa_resend_attempts_{$admin->id}");
        Cache::forget("admin_2fa_resend_cooldown_{$admin->id}");

        // Track login timestamp
        $admin->recordLogin();

        Log::info('Admin logged in (2FA passed)', [
            'admin_id' => $admin->id,
            'email' => $admin->email,
        ]);

        $defaultRoute = $admin->canAccessAdminRoute('dashboard')
            ? route('admin.dashboard', absolute: false)
            : ($admin->canAccessAdminRoute('operations')
                ? route('admin.operations', absolute: false)
                : route('admin.account', absolute: false));

        return redirect()->intended($defaultRoute);
    }

    public function resend(Request $request): RedirectResponse
    {
        if (! $request->session()->has('admin_2fa_id')) {
            return redirect()->route('admin.login');
        }

        $adminId = $request->session()->get('admin_2fa_id');
        $admin = Admin::find($adminId);

        if (! $admin || ! $admin->isActive()) {
            $request->session()->forget(['admin_2fa_id', 'admin_2fa_remember']);

            return redirect()->route('admin.login');
        }

        if ($admin->hasTotpEnabled()) {
            return back()->with('status', 'Please use your Authenticator app.');
        }

        $cooldownKey = "admin_2fa_resend_cooldown_{$admin->id}";
        $attemptsKey = "admin_2fa_resend_attempts_{$admin->id}";

        if (Cache::has($cooldownKey)) {
            $remaining = Cache::get($cooldownKey) - now()->timestamp;
            if ($remaining > 0) {
                return back();
            }
        }

        if (! Cache::has($attemptsKey)) {
            Cache::put($attemptsKey, 1, now()->addHours(1));
            $attempts = 1;
        } else {
            $attempts = Cache::increment($attemptsKey);
        }

        $delaySeconds = match (true) {
            $attempts === 1 => 30,
            $attempts === 2 => 60,
            $attempts === 3 => 300,
            default => 1800, // 30 mins
        };

        if ($attempts >= 4) {
            return back()->withErrors(['code' => 'Too many attempts. Please wait 30 minutes or contact a super admin.']);
        }

        Cache::put($cooldownKey, now()->addSeconds($delaySeconds)->timestamp, now()->addSeconds($delaySeconds + 10));

        // Generate and send new OTP
        $otp = $this->twoFactorService->generateAndSaveEmailOtp($admin);

        app(CommunicationManager::class)->send(
            recipient: $admin,
            category: CommunicationCategory::TwoFactor,
            title: 'Your Admin 2FA Code',
            message: "Your admin 2FA code is {$otp}.",
            mailable: new AdminTwoFactorOtpMail($admin, $otp),
            correlationId: 'admin_2fa_'.$admin->id.'_'.time()
        );

        return back()->with('status', 'A new code has been sent to your email.');
    }
}

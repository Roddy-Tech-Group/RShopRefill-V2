<?php

namespace App\Http\Controllers\Admin\Auth;

use App\Domain\Admin\Services\AdminTwoFactorService;
use App\Http\Controllers\Controller;
use App\Mail\AdminTwoFactorOtpMail;
use App\Models\Admin;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
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

        $isValid = false;

        if ($admin->hasTotpEnabled()) {
            $isValid = $this->twoFactorService->verifyTotp($admin->two_factor_secret, $code);
        }

        // If TOTP wasn't enabled, or if they used the email code instead
        if (! $isValid) {
            $isValid = $this->twoFactorService->verifyEmailOtp($admin, $code);
        }

        if (! $isValid) {
            throw ValidationException::withMessages([
                'code' => __('The provided code is invalid or expired.'),
            ]);
        }

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
            : route('admin.account', absolute: false);

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
        Mail::to($admin->email)->send(new AdminTwoFactorOtpMail($admin, $otp));

        return back()->with('status', 'A new code has been sent to your email.');
    }
}

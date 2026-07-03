{{-- ─────────────────────────────────────────────────────────────────────
     Global auth modal. Mounted once at body root via the storefront layout
     so any "Log in" or "Sign up" trigger can pop it open without a redirect.

     Public API (Alpine window events):
       open-auth-modal { mode: 'login' | 'register' }
       close-auth-modal

     Mode animation:
       login    → slides in from the right
       register → slides in from the left

     Forgot-password + reset still use the standalone /forgot-password and
     /reset-password routes - low-frequency flows that don't justify another
     view inside the modal.
───────────────────────────────────────────────────────────────────────── --}}
<?php

use App\Domain\Security\Services\TurnstileService;
use App\Http\Middleware\CaptureReferralCookie;
use App\Models\NewsletterSubscriber;
use App\Models\Referral;
use App\Models\User;
use App\Support\TaggedCache;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Auth\Events\Registered;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Validate;
use Livewire\Volt\Component;

new class extends Component {
    // Login form fields
    #[Validate('required|string|email')]
    public string $loginEmail = '';
    #[Validate('required|string')]
    public string $loginPassword = '';
    public bool $loginRemember = false;

    // Register form fields
    public string $regName = '';
    public string $regEmail = '';
    public string $regPassword = '';
    public string $regPasswordConfirmation = '';
    public string $regGender = '';
    public bool $regAcceptedTerms = false;
    public bool $regNewsletterOptIn = false;
    public string $regReferral = '';

    public ?string $turnstileToken = null;

    /**
     * Prefill the referral code from the ?ref cookie so a customer who arrived
     * through a friend's invite link sees it filled in (still editable).
     */
    public function mount(): void
    {
        $this->regReferral = trim((string) request()->cookie(CaptureReferralCookie::COOKIE_NAME, ''));
    }

    public function login(): void
    {
        $this->validateTurnstile();

        $this->validate([
            'loginEmail' => 'required|string|email',
            'loginPassword' => 'required|string',
        ]);

        $this->ensureIsNotRateLimited();

        if (! Auth::attempt(['email' => $this->loginEmail, 'password' => $this->loginPassword], $this->loginRemember)) {
            RateLimiter::hit($this->throttleKey());
            throw ValidationException::withMessages(['loginEmail' => __('auth.failed')]);
        }

        RateLimiter::clear($this->throttleKey());
        Session::regenerate();
        $this->redirectIntended(default: route('dashboard', absolute: false), navigate: true);
    }

    public function register(): void
    {
        // Server-side gate on the signup feature flag - blocks API replays
        // even if the front-end Register tab has been hidden by features.signup_enabled.
        if (! \App\Support\FeatureFlag::on('signup')) {
            throw ValidationException::withMessages(['regEmail' => 'New registrations are temporarily closed.']);
        }

        // Validate up-front; on failure tell the wizard which step holds the
        // first error so it can slide back to it (the user submits from step 3
        // but an email / password error lives on an earlier step).
        try {
            $this->validateTurnstile();

            $validated = $this->validate([
                'regName' => ['required', 'string', 'max:255'],
                'regEmail' => ['required', 'string', 'lowercase', 'email', 'max:255', 'unique:'.User::class.',email'],
                'regPassword' => ['required', 'string', 'confirmed:regPasswordConfirmation', Rules\Password::defaults()],
                'regGender' => ['required', 'in:male,female,other'],
                'regAcceptedTerms' => ['accepted'],
            ]);
        } catch (ValidationException $e) {
            $this->dispatch('registration-error', step: $this->firstErrorStep(array_keys($e->errors())));

            throw $e;
        }

        // Gender drives the default avatar (resources/views/livewire/settings/profile.blade.php
        // matches on this field to pick male/female PNG). Collecting it at
        // signup gives every new user the correct default until they upload.
        $user = User::create([
            'name' => $validated['regName'],
            'email' => $validated['regEmail'],
            'password' => Hash::make($validated['regPassword']),
            'gender' => $validated['regGender'],
        ]);

        // Fire Registered (which sends the email-verification mail). We
        // never want the verification mail's transport to fail the whole
        // signup - the user is already saved, they can verify later from
        // the dashboard banner. Log + continue if Resend / SMTP errors.
        try {
            event(new Registered($user));
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('Verification email send failed during signup', [
                'user_id' => $user->id,
                'email'   => $user->email,
                'error'   => $e->getMessage(),
            ]);
        }

        // Newsletter opt-in. The checkbox in the modal feeds the same list the
        // admin Newsletter page broadcasts to. firstOrCreate keeps it idempotent
        // (e.g. the email was previously subscribed via the footer form). If it
        // was unsubscribed, re-activate so the user's explicit signup wins.
        if ($this->regNewsletterOptIn) {
            $sub = NewsletterSubscriber::firstOrCreate(
                ['email' => $user->email],
                ['status' => 'active', 'subscribed_at' => now(), 'source' => 'signup_modal'],
            );
            if ($sub->status !== 'active') {
                $sub->update(['status' => 'active', 'subscribed_at' => now(), 'unsubscribed_at' => null]);
            }
        }

        // Referral attribution: prefer the code typed in the modal, otherwise
        // fall back to the ?ref cookie. Referral::attribute matches it to a
        // referrer and silently no-ops on blank / no-match / self-referral.
        $referralCode = $this->regReferral !== ''
            ? $this->regReferral
            : (string) request()->cookie(CaptureReferralCookie::COOKIE_NAME, '');
        Referral::attribute($user, $referralCode);
        Cookie::queue(Cookie::forget(CaptureReferralCookie::COOKIE_NAME));

        Auth::login($user);
        $this->redirect(route('dashboard', absolute: false), navigate: true);
    }

    /**
     * Map the first failing field to its wizard step (1-3) so the modal can
     * slide back to where the user needs to fix something.
     *
     * @param  array<int, string>  $fields
     */
    private function firstErrorStep(array $fields): int
    {
        $step = 3;

        foreach ($fields as $field) {
            if (in_array($field, ['regName', 'regEmail'], true)) {
                $step = min($step, 1);
            } elseif (in_array($field, ['regPassword', 'regPasswordConfirmation', 'regGender'], true)) {
                $step = min($step, 2);
            }
        }

        return $step;
    }

    protected function ensureIsNotRateLimited(): void
    {
        if (! RateLimiter::tooManyAttempts($this->throttleKey(), 5)) {
            return;
        }
        event(new Lockout(request()));
        $seconds = RateLimiter::availableIn($this->throttleKey());
        throw ValidationException::withMessages([
            'loginEmail' => __('auth.throttle', ['seconds' => $seconds, 'minutes' => ceil($seconds / 60)]),
        ]);
    }

    protected function throttleKey(): string
    {
        return Str::transliterate(Str::lower($this->loginEmail).'|'.request()->ip());
    }

    /**
     * Validate the Cloudflare Turnstile token attached to this submission.
     * No-ops when TURNSTILE_ENABLED=false or TURNSTILE_ENFORCE_AUTH=false,
     * so dev environments don't need the widget to test signup/login.
     * Token comes from the cf-turnstile widget on the form via a JS callback
     * that calls @this.set('turnstileToken', token).
     */
    protected function validateTurnstile(): void
    {
        if (! config('services.turnstile.enabled')) {
            return;
        }
        if (! config('services.turnstile.enforce_auth', true)) {
            return;
        }

        $service = TurnstileService::make();
        $result  = $service->validateToken($this->turnstileToken, request()->ip());

        if (in_array($result['status'] ?? null, [TurnstileService::STATUS_SUCCESS, TurnstileService::STATUS_BYPASSED], true)) {
            return;
        }
        if (($result['status'] ?? null) === TurnstileService::STATUS_TIMEOUT) {
            throw ValidationException::withMessages(['turnstileToken' => 'Security check is temporarily unavailable. Please try again.']);
        }

        throw ValidationException::withMessages(['turnstileToken' => 'Security verification failed. Please refresh and try again.']);
    }
}; ?>

<div
    wire:ignore.self
    x-data="{
        open: false,
        mode: 'login',
        step: 1,
        turnstileWidgets: { login: null, register: null },
        showOpen(mode) {
            this.mode = (mode === 'register') ? 'register' : 'login';
            this.step = 1;
            this.open = true;
            window.rshopScrollLock?.lock();
            this.$nextTick(() => this.renderTurnstile(this.mode));
        },
        close() {
            this.open = false;
            window.rshopScrollLock?.unlock();
            this.resetTurnstile();
            // Strip the ?auth= query so a refresh doesn't immediately re-open.
            const url = new URL(window.location.href);
            if (url.searchParams.has('auth')) {
                url.searchParams.delete('auth');
                window.history.replaceState({}, '', url);
            }
        },
        switchTo(mode) {
            this.mode = mode;
            this.step = 1;
            this.$nextTick(() => this.renderTurnstile(mode));
        },
        nextStep() {
            // Gate each step on the browser's native validation for its inputs
            // (required + email format) before sliding forward.
            const panel = this.$refs['step' + this.step];
            if (panel) {
                for (const input of panel.querySelectorAll('input[required]')) {
                    if (! input.reportValidity()) { return; }
                }
            }
            if (this.step < 3) { this.step++; }
        },
        renderTurnstile(mode, attempt = 0) {
            // Explicit render so the widget shows up even though the form was
            // display:none on initial page load. Cloudflare's auto-scan misses
            // hidden elements; this picks them up when the modal opens.
            //
            // The Cloudflare api.js loads async, so on a freshly-loaded page
            // window.turnstile may not exist yet when the user clicks Sign in.
            // Poll up to ~5s (50 * 100ms) for it to appear before giving up.
            if (! this.$refs['turnstile_' + mode]) { return; }
            if (! window.turnstile) {
                if (attempt < 50) {
                    setTimeout(() => this.renderTurnstile(mode, attempt + 1), 100);
                }
                return;
            }
            // Remove the previous instance for this mode to avoid duplicates
            // when the user closes / re-opens the modal.
            if (this.turnstileWidgets[mode]) {
                try { window.turnstile.remove(this.turnstileWidgets[mode]); } catch (e) {}
                this.turnstileWidgets[mode] = null;
            }
            const el = this.$refs['turnstile_' + mode];
            const siteKey = el.dataset.sitekey;
            if (! siteKey) { return; }
            this.turnstileWidgets[mode] = window.turnstile.render(el, {
                sitekey: siteKey,
                theme: 'dark',
                callback: (token) => { @this.set('turnstileToken', token); },
                'error-callback': () => { @this.set('turnstileToken', null); },
                'expired-callback': () => { @this.set('turnstileToken', null); },
            });
        },
        resetTurnstile() {
            for (const mode of ['login', 'register']) {
                if (this.turnstileWidgets[mode]) {
                    try { window.turnstile.remove(this.turnstileWidgets[mode]); } catch (e) {}
                    this.turnstileWidgets[mode] = null;
                }
            }
            @this.set('turnstileToken', null);
        },
        init() {
            // Auto-open when the page loads with ?auth=login or ?auth=register.
            // Lets the redirected /login and /register URLs trigger the modal
            // without needing a separate handler on every page.
            const param = new URLSearchParams(window.location.search).get('auth');
            if (param === 'login' || param === 'register') {
                this.showOpen(param);
            }
        },
    }"
    @open-auth-modal.window="showOpen($event.detail?.mode)"
    @close-auth-modal.window="close()"
    @registration-error.window="step = $event.detail.step"
    @keydown.escape.window="open && close()"
    x-cloak
>
    {{-- Backdrop. NOT click-dismissable - a half-finished registration is too
         valuable to lose to a stray tap or a swipe interpreted as a click on
         touch devices. Close via the X button or Esc key only. --}}
    <div
        x-show="open"
        x-transition:enter="transition-opacity ease-out duration-200"
        x-transition:enter-start="opacity-0"
        x-transition:enter-end="opacity-100"
        x-transition:leave="transition-opacity ease-in duration-150"
        x-transition:leave-start="opacity-100"
        x-transition:leave-end="opacity-0"
        class="fixed inset-0 z-[90] bg-zinc-900/45"
        aria-hidden="true"
    ></div>

    {{-- Centered panel - smooth slide-up from the bottom (same motion pattern
         used across the other modals so the system reads as one family).
         Gated on `open` so the z-[91] layer is fully removed from the DOM
         when the modal is closed - prevents it from capturing clicks
         destined for lower-z UI like the locale-modal trigger in the top bar. --}}
    <div x-show="open" x-cloak class="fixed inset-0 z-[91] flex items-center justify-center p-4 pointer-events-none">
    <div
        x-show="open"
        x-transition:enter="transition duration-500 ease-[cubic-bezier(0.22,1,0.36,1)]"
        x-transition:enter-start="opacity-0 translate-y-16 scale-[0.97]"
        x-transition:enter-end="opacity-100 translate-y-0 scale-100"
        x-transition:leave="transition duration-250 ease-in"
        x-transition:leave-start="opacity-100 translate-y-0 scale-100"
        x-transition:leave-end="opacity-0 translate-y-8 scale-[0.98]"
        class="pointer-events-auto relative flex w-full max-w-md flex-col max-h-[92vh] overflow-hidden rounded-[12px] bg-[#0c1a36] text-white shadow-2xl shadow-zinc-900/50 sm:max-w-lg"
        role="dialog"
        aria-modal="true"
    >
        {{-- Header --}}
        <div class="flex shrink-0 items-start justify-between gap-3 px-5 py-4 sm:px-7">
            <div class="leading-none">
                <h2 class="text-lg font-bold leading-none text-white sm:text-xl" x-text="mode === 'login' ? 'Log in' : 'Register'">Log in</h2>
                <p
                    x-show="mode === 'register'"
                    x-cloak
                    class="mt-1 text-xs leading-none text-white/65 sm:text-sm"
                >Join {{ $siteName }} and start spending instantly</p>
            </div>
            <button
                type="button"
                @click="close()"
                aria-label="Close"
                class="flex h-9 w-9 items-center justify-center rounded-[12px] text-white/70 transition-colors hover:bg-white/10 hover:text-white"
            >
                <svg class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12"/>
                </svg>
            </button>
        </div>

        {{-- Scrollable body. Register form is content-heavy (name, email, two
             passwords, gender, two checkboxes) so it gets tighter top/bottom
             padding than the leaner login view. --}}
        <div
            class="flex-1 overflow-y-auto overscroll-contain [-webkit-overflow-scrolling:touch] px-5 sm:px-7"
            :class="mode === 'register' ? 'py-4 sm:py-5' : 'py-5 sm:py-6'"
        >

            {{-- ── LOGIN VIEW ───────────────────────────────────────────── --}}
            <div x-show="mode === 'login'" x-cloak>
                <h3 class="text-xl font-bold tracking-tight text-white sm:text-2xl">Welcome back</h3>
                <p class="mt-1.5 text-sm text-white/65">Sign in to your {{ $siteName }} account</p>

                {{-- Google OAuth --}}
                <a
                    href="{{ route('auth.google.redirect', ['popup' => 1]) }}"
                    @click.prevent="window.rshopOpenGoogleOAuth ? window.rshopOpenGoogleOAuth($el.href) : (window.location.href = $el.href)"
                    class="mt-5 flex w-full items-center justify-center gap-3 rounded-[12px] border border-white/15 bg-white/5 px-4 py-2.5 text-sm font-semibold text-white transition-colors hover:bg-white/10"
                >
                    <svg viewBox="0 0 24 24" class="h-5 w-5" aria-hidden="true">
                        <path fill="#4285F4" d="M22.56 12.25c0-.78-.07-1.53-.2-2.25H12v4.26h5.92c-.26 1.37-1.04 2.53-2.21 3.31v2.77h3.57c2.08-1.92 3.28-4.74 3.28-8.09z"/>
                        <path fill="#34A853" d="M12 23c2.97 0 5.46-.98 7.28-2.66l-3.57-2.77c-.98.66-2.23 1.06-3.71 1.06-2.86 0-5.29-1.93-6.16-4.53H2.18v2.84C3.99 20.53 7.7 23 12 23z"/>
                        <path fill="#FBBC05" d="M5.84 14.09c-.22-.66-.35-1.36-.35-2.09s.13-1.43.35-2.09V7.07H2.18C1.43 8.55 1 10.22 1 12s.43 3.45 1.18 4.93l2.85-2.22.81-.62z"/>
                        <path fill="#EA4335" d="M12 5.38c1.62 0 3.06.56 4.21 1.64l3.15-3.15C17.45 2.09 14.97 1 12 1 7.7 1 3.99 3.47 2.18 7.07l3.66 2.84c.87-2.6 3.3-4.53 6.16-4.53z"/>
                    </svg>
                    Continue with Google
                </a>

                <div class="my-6 flex items-center gap-3">
                    <span class="h-px flex-1 bg-white/15"></span>
                    <span class="text-sm uppercase tracking-wider text-white/50">or</span>
                    <span class="h-px flex-1 bg-white/15"></span>
                </div>

                <form wire:submit="login" class="flex flex-col gap-5">
                    <div>
                        <label for="loginEmail" class="mb-1.5 block text-sm font-medium text-white/85">Email</label>
                        <input
                            wire:model="loginEmail"
                            id="loginEmail"
                            type="email"
                            required
                            autocomplete="email"
                            placeholder="you@example.com"
                            class="w-full rounded-[12px] border border-white/15 bg-white/5 px-3.5 py-2.5 text-sm text-white placeholder:text-white/40 outline-none transition-colors focus:border-blue-400 focus:bg-white/10 focus:ring-2 focus:ring-blue-500/20"
                        >
                        @error('loginEmail') <p class="mt-1.5 text-sm text-red-400">{{ $message }}</p> @enderror
                    </div>

                    <div x-data="{ show: false }">
                        <label for="loginPassword" class="mb-1.5 block text-sm font-medium text-white/85">Password</label>
                        <div class="relative">
                            <input
                                wire:model="loginPassword"
                                id="loginPassword"
                                :type="show ? 'text' : 'password'"
                                required
                                autocomplete="current-password"
                                placeholder="••••••••"
                                class="w-full rounded-[12px] border border-white/15 bg-white/5 px-3.5 py-2.5 pr-12 text-sm text-white placeholder:text-white/40 outline-none transition-colors focus:border-blue-400 focus:bg-white/10 focus:ring-2 focus:ring-blue-500/20"
                            >
                            <button type="button" @click="show = !show" class="absolute right-3 top-1/2 -translate-y-1/2 text-white/60 transition-colors hover:text-white" :aria-label="show ? 'Hide password' : 'Show password'">
                                <svg x-show="!show" class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75"><path stroke-linecap="round" stroke-linejoin="round" d="M2.036 12.322a1.012 1.012 0 010-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.638 0-8.573-3.007-9.963-7.178z"/><path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
                                <svg x-show="show" x-cloak class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75"><path stroke-linecap="round" stroke-linejoin="round" d="M3.98 8.223A10.477 10.477 0 001.934 12C3.226 16.338 7.244 19.5 12 19.5c.993 0 1.953-.138 2.863-.395M6.228 6.228A10.45 10.45 0 0112 4.5c4.756 0 8.773 3.162 10.065 7.498a10.523 10.523 0 01-4.293 5.774M6.228 6.228L3 3m3.228 3.228l3.65 3.65m7.244 7.244L19.5 19.5m-2.876-2.876L13.875 13.875M9.878 9.878a3 3 0 105.249 5.249"/></svg>
                            </button>
                        </div>
                        @error('loginPassword') <p class="mt-1.5 text-sm text-red-400">{{ $message }}</p> @enderror
                    </div>

                    <div class="flex items-center justify-between">
                        <label class="flex cursor-pointer select-none items-center gap-2.5">
                            <input wire:model="loginRemember" type="checkbox" class="h-5 w-5 rounded-[5px] border-white/30 bg-white/5 text-blue-500 focus:ring-blue-500/30">
                            <span class="text-sm text-white/75">Remember me</span>
                        </label>
                        @if (Route::has('password.request'))
                            <a href="{{ route('password.request') }}" wire:navigate class="text-sm font-medium text-blue-400 hover:text-blue-300">Forgot password?</a>
                        @endif
                    </div>

                    @if(config('services.turnstile.enabled') && config('services.turnstile.enforce_auth'))
                        <div wire:ignore class="flex justify-center">
                            <div x-ref="turnstile_login" data-sitekey="{{ config('services.turnstile.site_key') }}"></div>
                        </div>
                        @error('turnstileToken') <p class="text-center text-sm text-red-400">{{ $message }}</p> @enderror
                    @endif

                    <button
                        type="submit"
                        class="mt-3 w-full rounded-[12px] bg-blue-600 px-4 py-3 text-sm font-bold text-white shadow-lg shadow-blue-900/40 transition-colors hover:bg-blue-700"
                    >
                        <span wire:loading.remove wire:target="login">Log in</span>
                        <span wire:loading wire:target="login">Signing in...</span>
                    </button>
                </form>

                @feature('signup')
                <p class="mt-5 border-t border-white pt-5 text-center text-sm text-white/65">
                    Don't have an account?
                    <button type="button" @click="switchTo('register')" class="ml-1 font-semibold text-blue-400 underline-offset-4 hover:text-blue-300 hover:underline">Create one</button>
                </p>
                @endfeature
            </div>

            {{-- ── REGISTER VIEW ────────────────────────────────────────── --}}
            <div x-show="mode === 'register'" x-cloak>

                {{-- Progress: animated step counter + bar. --}}
                <div class="mt-6">
                    <div class="flex items-center justify-between text-xs font-semibold">
                        <span class="text-white/55" x-text="`Step ${step} of 3`"></span>
                        <span class="text-white/80" x-text="['Your details', 'Security', 'Finish'][step - 1]"></span>
                    </div>
                    <div class="mt-2 h-1.5 w-full overflow-hidden rounded-full bg-white/10">
                        <div class="h-full rounded-full bg-blue-500 transition-[width] duration-500 ease-[cubic-bezier(0.22,1,0.36,1)]" :style="`width: ${(step / 3) * 100}%`"></div>
                    </div>
                </div>

                {{-- Sliding 3-step wizard. Every input stays in the DOM (steps are
                     just translated off-screen) so a single wire:submit posts the
                     whole form. Enter advances instead of submitting until step 3. --}}
                <form wire:submit="register" @keydown.enter="if (step < 3) { $event.preventDefault(); nextStep(); }" class="mt-6">
                    <div class="overflow-hidden">
                        <div class="flex items-start transition-transform duration-[450ms] ease-[cubic-bezier(0.22,1,0.36,1)]" :style="`transform: translateX(-${(step - 1) * 100}%)`">
                            {{-- STEP 1 — Your details --}}
                            <div x-ref="step1" class="flex w-full shrink-0 flex-col gap-5 px-0.5">
                    <div>
                        <label for="regName" class="mb-1.5 block text-sm font-medium text-white/85">Full name</label>
                        <input
                            wire:model="regName"
                            id="regName"
                            type="text"
                            required
                            autocomplete="name"
                            placeholder="Your name"
                            class="w-full rounded-[12px] border border-white/15 bg-white/5 px-3.5 py-2.5 text-sm text-white placeholder:text-white/40 outline-none transition-colors focus:border-blue-400 focus:bg-white/10 focus:ring-2 focus:ring-blue-500/20"
                        >
                        @error('regName') <p class="mt-1.5 text-sm text-red-400">{{ $message }}</p> @enderror
                    </div>

                    <div>
                        <label for="regEmail" class="mb-1.5 block text-sm font-medium text-white/85">Email</label>
                        <input
                            wire:model="regEmail"
                            id="regEmail"
                            type="email"
                            required
                            autocomplete="email"
                            placeholder="you@example.com"
                            class="w-full rounded-[12px] border border-white/15 bg-white/5 px-3.5 py-2.5 text-sm text-white placeholder:text-white/40 outline-none transition-colors focus:border-blue-400 focus:bg-white/10 focus:ring-2 focus:ring-blue-500/20"
                        >
                        @error('regEmail') <p class="mt-1.5 text-sm text-red-400">{{ $message }}</p> @enderror
                    </div>
                            </div>

                            {{-- STEP 2 — Security --}}
                            <div x-ref="step2" class="flex w-full shrink-0 flex-col gap-5 px-0.5">

                    <div x-data="{ show: false }">
                        <label for="regPassword" class="mb-1.5 block text-sm font-medium text-white/85">Password</label>
                        <div class="relative">
                            <input
                                wire:model="regPassword"
                                id="regPassword"
                                :type="show ? 'text' : 'password'"
                                required
                                autocomplete="new-password"
                                placeholder="••••••••"
                                class="w-full rounded-[12px] border border-white/15 bg-white/5 px-3.5 py-2.5 pr-12 text-sm text-white placeholder:text-white/40 outline-none transition-colors focus:border-blue-400 focus:bg-white/10 focus:ring-2 focus:ring-blue-500/20"
                            >
                            <button type="button" @click="show = !show" class="absolute right-3 top-1/2 -translate-y-1/2 text-white/60 transition-colors hover:text-white" :aria-label="show ? 'Hide password' : 'Show password'">
                                <svg x-show="!show" class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75"><path stroke-linecap="round" stroke-linejoin="round" d="M2.036 12.322a1.012 1.012 0 010-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.638 0-8.573-3.007-9.963-7.178z"/><path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
                                <svg x-show="show" x-cloak class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75"><path stroke-linecap="round" stroke-linejoin="round" d="M3.98 8.223A10.477 10.477 0 001.934 12C3.226 16.338 7.244 19.5 12 19.5c.993 0 1.953-.138 2.863-.395M6.228 6.228A10.45 10.45 0 0112 4.5c4.756 0 8.773 3.162 10.065 7.498a10.523 10.523 0 01-4.293 5.774M6.228 6.228L3 3m3.228 3.228l3.65 3.65m7.244 7.244L19.5 19.5m-2.876-2.876L13.875 13.875M9.878 9.878a3 3 0 105.249 5.249"/></svg>
                            </button>
                        </div>
                        <p class="mt-2 text-sm text-white/50">More than 8 characters with upper, lower, numbers and at least one special character</p>
                        @error('regPassword') <p class="mt-1.5 text-sm text-red-400">{{ $message }}</p> @enderror
                    </div>

                    <div>
                        <label for="regPasswordConfirmation" class="mb-1.5 block text-sm font-medium text-white/85">Confirm password</label>
                        <input
                            wire:model="regPasswordConfirmation"
                            id="regPasswordConfirmation"
                            type="password"
                            required
                            autocomplete="new-password"
                            placeholder="••••••••"
                            class="w-full rounded-[12px] border border-white/15 bg-white/5 px-3.5 py-2.5 text-sm text-white placeholder:text-white/40 outline-none transition-colors focus:border-blue-400 focus:bg-white/10 focus:ring-2 focus:ring-blue-500/20"
                        >
                    </div>

                    {{-- Gender. Used to pick the default avatar PNG (male/female
                         variants in /assets) until the user uploads their own.
                         Three-way segmented toggle - same pattern as the theme
                         picker so the UI reads as one family. --}}
                    <div>
                        <span class="mb-1.5 block text-sm font-medium text-white/85">Gender</span>
                        <div class="grid grid-cols-3 gap-1 rounded-[12px] bg-white/5 p-1 ring-1 ring-white/10">
                            @foreach ([
                                ['value' => 'male',   'label' => 'Male'],
                                ['value' => 'female', 'label' => 'Female'],
                                ['value' => 'other',  'label' => 'Other'],
                            ] as $opt)
                                <button
                                    type="button"
                                    wire:click="$set('regGender', '{{ $opt['value'] }}')"
                                    class="rounded-[12px] px-3 py-2.5 text-sm font-semibold transition-all active:scale-95 {{ $regGender === $opt['value'] ? 'bg-blue-600 text-white shadow-sm' : 'text-white/65 hover:text-white' }}"
                                    aria-pressed="{{ $regGender === $opt['value'] ? 'true' : 'false' }}"
                                >
                                    {{ $opt['label'] }}
                                </button>
                            @endforeach
                        </div>
                        @error('regGender') <p class="mt-1.5 text-sm text-red-400">{{ $message }}</p> @enderror
                    </div>
                            </div>

                            {{-- STEP 3 — Finish --}}
                            <div x-ref="step3" class="flex w-full shrink-0 flex-col gap-5 px-0.5">

                    {{-- Referral code (optional). Prefilled from the ?ref invite
                         cookie when present, but always editable so a friend's
                         code can be pasted in by hand. --}}
                    <div>
                        <label for="regReferral" class="mb-1.5 block text-sm font-medium text-white/85">
                            Referral code <span class="font-normal text-white/45">(optional)</span>
                        </label>
                        <input
                            wire:model="regReferral"
                            id="regReferral"
                            type="text"
                            autocomplete="off"
                            placeholder="Enter a friend's referral code"
                            class="w-full rounded-[12px] border border-white/15 bg-white/5 px-3.5 py-2.5 text-sm text-white placeholder:text-white/40 outline-none transition-colors focus:border-blue-400 focus:bg-white/10 focus:ring-2 focus:ring-blue-500/20"
                        >
                        @error('regReferral') <p class="mt-1.5 text-sm text-red-400">{{ $message }}</p> @enderror
                    </div>

                    <label class="flex cursor-pointer items-start gap-3 text-sm text-white/75">
                        <input wire:model="regAcceptedTerms" type="checkbox" required class="mt-1 h-5 w-5 shrink-0 rounded-full border-white/30 bg-white/5 text-blue-500 focus:ring-blue-500/30">
                        <span>* I declare that I have read and agree to the <a href="{{ route('shop.terms') }}" target="_blank" class="underline underline-offset-2 text-blue-400 hover:text-blue-300">terms of use</a> and <a href="{{ route('shop.privacy') }}" target="_blank" class="underline underline-offset-2 text-blue-400 hover:text-blue-300">privacy notice</a></span>
                    </label>
                    @error('regAcceptedTerms') <p class="text-sm text-red-400">{{ $message }}</p> @enderror

                    {{-- Newsletter opt-in. Hidden when features.newsletter_signup_enabled is off. --}}
                    @feature('newsletter_signup')
                    <label class="flex cursor-pointer items-start gap-3 text-sm text-white/75">
                        <input wire:model="regNewsletterOptIn" type="checkbox" class="mt-1 h-5 w-5 shrink-0 rounded-full border-white/30 bg-white/5 text-blue-500 focus:ring-blue-500/30">
                        <span>Don't miss out on our next coupon drop! Sign me up for exclusive discounts and promo codes</span>
                    </label>
                    @endfeature

                    @if(config('services.turnstile.enabled') && config('services.turnstile.enforce_auth'))
                        <div wire:ignore class="flex justify-center">
                            <div x-ref="turnstile_register" data-sitekey="{{ config('services.turnstile.site_key') }}"></div>
                        </div>
                        @error('turnstileToken') <p class="text-center text-sm text-red-400">{{ $message }}</p> @enderror
                    @endif

                            </div>
                        </div>
                    </div>

                    {{-- Wizard nav: Back / Continue, with the real submit only on step 3. --}}
                    <div class="mt-7 flex items-center gap-3">
                        <button type="button" x-show="step > 1" @click="step--" class="rounded-[12px] border border-white/20 px-5 py-3 text-sm font-bold text-white/85 transition-colors hover:bg-white/10">Back</button>
                        <button type="button" x-show="step < 3" @click="nextStep()" class="flex-1 rounded-[12px] bg-blue-600 px-4 py-3 text-sm font-bold text-white shadow-lg shadow-blue-900/40 transition-colors hover:bg-blue-700">Continue</button>
                        <button type="submit" x-show="step === 3" x-cloak class="flex-1 rounded-[12px] bg-blue-600 px-4 py-3 text-sm font-bold text-white shadow-lg shadow-blue-900/40 transition-colors hover:bg-blue-700">
                            <span wire:loading.remove wire:target="register">Create account</span>
                            <span wire:loading wire:target="register">Creating account...</span>
                        </button>
                    </div>
                </form>

                <p class="mt-5 border-t border-white pt-5 text-center text-sm text-white/65">
                    Already have an account?
                    <button type="button" @click="switchTo('login')" class="ml-1 font-semibold text-blue-400 underline-offset-4 hover:text-blue-300 hover:underline">Log in</button>
                </p>
            </div>

        </div>
    </div>
    </div>

    {{-- Cloudflare Turnstile api.js is loaded by the storefront layout head
         (resources/views/components/layouts/app/header.blade.php) so it's
         guaranteed to be in the page even though this Livewire component
         is conditionally mounted inside @guest. The renderTurnstile()
         method above polls for window.turnstile and calls render() when
         the modal opens. --}}
</div>

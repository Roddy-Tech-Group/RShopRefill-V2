<?php

use App\Http\Middleware\CaptureReferralCookie;
use App\Models\Referral;
use App\Models\User;
use Illuminate\Auth\Events\Registered;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules;
use Illuminate\Validation\ValidationException;
use App\Domain\Security\Services\TurnstileService;
use App\Support\TaggedCache;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('components.layouts.auth')] class extends Component {
    public string $name = '';

    public string $email = '';

    public string $password = '';

    public string $password_confirmation = '';

    public string $referral = '';

    public ?string $turnstileToken = null;

    /**
     * Prefill the referral code from the CaptureReferralCookie so a customer
     * who arrived via a `?ref=CODE` link sees their friend's code already
     * filled in (still editable). Falls back to an empty, optional field.
     */
    public function mount(): void
    {
        $this->referral = trim((string) request()->cookie(CaptureReferralCookie::COOKIE_NAME, ''));
    }

    /**
     * Handle an incoming registration request.
     */
    public function register(): void
    {
        $this->validateTurnstile();
        
        $this->ensureIsNotRateLimited();

        $validated = $this->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'lowercase', 'email', 'max:255', 'unique:'.User::class],
            'password' => ['required', 'string', 'confirmed', Rules\Password::defaults()],
        ]);

        $validated['password'] = Hash::make($validated['password']);

        event(new Registered(($user = User::create($validated))));

        // ── Referral attribution ────────────────────────────────────────
        // Prefer the code the customer typed on the form; fall back to the
        // `?ref=CODE` value CaptureReferralCookie stashed for 90 days. Either
        // way Referral::attribute matches it against users.referral_code and,
        // if it points at a DIFFERENT real user, creates the Referral row the
        // RewardEngine reads when this user's first order completes. It
        // silently no-ops on blank / no-match / self-referral.
        $referralCode = $this->referral !== ''
            ? $this->referral
            : (string) request()->cookie(CaptureReferralCookie::COOKIE_NAME, '');

        Referral::attribute($user, $referralCode);

        // Clear the cookie - it's done its job and a future signup on the same
        // browser shouldn't accidentally attribute to the same referrer.
        Cookie::queue(Cookie::forget(CaptureReferralCookie::COOKIE_NAME));

        Auth::login($user);
        
        RateLimiter::clear($this->throttleKey());

        $this->redirect(route('dashboard', absolute: false), navigate: true);
    }

    protected function ensureIsNotRateLimited(): void
    {
        if (! RateLimiter::tooManyAttempts($this->throttleKey(), 5)) {
            RateLimiter::hit($this->throttleKey());
            return;
        }

        event(new Lockout(request()));

        $seconds = RateLimiter::availableIn($this->throttleKey());

        throw ValidationException::withMessages([
            'email' => __('auth.throttle', [
                'seconds' => $seconds,
                'minutes' => ceil($seconds / 60),
            ]),
        ]);
    }

    protected function throttleKey(): string
    {
        return Str::transliterate('register|'.request()->ip());
    }

    protected function validateTurnstile(): void
    {
        if (! config('services.turnstile.enabled')) {
            return;
        }

        $enforceAuth = config('services.turnstile.enforce_auth', true);
        if (! $enforceAuth) {
            return;
        }

        $service = TurnstileService::make();
        $result = $service->validateToken($this->turnstileToken, request()->ip());

        if ($result['status'] === TurnstileService::STATUS_SUCCESS || $result['status'] === TurnstileService::STATUS_BYPASSED) {
            return;
        }

        if ($result['status'] === TurnstileService::STATUS_TIMEOUT) {
            throw ValidationException::withMessages([
                'turnstileToken' => 'Security verification service is temporarily unavailable. Please try again later.',
            ]);
        }

        $this->recordTurnstileFailure();

        throw ValidationException::withMessages([
            'turnstileToken' => 'Security verification failed. Please refresh the page and try again.',
        ]);
    }

    private function recordTurnstileFailure(): void
    {
        $ip = request()->ip();
        $key = "turnstile_failures_{$ip}";
        $failures = TaggedCache::for(['security'])->get($key, 0);
        TaggedCache::for(['security'])->put($key, $failures + 1, now()->addMinutes(15));
    }
}; ?>

<div class="flex flex-col sm:flex-1">
    {{-- Centered form --}}
    <div class="mx-auto flex w-full max-w-sm flex-col py-3 sm:flex-1 sm:justify-center sm:py-6">

        <p class="text-center text-base text-zinc-600">Join {{ $siteName }} and start shopping today</p>

        <x-auth-session-status class="mt-4 text-center" :status="session('status')" />

        {{-- OAuth buttons --}}
        <div class="mt-4 flex flex-col gap-3">
            <a
                href="{{ route('auth.google.redirect', ['popup' => 1]) }}"
                @click.prevent="window.rshopOpenGoogleOAuth($el.href)"
                class="flex items-center justify-center gap-3 rounded-[12px] border border-zinc-300 bg-white px-4 py-2.5 text-sm font-semibold text-zinc-800 transition-colors hover:bg-zinc-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-blue-500/40"
            >
                <svg viewBox="0 0 24 24" class="h-5 w-5" aria-hidden="true">
                    <path fill="#4285F4" d="M22.56 12.25c0-.78-.07-1.53-.2-2.25H12v4.26h5.92c-.26 1.37-1.04 2.53-2.21 3.31v2.77h3.57c2.08-1.92 3.28-4.74 3.28-8.09z"/>
                    <path fill="#34A853" d="M12 23c2.97 0 5.46-.98 7.28-2.66l-3.57-2.77c-.98.66-2.23 1.06-3.71 1.06-2.86 0-5.29-1.93-6.16-4.53H2.18v2.84C3.99 20.53 7.7 23 12 23z"/>
                    <path fill="#FBBC05" d="M5.84 14.09c-.22-.66-.35-1.36-.35-2.09s.13-1.43.35-2.09V7.07H2.18C1.43 8.55 1 10.22 1 12s.43 3.45 1.18 4.93l2.85-2.22.81-.62z"/>
                    <path fill="#EA4335" d="M12 5.38c1.62 0 3.06.56 4.21 1.64l3.15-3.15C17.45 2.09 14.97 1 12 1 7.7 1 3.99 3.47 2.18 7.07l3.66 2.84c.87-2.6 3.3-4.53 6.16-4.53z"/>
                </svg>
                Sign up with Google
            </a>
        </div>

        {{-- "or" divider --}}
        <div class="my-3 flex items-center gap-4 sm:my-4">
            <span class="h-px flex-1 bg-zinc-200"></span>
            <span class="text-sm uppercase tracking-wider text-zinc-600">or</span>
            <span class="h-px flex-1 bg-zinc-200"></span>
        </div>

        {{-- Registration form --}}
        <form wire:submit="register" class="flex flex-col gap-3 sm:gap-4">

            {{-- Name --}}
            <div>
                <label for="name" class="mb-1.5 block text-sm font-medium text-zinc-700">Full Name</label>
                <div class="relative">
                    <span class="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-zinc-600">
                        <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 6a3.75 3.75 0 1 1-7.5 0 3.75 3.75 0 0 1 7.5 0ZM4.501 20.118a7.5 7.5 0 0 1 14.998 0A17.933 17.933 0 0 1 12 21.75c-2.676 0-5.216-.584-7.499-1.632Z" />
                        </svg>
                    </span>
                    <input
                        wire:model="name"
                        id="name"
                        name="name"
                        type="text"
                        required
                        autofocus
                        autocomplete="name"
                        placeholder="Enter your full name"
                        class="w-full rounded-[12px] border border-zinc-300 bg-white py-2.5 pl-10 pr-3 text-sm text-zinc-900 placeholder:text-zinc-600 outline-none transition-colors focus:border-blue-500 focus:ring-2 focus:ring-blue-500/15"
                    />
                </div>
                @error('name') <p class="mt-1 text-center text-sm text-red-600">{{ $message }}</p> @enderror
            </div>

            {{-- Email --}}
            <div>
                <label for="email" class="mb-1.5 block text-sm font-medium text-zinc-700">Email Address</label>
                <div class="relative">
                    <span class="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-zinc-600">
                        <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M21.75 6.75v10.5a2.25 2.25 0 01-2.25 2.25h-15a2.25 2.25 0 01-2.25-2.25V6.75m19.5 0A2.25 2.25 0 0019.5 4.5h-15a2.25 2.25 0 00-2.25 2.25m19.5 0v.243a2.25 2.25 0 01-1.07 1.916l-7.5 4.615a2.25 2.25 0 01-2.36 0L3.32 8.91a2.25 2.25 0 01-1.07-1.916V6.75" />
                        </svg>
                    </span>
                    <input
                        wire:model="email"
                        id="email"
                        name="email"
                        type="email"
                        required
                        autocomplete="email"
                        placeholder="Enter your email address"
                        class="w-full rounded-[12px] border border-zinc-300 bg-white py-2.5 pl-10 pr-3 text-sm text-zinc-900 placeholder:text-zinc-600 outline-none transition-colors focus:border-blue-500 focus:ring-2 focus:ring-blue-500/15"
                    />
                </div>
                @error('email') <p class="mt-1 text-center text-sm text-red-600">{{ $message }}</p> @enderror
            </div>

            {{-- Password --}}
            <div x-data="{ show: false }">
                <label for="password" class="mb-1.5 block text-sm font-medium text-zinc-700">Password</label>
                <div class="relative">
                    <span class="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-zinc-600">
                        <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M16.5 10.5V6.75a4.5 4.5 0 10-9 0v3.75m-.75 11.25h10.5a2.25 2.25 0 002.25-2.25v-6.75a2.25 2.25 0 00-2.25-2.25H6.75a2.25 2.25 0 00-2.25 2.25v6.75a2.25 2.25 0 002.25 2.25z" />
                        </svg>
                    </span>
                    <input
                        wire:model="password"
                        id="password"
                        name="password"
                        :type="show ? 'text' : 'password'"
                        required
                        autocomplete="new-password"
                        placeholder="Create a password"
                        class="w-full rounded-[12px] border border-zinc-300 bg-white py-2.5 pl-10 pr-12 text-sm text-zinc-900 placeholder:text-zinc-600 outline-none transition-colors focus:border-blue-500 focus:ring-2 focus:ring-blue-500/15"
                    />
                    <button
                        type="button"
                        @click="show = !show"
                        class="absolute right-3 top-1/2 -translate-y-1/2 rounded-[12px] text-zinc-600 hover:text-zinc-600 focus:outline-none focus-visible:ring-2 focus-visible:ring-blue-500/40"
                        :aria-label="show ? 'Hide password' : 'Show password'"
                    >
                        <svg x-show="!show" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M2.036 12.322a1.012 1.012 0 010-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.638 0-8.573-3.007-9.963-7.178z" />
                            <path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                        </svg>
                        <svg x-show="show" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5" aria-hidden="true" style="display:none;">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M3.98 8.223A10.477 10.477 0 001.934 12C3.226 16.338 7.244 19.5 12 19.5c.993 0 1.953-.138 2.863-.395M6.228 6.228A10.45 10.45 0 0112 4.5c4.756 0 8.773 3.162 10.065 7.498a10.523 10.523 0 01-4.293 5.774M6.228 6.228L3 3m3.228 3.228l3.65 3.65m7.244 7.244L19.5 19.5m-2.876-2.876L13.875 13.875M9.878 9.878a3 3 0 105.249 5.249" />
                        </svg>
                    </button>
                </div>
                @error('password') <p class="mt-1 text-center text-sm text-red-600">{{ $message }}</p> @enderror
            </div>

            {{-- Confirm Password --}}
            <div x-data="{ show: false }">
                <label for="password_confirmation" class="mb-1.5 block text-sm font-medium text-zinc-700">Confirm Password</label>
                <div class="relative">
                    <span class="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-zinc-600">
                        <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M16.5 10.5V6.75a4.5 4.5 0 10-9 0v3.75m-.75 11.25h10.5a2.25 2.25 0 002.25-2.25v-6.75a2.25 2.25 0 00-2.25-2.25H6.75a2.25 2.25 0 00-2.25 2.25v6.75a2.25 2.25 0 002.25 2.25z" />
                        </svg>
                    </span>
                    <input
                        wire:model="password_confirmation"
                        id="password_confirmation"
                        name="password_confirmation"
                        :type="show ? 'text' : 'password'"
                        required
                        autocomplete="new-password"
                        placeholder="Re-enter your password"
                        class="w-full rounded-[12px] border border-zinc-300 bg-white py-2.5 pl-10 pr-12 text-sm text-zinc-900 placeholder:text-zinc-600 outline-none transition-colors focus:border-blue-500 focus:ring-2 focus:ring-blue-500/15"
                    />
                    <button
                        type="button"
                        @click="show = !show"
                        class="absolute right-3 top-1/2 -translate-y-1/2 rounded-[12px] text-zinc-600 hover:text-zinc-600 focus:outline-none focus-visible:ring-2 focus-visible:ring-blue-500/40"
                        :aria-label="show ? 'Hide password' : 'Show password'"
                    >
                        <svg x-show="!show" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M2.036 12.322a1.012 1.012 0 010-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.638 0-8.573-3.007-9.963-7.178z" />
                            <path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                        </svg>
                        <svg x-show="show" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5" aria-hidden="true" style="display:none;">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M3.98 8.223A10.477 10.477 0 001.934 12C3.226 16.338 7.244 19.5 12 19.5c.993 0 1.953-.138 2.863-.395M6.228 6.228A10.45 10.45 0 0112 4.5c4.756 0 8.773 3.162 10.065 7.498a10.523 10.523 0 01-4.293 5.774M6.228 6.228L3 3m3.228 3.228l3.65 3.65m7.244 7.244L19.5 19.5m-2.876-2.876L13.875 13.875M9.878 9.878a3 3 0 105.249 5.249" />
                        </svg>
                    </button>
                </div>
                @error('password_confirmation') <p class="mt-1 text-center text-sm text-red-600">{{ $message }}</p> @enderror
            </div>

            {{-- Referral code (optional). Prefilled from the ?ref=CODE cookie
                 when the customer arrived through a friend's invite link, but
                 always editable so anyone can paste a code in by hand. --}}
            <div>
                <label for="referral" class="mb-1.5 block text-sm font-medium text-zinc-700">
                    Referral code <span class="font-normal text-zinc-500">(optional)</span>
                </label>
                <div class="relative">
                    <span class="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-zinc-600">
                        <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M21 11.25v8.25a1.5 1.5 0 0 1-1.5 1.5H5.25a1.5 1.5 0 0 1-1.5-1.5v-8.25M12 4.875A2.625 2.625 0 1 0 9.375 7.5H12m0-2.625V7.5m0-2.625A2.625 2.625 0 1 1 14.625 7.5H12m0 0V21m-8.625-9.75h18c.621 0 1.125-.504 1.125-1.125v-1.5c0-.621-.504-1.125-1.125-1.125h-18c-.621 0-1.125.504-1.125 1.125v1.5c0 .621.504 1.125 1.125 1.125Z" />
                        </svg>
                    </span>
                    <input
                        wire:model="referral"
                        id="referral"
                        name="referral"
                        type="text"
                        autocomplete="off"
                        placeholder="Enter a friend's referral code"
                        class="w-full rounded-[12px] border border-zinc-300 bg-white py-2.5 pl-10 pr-3 text-sm text-zinc-900 placeholder:text-zinc-600 outline-none transition-colors focus:border-blue-500 focus:ring-2 focus:ring-blue-500/15"
                    />
                </div>
                @error('referral') <p class="mt-1 text-center text-sm text-red-600">{{ $message }}</p> @enderror
            </div>

            {{-- Submit --}}
            <div wire:ignore class="mb-2">
                @if(config('services.turnstile.enabled') && config('services.turnstile.enforce_auth'))
                    <div class="cf-turnstile" data-sitekey="{{ config('services.turnstile.site_key') }}" data-callback="onTurnstileSuccessRegister" data-theme="light"></div>
                    <script>
                        function onTurnstileSuccessRegister(token) {
                            @this.set('turnstileToken', token);
                        }
                    </script>
                @endif
            </div>
            @error('turnstileToken') <p class="mt-1 text-center text-sm text-red-600">{{ $message }}</p> @enderror

            <button
                type="submit"
                class="mt-2 flex w-full items-center justify-center gap-2 rounded-[12px] bg-blue-600 px-4 py-2.5 text-sm font-semibold text-white shadow-lg shadow-blue-600/25 transition-colors hover:bg-blue-700 focus:outline-none focus-visible:ring-2 focus-visible:ring-blue-500/50"
            >
                <span>Create Account</span>
                <svg class="h-5 w-5" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
                    <path d="M9 4.5a1.5 1.5 0 0 1 3 0V10h.5V6a1.5 1.5 0 0 1 3 0v4h.5V7.5a1.5 1.5 0 0 1 3 0V10h.5a1.5 1.5 0 0 1 1.5 1.5v3.5a5 5 0 0 1-5 5h-2.4a3 3 0 0 1-2.12-.88l-3.96-3.96a1.5 1.5 0 0 1 2.12-2.12L9 14.38V4.5z" />
                </svg>
            </button>
        </form>

        {{-- Log in link --}}
        <div class="mt-3 flex justify-center text-base sm:mt-4">
            <span class="text-zinc-600">Already have an account?</span>
            <a href="{{ route('login') }}" wire:navigate class="ml-1 font-semibold text-blue-600 hover:text-blue-700">Log in</a>
        </div>

        {{-- Socials divider --}}
        <div class="my-3 flex items-center gap-4 sm:my-5">
            <span class="h-px flex-1 bg-zinc-200"></span>
            <span class="text-sm text-zinc-600">Connect with us on our socials</span>
            <span class="h-px flex-1 bg-zinc-200"></span>
        </div>

        {{-- Social row --}}
        <div class="flex items-center justify-center gap-3">
            <a href="https://facebook.com/rshoprefills" target="_blank" rel="noopener noreferrer" aria-label="Visit our Facebook page" class="flex h-11 w-11 items-center justify-center rounded-[12px] border border-zinc-200 bg-white transition-colors hover:bg-zinc-50">
                <svg viewBox="0 0 24 24" class="h-5 w-5" fill="#1877F2" aria-hidden="true">
                    <path d="M24 12.073c0-6.627-5.373-12-12-12s-12 5.373-12 12c0 5.99 4.388 10.954 10.125 11.854v-8.385H7.078v-3.47h3.047V9.43c0-3.007 1.792-4.669 4.533-4.669 1.312 0 2.686.235 2.686.235v2.953H15.83c-1.491 0-1.956.925-1.956 1.874v2.25h3.328l-.532 3.47h-2.796v8.385C19.612 23.027 24 18.062 24 12.073z"/>
                </svg>
            </a>
            <a href="https://x.com/rshoprefills" target="_blank" rel="noopener noreferrer" aria-label="Follow us on X" class="flex h-11 w-11 items-center justify-center rounded-[12px] border border-zinc-200 bg-white transition-colors hover:bg-zinc-50">
                <svg viewBox="0 0 24 24" class="h-4 w-4 text-zinc-900" fill="currentColor" aria-hidden="true">
                    <path d="M18.244 2.25h3.308l-7.227 8.26 8.502 11.24H16.17l-5.214-6.817L4.99 21.75H1.68l7.73-8.835L1.254 2.25H8.08l4.713 6.231zm-1.161 17.52h1.833L7.084 4.126H5.117L17.083 19.77z"/>
                </svg>
            </a>
            <a href="https://tiktok.com/@rshoprefills" target="_blank" rel="noopener noreferrer" aria-label="Follow us on TikTok" class="flex h-11 w-11 items-center justify-center rounded-[12px] border border-zinc-200 bg-white transition-colors hover:bg-zinc-50">
                <svg viewBox="0 0 24 24" class="h-5 w-5 text-zinc-900" fill="currentColor" aria-hidden="true">
                    <path d="M12.525.02c1.31-.02 2.61-.01 3.91-.02.08 1.53.63 3.09 1.75 4.17 1.12 1.11 2.7 1.62 4.24 1.79v4.03c-1.44-.05-2.89-.35-4.2-.97-.57-.26-1.1-.59-1.62-.93-.01 2.92.01 5.84-.02 8.75-.08 1.4-.54 2.79-1.35 3.94-1.31 1.92-3.58 3.17-5.91 3.21-1.43.08-2.86-.31-4.08-1.03-2.02-1.19-3.44-3.37-3.65-5.71-.02-.5-.03-1-.01-1.49.18-1.9 1.12-3.72 2.58-4.96 1.66-1.44 3.98-2.13 6.15-1.72.02 1.48-.04 2.96-.04 4.44-.99-.32-2.15-.23-3.02.37-.63.41-1.11 1.04-1.36 1.75-.21.51-.15 1.07-.14 1.61.24 1.64 1.82 3.02 3.5 2.87 1.12-.01 2.19-.66 2.77-1.61.19-.33.4-.67.41-1.06.1-1.79.06-3.57.07-5.36.01-4.03-.01-8.05.02-12.07z"/>
                </svg>
            </a>
            <a href="https://instagram.com/rshoprefills" target="_blank" rel="noopener noreferrer" aria-label="Follow us on Instagram" class="flex h-11 w-11 items-center justify-center rounded-[12px] border border-zinc-200 bg-white transition-colors hover:bg-zinc-50">
                <svg viewBox="0 0 24 24" class="h-5 w-5 text-pink-600" fill="currentColor" aria-hidden="true">
                    <path d="M12 2.163c3.204 0 3.584.012 4.849.07 3.252.148 4.771 1.691 4.919 4.919.058 1.265.07 1.644.07 4.849 0 3.205-.012 3.584-.07 4.849-.149 3.225-1.664 4.771-4.919 4.919-1.266.058-1.644.07-4.849.07-3.204 0-3.584-.012-4.849-.07-3.26-.149-4.771-1.699-4.919-4.92-.058-1.265-.07-1.644-.07-4.849 0-3.204.013-3.583.07-4.849.149-3.227 1.664-4.771 4.919-4.919 1.266-.057 1.645-.069 4.849-.069zM12 0C8.741 0 8.333.014 7.053.072 2.695.272.273 2.69.073 7.052.014 8.333 0 8.741 0 12c0 3.259.014 3.668.072 4.948.2 4.358 2.618 6.78 6.98 6.98C8.333 23.986 8.741 24 12 24c3.259 0 3.668-.014 4.948-.072 4.354-.2 6.782-2.618 6.979-6.98.059-1.28.073-1.689.073-4.948 0-3.259-.014-3.667-.072-4.947-.196-4.354-2.617-6.78-6.979-6.98C15.668.014 15.259 0 12 0zm0 5.838a6.162 6.162 0 100 12.324 6.162 6.162 0 000-12.324zM12 16a4 4 0 110-8 4 4 0 010 8zm6.406-11.845a1.44 1.44 0 100 2.881 1.44 1.44 0 000-2.881z"/>
                </svg>
            </a>
        </div>

        {{-- Security note --}}
        <div class="mt-4 flex items-center justify-center gap-1.5 text-sm text-zinc-600 sm:mt-6">
            <svg class="h-4 w-4 shrink-0 text-emerald-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75 11.25 15 15 9.75m-3-7.036A11.959 11.959 0 0 1 3.598 6 11.99 11.99 0 0 0 3 9.749c0 5.592 3.824 10.29 9 11.623 5.176-1.332 9-6.03 9-11.622 0-1.31-.21-2.571-.598-3.751h-.152A11.959 11.959 0 0 1 12 2.714Z" />
            </svg>
            <span>Your security is our priority. We never share your data.</span>
        </div>

    </div>
</div>

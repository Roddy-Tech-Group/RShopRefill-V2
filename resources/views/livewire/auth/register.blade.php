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

    /**
     * Consent to the Terms of Service and Privacy Policy. Not a users column -
     * it gates the submit and is stripped before the model is created.
     */
    public bool $terms = false;

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
            'terms' => ['accepted'],
        ], [
            'terms.accepted' => 'Please accept the Terms of Service and Privacy Policy to continue.',
        ]);

        // Consent is recorded by the act of registering; it is not a column on
        // users, so it must not reach User::create().
        unset($validated['terms']);

        $validated['password'] = Hash::make($validated['password']);

        $user = User::create($validated);

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

        event(new Registered($user));

        Session::regenerate();
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

{{-- Enters from the right; see .auth-slide-right in app.css.
     Sign-in and create-account arrive from opposite sides so the two read
     as a pair rather than as one screen replacing the other. --}}
<div class="auth-slide-right flex flex-col sm:flex-1">
    {{-- Centered form --}}
    <div class="mx-auto flex w-full flex-col sm:flex-1 sm:justify-center">

        <x-auth-session-status class="mt-4 text-center" :status="session('status')" />

        {{-- OAuth buttons --}}
        <div class="mt-4 flex flex-col gap-3">
            <a
                href="{{ route('auth.google.redirect', ['popup' => 1]) }}"
                @click.prevent="window.rshopOpenGoogleOAuth($el.href)"
                class="flex items-center justify-center gap-3 rounded-[12px] border border-zinc-300 bg-white px-4 py-3 text-base font-semibold text-zinc-800 transition-colors hover:bg-zinc-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-blue-500/40"
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
                <label for="name" class="mb-2 block text-sm font-medium text-zinc-700">Full Name</label>
                <div class="relative">
                    <input
                        wire:model="name"
                        id="name"
                        name="name"
                        type="text"
                        required
                        autofocus
                        autocomplete="name"
                        placeholder="Enter your full name"
                        class="w-full rounded-[12px] border border-zinc-300 bg-white px-4 py-3 text-base text-zinc-900 placeholder:text-zinc-600 outline-none transition-colors focus:border-blue-500 focus:ring-2 focus:ring-blue-500/15"
                    />
                </div>
                @error('name') <p class="mt-1.5 text-sm text-red-600">{{ $message }}</p> @enderror
            </div>

            {{-- Email --}}
            <div>
                <label for="email" class="mb-2 block text-sm font-medium text-zinc-700">Email Address</label>
                <div class="relative">
                    <input
                        wire:model="email"
                        id="email"
                        name="email"
                        type="email"
                        required
                        autocomplete="email"
                        placeholder="Enter your email address"
                        class="w-full rounded-[12px] border border-zinc-300 bg-white px-4 py-3 text-base text-zinc-900 placeholder:text-zinc-600 outline-none transition-colors focus:border-blue-500 focus:ring-2 focus:ring-blue-500/15"
                    />
                </div>
                @error('email') <p class="mt-1.5 text-sm text-red-600">{{ $message }}</p> @enderror
            </div>

            {{-- Password --}}
            <div x-data="{ show: false }">
                <label for="password" class="mb-2 block text-sm font-medium text-zinc-700">Password</label>
                <div class="relative">
                    <input
                        wire:model="password"
                        id="password"
                        name="password"
                        :type="show ? 'text' : 'password'"
                        required
                        autocomplete="new-password"
                        placeholder="Create a password"
                        class="w-full rounded-[12px] border border-zinc-300 bg-white py-3 pl-4 pr-12 text-base text-zinc-900 placeholder:text-zinc-600 outline-none transition-colors focus:border-blue-500 focus:ring-2 focus:ring-blue-500/15"
                    />
                    <button
                        type="button"
                        @click="show = !show"
                        class="absolute right-3 top-1/2 -translate-y-1/2 rounded-[12px] text-blue-600 hover:text-blue-700 focus:outline-none focus-visible:ring-2 focus-visible:ring-blue-500/40"
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
                @error('password') <p class="mt-1.5 text-sm text-red-600">{{ $message }}</p> @enderror
            </div>

            {{-- Confirm Password --}}
            <div x-data="{ show: false }">
                <label for="password_confirmation" class="mb-2 block text-sm font-medium text-zinc-700">Confirm Password</label>
                <div class="relative">
                    <input
                        wire:model="password_confirmation"
                        id="password_confirmation"
                        name="password_confirmation"
                        :type="show ? 'text' : 'password'"
                        required
                        autocomplete="new-password"
                        placeholder="Re-enter your password"
                        class="w-full rounded-[12px] border border-zinc-300 bg-white py-3 pl-4 pr-12 text-base text-zinc-900 placeholder:text-zinc-600 outline-none transition-colors focus:border-blue-500 focus:ring-2 focus:ring-blue-500/15"
                    />
                    <button
                        type="button"
                        @click="show = !show"
                        class="absolute right-3 top-1/2 -translate-y-1/2 rounded-[12px] text-blue-600 hover:text-blue-700 focus:outline-none focus-visible:ring-2 focus-visible:ring-blue-500/40"
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
                @error('password_confirmation') <p class="mt-1.5 text-sm text-red-600">{{ $message }}</p> @enderror
            </div>

            {{-- Referral code (optional). Prefilled from the ?ref=CODE cookie
                 when the customer arrived through a friend's invite link, but
                 always editable so anyone can paste a code in by hand. --}}
            <div>
                <label for="referral" class="mb-2 block text-sm font-medium text-zinc-700">
                    Referral code <span class="font-normal text-zinc-500">(optional)</span>
                </label>
                <div class="relative">
                    <input
                        wire:model="referral"
                        id="referral"
                        name="referral"
                        type="text"
                        autocomplete="off"
                        placeholder="Enter a friend's referral code"
                        class="w-full rounded-[12px] border border-zinc-300 bg-white px-4 py-3 text-base text-zinc-900 placeholder:text-zinc-600 outline-none transition-colors focus:border-blue-500 focus:ring-2 focus:ring-blue-500/15"
                    />
                </div>
                @error('referral') <p class="mt-1.5 text-sm text-red-600">{{ $message }}</p> @enderror
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
            @error('turnstileToken') <p class="mt-1.5 text-sm text-red-600">{{ $message }}</p> @enderror

            {{-- Consent. Required, and enforced server-side by the `accepted`
                 rule above - a disabled button alone would be advisory only. --}}
            <div>
                <label for="terms" class="flex cursor-pointer items-start gap-2.5 text-sm text-zinc-600 dark:text-zinc-400">
                    <input
                        wire:model="terms"
                        id="terms"
                        name="terms"
                        type="checkbox"
                        class="mt-0.5 h-4 w-4 shrink-0 cursor-pointer rounded-[4px] border-zinc-300 text-blue-600 focus:ring-2 focus:ring-blue-500/40 dark:border-white/20 dark:bg-white/5"
                    />
                    <span>
                        I agree to the
                        <a href="{{ route('shop.terms') }}" target="_blank" rel="noopener" class="font-semibold text-zinc-900 underline underline-offset-2 hover:text-blue-600 dark:text-white">Terms of Service</a>
                        and
                        <a href="{{ route('shop.privacy') }}" target="_blank" rel="noopener" class="font-semibold text-zinc-900 underline underline-offset-2 hover:text-blue-600 dark:text-white">Privacy Policy</a>.
                    </span>
                </label>
                @error('terms') <p class="mt-1.5 text-sm text-red-600">{{ $message }}</p> @enderror
            </div>

            <button
                type="submit"
                class="mt-2 flex w-full items-center justify-center gap-2 rounded-[12px] bg-blue-600 px-4 py-3 text-base font-semibold text-white shadow-lg shadow-blue-600/25 transition-colors hover:bg-blue-700 focus:outline-none focus-visible:ring-2 focus-visible:ring-blue-500/50"
            >
                <span>Create Account</span>
            </button>
        </form>

        {{-- Security note --}}
        <div class="mt-4 flex items-center justify-center gap-1.5 text-sm text-zinc-600 sm:mt-6">
            <svg class="h-4 w-4 shrink-0 text-emerald-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75 11.25 15 15 9.75m-3-7.036A11.959 11.959 0 0 1 3.598 6 11.99 11.99 0 0 0 3 9.749c0 5.592 3.824 10.29 9 11.623 5.176-1.332 9-6.03 9-11.622 0-1.31-.21-2.571-.598-3.751h-.152A11.959 11.959 0 0 1 12 2.714Z" />
            </svg>
            <span>Your information is protected and handled according to our
                <a href="{{ route('shop.privacy') }}" wire:navigate class="font-semibold underline underline-offset-2 transition-colors hover:text-zinc-900 dark:hover:text-white">Privacy Policy</a>
            </span>
        </div>

    </div>
</div>

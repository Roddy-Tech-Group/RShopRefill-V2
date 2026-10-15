<?php

use App\Domain\Auth\Services\CustomerRegistration;
use App\Http\Middleware\CaptureReferralCookie;
use App\Support\PhoneCountries;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Validation\ValidationException;
use App\Domain\Security\Services\TurnstileService;
use App\Support\TaggedCache;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('components.layouts.auth')] class extends Component {
    public string $first_name = '';

    public string $last_name = '';

    public string $email = '';

    /**
     * The country is picked from the phone field's dial code rather than from
     * a list of its own: one control, one answer. The ISO code chosen there is
     * what lands in users.country, and the number beside it is validated
     * against that country's numbering plan.
     */
    public string $country = PhoneCountries::DEFAULT_ISO;

    public string $phone = '';

    /**
     * Drives the default avatar until the customer uploads one, the same way
     * the sign-up modal uses it.
     */
    public string $gender = '';

    public string $password = '';

    public string $password_confirmation = '';

    public string $referral = '';

    /**
     * Consent to the Terms of Service and Privacy Policy. Not a users column -
     * it gates the submit and is stripped before the model is created.
     */
    public bool $terms = false;

    public ?string $turnstileToken = null;

    /** Which page of the form is showing. */
    public int $step = 1;

    public const TOTAL_STEPS = 3;

    /**
     * What each page asks for. The rules, the steps and the account itself live
     * in CustomerRegistration, which the mobile app's sign-up shares.
     *
     * Presentation only. register() still validates the whole form before it
     * writes anything, so a customer who skips ahead by poking at the Livewire
     * component gains nothing: the steps are a way to present eleven fields
     * without a wall of them, never the gate.
     *
     * @return array<string, array<int, mixed>>
     */
    private function rulesForStep(int $step): array
    {
        return app(CustomerRegistration::class)->rulesForStep($step);
    }

    /**
     * Forward, once this page's own answers hold up. The phone is checked for
     * an existing account here rather than at the end, so a customer who has
     * signed up before learns it now and not after choosing a password.
     */
    public function next(): void
    {
        $this->validate($this->rulesForStep($this->step), $this->validationMessages());

        if ($this->step === 2) {
            $this->ensurePhoneIsFree();
        }

        $this->step = min($this->step + 1, self::TOTAL_STEPS);
    }

    public function back(): void
    {
        $this->step = max($this->step - 1, 1);
    }

    /**
     * The whole form's rules, so a step can borrow its slice and the final
     * submit can take the lot.
     *
     * @return array<string, array<int, mixed>>
     */
    private function registrationRules(): array
    {
        return app(CustomerRegistration::class)->rules();
    }

    /** @return array<string, string> */
    private function validationMessages(): array
    {
        return app(CustomerRegistration::class)->messages();
    }

    /** One line, one account, checked on E.164. */
    private function ensurePhoneIsFree(): void
    {
        app(CustomerRegistration::class)->ensurePhoneIsFree($this->phone, $this->country);
    }

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

        // Every field, whatever step the form thinks it is on. The steps are a
        // presentation device; this is the gate.
        $validated = $this->validate($this->registrationRules(), $this->validationMessages());

        // ── Referral attribution ────────────────────────────────────────
        // Prefer the code the customer typed on the form; fall back to the
        // `?ref=CODE` value CaptureReferralCookie stashed for 90 days.
        $referralCode = $this->referral !== ''
            ? $this->referral
            : (string) request()->cookie(CaptureReferralCookie::COOKIE_NAME, '');

        // The phone check, the row, the referral and the verification email.
        $user = app(CustomerRegistration::class)->create($validated, $referralCode);

        // Clear the cookie - it's done its job and a future signup on the same
        // browser shouldn't accidentally attribute to the same referrer.
        Cookie::queue(Cookie::forget(CaptureReferralCookie::COOKIE_NAME));

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
                class="flex items-center justify-center gap-3 rounded-full border border-zinc-300 bg-white px-4 py-3 text-base font-semibold text-zinc-800 transition-colors hover:bg-zinc-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-blue-500/40"
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

            {{-- Where you are, and how much is left. Three bars rather than a
                 count, so the answer is the same shape as the form: short. --}}
            <div class="mb-1 flex items-center gap-2" role="group" aria-label="Sign-up progress">
                @foreach ([1, 2, 3] as $dot)
                    <span
                        class="h-1.5 flex-1 rounded-full transition-colors {{ $step >= $dot ? 'bg-blue-600' : 'bg-zinc-200 dark:bg-zinc-700' }}"
                        @if ($step === $dot) aria-current="step" @endif
                    ></span>
                @endforeach
                <span class="ml-1 shrink-0 text-xs font-semibold text-zinc-500 dark:text-zinc-400">{{ $step }}/3</span>
            </div>

            {{-- Page one: who you are. --}}
            @if ($step === 1)
            {{-- Name. Two fields, not one: given-name / family-name is what
                 browsers and password managers autofill against, and it is the
                 shape every later step wants a name in. One row at every width,
                 phones included - they are two halves of one answer, and
                 stacking them read as two unrelated questions. --}}
            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label for="first_name" class="mb-2 block text-sm font-medium text-zinc-700">First Name</label>
                    <div class="relative">
                        <input
                            wire:model="first_name"
                            id="first_name"
                            name="first_name"
                            type="text"
                            required
                            autofocus
                            autocomplete="given-name"
                            placeholder="First name"
                            class="w-full rounded-full border-2 border-zinc-300 bg-white px-4 py-3 text-base text-zinc-900 placeholder:text-zinc-600 outline-none transition-colors focus:border-blue-500 focus:ring-2 focus:ring-blue-500/15 focus:border-edge dark:focus:border-edge"
                        />
                    </div>
                    @error('first_name') <p class="mt-1.5 text-sm text-red-600">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label for="last_name" class="mb-2 block text-sm font-medium text-zinc-700">Last Name</label>
                    <div class="relative">
                        <input
                            wire:model="last_name"
                            id="last_name"
                            name="last_name"
                            type="text"
                            required
                            autocomplete="family-name"
                            placeholder="Last name"
                            class="w-full rounded-full border-2 border-zinc-300 bg-white px-4 py-3 text-base text-zinc-900 placeholder:text-zinc-600 outline-none transition-colors focus:border-blue-500 focus:ring-2 focus:ring-blue-500/15 focus:border-edge dark:focus:border-edge"
                        />
                    </div>
                    @error('last_name') <p class="mt-1.5 text-sm text-red-600">{{ $message }}</p> @enderror
                </div>
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
                        class="w-full rounded-full border-2 border-zinc-300 bg-white px-4 py-3 text-base text-zinc-900 placeholder:text-zinc-600 outline-none transition-colors focus:border-blue-500 focus:ring-2 focus:ring-blue-500/15 focus:border-edge dark:focus:border-edge"
                    />
                </div>
                @error('email') <p class="mt-1.5 text-sm text-red-600">{{ $message }}</p> @enderror
            </div>

            @endif

            {{-- Page two: how we reach you. --}}
            @if ($step === 2)

            {{-- Country dial-code + phone, one control. The left segment is a
                 searchable flag and +code picker, and what it picks IS the
                 account's country; the right segment is the national number.
                 libphonenumber validates the pair server-side and the number is
                 stored as E.164, so it can be typed with or without a trunk 0. --}}
            <div
                x-data="{
                    countries: @js(App\Support\PhoneCountries::all()),
                    selected: @js($country),
                    search: '',
                    open: false,
                    get current() { return this.countries.find(c => c.iso === this.selected) || this.countries[0]; },
                    get matches() {
                        const q = this.search.trim().toLowerCase();
                        if (! q) return this.countries;
                        return this.countries.filter(c =>
                            c.name.toLowerCase().includes(q) || c.iso.toLowerCase().includes(q) || ('+' + c.dial).includes(q)
                        );
                    },
                    choose(iso) {
                        this.selected = iso;
                        $wire.country = iso;
                        this.open = false;
                        this.search = '';
                        this.$nextTick(() => this.$refs.phoneInput?.focus());
                    },
                }"
                @click.outside="open = false"
            >
                <label for="phone" class="mb-2 block text-sm font-medium text-zinc-700">{{ __('auth.modal_phone') }}</label>
                <div class="flex items-stretch rounded-full border-2 border-zinc-300 bg-white transition-colors focus-within:border-blue-500 focus-within:ring-2 focus-within:ring-blue-500/15">
                    <button
                        type="button"
                        @click="open = ! open"
                        :aria-expanded="open"
                        aria-label="{{ __('auth.modal_dial_code') }}"
                        class="flex shrink-0 items-center gap-1.5 rounded-l-full border-r-2 border-zinc-300 px-4 text-base text-zinc-900 outline-none transition-colors hover:bg-zinc-50"
                    >
                        <span class="text-base leading-none" x-text="current.flag"></span>
                        <span class="font-medium" x-text="'+' + current.dial"></span>
                        <svg class="h-4 w-4 text-zinc-500 transition-transform" :class="open && 'rotate-180'" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="m19.5 8.25-7.5 7.5-7.5-7.5"/></svg>
                    </button>

                    <input
                        wire:model="phone"
                        x-ref="phoneInput"
                        id="phone"
                        name="phone"
                        type="tel"
                        inputmode="numeric"
                        autocomplete="tel-national"
                        required
                        :placeholder="current.example"
                        class="w-full min-w-0 rounded-r-full bg-transparent px-4 py-3 text-base text-zinc-900 placeholder:text-zinc-600 outline-none"
                    />
                </div>

                {{-- The list opens in flow rather than floating over the form, so
                     it can never be clipped by an ancestor and never paints over
                     the fields under it. It caps its own height and scrolls. --}}
                <div
                    x-show="open"
                    x-collapse
                    x-cloak
                    @keydown.escape.stop="open = false"
                    class="mt-2 overflow-hidden rounded-[12px] bg-white shadow-lg shadow-zinc-900/[0.08] ring-1 ring-zinc-200"
                >
                    <div class="border-b border-zinc-200 p-2">
                        <input
                            x-model="search"
                            type="text"
                            placeholder="{{ __('auth.modal_search_country') }}"
                            class="w-full rounded-full border-2 border-zinc-300 bg-white px-3 py-2 text-sm text-zinc-900 placeholder:text-zinc-600 outline-none transition-colors focus:border-blue-500"
                            @keydown.enter.prevent="if (matches.length) choose(matches[0].iso)"
                        >
                    </div>
                    <ul class="max-h-52 overflow-y-auto overscroll-contain py-1" role="listbox">
                        <template x-for="c in matches" :key="c.iso">
                            <li>
                                <button
                                    type="button"
                                    @click="choose(c.iso)"
                                    class="flex w-full items-center gap-2.5 px-3 py-2 text-left text-sm text-zinc-900 transition-colors hover:bg-[#f4f6f5]"
                                    :class="c.iso === selected && 'bg-[#f4f6f5] font-semibold'"
                                >
                                    <span class="text-base leading-none" x-text="c.flag"></span>
                                    <span class="flex-1 truncate" x-text="c.name"></span>
                                    <span class="text-zinc-500" x-text="'+' + c.dial"></span>
                                </button>
                            </li>
                        </template>
                        <li x-show="! matches.length" class="px-3 py-3 text-center text-sm text-zinc-500">{{ __('auth.modal_no_match') }}</li>
                    </ul>
                </div>
                @error('country') <p class="mt-1.5 text-sm text-red-600">{{ $message }}</p> @enderror
                @error('phone') <p class="mt-1.5 text-sm text-red-600">{{ $message }}</p> @enderror
            </div>

            {{-- Gender. Three choices on one segmented control, in the same pill
                 language as the fields above it. It picks the default avatar
                 until the customer uploads one of their own. --}}
            <div>
                <span class="mb-2 block text-sm font-medium text-zinc-700">{{ __('auth.modal_gender') }}</span>
                <div class="grid grid-cols-3 gap-1 rounded-full bg-zinc-100 p-1 ring-1 ring-zinc-200">
                    @foreach ([
                        ['value' => 'male',   'label' => __('auth.modal_gender_male')],
                        ['value' => 'female', 'label' => __('auth.modal_gender_female')],
                        ['value' => 'other',  'label' => __('auth.modal_gender_other')],
                    ] as $opt)
                        <button
                            type="button"
                            wire:click="$set('gender', '{{ $opt['value'] }}')"
                            class="rounded-full px-3 py-3 text-sm font-semibold transition-all active:scale-95 {{ $gender === $opt['value'] ? 'bg-blue-600 text-white shadow-sm' : 'text-zinc-600 hover:text-zinc-900' }}"
                            aria-pressed="{{ $gender === $opt['value'] ? 'true' : 'false' }}"
                        >
                            {{ $opt['label'] }}
                        </button>
                    @endforeach
                </div>
                @error('gender') <p class="mt-1.5 text-sm text-red-600">{{ $message }}</p> @enderror
            </div>

            @endif

            {{-- Page three: securing it, and the consent that finishes it. --}}
            @if ($step === 3)

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
                        class="w-full rounded-full border-2 border-zinc-300 bg-white py-3 pl-4 pr-12 text-base text-zinc-900 placeholder:text-zinc-600 outline-none transition-colors focus:border-blue-500 focus:ring-2 focus:ring-blue-500/15 focus:border-edge dark:focus:border-edge"
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
                        class="w-full rounded-full border-2 border-zinc-300 bg-white py-3 pl-4 pr-12 text-base text-zinc-900 placeholder:text-zinc-600 outline-none transition-colors focus:border-blue-500 focus:ring-2 focus:ring-blue-500/15 focus:border-edge dark:focus:border-edge"
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
                        class="w-full rounded-full border-2 border-zinc-300 bg-white px-4 py-3 text-base text-zinc-900 placeholder:text-zinc-600 outline-none transition-colors focus:border-blue-500 focus:ring-2 focus:ring-blue-500/15 focus:border-edge dark:focus:border-edge"
                    />
                </div>
                @error('referral') <p class="mt-1.5 text-sm text-red-600">{{ $message }}</p> @enderror
            </div>

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

            @endif

            {{-- Kept in the DOM from the first paint and merely hidden until
                 the last page. Cloudflare's script draws .cf-turnstile once,
                 when it loads, so a widget that only appeared on step three
                 would never be drawn and its token would never arrive. The
                 widget itself is wire:ignore so Livewire leaves it alone
                 across the step changes; the wrapper is not, so it can hide
                 and show. --}}
            <div class="{{ $step === 3 ? 'mb-2' : 'hidden' }}">
                <div wire:ignore>
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
            </div>

            {{-- Continue until the last page, then submit. Both are the same
                 pill; only the label and what they do change. The back button
                 is a button, not a link: going back must not re-request the
                 page and lose what has been typed. --}}
            <div class="mt-2 flex items-center gap-3">
                @if ($step > 1)
                    <button
                        type="button"
                        wire:click="back"
                        class="flex shrink-0 items-center justify-center rounded-full border-2 border-zinc-300 px-5 py-3 text-base font-semibold text-zinc-700 transition-colors hover:bg-zinc-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-blue-500/50 dark:border-zinc-600 dark:text-zinc-200 dark:hover:bg-white/5"
                    >
                        Back
                    </button>
                @endif

                @if ($step < 3)
                    <button
                        type="button"
                        wire:click="next"
                        class="flex flex-1 items-center justify-center gap-2 rounded-full bg-blue-600 px-4 py-3 text-base font-semibold text-white shadow-lg shadow-blue-600/25 transition-colors hover:bg-blue-700 focus:outline-none focus-visible:ring-2 focus-visible:ring-blue-500/50"
                    >
                        <span>Continue</span>
                    </button>
                @else
                    <button
                        type="submit"
                        class="flex flex-1 items-center justify-center gap-2 rounded-full bg-blue-600 px-4 py-3 text-base font-semibold text-white shadow-lg shadow-blue-600/25 transition-colors hover:bg-blue-700 focus:outline-none focus-visible:ring-2 focus-visible:ring-blue-500/50"
                    >
                        <span>Create Account</span>
                    </button>
                @endif
            </div>
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

<?php

use Illuminate\Support\Facades\Password;
use Illuminate\Validation\ValidationException;
use App\Domain\Security\Services\TurnstileService;
use App\Support\TaggedCache;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('components.layouts.auth.centered')] class extends Component {
    public string $email = '';

    public ?string $turnstileToken = null;

    /**
     * Send a password reset link to the provided email address.
     */
    public function sendPasswordResetLink(): void
    {
        $this->validateTurnstile();
        
        $this->ensureIsNotRateLimited();

        $this->validate([
            'email' => ['required', 'string', 'email'],
        ]);

        Password::sendResetLink($this->only('email'));
        
        RateLimiter::clear($this->throttleKey());

        session()->flash('status', __('A reset link will be sent if the account exists.'));
    }

    protected function ensureIsNotRateLimited(): void
    {
        if (! RateLimiter::tooManyAttempts($this->throttleKey(), 3)) {
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
        return Str::transliterate(Str::lower($this->email).'|'.request()->ip());
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
    {{-- Form (top-aligned on mobile so logo and heading stay close) --}}
    <div class="mx-auto flex w-full max-w-sm flex-col py-3 sm:flex-1 sm:justify-center sm:py-6">

        <h1 class="flex items-center justify-center gap-2 text-2xl font-bold tracking-tight text-zinc-900">
            <span>Forgot password?</span>
            <svg class="h-7 w-7 shrink-0 text-blue-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 5.25a3 3 0 0 1 3 3m3 0a6 6 0 0 1-7.029 5.912c-.563-.097-1.159.026-1.563.43L10.5 17.25H8.25v2.25H6v2.25H2.25v-2.818c0-.597.237-1.17.659-1.591l6.499-6.499c.404-.404.527-1 .43-1.563A6 6 0 1 1 21.75 8.25Z" />
            </svg>
        </h1>
        <p class="mt-2 text-center text-sm text-zinc-600">Enter your email and we'll send you a reset link</p>

        <x-auth-session-status class="mt-4 text-center" :status="session('status')" />

        <form wire:submit="sendPasswordResetLink" class="mt-4 flex flex-col gap-3 sm:gap-4">

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
                        autofocus
                        autocomplete="email"
                        placeholder="Enter your email address"
                        class="w-full rounded-[12px] border border-zinc-300 bg-white py-2.5 pl-10 pr-3 text-sm text-zinc-900 placeholder:text-zinc-600 outline-none transition-colors focus:border-blue-500 focus:ring-2 focus:ring-blue-500/15"
                    />
                </div>
                @error('email') <p class="mt-1 text-center text-sm text-red-600">{{ $message }}</p> @enderror
            </div>

            {{-- Submit --}}
            <div wire:ignore class="mb-2">
                @if(config('services.turnstile.enabled') && config('services.turnstile.enforce_auth'))
                    <div class="cf-turnstile" data-sitekey="{{ config('services.turnstile.site_key') }}" data-callback="onTurnstileSuccessForgot" data-theme="light"></div>
                    <script>
                        function onTurnstileSuccessForgot(token) {
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
                <span>Send reset link</span>
                <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M6 12 3.269 3.125A59.769 59.769 0 0 1 21.485 12 59.768 59.768 0 0 1 3.27 20.875L5.999 12Zm0 0h7.5" />
                </svg>
            </button>
        </form>

        {{-- Back to login link --}}
        <div class="mt-3 flex justify-center text-base sm:mt-4">
            <span class="text-zinc-600">Remember your password?</span>
            <a href="{{ route('login') }}" wire:navigate class="ml-1 font-semibold text-blue-600 hover:text-blue-700">Log in</a>
        </div>

        {{-- Security note --}}
        <div class="mt-5 flex items-center justify-center gap-1.5 text-sm text-zinc-600 sm:mt-8">
            <svg class="h-4 w-4 shrink-0 text-emerald-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75 11.25 15 15 9.75m-3-7.036A11.959 11.959 0 0 1 3.598 6 11.99 11.99 0 0 0 3 9.749c0 5.592 3.824 10.29 9 11.623 5.176-1.332 9-6.03 9-11.622 0-1.31-.21-2.571-.598-3.751h-.152A11.959 11.959 0 0 1 12 2.714Z" />
            </svg>
            <span>Reset links expire after 60 minutes for your security.</span>
        </div>

    </div>
</div>

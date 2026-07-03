<?php

use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules;
use Illuminate\Validation\ValidationException;
use App\Domain\Security\Services\TurnstileService;
use App\Support\TaggedCache;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Volt\Component;

new #[Layout('components.layouts.auth.centered')] class extends Component {
    #[Locked]
    public string $token = '';
    public string $email = '';
    public string $password = '';
    public string $password_confirmation = '';
    public ?string $turnstileToken = null;

    /**
     * Mount the component.
     */
    public function mount(string $token): void
    {
        $this->token = $token;

        $this->email = request()->string('email');
    }

    /**
     * Reset the password for the given user.
     */
    public function resetPassword(): void
    {
        $this->validateTurnstile();
        
        $this->ensureIsNotRateLimited();

        $this->validate([
            'token' => ['required'],
            'email' => ['required', 'string', 'email'],
            'password' => ['required', 'string', 'confirmed', Rules\Password::defaults()],
        ]);

        // Here we will attempt to reset the user's password. If it is successful we
        // will update the password on an actual user model and persist it to the
        // database. Otherwise we will parse the error and return the response.
        $status = Password::reset(
            $this->only('email', 'password', 'password_confirmation', 'token'),
            function ($user) {
                $user->forceFill([
                    'password' => Hash::make($this->password),
                    'remember_token' => Str::random(60),
                ])->save();

                event(new PasswordReset($user));
            }
        );

        // If the password was successfully reset, we will redirect the user back to
        // the application's home authenticated view. If there is an error we can
        // redirect them back to where they came from with their error message.
        if ($status != Password::PasswordReset) {
            $this->addError('email', __($status));

            return;
        }

        RateLimiter::clear($this->throttleKey());
        Session::flash('status', __($status));

        $this->redirectRoute('login', navigate: true);
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
    {{-- Centered form --}}
    <div class="mx-auto flex w-full max-w-sm flex-col py-3 sm:flex-1 sm:justify-center sm:py-6">

        <h1 class="flex items-center justify-center gap-2 text-2xl font-bold tracking-tight text-zinc-900">
            <span>Set a new password</span>
            <svg class="h-7 w-7 shrink-0 text-blue-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" d="M16.5 10.5V6.75a4.5 4.5 0 1 0-9 0v3.75m-.75 11.25h10.5a2.25 2.25 0 0 0 2.25-2.25v-6.75a2.25 2.25 0 0 0-2.25-2.25H6.75a2.25 2.25 0 0 0-2.25 2.25v6.75a2.25 2.25 0 0 0 2.25 2.25Z" />
            </svg>
        </h1>
        <p class="mt-2 text-center text-sm text-zinc-600">Choose a strong password for your account</p>

        <x-auth-session-status class="mt-4 text-center" :status="session('status')" />

        <form wire:submit="resetPassword" class="mt-4 flex flex-col gap-3 sm:gap-4">

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

            {{-- New Password --}}
            <div x-data="{ show: false }">
                <label for="password" class="mb-1.5 block text-sm font-medium text-zinc-700">New Password</label>
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
                        placeholder="Create a new password"
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
                <label for="password_confirmation" class="mb-1.5 block text-sm font-medium text-zinc-700">Confirm New Password</label>
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
                        placeholder="Re-enter your new password"
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

            {{-- Submit --}}
            <div wire:ignore class="mb-2">
                @if(config('services.turnstile.enabled') && config('services.turnstile.enforce_auth'))
                    <div class="cf-turnstile" data-sitekey="{{ config('services.turnstile.site_key') }}" data-callback="onTurnstileSuccessReset" data-theme="light"></div>
                    <script>
                        function onTurnstileSuccessReset(token) {
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
                <span>Reset password</span>
                <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5" />
                </svg>
            </button>
        </form>

        {{-- Back to login link --}}
        <div class="mt-3 flex justify-center text-base sm:mt-4">
            <span class="text-zinc-600">Changed your mind?</span>
            <a href="{{ route('login') }}" wire:navigate class="ml-1 font-semibold text-blue-600 hover:text-blue-700">Back to login</a>
        </div>

        {{-- Security note --}}
        <div class="mt-5 flex items-center justify-center gap-1.5 text-sm text-zinc-600 sm:mt-8">
            <svg class="h-4 w-4 shrink-0 text-emerald-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75 11.25 15 15 9.75m-3-7.036A11.959 11.959 0 0 1 3.598 6 11.99 11.99 0 0 0 3 9.749c0 5.592 3.824 10.29 9 11.623 5.176-1.332 9-6.03 9-11.622 0-1.31-.21-2.571-.598-3.751h-.152A11.959 11.959 0 0 1 12 2.714Z" />
            </svg>
            <span>Use at least 8 characters with a mix of letters and numbers.</span>
        </div>

    </div>
</div>

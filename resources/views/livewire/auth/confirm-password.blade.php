<?php

use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('components.layouts.auth.centered')] class extends Component {
    public string $password = '';

    /**
     * Confirm the current user's password.
     */
    public function confirmPassword(): void
    {
        $this->validate([
            'password' => ['required', 'string'],
        ]);

        if (! Auth::guard('web')->validate([
            'email' => Auth::user()->email,
            'password' => $this->password,
        ])) {
            throw ValidationException::withMessages([
                'password' => __('auth.password'),
            ]);
        }

        session(['auth.password_confirmed_at' => time()]);

        $this->redirectIntended(default: route('dashboard', absolute: false), navigate: true);
    }
}; ?>

<div class="flex flex-col sm:flex-1">
    {{-- Centered form --}}
    <div class="mx-auto flex w-full max-w-sm flex-col py-3 sm:flex-1 sm:justify-center sm:py-6">

        <h1 class="flex items-center justify-center gap-2 text-2xl font-bold tracking-tight text-zinc-900">
            <span>Confirm your password</span>
            <svg class="h-7 w-7 shrink-0 text-blue-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75 11.25 15 15 9.75M21 12c0 1.268-.63 2.39-1.593 3.068a3.745 3.745 0 0 1-1.043 3.296 3.745 3.745 0 0 1-3.296 1.043A3.745 3.745 0 0 1 12 21c-1.268 0-2.39-.63-3.068-1.593a3.746 3.746 0 0 1-3.296-1.043 3.745 3.745 0 0 1-1.043-3.296A3.745 3.745 0 0 1 3 12c0-1.268.63-2.39 1.593-3.068a3.745 3.745 0 0 1 1.043-3.296 3.746 3.746 0 0 1 3.296-1.043A3.746 3.746 0 0 1 12 3c1.268 0 2.39.63 3.068 1.593a3.746 3.746 0 0 1 3.296 1.043 3.746 3.746 0 0 1 1.043 3.296A3.745 3.745 0 0 1 21 12Z" />
            </svg>
        </h1>
        <p class="mt-2 text-center text-sm text-zinc-600">This is a secure area. Please confirm your password to continue.</p>

        <x-auth-session-status class="mt-4 text-center" :status="session('status')" />

        <form wire:submit="confirmPassword" class="mt-4 flex flex-col gap-3 sm:gap-4">

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
                        autofocus
                        autocomplete="current-password"
                        placeholder="Enter your password"
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

            {{-- Submit --}}
            <button
                type="submit"
                class="mt-2 flex w-full items-center justify-center gap-2 rounded-[12px] bg-blue-600 px-4 py-2.5 text-sm font-semibold text-white shadow-lg shadow-blue-600/25 transition-colors hover:bg-blue-700 focus:outline-none focus-visible:ring-2 focus-visible:ring-blue-500/50"
            >
                <span>Confirm</span>
                <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5" />
                </svg>
            </button>
        </form>

        {{-- Security note --}}
        <div class="mt-5 flex items-center justify-center gap-1.5 text-sm text-zinc-600 sm:mt-8">
            <svg class="h-4 w-4 shrink-0 text-emerald-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75 11.25 15 15 9.75m-3-7.036A11.959 11.959 0 0 1 3.598 6 11.99 11.99 0 0 0 3 9.749c0 5.592 3.824 10.29 9 11.623 5.176-1.332 9-6.03 9-11.622 0-1.31-.21-2.571-.598-3.751h-.152A11.959 11.959 0 0 1 12 2.714Z" />
            </svg>
            <span>We re-verify your identity to protect sensitive actions.</span>
        </div>

    </div>
</div>

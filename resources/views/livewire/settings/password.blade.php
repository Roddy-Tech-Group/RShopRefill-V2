<?php

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('components.layouts.dashboard')] class extends Component {
    public string $current_password = '';
    public string $password = '';
    public string $password_confirmation = '';

    /**
     * Update the password for the currently authenticated user.
     */
    public function updatePassword(): void
    {
        try {
            $validated = $this->validate([
                'current_password' => ['required', 'string', 'current_password'],
                'password' => ['required', 'string', Password::defaults(), 'confirmed'],
            ]);
        } catch (ValidationException $e) {
            $this->reset('current_password', 'password', 'password_confirmation');

            throw $e;
        }

        Auth::user()->update([
            'password' => Hash::make($validated['password']),
        ]);

        $this->reset('current_password', 'password', 'password_confirmation');

        $this->dispatch('password-updated');
    }
}; ?>

<div class="flex w-full flex-col gap-6">
    {{-- Page heading --}}
    <div class="text-center sm:text-left">
        <h1 class="text-2xl font-bold tracking-tight text-black sm:text-3xl">Security</h1>
        <p class="mt-1 text-sm text-zinc-600">Use a long, random password to keep your account secure.</p>
    </div>

    {{-- Password form --}}
    <div class="rounded-[12px] bg-[#eff6ff] p-6 dash-shimmer border border-zinc-200 shadow-md shadow-zinc-900/[0.06] transition-colors hover:border-green-200 dark:border-zinc-700 dark:hover:border-white dark:shadow-none">
        <div class="mb-5">
            <h2 class="text-base font-semibold text-black">Change password</h2>
            <p class="mt-0.5 text-xs text-zinc-600">You'll stay signed in on this device after updating.</p>
        </div>

        <form wire:submit="updatePassword" class="space-y-5">
            {{-- Current password --}}
            <div x-data="{ show: false }">
                <label for="password-current" class="mb-1.5 block text-sm font-medium text-zinc-700">{{ __('Current password') }}</label>
                <div class="relative">
                    <input
                        wire:model="current_password"
                        id="password-current"
                        name="current_password"
                        :type="show ? 'text' : 'password'"
                        required
                        autocomplete="current-password"
                        placeholder="Enter your current password"
                        class="w-full rounded-[12px] border border-zinc-300 bg-white px-3.5 py-2.5 pr-11 text-sm text-black placeholder:text-zinc-600 outline-none transition-colors focus:border-blue-500 focus:ring-2 focus:ring-blue-500/15"
                    />
                    <button type="button" @click="show = !show" class="absolute right-3 top-1/2 -translate-y-1/2 text-zinc-600 hover:text-zinc-600" :aria-label="show ? 'Hide password' : 'Show password'">
                        <svg x-show="!show" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M2.036 12.322a1.012 1.012 0 010-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.638 0-8.573-3.007-9.963-7.178z"/>
                            <path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/>
                        </svg>
                        <svg x-show="show" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5" aria-hidden="true" style="display:none;">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M3.98 8.223A10.477 10.477 0 001.934 12C3.226 16.338 7.244 19.5 12 19.5c.993 0 1.953-.138 2.863-.395M6.228 6.228A10.45 10.45 0 0112 4.5c4.756 0 8.773 3.162 10.065 7.498a10.523 10.523 0 01-4.293 5.774M6.228 6.228L3 3m3.228 3.228l3.65 3.65m7.244 7.244L19.5 19.5m-2.876-2.876L13.875 13.875M9.878 9.878a3 3 0 105.249 5.249"/>
                        </svg>
                    </button>
                </div>
                @error('current_password') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
            </div>

            {{-- New password --}}
            <div x-data="{ show: false }">
                <label for="password-new" class="mb-1.5 block text-sm font-medium text-zinc-700">{{ __('New password') }}</label>
                <div class="relative">
                    <input
                        wire:model="password"
                        id="password-new"
                        name="password"
                        :type="show ? 'text' : 'password'"
                        required
                        autocomplete="new-password"
                        placeholder="At least 8 characters"
                        class="w-full rounded-[12px] border border-zinc-300 bg-white px-3.5 py-2.5 pr-11 text-sm text-black placeholder:text-zinc-600 outline-none transition-colors focus:border-blue-500 focus:ring-2 focus:ring-blue-500/15"
                    />
                    <button type="button" @click="show = !show" class="absolute right-3 top-1/2 -translate-y-1/2 text-zinc-600 hover:text-zinc-600" :aria-label="show ? 'Hide password' : 'Show password'">
                        <svg x-show="!show" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M2.036 12.322a1.012 1.012 0 010-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.638 0-8.573-3.007-9.963-7.178z"/>
                            <path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/>
                        </svg>
                        <svg x-show="show" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5" aria-hidden="true" style="display:none;">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M3.98 8.223A10.477 10.477 0 001.934 12C3.226 16.338 7.244 19.5 12 19.5c.993 0 1.953-.138 2.863-.395M6.228 6.228A10.45 10.45 0 0112 4.5c4.756 0 8.773 3.162 10.065 7.498a10.523 10.523 0 01-4.293 5.774M6.228 6.228L3 3m3.228 3.228l3.65 3.65m7.244 7.244L19.5 19.5m-2.876-2.876L13.875 13.875M9.878 9.878a3 3 0 105.249 5.249"/>
                        </svg>
                    </button>
                </div>
                @error('password') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
            </div>

            {{-- Confirm password --}}
            <div x-data="{ show: false }">
                <label for="password-confirm" class="mb-1.5 block text-sm font-medium text-zinc-700">{{ __('Confirm new password') }}</label>
                <div class="relative">
                    <input
                        wire:model="password_confirmation"
                        id="password-confirm"
                        name="password_confirmation"
                        :type="show ? 'text' : 'password'"
                        required
                        autocomplete="new-password"
                        placeholder="Re-enter your new password"
                        class="w-full rounded-[12px] border border-zinc-300 bg-white px-3.5 py-2.5 pr-11 text-sm text-black placeholder:text-zinc-600 outline-none transition-colors focus:border-blue-500 focus:ring-2 focus:ring-blue-500/15"
                    />
                    <button type="button" @click="show = !show" class="absolute right-3 top-1/2 -translate-y-1/2 text-zinc-600 hover:text-zinc-600" :aria-label="show ? 'Hide password' : 'Show password'">
                        <svg x-show="!show" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M2.036 12.322a1.012 1.012 0 010-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.638 0-8.573-3.007-9.963-7.178z"/>
                            <path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/>
                        </svg>
                        <svg x-show="show" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5" aria-hidden="true" style="display:none;">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M3.98 8.223A10.477 10.477 0 001.934 12C3.226 16.338 7.244 19.5 12 19.5c.993 0 1.953-.138 2.863-.395M6.228 6.228A10.45 10.45 0 0112 4.5c4.756 0 8.773 3.162 10.065 7.498a10.523 10.523 0 01-4.293 5.774M6.228 6.228L3 3m3.228 3.228l3.65 3.65m7.244 7.244L19.5 19.5m-2.876-2.876L13.875 13.875M9.878 9.878a3 3 0 105.249 5.249"/>
                        </svg>
                    </button>
                </div>
                @error('password_confirmation') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
            </div>

            {{-- Save action --}}
            <div class="flex items-center gap-3 pt-1">
                <button type="submit" class="inline-flex items-center gap-2 rounded-[12px] bg-blue-600 px-5 py-2.5 text-sm font-semibold text-white shadow-sm shadow-blue-600/25 transition-colors hover:bg-blue-700 focus:outline-none focus-visible:ring-2 focus-visible:ring-blue-500/50">
                    {{ __('Update password') }}
                </button>

                <x-action-message on="password-updated" class="text-sm font-medium text-emerald-600">
                    {{ __('Saved.') }}
                </x-action-message>
            </div>
        </form>
    </div>

    {{-- Transaction PIN — authorizes wallet payments (4-digit). --}}
    <livewire:settings.transaction-pin />
</div>

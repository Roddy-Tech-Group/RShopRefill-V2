<?php

use App\Domain\Wallet\Services\TransactionPinService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Livewire\Volt\Component;

new class extends Component
{
    /** Whether the customer currently has a transaction PIN configured. */
    public bool $hasPin = false;

    /** Whether the PIN is temporarily locked after too many failed attempts. */
    public bool $locked = false;

    // Set-up form (no PIN yet).
    public string $pin = '';
    public string $pin_confirmation = '';

    // Change form (PIN exists).
    public string $old_pin = '';
    public string $new_pin = '';
    public string $new_pin_confirmation = '';

    // Remove form (PIN exists).
    public string $remove_password = '';

    public function mount(): void
    {
        $user = Auth::user();
        $this->hasPin = $user->hasTransactionPin();
        $this->locked = $user->isTransactionPinLocked();
    }

    /**
     * Configure a transaction PIN for the first time.
     */
    public function setupPin(TransactionPinService $pinService): void
    {
        $this->validate([
            'pin' => ['required', 'digits:4', 'confirmed'],
        ], [], ['pin' => 'PIN']);

        try {
            $pinService->validateStrength($this->pin);
            $pinService->setupPin(Auth::user(), $this->pin);
        } catch (ValidationException $e) {
            $this->addError('pin', $e->validator->errors()->first());

            return;
        }

        $this->reset('pin', 'pin_confirmation');
        $this->hasPin = true;
        $this->dispatch('pin-updated');
    }

    /**
     * Change the existing PIN. Requires the current PIN, which counts toward
     * the lockout policy on failure (handled inside the service).
     */
    public function changePin(TransactionPinService $pinService): void
    {
        $this->validate([
            'old_pin' => ['required', 'digits:4'],
            'new_pin' => ['required', 'digits:4', 'confirmed', 'different:old_pin'],
        ]);

        // Strength errors belong to the NEW pin field.
        try {
            $pinService->validateStrength($this->new_pin);
        } catch (ValidationException $e) {
            $this->addError('new_pin', $e->validator->errors()->first());

            return;
        }

        // Anything thrown here is an invalid current PIN or a lockout.
        try {
            $pinService->changePin(Auth::user(), $this->old_pin, $this->new_pin);
        } catch (ValidationException $e) {
            $this->addError('old_pin', $e->validator->errors()->first());
            $this->locked = Auth::user()->fresh()->isTransactionPinLocked();

            return;
        }

        $this->reset('old_pin', 'new_pin', 'new_pin_confirmation');
        $this->dispatch('pin-updated');
    }

    /**
     * Remove the PIN entirely. Requires the account password.
     */
    public function removePin(TransactionPinService $pinService): void
    {
        $this->validate([
            'remove_password' => ['required', 'string'],
        ]);

        try {
            $pinService->removePin(Auth::user(), $this->remove_password);
        } catch (ValidationException $e) {
            $this->addError('remove_password', $e->validator->errors()->first());

            return;
        }

        $this->reset('remove_password');
        $this->hasPin = false;
        $this->locked = false;
        $this->dispatch('pin-updated');
    }
}; ?>

<div class="rounded-[12px] bg-[#eff6ff] p-6 dash-shimmer border border-zinc-200 shadow-md shadow-zinc-900/[0.06] transition-colors hover:border-green-200 dark:border-zinc-700 dark:hover:border-white dark:shadow-none">
    <div class="mb-5">
        <h2 class="text-base font-semibold text-black">Transaction PIN</h2>
        <p class="mt-0.5 text-xs text-zinc-600">A 4-digit PIN that authorizes payments from your wallet balance.</p>
    </div>

    @if ($locked)
        <div class="mb-5 flex items-start gap-2 rounded-[12px] bg-red-50 px-3.5 py-3 ring-1 ring-red-100">
            <svg class="mt-0.5 h-4 w-4 shrink-0 text-red-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m9-.75a9 9 0 11-18 0 9 9 0 0118 0zm-9 3.75h.008v.008H12v-.008z"/>
            </svg>
            <p class="text-xs font-medium text-red-700">Your PIN is locked after too many incorrect attempts. Please try again later.</p>
        </div>
    @endif

    @if (! $hasPin)
        {{-- Set up a new PIN --}}
        <form wire:submit="setupPin" class="space-y-5">
            <div x-data="{ show: false }">
                <label for="pin-new" class="mb-1.5 block text-sm font-medium text-zinc-700">New PIN</label>
                <div class="relative">
                    <input
                        wire:model="pin"
                        id="pin-new"
                        :type="show ? 'text' : 'password'"
                        inputmode="numeric"
                        maxlength="4"
                        autocomplete="off"
                        placeholder="4 digits"
                        class="w-full rounded-[12px] border border-zinc-300 bg-white px-3.5 py-2.5 pr-11 text-sm tracking-[0.5em] text-black placeholder:tracking-normal placeholder:text-zinc-600 outline-none transition-colors focus:border-blue-500 focus:ring-2 focus:ring-blue-500/15"
                    />
                    <button type="button" @click="show = !show" class="absolute right-3 top-1/2 -translate-y-1/2 text-zinc-600" :aria-label="show ? 'Hide PIN' : 'Show PIN'">
                        <svg x-show="!show" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M2.036 12.322a1.012 1.012 0 010-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.638 0-8.573-3.007-9.963-7.178z"/>
                            <path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/>
                        </svg>
                        <svg x-show="show" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5" aria-hidden="true" style="display:none;">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M3.98 8.223A10.477 10.477 0 001.934 12C3.226 16.338 7.244 19.5 12 19.5c.993 0 1.953-.138 2.863-.395M6.228 6.228A10.45 10.45 0 0112 4.5c4.756 0 8.773 3.162 10.065 7.498a10.523 10.523 0 01-4.293 5.774M6.228 6.228L3 3m3.228 3.228l3.65 3.65m7.244 7.244L19.5 19.5m-2.876-2.876L13.875 13.875M9.878 9.878a3 3 0 105.249 5.249"/>
                        </svg>
                    </button>
                </div>
                @error('pin') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
            </div>

            <div x-data="{ show: false }">
                <label for="pin-confirm" class="mb-1.5 block text-sm font-medium text-zinc-700">Confirm PIN</label>
                <div class="relative">
                    <input
                        wire:model="pin_confirmation"
                        id="pin-confirm"
                        :type="show ? 'text' : 'password'"
                        inputmode="numeric"
                        maxlength="4"
                        autocomplete="off"
                        placeholder="Re-enter PIN"
                        class="w-full rounded-[12px] border border-zinc-300 bg-white px-3.5 py-2.5 pr-11 text-sm tracking-[0.5em] text-black placeholder:tracking-normal placeholder:text-zinc-600 outline-none transition-colors focus:border-blue-500 focus:ring-2 focus:ring-blue-500/15"
                    />
                    <button type="button" @click="show = !show" class="absolute right-3 top-1/2 -translate-y-1/2 text-zinc-600" :aria-label="show ? 'Hide PIN' : 'Show PIN'">
                        <svg x-show="!show" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M2.036 12.322a1.012 1.012 0 010-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.638 0-8.573-3.007-9.963-7.178z"/>
                            <path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/>
                        </svg>
                        <svg x-show="show" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5" aria-hidden="true" style="display:none;">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M3.98 8.223A10.477 10.477 0 001.934 12C3.226 16.338 7.244 19.5 12 19.5c.993 0 1.953-.138 2.863-.395M6.228 6.228A10.45 10.45 0 0112 4.5c4.756 0 8.773 3.162 10.065 7.498a10.523 10.523 0 01-4.293 5.774M6.228 6.228L3 3m3.228 3.228l3.65 3.65m7.244 7.244L19.5 19.5m-2.876-2.876L13.875 13.875M9.878 9.878a3 3 0 105.249 5.249"/>
                        </svg>
                    </button>
                </div>
                @error('pin_confirmation') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
            </div>

            <div class="flex items-center gap-3 pt-1">
                <button type="submit" class="inline-flex items-center gap-2 rounded-[12px] bg-blue-600 px-5 py-2.5 text-sm font-semibold text-white shadow-sm shadow-blue-600/25 transition-colors hover:bg-blue-700 focus:outline-none focus-visible:ring-2 focus-visible:ring-blue-500/50">
                    Set up PIN
                </button>
                <x-action-message on="pin-updated" class="text-sm font-medium text-emerald-600">
                    {{ __('Saved.') }}
                </x-action-message>
            </div>
        </form>
    @else
        {{-- Change the existing PIN --}}
        <form wire:submit="changePin" class="space-y-5">
            <div x-data="{ show: false }">
                <label for="pin-old" class="mb-1.5 block text-sm font-medium text-zinc-700">Current PIN</label>
                <div class="relative">
                    <input
                        wire:model="old_pin"
                        id="pin-old"
                        :type="show ? 'text' : 'password'"
                        inputmode="numeric"
                        maxlength="4"
                        autocomplete="off"
                        placeholder="4 digits"
                        class="w-full rounded-[12px] border border-zinc-300 bg-white px-3.5 py-2.5 pr-11 text-sm tracking-[0.5em] text-black placeholder:tracking-normal placeholder:text-zinc-600 outline-none transition-colors focus:border-blue-500 focus:ring-2 focus:ring-blue-500/15"
                    />
                    <button type="button" @click="show = !show" class="absolute right-3 top-1/2 -translate-y-1/2 text-zinc-600" :aria-label="show ? 'Hide PIN' : 'Show PIN'">
                        <svg x-show="!show" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M2.036 12.322a1.012 1.012 0 010-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.638 0-8.573-3.007-9.963-7.178z"/>
                            <path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/>
                        </svg>
                        <svg x-show="show" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5" aria-hidden="true" style="display:none;">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M3.98 8.223A10.477 10.477 0 001.934 12C3.226 16.338 7.244 19.5 12 19.5c.993 0 1.953-.138 2.863-.395M6.228 6.228A10.45 10.45 0 0112 4.5c4.756 0 8.773 3.162 10.065 7.498a10.523 10.523 0 01-4.293 5.774M6.228 6.228L3 3m3.228 3.228l3.65 3.65m7.244 7.244L19.5 19.5m-2.876-2.876L13.875 13.875M9.878 9.878a3 3 0 105.249 5.249"/>
                        </svg>
                    </button>
                </div>
                @error('old_pin') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
            </div>

            <div x-data="{ show: false }">
                <label for="pin-change-new" class="mb-1.5 block text-sm font-medium text-zinc-700">New PIN</label>
                <div class="relative">
                    <input
                        wire:model="new_pin"
                        id="pin-change-new"
                        :type="show ? 'text' : 'password'"
                        inputmode="numeric"
                        maxlength="4"
                        autocomplete="off"
                        placeholder="4 digits"
                        class="w-full rounded-[12px] border border-zinc-300 bg-white px-3.5 py-2.5 pr-11 text-sm tracking-[0.5em] text-black placeholder:tracking-normal placeholder:text-zinc-600 outline-none transition-colors focus:border-blue-500 focus:ring-2 focus:ring-blue-500/15"
                    />
                    <button type="button" @click="show = !show" class="absolute right-3 top-1/2 -translate-y-1/2 text-zinc-600" :aria-label="show ? 'Hide PIN' : 'Show PIN'">
                        <svg x-show="!show" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M2.036 12.322a1.012 1.012 0 010-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.638 0-8.573-3.007-9.963-7.178z"/>
                            <path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/>
                        </svg>
                        <svg x-show="show" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5" aria-hidden="true" style="display:none;">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M3.98 8.223A10.477 10.477 0 001.934 12C3.226 16.338 7.244 19.5 12 19.5c.993 0 1.953-.138 2.863-.395M6.228 6.228A10.45 10.45 0 0112 4.5c4.756 0 8.773 3.162 10.065 7.498a10.523 10.523 0 01-4.293 5.774M6.228 6.228L3 3m3.228 3.228l3.65 3.65m7.244 7.244L19.5 19.5m-2.876-2.876L13.875 13.875M9.878 9.878a3 3 0 105.249 5.249"/>
                        </svg>
                    </button>
                </div>
                @error('new_pin') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
            </div>

            <div x-data="{ show: false }">
                <label for="pin-change-confirm" class="mb-1.5 block text-sm font-medium text-zinc-700">Confirm new PIN</label>
                <div class="relative">
                    <input
                        wire:model="new_pin_confirmation"
                        id="pin-change-confirm"
                        :type="show ? 'text' : 'password'"
                        inputmode="numeric"
                        maxlength="4"
                        autocomplete="off"
                        placeholder="Re-enter new PIN"
                        class="w-full rounded-[12px] border border-zinc-300 bg-white px-3.5 py-2.5 pr-11 text-sm tracking-[0.5em] text-black placeholder:tracking-normal placeholder:text-zinc-600 outline-none transition-colors focus:border-blue-500 focus:ring-2 focus:ring-blue-500/15"
                    />
                    <button type="button" @click="show = !show" class="absolute right-3 top-1/2 -translate-y-1/2 text-zinc-600" :aria-label="show ? 'Hide PIN' : 'Show PIN'">
                        <svg x-show="!show" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M2.036 12.322a1.012 1.012 0 010-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.638 0-8.573-3.007-9.963-7.178z"/>
                            <path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/>
                        </svg>
                        <svg x-show="show" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5" aria-hidden="true" style="display:none;">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M3.98 8.223A10.477 10.477 0 001.934 12C3.226 16.338 7.244 19.5 12 19.5c.993 0 1.953-.138 2.863-.395M6.228 6.228A10.45 10.45 0 0112 4.5c4.756 0 8.773 3.162 10.065 7.498a10.523 10.523 0 01-4.293 5.774M6.228 6.228L3 3m3.228 3.228l3.65 3.65m7.244 7.244L19.5 19.5m-2.876-2.876L13.875 13.875M9.878 9.878a3 3 0 105.249 5.249"/>
                        </svg>
                    </button>
                </div>
                @error('new_pin_confirmation') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
            </div>

            <div class="flex items-center gap-3 pt-1">
                <button type="submit" class="inline-flex items-center gap-2 rounded-[12px] bg-blue-600 px-5 py-2.5 text-sm font-semibold text-white shadow-sm shadow-blue-600/25 transition-colors hover:bg-blue-700 focus:outline-none focus-visible:ring-2 focus-visible:ring-blue-500/50">
                    Change PIN
                </button>
                <x-action-message on="pin-updated" class="text-sm font-medium text-emerald-600">
                    {{ __('Saved.') }}
                </x-action-message>
            </div>
        </form>

        {{-- Remove the PIN (requires the account password) --}}
        <div x-data="{ open: false }" class="mt-6 border-t border-zinc-100 pt-5">
            <button type="button" @click="open = !open" class="text-sm font-semibold text-red-600 underline underline-offset-2 transition-colors hover:text-red-700">
                Remove transaction PIN
            </button>
            <div x-show="open" x-collapse x-cloak class="mt-3">
                <form wire:submit="removePin" class="space-y-3">
                    <p class="text-xs text-zinc-600">Enter your account password to remove the PIN. Wallet payments will no longer ask for it.</p>
                    <div>
                        <input
                            wire:model="remove_password"
                            type="password"
                            autocomplete="current-password"
                            placeholder="Account password"
                            class="w-full rounded-[12px] border border-zinc-300 bg-white px-3.5 py-2.5 text-sm text-black placeholder:text-zinc-600 outline-none transition-colors focus:border-red-500 focus:ring-2 focus:ring-red-500/15"
                        />
                        @error('remove_password') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                    </div>
                    <button type="submit" class="inline-flex items-center gap-2 rounded-[12px] bg-red-600 px-4 py-2 text-sm font-semibold text-white transition-colors hover:bg-red-700">
                        Remove PIN
                    </button>
                </form>
            </div>
        </div>
    @endif
</div>

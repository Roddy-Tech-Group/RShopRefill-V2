<?php

use App\Domain\Wallet\Services\TransactionPinService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;

new #[Layout('components.layouts.dashboard')] class extends Component {
    #[Url]
    public string $token = '';

    public string $pin = '';
    public string $pin_confirmation = '';

    public function mount(): void
    {
        if (empty($this->token)) {
            session()->flash('error', 'Invalid or missing reset token.');
            $this->redirectRoute('dashboard.security', navigate: true);
        }
    }

    public function resetPin(TransactionPinService $pinService): void
    {
        $this->validate([
            'pin' => ['required', 'digits:4', 'confirmed'],
        ], [], ['pin' => 'PIN']);

        try {
            $pinService->confirmReset(Auth::user(), $this->token, $this->pin);
        } catch (ValidationException $e) {
            $this->addError('token', $e->validator->errors()->first());
            return;
        }

        session()->flash('status', 'Transaction PIN has been successfully reset.');
        $this->redirectRoute('dashboard.security', navigate: true);
    }
}; ?>

<div class="mx-auto max-w-md pt-12">
    <div class="mb-8 text-center">
        <h1 class="text-2xl font-bold tracking-tight text-black dark:text-white">Reset Transaction PIN</h1>
        <p class="mt-2 text-sm text-zinc-600 dark:text-zinc-400">Set a new 4-digit PIN for your wallet transactions.</p>
    </div>

    <div class="rounded-[12px] bg-[#eff6ff] p-6 dash-shimmer border border-zinc-200 shadow-md shadow-zinc-900/[0.06] dark:bg-[#0c1a36]/50 dark:border-zinc-700 dark:shadow-none">
        @error('token')
            <div class="mb-5 flex items-start gap-2 rounded-[12px] bg-red-50 px-3.5 py-3 ring-1 ring-red-100">
                <svg class="mt-0.5 h-4 w-4 shrink-0 text-red-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m9-.75a9 9 0 11-18 0 9 9 0 0118 0zm-9 3.75h.008v.008H12v-.008z"/>
                </svg>
                <p class="text-xs font-medium text-red-700">{{ $message }}</p>
            </div>
        @enderror

        <form wire:submit="resetPin" class="space-y-5">
            <div x-data="{ show: false }">
                <label for="pin-new" class="mb-1.5 block text-sm font-medium text-zinc-700 dark:text-zinc-300">New PIN</label>
                <div class="relative">
                    <input
                        wire:model="pin"
                        id="pin-new"
                        :type="show ? 'text' : 'password'"
                        inputmode="numeric"
                        maxlength="4"
                        autocomplete="off"
                        placeholder="4 digits"
                        class="w-full rounded-[12px] border border-zinc-300 bg-white px-3.5 py-2.5 pr-11 text-sm tracking-[0.5em] text-black placeholder:tracking-normal placeholder:text-zinc-600 outline-none transition-colors focus:border-blue-500 focus:ring-2 focus:ring-blue-500/15 dark:border-zinc-700 dark:bg-[#1d3252] dark:text-white dark:placeholder:text-zinc-400"
                    />
                    <button type="button" @click="show = !show" class="absolute right-3 top-1/2 -translate-y-1/2 text-zinc-600 dark:text-zinc-400" :aria-label="show ? 'Hide PIN' : 'Show PIN'">
                        <svg x-show="!show" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M2.036 12.322a1.012 1.012 0 010-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.638 0-8.573-3.007-9.963-7.178z"/>
                            <path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/>
                        </svg>
                        <svg x-show="show" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5" style="display:none;">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M3.98 8.223A10.477 10.477 0 001.934 12C3.226 16.338 7.244 19.5 12 19.5c.993 0 1.953-.138 2.863-.395M6.228 6.228A10.45 10.45 0 0112 4.5c4.756 0 8.773 3.162 10.065 7.498a10.523 10.523 0 01-4.293 5.774M6.228 6.228L3 3m3.228 3.228l3.65 3.65m7.244 7.244L19.5 19.5m-2.876-2.876L13.875 13.875M9.878 9.878a3 3 0 105.249 5.249"/>
                        </svg>
                    </button>
                </div>
                @error('pin') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
            </div>

            <div x-data="{ show: false }">
                <label for="pin-confirm" class="mb-1.5 block text-sm font-medium text-zinc-700 dark:text-zinc-300">Confirm PIN</label>
                <div class="relative">
                    <input
                        wire:model="pin_confirmation"
                        id="pin-confirm"
                        :type="show ? 'text' : 'password'"
                        inputmode="numeric"
                        maxlength="4"
                        autocomplete="off"
                        placeholder="Re-enter PIN"
                        class="w-full rounded-[12px] border border-zinc-300 bg-white px-3.5 py-2.5 pr-11 text-sm tracking-[0.5em] text-black placeholder:tracking-normal placeholder:text-zinc-600 outline-none transition-colors focus:border-blue-500 focus:ring-2 focus:ring-blue-500/15 dark:border-zinc-700 dark:bg-[#1d3252] dark:text-white dark:placeholder:text-zinc-400"
                    />
                    <button type="button" @click="show = !show" class="absolute right-3 top-1/2 -translate-y-1/2 text-zinc-600 dark:text-zinc-400" :aria-label="show ? 'Hide PIN' : 'Show PIN'">
                        <svg x-show="!show" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M2.036 12.322a1.012 1.012 0 010-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.638 0-8.573-3.007-9.963-7.178z"/>
                            <path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/>
                        </svg>
                        <svg x-show="show" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5" style="display:none;">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M3.98 8.223A10.477 10.477 0 001.934 12C3.226 16.338 7.244 19.5 12 19.5c.993 0 1.953-.138 2.863-.395M6.228 6.228A10.45 10.45 0 0112 4.5c4.756 0 8.773 3.162 10.065 7.498a10.523 10.523 0 01-4.293 5.774M6.228 6.228L3 3m3.228 3.228l3.65 3.65m7.244 7.244L19.5 19.5m-2.876-2.876L13.875 13.875M9.878 9.878a3 3 0 105.249 5.249"/>
                        </svg>
                    </button>
                </div>
                @error('pin_confirmation') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
            </div>

            <div class="pt-2 text-center">
                <button type="submit" class="w-full inline-flex items-center justify-center gap-2 rounded-[12px] bg-blue-600 px-5 py-3 text-sm font-semibold text-white shadow-sm shadow-blue-600/25 transition-colors hover:bg-blue-700 focus:outline-none focus-visible:ring-2 focus-visible:ring-blue-500/50">
                    Reset PIN
                </button>
                <a href="{{ route('dashboard.security') }}" wire:navigate class="mt-4 inline-block text-sm font-medium text-zinc-500 hover:text-zinc-700 dark:text-zinc-400 dark:hover:text-zinc-300">
                    Cancel and return to Security
                </a>
            </div>
        </form>
    </div>
</div>

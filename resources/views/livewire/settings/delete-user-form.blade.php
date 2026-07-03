<?php

use App\Livewire\Actions\Logout;
use Illuminate\Support\Facades\Auth;
use Livewire\Volt\Component;

new class extends Component {
    public string $password = '';

    /**
     * Delete the currently authenticated user.
     */
    public function deleteUser(Logout $logout): void
    {
        $this->validate([
            'password' => ['required', 'string', 'current_password'],
        ]);

        tap(Auth::user(), $logout(...))->delete();

        $this->redirect('/', navigate: true);
    }
}; ?>

<section
    x-data="{ open: {{ $errors->isNotEmpty() ? 'true' : 'false' }} }"
    x-effect="open ? window.rshopScrollLock?.lock() : window.rshopScrollLock?.unlock()"
    @keydown.escape.window="open = false"
    class="flex items-center justify-between gap-3"
>
    <div class="min-w-0">
        <p class="text-sm font-semibold text-black dark:text-white">{{ __('Delete account') }}</p>
        <p class="mt-0.5 text-xs text-zinc-600 dark:text-zinc-400">{{ __('Permanent and cannot be undone') }}</p>
    </div>

    <button
        type="button"
        @click="open = true"
        class="shrink-0 text-sm font-medium text-red-600 transition-colors hover:text-red-700 hover:underline focus:outline-none focus-visible:underline dark:text-red-400 dark:hover:text-red-300"
    >
        {{ __('Delete account') }}
    </button>

    {{-- Custom mobile-responsive glass modal. Full-bleed on small screens with
         safe-area padding; max-w-md card on tablet+. Buttons stack on mobile,
         go inline on sm+. Matches the project's glass-modal pattern. --}}
    <div
        x-show="open"
        x-cloak
        x-transition:enter="transition-opacity ease-out duration-200"
        x-transition:enter-start="opacity-0"
        x-transition:enter-end="opacity-100"
        x-transition:leave="transition-opacity ease-in duration-150"
        x-transition:leave-start="opacity-100"
        x-transition:leave-end="opacity-0"
        class="fixed inset-0 z-[80] flex items-end justify-center sm:items-center sm:p-4"
        role="dialog"
        aria-modal="true"
        aria-labelledby="delete-account-title"
    >
        <div @click="open = false" class="absolute inset-0 bg-zinc-900/45" aria-hidden="true"></div>

        <div
            x-show="open"
            x-cloak
            x-transition:enter="transition duration-300 ease-[cubic-bezier(0.22,1,0.36,1)]"
            x-transition:enter-start="opacity-0 translate-y-8 sm:translate-y-4 sm:scale-95"
            x-transition:enter-end="opacity-100 translate-y-0 sm:scale-100"
            x-transition:leave="transition duration-200 ease-in"
            x-transition:leave-start="opacity-100 translate-y-0"
            x-transition:leave-end="opacity-0 translate-y-4"
            class="glass-panel relative w-full max-w-md rounded-t-3xl p-5 shadow-2xl shadow-zinc-900/30 sm:rounded-[12px]"
            style="padding-bottom: max(1.25rem, env(safe-area-inset-bottom));"
        >
            {{-- Drag handle (mobile only) --}}
            <div class="-mt-1 mb-3 flex justify-center sm:hidden">
                <span class="h-1.5 w-10 rounded-full bg-zinc-300 dark:bg-white/25"></span>
            </div>

            <form wire:submit="deleteUser" class="space-y-5">
                <div class="flex items-start justify-between gap-3">
                    <div>
                        <h3 id="delete-account-title" class="text-lg font-bold text-zinc-900 dark:text-white">{{ __('Delete your account?') }}</h3>
                        <p class="mt-1 text-sm text-zinc-600 dark:text-zinc-300">
                            {{ __('Everything will be permanently removed: orders, wallet balance, and transaction history. Enter your password to confirm.') }}
                        </p>
                    </div>
                    <x-close-button @click="open = false" />
                </div>

                <div>
                    <label for="delete-password" class="mb-1.5 block text-xs font-semibold text-zinc-700 dark:text-zinc-300">{{ __('Password') }}</label>
                    <input
                        wire:model="password"
                        id="delete-password"
                        type="password"
                        name="password"
                        autocomplete="current-password"
                        required
                        class="w-full rounded-[12px] border border-zinc-300 bg-white px-3.5 py-2.5 text-sm text-black outline-none transition-colors focus:border-blue-500 focus:ring-2 focus:ring-blue-500/15 dark:border-white/15 dark:bg-[#0c1a36] dark:text-white"
                        placeholder="••••••••"
                    >
                    @error('password') <p class="mt-1 text-xs text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
                </div>

                <div class="flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
                    <button
                        type="button"
                        @click="open = false"
                        class="rounded-[12px] border border-zinc-200 bg-white px-4 py-2.5 text-sm font-semibold text-zinc-700 transition-colors hover:bg-zinc-50 dark:border-white/15 dark:bg-white/10 dark:text-white dark:hover:bg-white/15"
                    >
                        {{ __('Cancel') }}
                    </button>
                    <button
                        type="submit"
                        class="rounded-[12px] bg-red-600 px-4 py-2.5 text-sm font-semibold text-white shadow-sm shadow-red-600/25 transition-colors hover:bg-red-700"
                    >
                        {{ __('Delete account') }}
                    </button>
                </div>
            </form>
        </div>
    </div>
</section>

<?php

use App\Domain\Auth\Exceptions\AccountClosureException;
use App\Domain\Auth\Services\AccountClosureService;
use App\Livewire\Actions\Logout;
use App\Models\AccountClosureFeedback;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Livewire\Volt\Component;

/**
 * Closing an account: the customer confirms with their password (or, for a
 * Google-only account with no password, by typing their email address), is
 * signed out, and the account is anonymised after the grace period unless
 * they sign in again first. Nothing is deleted here; see AccountClosureService.
 */
new class extends Component {
    public string $password = '';

    public string $confirmation = '';

    /** @var list<string> */
    public array $reasons = [];

    public string $comment = '';

    public function requiresPassword(): bool
    {
        return Auth::user()->password !== null;
    }

    public function graceDays(): int
    {
        return app(AccountClosureService::class)->graceDays();
    }

    /**
     * @return list<string>
     */
    public function blockers(): array
    {
        return app(AccountClosureService::class)->blockers(Auth::user());
    }

    /**
     * @return list<string>
     */
    public function forfeitedBalances(): array
    {
        return app(AccountClosureService::class)->forfeitedBalances(Auth::user());
    }

    public function closeAccount(Logout $logout): void
    {
        $user = Auth::user();

        if ($this->requiresPassword()) {
            $this->validate([
                'password' => ['required', 'string', 'current_password'],
            ]);
        } else {
            $this->validate([
                'confirmation' => ['required', 'string', function (string $attribute, mixed $value, \Closure $fail) use ($user): void {
                    if (mb_strtolower(trim((string) $value)) !== mb_strtolower($user->email)) {
                        $fail(__('Type your email address exactly as it appears on your account.'));
                    }
                }],
            ]);
        }

        $this->validate([
            'reasons' => ['array'],
            'reasons.*' => ['string', 'in:'.implode(',', array_keys(AccountClosureFeedback::REASONS))],
            'comment' => ['nullable', 'string', 'max:500'],
        ]);

        try {
            app(AccountClosureService::class)->request($user, $this->reasons, $this->comment);
        } catch (AccountClosureException $e) {
            throw ValidationException::withMessages(['closure' => $e->getMessage()]);
        }

        $logout();

        session()->flash('status', __('Your account will close in :days days. Sign in again before then if you change your mind.', ['days' => $this->graceDays()]));

        $this->redirect(route('login'), navigate: true);
    }
}; ?>

<section
    x-data="{ open: {{ $errors->isNotEmpty() ? 'true' : 'false' }} }"
    x-effect="open ? window.rshopScrollLock?.lock() : window.rshopScrollLock?.unlock()"
    @keydown.escape.window="open = false"
    class="flex items-center justify-between gap-3"
>
    @php
        $blockers = $this->blockers();
        $forfeited = $this->forfeitedBalances();
        $graceDays = $this->graceDays();
        $requiresPassword = $this->requiresPassword();
    @endphp

    <div class="min-w-0">
        <p class="text-sm font-semibold text-black dark:text-white">{{ __('Close account') }}</p>
        <p class="mt-0.5 text-xs text-zinc-600 dark:text-zinc-400">{{ __('Closes after a :days-day grace period', ['days' => $graceDays]) }}</p>
    </div>

    <button
        type="button"
        @click="open = true"
        class="shrink-0 text-sm font-medium text-red-600 transition-colors hover:text-red-700 hover:underline focus:outline-none focus-visible:underline dark:text-red-400 dark:hover:text-red-300"
    >
        {{ __('Close account') }}
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
        aria-labelledby="close-account-title"
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

            <form wire:submit="closeAccount" class="space-y-5">
                <div class="flex items-start justify-between gap-3">
                    <div>
                        <h3 id="close-account-title" class="text-lg font-bold text-zinc-900 dark:text-white">{{ __('Close your account?') }}</h3>
                        <p class="mt-1 text-sm text-zinc-600 dark:text-zinc-300">
                            {{ __('You will be signed out now, and your account closes in :days days. Sign in again before then to keep it.', ['days' => $graceDays]) }}
                        </p>
                    </div>
                    <x-close-button @click="open = false" />
                </div>

                @if ($blockers !== [])
                    <div class="rounded-[12px] border border-amber-200 bg-amber-50 px-3.5 py-3 text-sm text-amber-800 dark:border-amber-500/30 dark:bg-amber-500/10 dark:text-amber-200" role="alert">
                        <p class="font-semibold">{{ __('Before you can close your account') }}</p>
                        <ul class="mt-1.5 list-disc space-y-1 pl-4 text-xs leading-relaxed">
                            @foreach ($blockers as $blocker)
                                <li>{{ $blocker }}</li>
                            @endforeach
                        </ul>
                    </div>
                @endif

                @if ($forfeited !== [])
                    <p class="rounded-[12px] border border-zinc-200 bg-zinc-50 px-3.5 py-2.5 text-xs text-zinc-600 dark:border-white/15 dark:bg-white/5 dark:text-zinc-300">
                        {{ __('Your remaining balance of :amount is too small to spend and will be forfeited when the account closes.', ['amount' => implode(', ', $forfeited)]) }}
                    </p>
                @endif

                @if ($blockers === [])
                    <fieldset>
                        <legend class="text-xs font-semibold text-zinc-700 dark:text-zinc-300">{{ __('Mind telling us why? (optional)') }}</legend>
                        <div class="mt-2 grid gap-1.5 sm:grid-cols-2">
                            @foreach (\App\Models\AccountClosureFeedback::REASONS as $value => $label)
                                <label class="flex cursor-pointer items-center gap-2 rounded-[10px] border border-zinc-200 bg-white px-3 py-2 text-xs text-zinc-700 transition-colors hover:border-zinc-300 has-[:checked]:border-blue-500 has-[:checked]:bg-blue-50 dark:border-white/15 dark:bg-white/5 dark:text-zinc-200 dark:has-[:checked]:bg-blue-500/15">
                                    <input type="checkbox" wire:model="reasons" value="{{ $value }}" class="h-4 w-4 rounded border-zinc-300 text-blue-600 focus:ring-blue-500">
                                    <span>{{ __($label) }}</span>
                                </label>
                            @endforeach
                        </div>
                        <textarea
                            wire:model="comment"
                            rows="2"
                            maxlength="500"
                            placeholder="{{ __('Anything else you want us to know?') }}"
                            class="mt-2 w-full rounded-[12px] border border-zinc-300 bg-white px-3.5 py-2.5 text-sm text-black outline-none transition-colors placeholder:text-zinc-400 focus:border-blue-500 focus:ring-2 focus:ring-blue-500/15 dark:border-white/15 dark:bg-[#0c1a36] dark:text-white"
                        ></textarea>
                        @error('comment') <p class="mt-1 text-xs text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
                    </fieldset>
                @endif

                @error('closure')
                    <p class="rounded-[12px] border border-red-200 bg-red-50 px-3.5 py-2.5 text-xs text-red-700 dark:border-red-500/30 dark:bg-red-500/10 dark:text-red-300" role="alert">{{ $message }}</p>
                @enderror

                @if ($requiresPassword)
                    <div>
                        <label for="close-account-password" class="mb-1.5 block text-xs font-semibold text-zinc-700 dark:text-zinc-300">{{ __('Password') }}</label>
                        <input
                            wire:model="password"
                            id="close-account-password"
                            type="password"
                            name="password"
                            autocomplete="current-password"
                            required
                            class="w-full rounded-[12px] border border-zinc-300 bg-white px-3.5 py-2.5 text-sm text-black outline-none transition-colors focus:border-blue-500 focus:ring-2 focus:ring-blue-500/15 dark:border-white/15 dark:bg-[#0c1a36] dark:text-white"
                            placeholder="••••••••"
                        >
                        @error('password') <p class="mt-1 text-xs text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
                    </div>
                @else
                    <div>
                        <label for="close-account-confirmation" class="mb-1.5 block text-xs font-semibold text-zinc-700 dark:text-zinc-300">{{ __('Type your email address to confirm') }}</label>
                        <input
                            wire:model="confirmation"
                            id="close-account-confirmation"
                            type="email"
                            name="confirmation"
                            autocomplete="off"
                            required
                            class="w-full rounded-[12px] border border-zinc-300 bg-white px-3.5 py-2.5 text-sm text-black outline-none transition-colors focus:border-blue-500 focus:ring-2 focus:ring-blue-500/15 dark:border-white/15 dark:bg-[#0c1a36] dark:text-white"
                            placeholder="{{ Auth::user()->email }}"
                        >
                        @error('confirmation') <p class="mt-1 text-xs text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
                    </div>
                @endif

                <div class="flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
                    <button
                        type="button"
                        @click="open = false"
                        class="rounded-[12px] border border-zinc-200 bg-white px-4 py-2.5 text-sm font-semibold text-zinc-700 transition-colors hover:bg-zinc-50 dark:border-white/15 dark:bg-white/10 dark:text-white dark:hover:bg-white/15"
                    >
                        {{ __('Keep my account') }}
                    </button>
                    <button
                        type="submit"
                        @disabled($blockers !== [])
                        class="rounded-[12px] bg-red-600 px-4 py-2.5 text-sm font-semibold text-white shadow-sm shadow-red-600/25 transition-colors hover:bg-red-700 disabled:cursor-not-allowed disabled:opacity-50"
                    >
                        {{ __('Close account') }}
                    </button>
                </div>
            </form>
        </div>
    </div>
</section>

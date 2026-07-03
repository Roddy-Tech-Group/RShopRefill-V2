<?php

use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('components.layouts.dashboard')] class extends Component {
    //
}; ?>

<div class="mx-auto flex max-w-3xl flex-col gap-6">
    {{-- Page heading --}}
    <div class="text-center sm:text-left">
        <h1 class="text-2xl font-bold tracking-tight text-black sm:text-3xl">Appearance</h1>
        <p class="mt-1 text-sm text-zinc-600">Choose how {{ $siteName }} looks for you. System matches your device setting.</p>
    </div>

    {{-- Theme picker. Hand-rolled segmented control instead of flux:radio.group
         so the active-pill colour matches the rest of the project: zinc-200 in
         light mode, pure black in dark mode (same palette the sidebar active
         link uses). The radio group is bound to window.setTheme via Alpine so
         it persists through reloads, exactly like the old flux variant did. --}}
    <div
        x-data="{ theme: window.themeChoice ? window.themeChoice() : (localStorage.getItem('theme') || 'system'), softDark: window.pureDarkOn ? ! window.pureDarkOn() : false }"
        x-init="
            $watch('theme', v => window.setTheme(v));
            $watch('softDark', v => { window.setPureDark(! v); if (v && ! window.themeIsDark()) theme = 'dark'; });
        "
        class="rounded-[12px] bg-[#eff6ff] p-6 dash-shimmer border border-zinc-200 shadow-md shadow-zinc-900/[0.06] transition-colors hover:border-green-200 dark:border-zinc-700 dark:hover:border-white dark:shadow-none"
    >
        <div class="mb-5">
            <h2 class="text-base font-semibold text-black">Theme</h2>
            <p class="mt-0.5 text-xs text-zinc-600">Switch between light and dark mode, or follow your system.</p>
        </div>

        <div
            role="radiogroup"
            aria-label="Theme"
            class="grid grid-cols-3 gap-1 rounded-[12px] bg-zinc-100 p-1 dark:bg-[#0c1a36]"
        >
            @foreach ([
                ['value' => 'light',  'label' => 'Light', 'icon' => 'icons.theme-light'],
                ['value' => 'dark',   'label' => 'Dark',  'icon' => 'icons.theme-dark'],
                ['value' => 'system', 'label' => 'Auto',  'icon' => 'icons.theme-auto'],
            ] as $opt)
                <button
                    type="button"
                    role="radio"
                    @click="theme = '{{ $opt['value'] }}'"
                    :aria-checked="(theme === '{{ $opt['value'] }}').toString()"
                    :class="theme === '{{ $opt['value'] }}'
                        ? 'text-black ring-1 ring-blue-400 dark:text-white dark:ring-blue-500/60'
                        : 'text-zinc-600 hover:text-zinc-900 dark:text-zinc-400 dark:hover:text-white'"
                    class="inline-flex items-center justify-center gap-2 rounded-[12px] px-4 py-2.5 text-sm font-semibold transition-colors"
                >
                    {{-- Inline animated SVG inherits currentColor, so it follows
                         the button's own text colour in every state. --}}
                    <x-dynamic-component :component="$opt['icon']" class="h-4 w-4" />
                    {{ $opt['label'] }}
                </button>
            @endforeach
        </div>

        {{-- Soft dark (navy). Dark mode is pure black by default; flipping this on
             swaps it for the softer navy palette (and switches to dark right away
             so the change is visible). Saved locally via window.setPureDark, which
             stores the inverse - the theme engine drops `.pure-dark` from <html>. --}}
        <div class="mt-5 flex items-center justify-between gap-4 border-t border-zinc-200 pt-5 dark:border-zinc-700">
            <div class="min-w-0">
                <p class="text-sm font-semibold text-black dark:text-white">Soft dark</p>
                <p class="mt-0.5 text-xs text-zinc-600 dark:text-zinc-400">Use the softer navy palette instead of pure black. Dark mode is pure black by default.</p>
            </div>
            <button
                type="button"
                role="switch"
                @click="softDark = ! softDark"
                :aria-checked="softDark.toString()"
                aria-label="Soft dark"
                :class="softDark ? 'bg-blue-600' : 'bg-zinc-300 dark:bg-[#26416b]'"
                class="relative inline-flex h-6 w-11 shrink-0 items-center rounded-full transition-colors focus:outline-none focus-visible:ring-2 focus-visible:ring-blue-500/40"
            >
                <span :class="softDark ? 'translate-x-6' : 'translate-x-1'" class="inline-block h-4 w-4 rounded-full bg-white shadow transition-transform"></span>
            </button>
        </div>
    </div>
</div>

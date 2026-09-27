<?php

use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('components.layouts.dashboard')] class extends Component {
    //
}; ?>

<div class="mx-auto flex max-w-3xl flex-col gap-6">
    {{-- Page heading --}}
    <div class="text-center sm:text-left">
        <h1 class="text-2xl font-bold tracking-tight text-black sm:text-3xl">{{ __('common.appearance') }}</h1>
        <p class="mt-1 text-sm text-zinc-600">{{ __('common.appearance_intro', ['site' => $siteName]) }}</p>
    </div>

    {{-- Theme picker. Hand-rolled segmented control instead of flux:radio.group
         so the active-pill colour matches the rest of the project: zinc-200 in
         light mode, pure black in dark mode (same palette the sidebar active
         link uses). The radio group is bound to window.setTheme via Alpine so
         it persists through reloads, exactly like the old flux variant did. --}}
    <div
        x-data="{ theme: window.themeChoice ? window.themeChoice() : (localStorage.getItem('theme') || 'system')}"
        x-init="
            $watch('theme', v => window.setTheme(v));
        "
        class="rounded-[12px] bg-[#eff6ff] p-6 dash-shimmer shadow-md shadow-zinc-900/[0.06] dark:shadow-none"
    >
        <div class="mb-5">
            <h2 class="text-base font-semibold text-black">{{ __('common.theme') }}</h2>
            <p class="mt-0.5 text-xs text-zinc-600">{{ __('common.theme_hint') }}</p>
        </div>

        {{-- The chosen option is a solid tile, not an outline, and it is styled
             from aria-checked in plain CSS. Alpine already sets that attribute,
             and a rule per theme cannot lose the specificity tie that stacked
             dark: variants fall into, which is what left this control looking
             unset in dark mode. --}}
        <style>
            /* The solid tile is one thumb that slides between the options; the
               options themselves only change colour. */
            .theme-thumb { background-color: #0c1337; }
            html.dark .theme-thumb { background-color: #fdfdfd; }
            .theme-option[aria-checked="true"] { color: #ffffff; }
            html.dark .theme-option[aria-checked="true"] { color: #0c1337; }
            .theme-option:not([aria-checked="true"]):hover { background-color: rgba(12, 19, 55, 0.06); color: #0c1337; }
            html.dark .theme-option:not([aria-checked="true"]):hover { background-color: rgba(255, 255, 255, 0.08); color: #ffffff; }
        </style>
        @php
            $themeOptions = [
                ['value' => 'light',  'label' => __('common.theme_light'), 'icon' => 'icons.theme-light'],
                ['value' => 'dark',   'label' => __('common.theme_dark'), 'icon' => 'icons.theme-dark'],
                ['value' => 'system', 'label' => __('common.theme_auto'), 'icon' => 'icons.theme-auto'],
            ];
        @endphp
        <div
            role="radiogroup"
            aria-label="{{ __('common.theme') }}"
            {{-- The slide is armed one tick after the first paint, so the thumb
                 lands on the saved choice without animating in from the left. --}}
            x-data="{ slide: false }"
            x-init="$nextTick(() => slide = true)"
            class="relative grid grid-cols-3 gap-1 rounded-[12px] bg-zinc-100 p-1 dark:bg-white/5"
        >
            {{-- The thumb: one column wide (three columns, two 0.25rem gaps
                 inside the 0.25rem padding), moved by the chosen option's index
                 across a column plus a gap. --}}
            <span
                aria-hidden="true"
                class="theme-thumb pointer-events-none absolute inset-y-1 left-1 w-[calc((100%-0.5rem)/3)] rounded-[12px] duration-300 ease-[cubic-bezier(0.22,1,0.36,1)] motion-reduce:transition-none"
                :class="slide && 'transition-transform'"
                :style="'transform: translateX(calc(' + {!! "['".implode("','", array_column($themeOptions, 'value'))."']" !!}.indexOf(theme) + ' * (100% + 0.25rem)))'"
            ></span>
            @foreach ($themeOptions as $opt)
                <button
                    type="button"
                    role="radio"
                    @click="theme = '{{ $opt['value'] }}'"
                    :aria-checked="(theme === '{{ $opt['value'] }}').toString()"
                    class="theme-option relative z-10 inline-flex items-center justify-center gap-2 rounded-[12px] px-4 py-2.5 text-sm font-semibold text-zinc-600 transition-colors duration-300 dark:text-zinc-400"
                >
                    {{-- Inline animated SVG inherits currentColor, so it follows
                         the button's own text colour in every state. --}}
                    <x-dynamic-component :component="$opt['icon']" class="h-4 w-4" />
                    {{ $opt['label'] }}
                </button>
            @endforeach
        </div>

    </div>
</div>

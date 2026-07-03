{{-- Global theme picker - two variants, one component, one source of truth.

       variant="dropdown" (default): sun/moon icon trigger that pops a panel
         with Light / Dark / Auto options. Use in nav bars and other tight
         spaces where an inline segmented control would crowd the layout.
         Supports `drop="down"` (default) or `drop="up"` for the panel side.

       variant="segmented": always-visible inline pill with Light / Dark /
         Auto as three segments inside a blue-bordered chip. Use in the
         footer and any surface that has room for a labelled control.

     Both share the same window.setTheme backing and stay in sync via the
     `theme-changed` window event, so picking a theme from one updates any
     other instance on the page instantly. --}}
@props([
    'variant' => 'dropdown',
    'drop'    => 'down',
])

@php
    // Each mode maps to its animated inline-SVG icon component
    // (components/icons/theme-*). Inline SVGs inherit currentColor, so the
    // same icon recolors automatically on light, dark, and selected rows.
    $themeChoices = [
        ['value' => 'light',  'label' => 'Light', 'icon' => 'icons.theme-light'],
        ['value' => 'dark',   'label' => 'Dark',  'icon' => 'icons.theme-dark'],
        ['value' => 'system', 'label' => 'Auto',  'icon' => 'icons.theme-auto'],
    ];
@endphp

{{-- Flashy switch animation: the freshly picked control does a full spin-pop.
     Shared by both variants; safe to repeat when several toggles render. --}}
<style>
    @keyframes theme-toggle-pop {
        0%   { transform: rotate(0deg) scale(1); }
        45%  { transform: rotate(180deg) scale(1.35); }
        100% { transform: rotate(360deg) scale(1); }
    }
    .theme-toggle-pop { animation: theme-toggle-pop 0.6s cubic-bezier(0.34, 1.56, 0.64, 1); }
    @media (prefers-reduced-motion: reduce) { .theme-toggle-pop { animation: none; } }
</style>

@if ($variant === 'segmented')
    {{-- ── Segmented chip (Light / Dark / Auto) ─────────────────────── --}}
    <div
        x-data="{
            choice: (window.themeChoice ? window.themeChoice() : (localStorage.getItem('theme') || 'system')),
            softDark: (window.pureDarkOn ? ! window.pureDarkOn() : false),
            switching: false,
            pick(v) {
                this.choice = v;
                if (window.setTheme) { window.setTheme(v); }
                this.switching = true;
                clearTimeout(this._popTimer);
                this._popTimer = setTimeout(() => this.switching = false, 650);
            },
            toggleSoft() {
                this.softDark = ! this.softDark;
                // Pure black is the default, so the stored pure-dark flag is the inverse.
                if (window.setPureDark) { window.setPureDark(! this.softDark); }
                if (this.softDark && window.themeIsDark && ! window.themeIsDark()) {
                    this.choice = 'dark';
                    if (window.setTheme) { window.setTheme('dark'); }
                }
            },
        }"
        x-on:theme-changed.window="choice = (window.themeChoice ? window.themeChoice() : (localStorage.getItem('theme') || 'system'))"
        x-on:pure-dark-changed.window="softDark = (window.pureDarkOn ? ! window.pureDarkOn() : false)"
        {{ $attributes->class('inline-flex w-fit items-center gap-0.5 rounded-[12px] border border-blue-400 p-0.5 dark:border-blue-500/60') }}
        role="radiogroup"
        aria-label="Theme"
    >
        @foreach ($themeChoices as $opt)
            <button
                type="button"
                @click="pick('{{ $opt['value'] }}')"
                :class="[
                    choice === '{{ $opt['value'] }}'
                        ? 'text-blue-600 dark:text-blue-300'
                        : 'text-zinc-400 hover:text-zinc-600 dark:text-zinc-500 dark:hover:text-zinc-300',
                    switching && choice === '{{ $opt['value'] }}' ? 'theme-toggle-pop' : '',
                ].join(' ')"
                class="flex h-7 w-7 items-center justify-center rounded-[7px] transition-colors duration-150"
                :aria-checked="(choice === '{{ $opt['value'] }}').toString()"
                role="radio"
                aria-label="{{ $opt['label'] }}"
                title="{{ $opt['label'] }}"
            >
                <x-dynamic-component :component="$opt['icon']" class="h-4 w-4" />
            </button>
        @endforeach
        {{-- Soft dark: navy palette opt-in (dark mode is pure black by default),
             divided off after the three theme segments. Blue when active; turning
             it on forces dark so the change is visible. --}}
        <span class="mx-0.5 h-5 w-px shrink-0 bg-blue-400/40 dark:bg-blue-500/50" aria-hidden="true"></span>
        <button
            type="button"
            @click="toggleSoft()"
            :class="softDark ? 'bg-blue-600 text-white' : 'text-zinc-400 hover:text-zinc-600 dark:text-zinc-500 dark:hover:text-zinc-300'"
            class="flex h-7 w-7 items-center justify-center rounded-[7px] transition-colors duration-150"
            :aria-pressed="softDark.toString()"
            aria-label="Soft dark"
            title="Soft dark"
        >
            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" d="M21.752 15.002A9.718 9.718 0 0118 15.75c-5.385 0-9.75-4.365-9.75-9.75 0-1.33.266-2.597.748-3.752A9.753 9.753 0 003 11.25C3 16.635 7.365 21 12.75 21a9.753 9.753 0 009.002-5.998z"/>
            </svg>
        </button>
    </div>
@else
    {{-- ── Dropdown (sun/moon trigger + popup panel) ─────────────────── --}}
    @php
        $panelPos = $drop === 'up' ? 'bottom-full mb-2' : 'top-full mt-2';
        $offStart = $drop === 'up' ? 'translate-y-1' : '-translate-y-1';
    @endphp
    <div
        x-data="{
            choice: (window.themeChoice ? window.themeChoice() : (localStorage.getItem('theme') || 'system')),
            dark: (window.themeIsDark ? window.themeIsDark() : document.documentElement.classList.contains('dark')),
            open: false,
            locked: false,
            switching: false,
            softDark: (window.pureDarkOn ? ! window.pureDarkOn() : false),
            pick(v) {
                this.choice = v;
                if (window.setTheme) { window.setTheme(v); }
                this.open = false;
                this.locked = false;
                this.switching = true;
                clearTimeout(this._popTimer);
                this._popTimer = setTimeout(() => this.switching = false, 650);
            },
            toggleSoft() {
                this.softDark = ! this.softDark;
                // Pure black is the default, so the stored pure-dark flag is the inverse.
                if (window.setPureDark) { window.setPureDark(! this.softDark); }
                if (this.softDark && window.themeIsDark && ! window.themeIsDark()) {
                    this.choice = 'dark';
                    if (window.setTheme) { window.setTheme('dark'); }
                }
            },
        }"
        x-on:theme-changed.window="dark = $event.detail.dark; choice = (window.themeChoice ? window.themeChoice() : (localStorage.getItem('theme') || 'system'))"
        x-on:pure-dark-changed.window="softDark = (window.pureDarkOn ? ! window.pureDarkOn() : false)"
        @mouseenter="open = true"
        @mouseleave="if (! locked) open = false"
        @click.outside="open = false; locked = false"
        @keydown.escape.window="open = false; locked = false"
        class="relative"
    >
        <button
            type="button"
            @click="locked = ! locked; open = locked"
            :aria-expanded="open.toString()"
            aria-label="Theme"
            title="Theme"
            :class="switching ? 'theme-toggle-pop' : ''"
            {{ $attributes->class('inline-flex items-center justify-center transition-colors') }}
        >
            {{-- Trigger shows the icon that matches the user's CHOICE (not the
                 resolved theme). That means the Auto icon is visible when
                 system mode is selected - same surface area for all three
                 modes. Inline SVGs follow currentColor on whichever chrome
                 they sit on. --}}
            <x-icons.theme-light x-show="choice === 'light'" class="h-5 w-5 text-zinc-900 dark:text-white" />
            <x-icons.theme-dark x-show="choice === 'dark'" x-cloak class="h-5 w-5 text-zinc-900 dark:text-white" />
            <x-icons.theme-auto x-show="choice === 'system'" x-cloak class="h-5 w-5 text-zinc-900 dark:text-white" />
        </button>

        <div
            x-show="open"
            x-cloak
            x-transition:enter="transition ease-out duration-150"
            x-transition:enter-start="opacity-0 {{ $offStart }}"
            x-transition:enter-end="opacity-100 translate-y-0"
            x-transition:leave="transition ease-in duration-100"
            x-transition:leave-start="opacity-100 translate-y-0"
            x-transition:leave-end="opacity-0 {{ $offStart }}"
            class="absolute right-0 {{ $panelPos }} z-50 w-max overflow-hidden rounded-[5px] bg-white/70 backdrop-blur-md shadow-xl shadow-zinc-900/10 ring-2 ring-blue-400 dark:bg-[#1d3252]/70 dark:ring-blue-500/60"
            role="menu"
            aria-label="Theme"
        >
            <div class="p-0.5">
                @foreach ($themeChoices as $opt)
                    <button
                        type="button"
                        @click="pick('{{ $opt['value'] }}')"
                        :class="choice === '{{ $opt['value'] }}'
                            ? 'bg-blue-600 text-white'
                            : 'text-zinc-900 hover:bg-zinc-100 dark:text-zinc-100 dark:hover:bg-[#26416b]'"
                        class="flex w-full items-center gap-2 whitespace-nowrap rounded-[5px] py-1.5 pl-2 pr-4 text-left text-xs font-medium transition-colors"
                        role="menuitemradio"
                        :aria-checked="(choice === '{{ $opt['value'] }}').toString()"
                    >
                        <x-dynamic-component :component="$opt['icon']" class="h-4 w-4" />
                        <span>{{ $opt['label'] }}</span>
                    </button>
                @endforeach
                {{-- Soft dark: navy palette opt-in (dark mode is pure black by
                     default). Highlights blue when active; enabling it forces dark. --}}
                <div class="my-0.5 h-px bg-zinc-200/70 dark:bg-white/10" aria-hidden="true"></div>
                <button
                    type="button"
                    @click="toggleSoft()"
                    :class="softDark ? 'bg-blue-600 text-white' : 'text-zinc-900 hover:bg-zinc-100 dark:text-zinc-100 dark:hover:bg-[#26416b]'"
                    class="flex w-full items-center gap-2 whitespace-nowrap rounded-[5px] py-1.5 pl-2 pr-4 text-left text-xs font-medium transition-colors"
                    role="menuitemcheckbox"
                    :aria-checked="softDark.toString()"
                >
                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M21.752 15.002A9.718 9.718 0 0118 15.75c-5.385 0-9.75-4.365-9.75-9.75 0-1.33.266-2.597.748-3.752A9.753 9.753 0 003 11.25C3 16.635 7.365 21 12.75 21a9.753 9.753 0 009.002-5.998z"/>
                    </svg>
                    <span>Soft dark</span>
                </button>
            </div>
        </div>
    </div>
@endif

{{-- Global theme picker - two variants, one component, one source of truth.

       variant="dropdown" (default): sun/moon icon trigger that pops a panel
         with Light / Dark / Auto options. Use in nav bars and other tight
         spaces where an inline segmented control would crowd the layout.
         Supports `drop="down"` (default) or `drop="up"` for the panel side.
         The panel rows are sm/20px-icon sized - the old xs/16px rows were
         cramped for a menu people read rather than scan.

       variant="segmented": always-visible inline pill with Light / Dark /
         Auto as three segments. It wears the locale chip's hairline ring
         (ring, not border, so both land at 32px tall) because the two sit in
         the same footer row. Use where a "Theme" label already sits beside it.

       variant="labelled": Theme: Light | System | Dark, in words. Use where the
         control stands alone - three bare marks with nothing naming them is a
         row of glyphs, not a setting.

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

@if ($variant === 'labelled')
    {{-- Labelled selector: Theme: Light | System | Dark.

         The segmented variant is three marks and nothing else, which reads as a
         row of glyphs unless something beside it says "Theme". Where the control
         stands alone, the words have to be on it. --}}
    <div
        x-data="{
            choice: (window.themeChoice ? window.themeChoice() : (localStorage.getItem('theme') || 'system')),
            pick(v, e) {
                this.choice = v;
                // Same switching style as the dashboard: a circular reveal from
                // the click point, degrading to a plain switch where View
                // Transitions are missing or motion is reduced.
                if (window.rshopThemeReveal) { window.rshopThemeReveal(e || null, v); }
                else if (window.setTheme) { window.setTheme(v); }
            },
        }"
        x-on:theme-changed.window="choice = (window.themeChoice ? window.themeChoice() : (localStorage.getItem('theme') || 'system'))"
        {{ $attributes->class('inline-flex w-fit items-center gap-2 text-sm') }}
        role="radiogroup"
        aria-label="Theme"
    >
        <span class="font-medium text-zinc-600 dark:text-zinc-400">Theme:</span>

        <div class="inline-flex items-center gap-1 rounded-full bg-[#f4f6f5] p-1 dark:bg-white/10">
            @foreach ([['light', 'Light'], ['system', 'System'], ['dark', 'Dark']] as [$value, $label])
                <button
                    type="button"
                    @click="pick('{{ $value }}', $event)"
                    :class="choice === '{{ $value }}'
                        ? 'bg-[#ffffff] font-semibold text-zinc-900 shadow-sm dark:bg-white/20 dark:text-white'
                        : 'text-zinc-600 hover:text-zinc-900 dark:text-zinc-400 dark:hover:text-white'"
                    class="rounded-full px-3 py-1 text-sm transition-colors focus:outline-none focus-visible:ring-2 focus-visible:ring-blue-500/40"
                    :aria-checked="(choice === '{{ $value }}').toString()"
                    role="radio"
                >{{ $label }}</button>
            @endforeach
        </div>
    </div>
@elseif ($variant === 'segmented')
    {{-- ── Segmented chip (Light / Dark / Auto) ─────────────────────── --}}
    <div
        x-data="{
            choice: (window.themeChoice ? window.themeChoice() : (localStorage.getItem('theme') || 'system')),
            switching: false,
            pick(v, e) {
                this.choice = v;
                // Same switching style as the dashboard: a circular reveal from
                // the click point, degrading to a plain switch where View
                // Transitions are missing or motion is reduced.
                if (window.rshopThemeReveal) { window.rshopThemeReveal(e || null, v); }
                else if (window.setTheme) { window.setTheme(v); }
                this.switching = true;
                clearTimeout(this._popTimer);
                this._popTimer = setTimeout(() => this.switching = false, 650);
            },
        }"
        x-on:theme-changed.window="choice = (window.themeChoice ? window.themeChoice() : (localStorage.getItem('theme') || 'system'))"
        {{ $attributes->class('inline-flex w-fit items-center gap-0.5 rounded-full p-0.5 ring-1 ring-zinc-200') }}
        role="radiogroup"
        aria-label="Theme"
    >
        @foreach ($themeChoices as $opt)
            <button
                type="button"
                @click="pick('{{ $opt['value'] }}', $event)"
                :class="[
                    choice === '{{ $opt['value'] }}'
                        ? 'text-blue-600 dark:text-blue-300'
                        : 'text-zinc-400 hover:text-zinc-600 dark:text-zinc-500 dark:hover:text-zinc-300',
                    switching && choice === '{{ $opt['value'] }}' ? 'theme-toggle-pop' : '',
                ].join(' ')"
                class="flex h-7 w-7 items-center justify-center rounded-full transition-colors duration-150"
                :aria-checked="(choice === '{{ $opt['value'] }}').toString()"
                role="radio"
                aria-label="{{ $opt['label'] }}"
                title="{{ $opt['label'] }}"
            >
                <x-dynamic-component :component="$opt['icon']" class="h-4 w-4" />
            </button>
        @endforeach
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
            pick(v, e) {
                this.choice = v;
                // Same switching style as the dashboard: a circular reveal from
                // the click point, degrading to a plain switch where View
                // Transitions are missing or motion is reduced.
                if (window.rshopThemeReveal) { window.rshopThemeReveal(e || null, v); }
                else if (window.setTheme) { window.setTheme(v); }
                this.open = false;
                this.locked = false;
                this.switching = true;
                clearTimeout(this._popTimer);
                this._popTimer = setTimeout(() => this.switching = false, 650);
            },
        }"
        x-on:theme-changed.window="dark = $event.detail.dark; choice = (window.themeChoice ? window.themeChoice() : (localStorage.getItem('theme') || 'system'))"
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
            class="absolute right-0 {{ $panelPos }} z-50 w-max overflow-hidden rounded-[10px] bg-white/70 backdrop-blur-md shadow-xl shadow-zinc-900/10 ring-1 ring-zinc-200 dark:bg-[#1d3252]/70 dark:ring-white/10"
            role="menu"
            aria-label="Theme"
        >
            <div class="p-1">
                @foreach ($themeChoices as $opt)
                    <button
                        type="button"
                        @click="pick('{{ $opt['value'] }}', $event)"
                        :class="choice === '{{ $opt['value'] }}'
                            ? 'bg-[#0c1337] text-white dark:bg-[#fdfdfd] dark:text-[#0c1337]'
                            : 'text-zinc-900 hover:bg-zinc-100 dark:text-zinc-100 dark:hover:bg-[#26416b]'"
                        class="flex w-full items-center gap-2.5 whitespace-nowrap rounded-[7px] py-2 pl-2.5 pr-5 text-left text-sm font-medium transition-colors"
                        role="menuitemradio"
                        :aria-checked="(choice === '{{ $opt['value'] }}').toString()"
                    >
                        <x-dynamic-component :component="$opt['icon']" class="h-5 w-5" />
                        <span>{{ $opt['label'] }}</span>
                    </button>
                @endforeach
            </div>
        </div>
    </div>
@endif

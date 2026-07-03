@props([
    'wrapperClass' => 'mt-1.5',
    // Array<value => label>. When passed, renders a fully-custom Alpine
    // dropdown (we control the panel styling). When null, falls back to
    // a styled native <select> using the slot's <option> children.
    'options' => null,
    'placeholder' => null,
    'disabled' => false,
])

@php
    // Read the wire:model property name from the attribute bag. The
    // ComponentAttributeBag's `get()` on a wire:model.X variant won't match
    // bare `wire:model`, so try the common variants in order. The value
    // (e.g. 'role' / 'type') is what Livewire entangles against.
    $wireProperty = null;
    foreach (['wire:model.live', 'wire:model', 'wire:model.defer', 'wire:model.lazy'] as $candidate) {
        if ($attributes->has($candidate)) {
            $wireProperty = $attributes->get($candidate);
            break;
        }
    }
@endphp

@if ($options !== null && $wireProperty)
    {{-- ── Modern custom dropdown. The OS-rendered panel from a native
         <select> can't be styled (text colors / background come from the OS,
         which is why dark-mode admin pages showed light panels). This version
         renders the trigger and the option list as plain divs we fully
         control, then writes back to the parent Livewire property via
         $wire.set() so it stays in sync. --}}
    <div
        x-data="{
            open: false,
            options: {{ \Illuminate\Support\Js::from($options) }},
            placeholder: {{ \Illuminate\Support\Js::from($placeholder ?? 'Select...') }},
            panelStyle: '',
            get value() { return $wire.get('{{ $wireProperty }}'); },
            get label() {
                const v = this.value;
                if (v === '' || v === null || v === undefined) { return this.placeholder; }
                return this.options[v] ?? this.placeholder;
            },
            select(v) {
                $wire.set('{{ $wireProperty }}', v);
                this.open = false;
            },
            /* Position the panel as `fixed` so it escapes any parent
               overflow-clipping (the pricing-rules modal body has
               overflow-y-auto, which would otherwise hide the panel). */
            position() {
                const r = this.$refs.trigger.getBoundingClientRect();
                const spaceBelow = window.innerHeight - r.bottom;
                const spaceAbove = r.top;
                const maxH = 288;
                const above = spaceBelow < 220 && spaceAbove > spaceBelow;
                this.panelStyle = `left:${r.left}px; width:${r.width}px; ` + (
                    above
                        ? `bottom:${window.innerHeight - r.top + 4}px; max-height:${Math.min(maxH, spaceAbove - 8)}px;`
                        : `top:${r.bottom + 4}px; max-height:${Math.min(maxH, spaceBelow - 8)}px;`
                );
            },
            toggle() {
                if (! this.open) { this.position(); }
                this.open = ! this.open;
            },
        }"
        x-on:resize.window="if (open) position()"
        x-on:scroll.window.capture="if (open) position()"
        @click.outside="open = false"
        @keydown.escape="open = false"
        class="relative {{ $wrapperClass }}"
    >
        <button
            x-ref="trigger"
            type="button"
            @click="toggle()"
            :aria-expanded="open.toString()"
            @if ($disabled) disabled @endif
            :class="open
                ? 'bg-blue-50 ring-2 ring-blue-500 text-blue-700 dark:bg-blue-600/15 dark:ring-blue-400 dark:text-blue-300'
                : 'bg-white border border-zinc-200 text-zinc-900 dark:border-zinc-700/60 dark:bg-[#0c1a36] dark:text-white'"
            class="flex w-full items-center justify-between rounded-[12px] px-3 py-2 text-left text-sm outline-none transition-colors focus:ring-2 focus:ring-blue-500/15 disabled:cursor-not-allowed disabled:opacity-60"
        >
            <span x-text="label" class="truncate font-semibold"></span>
            <svg class="ml-2 h-4 w-4 shrink-0 transition-transform" :class="open && 'rotate-180'" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7"/>
            </svg>
        </button>

        <div
            x-show="open"
            x-cloak
            x-transition:enter="transition ease-out duration-150"
            x-transition:enter-start="opacity-0 -translate-y-1"
            x-transition:enter-end="opacity-100 translate-y-0"
            :style="panelStyle"
            class="fixed z-[60] overflow-y-auto rounded-[12px] bg-white p-1 shadow-xl shadow-zinc-900/20 ring-2 ring-blue-500 dark:bg-[#0c1a36] dark:ring-blue-400"
        >
            @if ($placeholder)
                <button
                    type="button"
                    @click="select('')"
                    :class="value === '' || value === null ? 'bg-blue-50 text-blue-700 dark:bg-blue-500/15 dark:text-blue-300' : 'text-zinc-500 hover:bg-zinc-100 dark:text-zinc-400 dark:hover:bg-[#1d3252]'"
                    class="block w-full rounded-[12px] px-3 py-2 text-left text-sm transition-colors"
                >{{ $placeholder }}</button>
            @endif
            @foreach ($options as $optValue => $optLabel)
                <button
                    type="button"
                    @click="select('{{ addslashes((string) $optValue) }}')"
                    :class="String(value) === '{{ addslashes((string) $optValue) }}' ? 'bg-blue-50 font-semibold text-blue-700 dark:bg-blue-500/15 dark:text-blue-300' : 'text-zinc-700 hover:bg-zinc-100 dark:text-zinc-200 dark:hover:bg-[#1d3252]'"
                    class="block w-full rounded-[12px] px-3 py-2 text-left text-sm transition-colors"
                >{{ $optLabel }}</button>
            @endforeach
        </div>
    </div>
@else
    {{-- Fallback: styled native <select>. Use this when option markup is
         too dynamic to pass as an array (e.g. coupon Alpine state with
         template x-for). color-scheme tells the OS to render the dropdown
         panel itself in dark mode so options aren't unreadable. --}}
    <div class="relative {{ $wrapperClass }}">
        <select {{ $attributes->class([
            'w-full appearance-none rounded-[12px] border border-zinc-200 bg-white px-3 py-2 pr-9 text-sm text-zinc-900 outline-none transition-colors focus:border-blue-500 focus:ring-2 focus:ring-blue-500/15 disabled:cursor-not-allowed disabled:opacity-60 dark:border-zinc-700/60 dark:bg-[#0c1a36] dark:text-white [color-scheme:light] dark:[color-scheme:dark]',
        ]) }}>
            {{ $slot }}
        </select>
        <svg class="pointer-events-none absolute right-3 top-1/2 h-4 w-4 -translate-y-1/2 text-zinc-500 dark:text-zinc-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
            <path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7"/>
        </svg>
    </div>
@endif

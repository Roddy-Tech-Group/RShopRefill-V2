{{--
    Standard modal close button — frosted-glass square in both themes: light
    mode is translucent white with a dark X, dark mode translucent white with
    a white X. The single site-wide close control for modals (top-right
    placement, industry standard). Pass the close handler + any positioning
    via attributes:
      <x-close-button @click="open = false" />
      <x-close-button wire:click="$set('open', false)" class="absolute right-3 top-3" />
--}}
<button {{ $attributes->merge(['type' => 'button', 'aria-label' => 'Close'])->class('flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-white/40 text-zinc-900 ring-1 ring-zinc-900/10 backdrop-blur-md backdrop-saturate-150 transition-colors hover:bg-white/70 focus:outline-none focus-visible:ring-2 focus-visible:ring-blue-500/40 dark:bg-white/20 dark:text-white dark:ring-white/10 dark:hover:bg-zinc-500') }}>
    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5" aria-hidden="true">
        <path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12"/>
    </svg>
</button>

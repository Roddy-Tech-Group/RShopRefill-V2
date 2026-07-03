{{--
    Floating "back to top" button. Fixed to the bottom-left, appears after the
    customer scrolls down, and smooth-scrolls to the top. Positioning and size are
    set inline so it renders correctly even before a fresh CSS build; appearance
    uses standard (already-compiled) utility classes. bg-blue-600 stays blue in
    both light and dark themes.
--}}
<button
    type="button"
    x-data="{ shown: false }"
    x-show="shown"
    x-cloak
    x-transition:enter="transition ease-out duration-200"
    x-transition:enter-start="opacity-0 translate-y-2"
    x-transition:enter-end="opacity-100 translate-y-0"
    x-transition:leave="transition ease-in duration-150"
    x-transition:leave-start="opacity-100 translate-y-0"
    x-transition:leave-end="opacity-0 translate-y-2"
    @scroll.window.throttle.150ms="shown = window.scrollY > 400"
    @click="window.scrollTo({ top: 0, behavior: 'smooth' })"
    aria-label="Back to top"
    style="position: fixed; left: 1.5rem; bottom: 1.5rem; z-index: 60; width: 2.5rem; height: 2.5rem;"
    class="flex items-center justify-center rounded-[12px] bg-blue-600 text-white shadow-lg shadow-blue-600/30 ring-2 ring-blue-600/20 transition-colors hover:bg-blue-700 focus:outline-none focus-visible:ring-blue-500/60"
>
    <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="3" aria-hidden="true">
        <path stroke-linecap="round" stroke-linejoin="round" d="M4.5 15.75l7.5-7.5 7.5 7.5"/>
    </svg>
</button>

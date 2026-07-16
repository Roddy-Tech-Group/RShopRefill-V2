{{-- Gift Card Outage Banner. Renders a sticky red strip at the top of every
     storefront page when the 'gift_cards' feature flag is disabled. --}}
<div
    x-data="{ open: true }"
    x-show="open"
    x-cloak
    role="status"
    aria-live="polite"
    class="relative z-[60] flex items-center justify-center gap-3 bg-red-600 px-4 py-2.5 text-center text-sm font-medium text-white shadow-sm"
>
    <svg class="h-4 w-4 shrink-0 text-red-100" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
        <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/>
    </svg>
    <span><strong>Gift Cards Temporarily Unavailable:</strong> We're currently performing maintenance on our gift cards service to improve reliability.</span>
    <button
        type="button"
        @click="open = false"
        aria-label="Dismiss"
        class="absolute right-3 top-1/2 -translate-y-1/2 flex h-7 w-7 items-center justify-center rounded-[6px] text-red-100 transition-colors hover:bg-red-500 hover:text-white"
    >
        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5" aria-hidden="true">
            <path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12"/>
        </svg>
    </button>
</div>

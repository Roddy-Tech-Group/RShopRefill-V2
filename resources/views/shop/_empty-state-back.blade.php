{{-- The way out of a category a country has nothing in.

     A region with no billers, no networks or no eSIMs is a dead end the
     visitor did not walk into by choice: there are no filters to clear and
     nothing to search for, so the page has to offer somewhere to go. Gift
     cards already offered the country switcher; this is the other half, and
     every category carries both so none of them is a room with no door.

     Back goes where the customer came from. Inside the dashboard that is the
     overview; on the storefront there is no overview, so it is the home page,
     which is the same idea under a different name.

     Expects $inDash. Pass `switcher` false on a page that already draws its
     own country switcher, so the pair is not rendered twice. --}}
@php
    $emptyBackHref = ($inDash ?? false) ? route('dashboard') : route('home');
    $emptyShowSwitcher = $switcher ?? true;
@endphp

<div class="mt-5 flex flex-wrap items-center justify-center gap-2">
    <a
        href="{{ $emptyBackHref }}"
        wire:navigate
        class="inline-flex items-center gap-2 rounded-full bg-zinc-900 px-5 py-2.5 text-sm font-bold text-white transition-colors hover:bg-zinc-800 focus:outline-none focus-visible:ring-2 focus-visible:ring-blue-500/40 dark:bg-white dark:text-zinc-900 dark:hover:bg-zinc-200"
    >
        {{ ($inDash ?? false) ? __('products.back_to_overview') : __('products.back_to_home') }}
    </a>

    @if ($emptyShowSwitcher)
        {{-- Opens the same locale sheet the nav pill does. `.stop` for the
             reason the footer chip needs it: without it the click bubbles, the
             backdrop appears under the cursor, and the same click closes the
             sheet again. --}}
        <button
            type="button"
            x-data
            @click.stop="$dispatch('open-locale-modal'); localeModalOpen = true"
            class="inline-flex items-center gap-2 rounded-full bg-white px-5 py-2.5 text-sm font-bold text-zinc-900 ring-1 ring-zinc-200 transition-colors hover:bg-[#f4f6f5] focus:outline-none focus-visible:ring-2 focus-visible:ring-blue-500/40 dark:bg-white/10 dark:text-white dark:ring-white/15 dark:hover:bg-white/15"
        >
            {{ __('products.switch_country') }}
        </button>
    @endif
</div>

{{--
    The region + currency pill, on its own so both the desktop utility cluster
    and the mobile nav row can use it without duplicating the markup.
--}}
{{-- Region + currency, as ONE control. It was two buttons - country
     and language - that opened the same modal, so the split bought
     nothing and cost width. The flag is the country, the code pair
     is the language and the currency the country drives.

     The language half renders SERVER-side from the active locale, not
     from Alpine state. The old client `language` key was written by the
     retired translate widget and then never again, so a browser that
     once picked Portuguese showed "PO / USD" forever. The server value
     cannot go stale: it IS the language the page rendered in. --}}
@props(['compact' => false, 'suggestion' => false])

{{-- Relative wrapper so the region-suggestion popup can hang off THIS control
     rather than off the header, keeping the pointer aligned with the pill at
     every breakpoint. It adds no box of its own. --}}
<div class="relative flex shrink-0 items-center">

<button
    type="button"
    @click="localeModalOpen = true"
    class="flex shrink-0 items-center gap-2 rounded-full {{ $compact ? 'px-2 md:px-3' : 'px-3' }} py-1.5 nav-text text-zinc-900 transition-colors duration-150 hover:bg-[#f4f6f5] focus:outline-none focus-visible:ring-2 focus-visible:ring-blue-500/40 dark:text-zinc-200 dark:hover:bg-white/10 dark:hover:text-white"
    :aria-label="'Region and currency: ' + country + ', ' + (language || 'English') + ', ' + currency + '. Change'"
>
    <img
        :src="'https://flagcdn.com/w40/' + (countryCode || 'us').toLowerCase() + '.png'"
        alt=""
        class="h-6 w-6 shrink-0 rounded-full object-cover ring-1 ring-black/10 dark:ring-white/20"
    >
    {{-- Compact drops the label below md, where the nav row has no width to
         spare and the flag alone still says which country is selected. --}}
    <span class="{{ $compact ? 'hidden md:inline' : '' }}"><span x-text="(language || 'English').slice(0, 2).toUpperCase()">{{ config('localization.supported.'.app()->getLocale().'.short') }}</span> / <span x-text="currency || 'USD'">USD</span></span>
</button>

    @if ($suggestion)
        <x-nav.region-suggestion :region="$region ?? 'US'" />
    @endif
</div>

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
     cannot go stale: it IS the language the page rendered in.

     It used to say so here and then do the opposite: an x-text sliced the
     first two letters off the storefront scope's `language`, which holds the
     ENGLISH display name (window.rshopLanguage, the key the locale form posts
     through). That painted "SP" for Spanish, "GE" for German, "PO" for
     Portuguese and "CH" for Chinese, and read "English" to a screen reader
     whenever the scope had no value. Both halves now come from the active
     locale's config entry: its short code and its native name. --}}
@props(['compact' => false, 'suggestion' => false])

@php
    $activeLanguage = \App\Support\Languages::all()[app()->getLocale()] ?? null;
    $activeLanguageShort = $activeLanguage['short'] ?? strtoupper(substr(app()->getLocale(), 0, 2));
    $activeLanguageNative = $activeLanguage['native_name'] ?? \App\Support\Languages::defaultName();
@endphp

{{-- Relative wrapper so the region-suggestion popup can hang off THIS control
     rather than off the header, keeping the pointer aligned with the pill at
     every breakpoint. It adds no box of its own. --}}
<div class="relative flex shrink-0 items-center">

{{-- The dashboard's language + currency pill, adapted to this bar the way
     the cart and avatar chips beside it are: 36px tall, a 2px outline at 30%
     of the ink, a 10% wash on hover, the flag at 28px on the left, the label
     in the dashboard's 13px semibold. The dashboard draws white on its navy
     bar; this band is #f4f6f5, so the ink is the navy itself. In dark both
     bars are black and the dark classes are the dashboard's own. Compact
     (the phone row) drops the label below md and closes the padding up, so
     the flag sits in a round chip like the cart beside it. --}}
<button
    type="button"
    @click="localeModalOpen = true"
    class="flex h-9 shrink-0 items-center gap-2 rounded-full border-2 border-[#0c1337]/30 {{ $compact ? 'px-1 md:pl-1 md:pr-3' : 'pl-1 pr-3' }} text-[13px] font-semibold text-zinc-700 transition-colors hover:border-edge hover:bg-[#0c1337]/10 focus:outline-none focus-visible:ring-2 focus-visible:ring-blue-500/40 dark:border-white/10 dark:text-zinc-200 dark:hover:bg-white/5"
    :aria-label="@js(__('navigation.region_pill_label', ['language' => $activeLanguageNative, 'country' => '__COUNTRY__', 'currency' => '__CURRENCY__'])).replace('__COUNTRY__', country).replace('__CURRENCY__', currency)"
>
    {{-- Eager: this pill is in the nav on arrival. Sized so the row keeps
         its shape before the flag arrives, and decoded off the main thread. --}}
    <img
        :src="'https://flagcdn.com/w40/' + (countryCode || 'us').toLowerCase() + '.png'"
        alt=""
        width="28"
        height="28"
        decoding="async"
        class="h-7 w-7 shrink-0 rounded-full object-cover ring-1 ring-black/10 dark:ring-white/20"
    >
    {{-- Compact drops the label below md, where the nav row has no width to
         spare and the flag alone still says which country is selected. --}}
    <span class="{{ $compact ? 'hidden md:inline' : '' }}"><span>{{ $activeLanguageShort }}</span> / <span x-text="currency || 'USD'">USD</span></span>
</button>

    @if ($suggestion)
        <x-nav.region-suggestion :region="$region ?? 'US'" />
    @endif
</div>

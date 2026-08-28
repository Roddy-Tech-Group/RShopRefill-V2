@props([
    'name',
    'priceRange' => null,
    // ['average' => 4.9, 'count' => 12], or null when the brand has no
    // published reviews - the row then shows no rating rather than a zero.
    'rating' => null,
    'href' => '#',
    'cardClass' => 'bg-[#ffffff]',
])

{{--
    Brand card for the home rows. The slot is the visual that fills the card
    (logo, wordmark, etc.). Name + price range render below. The tile carries a
    pointer-tracked 3D tilt + glare (cardTilt() in app.js) for a premium feel.
--}}
<li data-reveal-item class="w-32 shrink-0 sm:w-auto">
    <a href="{{ $href }}" class="group block focus:outline-none">
        <div
            {{ $attributes->merge(['class' => 'relative flex aspect-[16/10] items-center justify-center overflow-hidden rounded-[15px] ring-1 ring-zinc-200 shadow-sm group-focus-visible:ring-2 group-focus-visible:ring-blue-500/40 '.$cardClass]) }}
        >
            {{ $slot }}
        </div>
        {{-- Caption: name, its rating when it has one, then the price range.
             Same shape as the listing-grid tile - the name takes the slack so
             the rating pins to the card's edge and a long name truncates into
             it rather than shoving it off.

             Same TYPE as it too, deliberately: x-shop.brand-tile is the
             reference, and these three lines match it line for line. The
             price had drifted to muted grey at a size LARGER than the name,
             which made the one number a shopper is looking for the faintest
             thing on the card. --}}
        <div class="mt-2">
            <div class="flex items-baseline gap-2">
                <p translate="no" class="notranslate min-w-0 flex-1 truncate text-[16px] font-bold leading-tight text-zinc-900 dark:text-white">{{ $name }}</p>

                @if ($rating)
                    <span
                        class="inline-flex shrink-0 items-center gap-1 text-[16px] font-bold leading-tight text-zinc-900 dark:text-white"
                        aria-label="{{ __('products.rating_out_of_five', ['rating' => $rating['average'], 'count' => $rating['count']]) }}"
                    >
                        {{ number_format($rating['average'], 1) }}
                        <svg class="h-3.5 w-3.5 shrink-0 translate-y-px" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                            <path d="M10 1.5l2.6 5.27 5.82.85-4.21 4.1.99 5.79L10 14.78l-5.2 2.73.99-5.79-4.21-4.1 5.82-.85L10 1.5z" />
                        </svg>
                    </span>
                @endif
            </div>

            @if ($priceRange)
                <p translate="no" class="notranslate mt-0.5 truncate text-[14px] font-bold text-zinc-900 dark:text-white">{{ $priceRange }}</p>
            @endif
        </div>
    </a>
</li>

{{--
    One gift-card / biller / network tile.

    Lifted out of the gift-cards listing when that page grew a section per
    category: the same tile renders from a grouped section and from the flat
    filtered grid, and 85 lines of duplicated markup is 85 lines that drift.

    `rating` is null until a brand has been reviewed - the caption then shows
    no rating at all rather than a zero. It arrives pre-resolved from the
    page's single Review::brandRatings() rollup, never looked up here, or the
    listing would be back to a query per card.

    `index` is the tile's position in the whole page, not in its section: it
    decides which images load eagerly, and that has to be counted across
    sections or every section would eagerly load its own first ten.
--}}
@props([
    'product',
    'href' => null,
    'rating' => null,
    'priceLabel' => null,
    'logoSrc' => null,
    'outOfStock' => false,
    'categoryEnabled' => true,
    'notBuyable' => false,
    'index' => 99,
    // Renders the tile's shape with no content - same boxes, same grid cell,
    // so a skeleton can never sit at a different size from what replaces it.
    'skeleton' => false,
])

@php
    use App\Models\Product;

    // A category switched off has nothing behind it, so its tiles stay inert.
    // Out of stock is a different answer: the product exists, this region has
    // none, and the page says so and offers the switcher. The tile still greys
    // out either way, so nothing about how it reads has changed.
    $tileInert = ! $categoryEnabled;
@endphp

@if ($skeleton)
    <li aria-hidden="true">
        <div class="relative aspect-[16/10] overflow-hidden rounded-[14px]">
            <x-skeleton rounded="rounded-[14px]" class="h-full w-full" />
        </div>

        {{-- The caption's two lines, at the widths real names and prices take. --}}
        <div class="mt-2 px-0.5">
            <x-skeleton class="h-4 w-3/4" />
            <x-skeleton class="mt-1.5 h-3 w-2/5" />
        </div>
    </li>
@else

<li>
    <a
        @if (! $tileInert && $href) href="{{ $href }}" wire:navigate @endif
        @class([
            'card-3d-scene group block focus:outline-none',
            'opacity-60 grayscale' => $notBuyable,
            'pointer-events-none' => $tileInert,
        ])
        aria-label="{{ Product::brandDisplayName($product->brand_key) }}"
        @if ($tileInert) aria-disabled="true" @endif
    >
        {{-- Logo tile. The plate stays white in BOTH themes: brand logos are
             drawn for a light tile and vanish on a dark one. The literal hex
             alone no longer guarantees that: bg-[#ffffff] became the dashboard
             card marker when the light palette moved to white cards on #f4f6f5,
             and dark mode repaints that class. keep-light opts this plate back
             out, which is what keeps the logo readable in dark. --}}
        <div
            class="relative flex aspect-[16/10] items-center justify-center overflow-hidden rounded-[14px] keep-light bg-[#ffffff] shadow-sm ring-1 ring-zinc-200"
        >
            @if ($logoSrc)

                {{-- The first ~10 tiles (the two rows above the fold on a 5-col
                     desktop grid) load eagerly with a high fetch priority so the
                     page paints its logos immediately; everything below defers with
                     lazy loading. decoding=async keeps logo decode off the main
                     thread, and the intrinsic 16:10 size prevents any reflow. --}}
                <img
                    src="{{ $logoSrc }}"
                    alt="{{ Product::brandDisplayName($product->brand_key) }}"
                    width="320" height="200"
                    class="h-full w-full object-cover"
                    loading="{{ $index < 10 ? 'eager' : 'lazy' }}"
                    @if ($index < 5) fetchpriority="high" @endif
                    decoding="async"
                >
            @else
                <span class="text-2xl font-black tracking-tight text-[#3f3f46]">
                    {{ str(Product::brandDisplayName($product->brand_key))->substr(0, 2)->upper() }}
                </span>
            @endif

            @if ($outOfStock)
                <span class="absolute bottom-2 right-2 inline-flex items-center rounded-full border border-white bg-zinc-900/60 px-2.5 py-0.5 text-[10px] font-bold uppercase tracking-wide text-white backdrop-blur-sm">
                    {{ __('products.out_of_stock') }}
                </span>
            @elseif (! $categoryEnabled)
                <span class="absolute bottom-2 right-2 inline-flex items-center rounded-full border border-white bg-amber-500/80 px-2.5 py-0.5 text-[10px] font-bold uppercase tracking-wide text-white backdrop-blur-sm">
                    {{ __('giftcards.badge_unavailable') }}
                </span>
            @elseif ($product->is_popular)
                {{-- Round pill with a soft fuchsia glow halo. --}}
                <span class="absolute left-2 top-2 inline-flex items-center rounded-full border border-fuchsia-500 bg-fuchsia-500/10 px-2.5 py-0.5 text-[10px] font-bold uppercase tracking-wide text-fuchsia-600 shadow-[0_0_12px_rgba(217,70,239,0.55)] backdrop-blur-sm">{{ __('products.detail_popular') }}</span>
            @elseif ($product->is_featured)
                {{-- Round pill with a soft amber glow halo. --}}
                <span class="absolute left-2 top-2 inline-flex items-center rounded-full border border-amber-500 bg-amber-500/10 px-2.5 py-0.5 text-[10px] font-bold uppercase tracking-wide text-amber-600 shadow-[0_0_12px_rgba(245,158,11,0.6)] backdrop-blur-sm">{{ __('navigation.featured') }}</span>
            @endif

        </div>

        {{-- Caption: brand name, its rating when it has one, and
             the real min-to-max denomination range.

             The rating sits on the name's row, pushed right by the
             name's flex-1 - so it lands against the card's edge
             whatever the name's length, and a long name truncates
             into it rather than shoving it off. --}}
        <div class="mt-2 px-0.5">
            <div class="flex items-baseline gap-2">
                <p translate="no" class="notranslate min-w-0 flex-1 truncate text-[16px] font-bold leading-tight text-zinc-900 dark:text-white">{{ Product::brandDisplayName($product->brand_key) }}</p>

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

            @if ($priceLabel)
                <p translate="no" class="notranslate mt-0.5 truncate text-[14px] font-bold text-zinc-900 dark:text-white">{{ $priceLabel }}</p>
            @endif
        </div>
    </a>
</li>
@endif

{{--
    RshopRefill eSIM destination card.

    ONE component for every destination - global, regional and single-country -
    because a destination is a product variant, not a separate brand. Only the
    accent, the background motif and the description line change between them.
    Data comes from App\Support\EsimCardPresenter, which only ever emits a card
    for a real purchasable catalog entry.

    This replaced a set of exported PNGs. The exports could not be corrected:
    the logo size, the letter-spacing on the category label and the half-drawn
    feature icons were baked into the pixels, and a card per destination does
    not scale to a catalog that changes when suppliers sync.

    Heights are driven by padding, never fixed, and every text node is free to
    wrap - French runs roughly 30% longer than English and a fixed-height band
    would clip it.
--}}
@props([
    'name',
    // global | regional | local - drives the header label and the copy.
    'type' => 'regional',
    'countryCount' => null,
    'description' => null,
    'accentColor' => '#22d3ee',
    // Flag image URLs for the destinations this plan reaches. A region has no
    // flag of its own, so it borrows a few from its coverage; a country card
    // shows its own. Empty falls back to the plain accent dot.
    'flagUrls' => [],
    // 'stack' overlaps up to six small flags; 'hero' shows a single large one.
    'flagDisplay' => 'stack',
    // 'tile'  - gift-card shape: a 16/10 artwork tile with the name and price
    //           as plain text below it, so a row of these and a row of gift
    //           cards read as the same object. Used on the home page.
    // 'full'  - the standalone card, which additionally carries the feature
    //           strip and the unavailable/error notes. Used on /esims.
    'variant' => 'full',
    'backgroundPattern' => 'orbit',
    // true shows the chip, false/null hides it. Null means "no supplier field
    // backs this yet", which must not render as a promise.
    'hotspotSupported' => null,
    'networkTypes' => [],
    'availability' => 'available',
    'destinationUrl' => null,
    'fromPrice' => null,
    'loading' => false,
    'error' => null,
])

@php
    $categoryLabel = match ($type) {
        'global' => __('esim.card_label_global'),
        'local' => __('esim.card_label_local'),
        default => __('esim.card_label_regional'),
    };

    // Only what the package confirms. No network data means no chip, rather
    // than a generic "4G & 5G" every card wears whether or not it is true.
    $networkTypes = array_values(array_filter((array) $networkTypes));
    $networkLabel = match (true) {
        in_array('4G', $networkTypes, true) && in_array('5G', $networkTypes, true) => __('esim.card_network_available', ['network' => '4G & 5G']),
        in_array('5G', $networkTypes, true) => __('esim.card_network_available', ['network' => '5G']),
        in_array('4G', $networkTypes, true) => __('esim.card_network_available', ['network' => '4G']),
        default => null,
    };

    $isAvailable = $availability === 'available' && ! $error && $destinationUrl;

    // Low-contrast geographic motifs, one per region, drawn in the band's own
    // white at 8-14% so they never compete with the destination name. Abstract
    // geometry only - no flags, landmarks or cultural motifs.
    $motif = match ($backgroundPattern) {
        'meridians' => '
            <path d="M300 -40 Q220 100 300 240" fill="none" stroke="white" stroke-opacity=".16" stroke-width="1.5"/>
            <path d="M350 -40 Q260 100 350 240" fill="none" stroke="white" stroke-opacity=".10" stroke-width="1.5"/>
            <circle cx="326" cy="70" r="46" fill="none" stroke="white" stroke-opacity=".14" stroke-width="1.5"/>
            <circle cx="360" cy="46" r="4" fill="white" fill-opacity=".5"/>',
        'lattice' => '
            <g stroke="white" stroke-opacity=".13" stroke-width="1.2" fill="none">
                <path d="M250 30 L300 10 L350 34 L392 14"/><path d="M256 96 L306 74 L352 98 L396 78"/>
                <path d="M250 30 L256 96"/><path d="M300 10 L306 74"/><path d="M350 34 L352 98"/>
            </g>
            <g fill="white" fill-opacity=".34"><circle cx="300" cy="10" r="3"/><circle cx="350" cy="34" r="3"/><circle cx="306" cy="74" r="3"/></g>',
        'waves' => '
            <g fill="none" stroke="white" stroke-opacity=".14" stroke-width="1.5">
                <path d="M236 40 Q286 8 336 40 T436 40"/><path d="M236 74 Q286 42 336 74 T436 74"/>
                <path d="M236 108 Q286 76 336 108 T436 108"/>
            </g>
            <circle cx="336" cy="40" r="4" fill="white" fill-opacity=".45"/>',
        'parallels' => '
            <g stroke="white" stroke-opacity=".12" stroke-width="1.4">
                <path d="M230 150 L400 -20"/><path d="M270 150 L440 -20"/><path d="M310 150 L480 -20"/>
            </g>
            <circle cx="352" cy="58" r="40" fill="none" stroke="white" stroke-opacity=".14" stroke-width="1.5"/>
            <circle cx="352" cy="18" r="4" fill="white" fill-opacity=".45"/>',
        'chevrons' => '
            <g fill="none" stroke="white" stroke-opacity=".14" stroke-width="1.5">
                <path d="M250 8 L318 62 L250 116"/><path d="M296 8 L364 62 L296 116"/><path d="M342 8 L410 62 L342 116"/>
            </g>
            <circle cx="318" cy="62" r="4" fill="white" fill-opacity=".42"/>',
        'radials' => '
            <g stroke="white" stroke-opacity=".13" stroke-width="1.3">
                <path d="M340 62 L440 62"/><path d="M340 62 L410 -8"/><path d="M340 62 L410 132"/>
                <path d="M340 62 L250 20"/><path d="M340 62 L250 104"/>
            </g>
            <circle cx="340" cy="62" r="44" fill="none" stroke="white" stroke-opacity=".15" stroke-width="1.5"/>
            <circle cx="340" cy="62" r="4.5" fill="white" fill-opacity=".5"/>',
        'archipelago' => '
            <path d="M232 104 Q320 54 424 92" fill="none" stroke="white" stroke-opacity=".14" stroke-width="1.5"/>
            <g fill="white" fill-opacity=".3">
                <circle cx="268" cy="86" r="5"/><circle cx="306" cy="66" r="7"/><circle cx="348" cy="58" r="4"/>
                <circle cx="386" cy="68" r="6"/><circle cx="414" cy="84" r="3.5"/>
            </g>',
        default => '
            <circle cx="344" cy="62" r="66" fill="none" stroke="white" stroke-opacity=".12" stroke-width="1.5"/>
            <circle cx="344" cy="62" r="40" fill="none" stroke="white" stroke-opacity=".16" stroke-width="1.5"/>
            <g fill="white" fill-opacity=".45"><circle cx="384" cy="62" r="4.5"/><circle cx="310" cy="30" r="3.5"/><circle cx="326" cy="122" r="3"/></g>',
    };
@endphp

@if ($loading)
    {{-- Loading skeleton. Mirrors the real card's three bands so the layout
         does not jump when the data arrives. --}}
    <div {{ $attributes->merge(['class' => 'overflow-hidden rounded-[18px] bg-white ring-1 ring-zinc-200']) }} aria-hidden="true">
        <div class="flex items-center justify-between px-5 py-4">
            <x-skeleton class="h-7 w-[92px] rounded-[6px]" />
            <x-skeleton class="h-3 w-20 rounded-[6px]" />
        </div>
        <div class="bg-zinc-100 px-5 py-6">
            <x-skeleton class="h-7 w-2/5 rounded-[6px]" />
            <x-skeleton class="mt-3 h-4 w-4/5 rounded-[6px]" />
        </div>
        <div class="flex flex-wrap gap-4 px-5 py-4">
            <x-skeleton class="h-4 w-24 rounded-[6px]" />
            <x-skeleton class="h-4 w-32 rounded-[6px]" />
        </div>
    </div>
@else
<a
    @if ($isAvailable)
        href="{{ $destinationUrl }}"
        wire:navigate
    @else
        {{-- No href: the card stays in the DOM and readable, but is neither
             clickable nor focusable, which is what an unbuyable product wants. --}}
        aria-disabled="true"
    @endif
    {{-- Most names are a bare destination ("France") and want the product word
         appended; the hero card is already called "Global eSIM" and would read
         "Global eSIM eSIM" if it were appended blindly. --}}
    @php
        $accessibleName = str_contains(strtolower($name), 'esim') ? $name : $name.' eSIM';
    @endphp
    aria-label="{{ $fromPrice ? __('esim.card_aria_from', ['name' => $accessibleName, 'price' => '$'.number_format($fromPrice, 2)]) : $accessibleName }}"
    @class([
        'group flex h-full flex-col text-left focus:outline-none',
        'cursor-default' => ! $isAvailable,
    ])
>
    {{-- The card box. White in BOTH themes, like the artwork it replaces: it is
         a printed-card object, not a page surface, and a dark variant would put
         the brand lockup (which carries its own white ground) on a dark plate
         and leave a grey block floating behind the logo. The background is set
         inline rather than by class because app.css repaints several literal
         background classes per theme, and this one must never be repainted. --}}
    <div
        style="--esim-accent: {{ $accentColor }}; background-color: #ffffff;"
        @class([
            'esim-card @container keep-light relative flex flex-col overflow-hidden ring-1 ring-zinc-200 shadow-sm',
            // Exactly the gift tile's box: same aspect, same radius.
            'aspect-[16/10] rounded-[15px]' => $variant === 'tile',
            'flex-1 rounded-[14px]' => $variant !== 'tile',
            'transition-[transform,box-shadow] duration-200 ease-out motion-reduce:transition-none',
            'group-focus-visible:ring-2 group-focus-visible:ring-blue-500/40',
            'group-hover:-translate-y-1 group-hover:shadow-lg group-hover:shadow-zinc-900/10 motion-reduce:group-hover:translate-y-0' => $isAvailable,
            'opacity-60 grayscale' => ! $isAvailable,
        ])
    >
    {{-- Header: brand lockup + what kind of eSIM this is. --}}
    <div @class([
        'flex items-start justify-between gap-1.5 px-3 @min-[190px]:px-3.5 @min-[260px]:gap-3 @min-[260px]:px-5',
        'pb-1.5 pt-2 @min-[260px]:pb-2 @min-[260px]:pt-2.5' => $variant === 'tile',
        'pb-2 pt-2.5 @min-[260px]:pb-3 @min-[260px]:pt-3.5' => $variant !== 'tile',
    ])>
        {{-- The lockup is the ICON as artwork plus a live-text wordmark, not a
             single flattened image. The exported lockup spelled the brand
             "RShopRefill" with a capital S; the rest of the app spells it
             "RshopRefill" in 74 places. Pixels cannot be corrected without the
             original font, and any future export could drift again - as live
             text in DM Sans (the app's own face) it simply cannot. --}}
        <span class="flex shrink-0 flex-col items-start gap-[3px]">
            <img
                src="{{ asset('assets/esim-cards/rshoprefill-esim-icon.png') }}"
                alt=""
                @class(['h-auto w-[30px] @min-[190px]:w-[34px]', '@min-[260px]:w-[42px]' => $variant === 'tile', '@min-[260px]:w-[48px]' => $variant !== 'tile'])
                width="111"
                height="78"
                loading="lazy"
                aria-hidden="true"
            >
            <span @class([
                'esim-card__wordmark font-bold leading-none tracking-tight text-[8px] @min-[190px]:text-[9px]',
                '@min-[260px]:text-[11px]' => $variant === 'tile',
                '@min-[260px]:text-[12px]' => $variant !== 'tile',
            ])>RshopRefill eSIM</span>
        </span>
        <span class="esim-card__label mt-0.5 shrink-0 text-[8px] font-bold tracking-[0.04em] @min-[190px]:text-[9px] @min-[260px]:text-[10px] @min-[260px]:tracking-[0.06em]">{{ $categoryLabel }}</span>
    </div>

    {{-- Destination band. The motif sits behind the name at low contrast and
         drifts a few pixels on hover; prefers-reduced-motion pins it. --}}
    <div @class([
        'relative flex flex-1 flex-col justify-center overflow-hidden bg-[#0a2ac2] px-3 @min-[190px]:px-3.5 @min-[260px]:px-5',
        'py-2 @min-[190px]:py-2.5 @min-[260px]:py-3' => $variant === 'tile',
        'py-3 @min-[190px]:py-4 @min-[260px]:py-5' => $variant !== 'tile',
    ])>
        <svg
            class="esim-card__motif pointer-events-none absolute inset-0 h-full w-full"
            viewBox="0 0 400 124"
            preserveAspectRatio="xMaxYMid slice"
            aria-hidden="true"
            focusable="false"
        >{!! $motif !!}</svg>

        {{-- Destination flags, or the plain accent dot when there are none.
             Both are decorative: the destination is already named in the
             heading right below, so a screen reader gains nothing from them
             and they stay out of the accessible name. The ring is the band's
             own blue, which is what makes an overlapping stack read as a
             stack rather than as a smudge. --}}
        @if (! empty($flagUrls) && $flagDisplay === 'hero')
            {{-- The hero treatment. Global is sold on one fact - a real +1
                 United States number - and a stack of six would file it under
                 the same look every region gets. One large flag instead. --}}
            <img src="{{ $flagUrls[0] }}" alt="" class="absolute right-3 top-1/2 h-7 w-7 -translate-y-1/2 rounded-full object-cover ring-2 ring-white/70 shadow-lg shadow-black/20 @min-[190px]:h-8 @min-[190px]:w-8 @min-[260px]:right-5 @min-[260px]:h-11 @min-[260px]:w-11" loading="lazy" aria-hidden="true">
        @elseif (! empty($flagUrls))
            <span class="absolute right-3 top-1/2 flex -translate-y-1/2 -space-x-1.5 @min-[260px]:right-5" aria-hidden="true">
                @foreach ($flagUrls as $flagUrl)
                    {{-- Six 24px flags want ~100px, which is more than half of
                         a 173px mobile card. The tail drops below `sm` rather
                         than every flag shrinking into illegibility. --}}
                    <img
                        src="{{ $flagUrl }}"
                        alt=""
                        @class([
                            'h-4 w-4 rounded-full object-cover ring-2 ring-[#0a2ac2] @min-[190px]:h-5 @min-[190px]:w-5',
                            'hidden @min-[260px]:block' => $loop->index >= 3,
                        ])
                        loading="lazy"
                    >
                @endforeach
            </span>
        @else
            <span class="absolute right-3 top-1/2 h-2.5 w-2.5 -translate-y-1/2 rounded-full @min-[260px]:right-5" style="background-color: var(--esim-accent);" aria-hidden="true"></span>
        @endif

        @php
            $flagCount = count($flagUrls);
            $titleReserve = ($flagDisplay === 'hero' || $flagCount <= 1)
                ? 'pr-9 @min-[260px]:pr-14'
                : ($flagCount <= 3 ? 'pr-10 @min-[260px]:pr-[68px]' : 'pr-10 @min-[260px]:pr-[96px]');
        @endphp
        <div class="relative {{ $titleReserve }}">
            <h3 @class([
                'font-bold leading-tight tracking-tight text-white text-sm @min-[190px]:text-base',
                'line-clamp-2 @min-[260px]:text-lg' => $variant === 'tile',
                '@min-[260px]:text-xl' => $variant !== 'tile',
            ])>{{ $name }}</h3>
            @if ($description)
                <p @class([
                    'text-[10px] leading-snug text-white/80 @min-[190px]:text-[11px]',
                    'mt-1 @min-[190px]:mt-1.5 @min-[260px]:mt-2' => $variant !== 'tile',
                    '@min-[260px]:text-[13px]' => $variant !== 'tile',
                    // The standalone card grows to fit its copy. The tile
                    // cannot - it is locked to 16/10 to match the gift-card box
                    // - so the copy is clamped, and on the smallest tile (160px
                    // wide, 100px tall) it is dropped entirely: a two-line
                    // destination name leaves no room, and the name is the part
                    // that matters.
                    //
                    // The un-hiding is done BY the line-clamp utility, not by a
                    // `block`: clamping needs display:-webkit-box, so a `block`
                    // at the same breakpoint silently switches the clamp off and
                    // the full copy runs past the bottom edge.
                    'hidden mt-0.5 @min-[190px]:line-clamp-1 @min-[260px]:mt-1 @min-[260px]:line-clamp-2 @min-[260px]:text-[11px]' => $variant === 'tile',
                ])>{{ $description }}</p>
            @endif
        </div>
    </div>

    @if ($variant !== 'tile')
    {{-- Features. Every eSIM here is delivered by QR, so that one is always
         true; the rest appear only when the package data confirms them. --}}
    <div class="flex flex-wrap items-center gap-x-2 gap-y-0.5 px-3 pb-2.5 pt-2 @min-[190px]:px-3.5 @min-[190px]:pb-3 @min-[190px]:pt-2.5 @min-[260px]:gap-x-4 @min-[260px]:gap-y-1 @min-[260px]:px-5 @min-[260px]:pb-3.5">
        <span class="esim-card__feature inline-flex items-center gap-1 text-[9px] font-medium @min-[190px]:text-[10px] @min-[260px]:gap-1.5 @min-[260px]:text-[11px]">
            <svg class="h-3 w-3 shrink-0 @min-[190px]:h-3.5 @min-[190px]:w-3.5" style="color: var(--esim-accent);" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                <rect x="3" y="3" width="7" height="7" rx="1.5"/><rect x="14" y="3" width="7" height="7" rx="1.5"/><rect x="3" y="14" width="7" height="7" rx="1.5"/>
                <path d="M14 14h3v3h-3zM20 14v.01M20 20v.01M17 20v.01"/>
            </svg>
            {{ __('esim.card_instant_qr') }}
        </span>

        @if ($hotspotSupported === true)
            <span class="esim-card__feature inline-flex items-center gap-1 text-[9px] font-medium @min-[190px]:text-[10px] @min-[260px]:gap-1.5 @min-[260px]:text-[11px]">
                <svg class="h-3 w-3 shrink-0 @min-[190px]:h-3.5 @min-[190px]:w-3.5" style="color: var(--esim-accent);" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                    <path d="M5 12.5a9 9 0 0114 0M8 16a5 5 0 018 0"/><circle cx="12" cy="19.5" r="1.1" fill="currentColor" stroke="none"/>
                </svg>
                {{ __('esim.card_hotspot') }}
            </span>
        @endif

        @if ($networkLabel)
            <span class="esim-card__feature inline-flex items-center gap-1 text-[9px] font-medium @min-[190px]:text-[10px] @min-[260px]:gap-1.5 @min-[260px]:text-[11px]">
                <svg class="h-3 w-3 shrink-0 @min-[190px]:h-3.5 @min-[190px]:w-3.5" style="color: var(--esim-accent);" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                    <path d="M4 20v-3M9 20v-7M14 20v-11M19 20V5"/>
                </svg>
                {{ $networkLabel }}
            </span>
        @endif

    </div>
    @endif

    @if ($variant === 'tile' && ! $isAvailable)
        {{-- Same overlay badge the gift-card and top-up tiles use, rather than
             the bordered note strip - a fixed-aspect tile has no room for a
             strip, and this is the treatment the rest of the storefront
             already trains people to read. --}}
        <span class="absolute bottom-2 right-2 z-10 inline-flex items-center rounded-full border border-white bg-zinc-900/60 px-2.5 py-0.5 text-[10px] font-bold uppercase tracking-wide text-white backdrop-blur-sm">
            {{ $error ? __('esim.card_unavailable') : __('esim.card_currently_unavailable') }}
        </span>
    @endif

    @if ($variant !== 'tile' && $error)
        <p class="esim-card__note esim-card__note--error border-t border-amber-200 px-3 py-2 text-[10px] font-semibold @min-[260px]:px-5 @min-[260px]:py-2.5 @min-[260px]:text-xs">{{ $error }}</p>
    @elseif ($variant !== 'tile' && ! $isAvailable)
        <p class="esim-card__note border-t border-zinc-200 px-3 py-2 text-[10px] font-semibold @min-[260px]:px-5 @min-[260px]:py-2.5 @min-[260px]:text-xs">{{ __('esim.card_currently_unavailable') }}</p>
    @endif
    </div>

    {{-- Caption, OUTSIDE the card box and on the page background - the same
         shape the gift-card tiles use, so the two rows read as one system. --}}
    <div class="mt-2 shrink-0">
        @if ($variant === 'tile')
            {{-- The name repeats here on purpose. A gift-card tile carries the
                 brand wordmark in its artwork AND the brand name underneath;
                 this is the same pattern, not a duplication bug. --}}
            <p translate="no" class="notranslate truncate text-[16px] font-bold leading-tight text-zinc-900 dark:text-white">{{ $name }}</p>
        @endif
        @if ($fromPrice)
            <p translate="no" class="notranslate mt-0.5 truncate text-[14px] font-bold tabular-nums text-zinc-900 dark:text-white">{{ __('esim.card_from_price', ['price' => '$'.number_format($fromPrice, 2)]) }}</p>
        @endif
    </div>
</a>
@endif

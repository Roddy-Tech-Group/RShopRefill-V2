{{--
    Gift-card categories, on the home page.

    The four tiles above answer "what do you sell"; this answers "what kind of
    gift card", which is the question most visitors actually arrive with. Each
    item deep-links into the listing with that category already selected, so a
    shopper reaches a filtered page in one click instead of landing on 285
    tiles and hunting for the menu.

    No tile around each item - just the mark over its label. Nineteen bordered
    cards in a row read as nineteen buttons competing with the four real
    category cards directly above them; unboxed, the row reads as what it is,
    a legend. The panel behind it is already the surface.

    Each item is as wide as its own name, not a fixed width: at a fixed one,
    "Clothing & Accessories" broke onto a second line and the whole row sat at
    the height of the longest label. whitespace-nowrap keeps every name on one
    line and hands the short ones back the space the long ones were taking.

    A carousel rather than a wrapped row: nineteen categories on four lines
    swallowed the panel and pushed the brand rows below the fold. One line that
    scrolls keeps the panel's proportions whatever the catalogue does. The
    arrows float over the track edges and appear one at a time - a back arrow
    at the start of a row has nothing to go back to.

    Marks come from config/gift_card_category_icons.php, keyed by slug, with a
    fallback - the subcategory list is supplier-synced, so a category we have
    never seen can appear at any time and has to render something.

    An entry is either an existing file from public/assets or inline path data.
    Files win wherever a relevant one exists, so Travel carries the same plane
    the eSIM pages use and a category means one thing across the whole site.
    Those files are black artwork, so they invert in dark mode the same way the
    nav's category marks do; the inline ones ride on currentColor instead.

    No heading and no "All gift cards" item. The row sits directly under the
    trust strip inside a panel that already reads as one block, and the four
    tiles above it already lead to the full listing.

    Only categories with something to sell are listed - the same rule the
    listing sidebar uses. A category whose only destination is the empty state
    is not a shortcut, it is a dead end.

    No country filter here on purpose. The listing applies the visitor's region
    when they arrive; narrowing the home page by it too would hide categories
    from someone who is about to switch country anyway.
--}}
@php
    use App\Models\Category;
    use App\Models\Product;

    $giftCards = Category::where('slug', 'gift-cards')->first();

    // One representative product per brand, which is what the curated
    // categories are resolved against - the same shape the listing builds.
    //
    // whereHas on an AVAILABLE variant, not just an active product: stock is a
    // variant fact, and a brand whose every variant is sold out would
    // otherwise put its category on the row with the empty state behind it.
    $brandProducts = $giftCards
        ? Product::query()
            ->where('category_id', $giftCards->id)
            ->where('is_active', true)
            ->whereNotNull('brand_key')
            ->whereHas('variants', fn ($variant) => $variant->where('is_available', true))
            ->select('id', 'brand_key')
            ->get()
            ->unique('brand_key')
            ->values()
        : collect();

    // Curated categories then the supplier's, from the one place that decides
    // what a category is - so this row, the listing and both of its menus can
    // never drift apart.
    $categories = \App\Support\GiftCardCategories::menu($brandProducts, $giftCards);

    $icons = config('gift_card_category_icons', []);

    $itemClass = 'group flex shrink-0 flex-col items-center gap-2 rounded-[12px] px-4 py-3 text-center transition-colors hover:bg-[#ffffff] focus:outline-none focus-visible:ring-2 focus-visible:ring-blue-500/40 dark:hover:bg-white/10';
    $markClass = 'h-7 w-7 shrink-0 text-zinc-900 dark:text-white';
    $labelClass = 'whitespace-nowrap text-[14px] font-bold leading-tight text-zinc-900 dark:text-white';
    $arrowClass = 'flex h-10 w-10 items-center justify-center rounded-full bg-[#ffffff] text-zinc-900 shadow-md shadow-zinc-900/10 ring-1 ring-[#dee3e1] transition-colors hover:bg-[#e8ecea] focus:outline-none focus-visible:ring-2 focus-visible:ring-blue-500/40 dark:bg-[#1d3252] dark:text-white dark:ring-white/10 dark:hover:bg-[#26416b]';
@endphp

@if ($categories->isNotEmpty() && \App\Support\FeatureFlag::on('gift_cards'))
    {{-- Sits inside the panel, under the trust strip, behind the same rule the
         strip uses so the panel reads as one card with three bands rather than
         a card with something bolted on. --}}
    <nav
        class="mt-10 border-t border-zinc-200 pt-10 dark:border-white/12"
        aria-label="{{ __('products.gift_card_categories') }}"
        x-data="{
            canPrev: false,
            canNext: false,

            refresh() {
                const track = this.$refs.track;
                if (! track) { return; }

                // 4px of slack: sub-pixel scroll positions otherwise leave the
                // arrow enabled at the very end of a track.
                this.canPrev = track.scrollLeft > 4;
                this.canNext = track.scrollLeft + track.clientWidth < track.scrollWidth - 4;
            },

            page(direction) {
                const track = this.$refs.track;
                if (! track) { return; }

                // Exactly one item per press, landing on that item's edge.
                //
                // Items are as wide as their own names, so a step taken from
                // the first item's width is wrong for every other item: after
                // one press the row sits mid-item and the error compounds.
                // Measuring to the next edge instead is right whatever the
                // labels are.
                const edge = track.getBoundingClientRect().left;
                const offsets = Array.from(track.querySelectorAll('li'))
                    .map((item) => Math.round(item.getBoundingClientRect().left - edge))
                    .filter((offset) => (direction > 0 ? offset > 1 : offset < -1));

                // Nothing left to align to - run to the end rather than stall.
                if (! offsets.length) {
                    track.scrollBy({ left: direction * track.clientWidth, behavior: 'smooth' });
                    return;
                }

                track.scrollBy({
                    left: direction > 0 ? offsets[0] : offsets[offsets.length - 1],
                    behavior: 'smooth',
                });
            },
        }"
        x-init="$nextTick(() => refresh())"
        x-on:livewire:navigated.window="$nextTick(() => refresh())"
        @resize.window.debounce.200ms="refresh()"
    >
        <div class="relative">
            <div
                x-ref="track"
                @scroll.debounce.100ms="refresh()"
                class="overflow-x-auto overflow-y-hidden [scrollbar-width:none] [&::-webkit-scrollbar]:hidden [-webkit-overflow-scrolling:touch] scroll-smooth"
            >
                <ul class="flex w-max items-stretch gap-1">
                    @foreach ($categories as $category)
                        <li>
                            @php $icon = $icons[$category['icon']] ?? $icons['default']; @endphp
                            <a href="{{ route('shop.gift-cards', ['subcategory' => $category['slug']]) }}" wire:navigate class="{{ $itemClass }}">
                                @if (! empty($icon['asset']))
                                    {{-- Black artwork, so it inverts in dark mode - the same
                                         treatment the nav's category marks use. --}}
                                    <img
                                        src="{{ asset('assets/' . rawurlencode($icon['asset'])) }}"
                                        alt=""
                                        class="{{ $markClass }} object-contain dark:brightness-0 dark:invert"
                                        loading="lazy"
                                    >
                                @else
                                    <svg class="{{ $markClass }}" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                        <path d="{{ $icon['path'] }}" />
                                    </svg>
                                @endif

                                <span class="{{ $labelClass }}">{{ $category['name'] }}</span>
                            </a>
                        </li>
                    @endforeach
                </ul>
            </div>

            {{-- Arrows float over the track's edges, each appearing only when
                 there is something that way. The gradient beside each one fades
                 the row out rather than letting an item sit half-cut under the
                 button. --}}
            <div x-show="canPrev" x-cloak class="pointer-events-none absolute inset-y-0 left-0 flex items-center pr-8">
                <span aria-hidden="true" class="absolute inset-y-0 left-0 w-24 bg-gradient-to-r from-[#f4f6f5] to-transparent dark:from-[#0c1a36]"></span>
                <button type="button" @click="page(-1)" aria-label="{{ __('products.previous_page', ['category' => __('products.gift_card_categories')]) }}" class="pointer-events-auto relative {{ $arrowClass }}">
                    <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                        <path d="M15 6l-6 6 6 6" />
                    </svg>
                </button>
            </div>

            <div x-show="canNext" x-cloak class="pointer-events-none absolute inset-y-0 right-0 flex items-center justify-end pl-8">
                <span aria-hidden="true" class="absolute inset-y-0 right-0 w-24 bg-gradient-to-l from-[#f4f6f5] to-transparent dark:from-[#0c1a36]"></span>
                <button type="button" @click="page(1)" aria-label="{{ __('products.next_page', ['category' => __('products.gift_card_categories')]) }}" class="pointer-events-auto relative {{ $arrowClass }}">
                    <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                        <path d="M9 6l6 6-6 6" />
                    </svg>
                </button>
            </div>
        </div>
    </nav>
@endif

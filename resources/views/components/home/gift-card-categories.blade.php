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

    $itemClass = 'group flex shrink-0 flex-col items-center gap-1.5 rounded-[12px] px-2 py-2 text-center sm:gap-2 sm:px-4 sm:py-3 transition-colors hover:text-blue-700 focus:outline-none focus-visible:ring-2 focus-visible:ring-blue-500/40 dark:hover:text-blue-300';
    $markClass = 'h-5 w-5 shrink-0 text-zinc-900 sm:h-7 sm:w-7 dark:text-white';
    $labelClass = 'whitespace-nowrap text-[11px] font-bold leading-tight text-zinc-900 sm:text-[14px] dark:text-white';
    $arrowClass = 'flex h-12 w-12 items-center justify-center rounded-full bg-white/45 backdrop-blur-md backdrop-saturate-150 text-zinc-900 shadow-md shadow-zinc-900/10 transition-colors focus:outline-none focus-visible:ring-2 focus-visible:ring-blue-500/40 dark:bg-white/10 dark:text-white';
@endphp

@if ($categories->isNotEmpty() && \App\Support\FeatureFlag::on('gift_cards'))
    {{-- Sits inside the panel, under the trust strip, behind the same rule the
         strip uses so the panel reads as one card with three bands rather than
         a card with something bolted on. --}}
    <nav
        class="mt-10 pt-10"
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
                // A screenful per press, then settle on the nearest item edge so
                // nothing sits half-cut. Stepping to the very next edge instead
                // meant one chip per press: eleven presses to cross eleven
                // categories, which read as the row barely moving.
                const edge = track.getBoundingClientRect().left;
                const reach = Math.max(160, track.clientWidth * 0.8);
                const offsets = Array.from(track.querySelectorAll('li'))
                    .map((item) => Math.round(item.getBoundingClientRect().left - edge))
                    .filter((offset) => (direction > 0 ? offset > 1 : offset < -1));

                // Nothing left to align to - run to the end rather than stall.
                if (! offsets.length) {
                    track.scrollBy({ left: direction * track.clientWidth, behavior: 'smooth' });
                    return;
                }

                const target = direction > 0
                    ? (offsets.filter((o) => o <= reach).pop() ?? offsets[0])
                    : (offsets.filter((o) => o >= -reach).shift() ?? offsets[offsets.length - 1]);

                track.scrollBy({ left: target, behavior: 'smooth' });
            },
        }"
        {{-- Re-measure on the element's OWN width, not just the window's: the
             dashboard pane resizes when the sidebar folds and fires no window
             resize, which leaves the track at its old width. --}}
        x-init="
            $nextTick(() => { refresh(); });
            if (window.ResizeObserver) {
                let seen = 0, queued = false;
                $el._chipsObserver = new ResizeObserver(() => {
                    const w = Math.round($el.getBoundingClientRect().width);
                    if (! w || w === seen || queued) { return; }
                    seen = w;
                    queued = true;
                    requestAnimationFrame(() => { queued = false; refresh(); });
                });
                $el._chipsObserver.observe($el);
            }
        "
        x-on:livewire:navigating.window="$el._chipsObserver?.disconnect()"
        x-on:livewire:navigated.window="$nextTick(() => refresh())"
        @resize.window.debounce.200ms="refresh()"
    >
        <div class="relative">
            <div
                x-ref="track"
                @scroll.debounce.100ms="refresh()"
                class="overflow-x-auto overflow-y-hidden [scrollbar-width:none] [&::-webkit-scrollbar]:hidden [-webkit-overflow-scrolling:touch] scroll-smooth"
            >
                <ul class="flex w-max items-stretch gap-0.5 sm:gap-1">
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
                 there is something that way. No fade beside them: it was a
                 gradient to the panel's base colour, and the panel does not
                 render that colour, so it read as a pale block rather than a
                 fade. The button's own fill is enough to sit over a row. --}}
            <div x-show="canPrev" x-cloak class="pointer-events-none absolute inset-y-0 left-0 flex items-center pr-8">
                <button type="button" @click="page(-1)" aria-label="{{ __('products.previous_page', ['category' => __('products.gift_card_categories')]) }}" class="pointer-events-auto relative {{ $arrowClass }}">
                    <svg class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                        <path d="M15 6l-6 6 6 6" />
                    </svg>
                </button>
            </div>

            <div x-show="canNext" x-cloak class="pointer-events-none absolute inset-y-0 right-0 flex items-center justify-end pl-8">
                <button type="button" @click="page(1)" aria-label="{{ __('products.next_page', ['category' => __('products.gift_card_categories')]) }}" class="pointer-events-auto relative {{ $arrowClass }}">
                    <svg class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                        <path d="M9 6l6 6-6 6" />
                    </svg>
                </button>
            </div>
        </div>
    </nav>
@endif

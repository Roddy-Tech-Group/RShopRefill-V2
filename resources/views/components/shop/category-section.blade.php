{{--
    One category on the gift-card listing: a heading and a paged grid of tiles.

    Three rows deep, four columns wide on desktop - so a category shows twelve
    cards and pages sideways to the next twelve. Before this, a category with
    126 brands ran for thirty-odd rows and buried every heading after it; the
    page was browsable only by scrolling past whole categories you did not want.

    One continuous grid, three rows deep, flowing into columns - so a category
    is three lines at EVERY breakpoint. Only the column width changes: four
    columns on desktop, three on a tablet, two on a phone.

    That width is measured in JS and handed to CSS as pixels, because a
    percentage inside a horizontally scrolling grid has no definite inline size
    to resolve against; the browser falls back to sizing each column by content,
    which rendered one enormous card per screen the first time this was built.

    Since a screenful is exactly one page of columns, the arrows can still
    scroll by `clientWidth` and land square on the next set.

    Native overflow-x-auto keeps trackpad and touch momentum, and scroll-snap
    stops a flick halfway between two columns. The arrows are the desktop
    affordance on top of that, not the only way to move.
--}}
@props([
    'title',
    'id',
    // Null on a page that does not filter client-side; the section then always
    // shows. Otherwise it shows when it is the selected category, or when
    // nothing is selected ("All gift cards").
    'slug' => null,
    // How many cards this section holds. Passed in rather than counted here:
    // the caller owns the collection, and a mismatch would be invisible.
    'total' => 0,
])

{{-- Space alone separates one category from the next. mb-16 is deliberately
     generous - it is the only thing marking the boundary now, so it has to be
     bigger than the gap between rows inside a category or the headings stop
     reading as headings. --}}
<section
    class="mb-16 min-w-0 last:mb-0"
    aria-labelledby="{{ $id }}"
    @if ($slug !== null)
        {{-- x-show, not x-if: the tiles stay in the DOM so switching back is
             instant and the browser keeps the images it already decoded. --}}
        x-show="activeCategory === '' || activeCategory === @js($slug)"
    @endif
    x-data="{
        canPrev: false,
        canNext: false,


        /*
         * Skeleton until this section's visible artwork has decoded, then the
         * whole section reveals at once. Per-tile reveals would pop a dozen
         * times as images land; one step reads as the page arriving.
         *
         * The 2s ceiling is the important half: a blocked or broken image must
         * not leave the section skeletal forever.
         */
        ready: false,

        settle() {
            /* Eager images only: the lazy ones sit in a display:none list,
               never intersect, and so never fire load - counting them held
               every section at the 2s ceiling. They load normally once the
               reveal makes them visible. */
            const images = [...this.$el.querySelectorAll('img[loading=eager]')].slice(0, 12);
            const pending = images.filter((image) => ! image.complete);

            /* Measure once the cards are actually on screen - the frame
               after ready flips is the first time they have positions. */
            const reveal = () => {
                this.ready = true;
                requestAnimationFrame(() => this.refresh());
            };

            if (! pending.length) { reveal(); return; }

            let left = pending.length;
            const done = () => { if (--left <= 0) reveal(); };

            pending.forEach((image) => {
                image.addEventListener('load', done, { once: true });
                image.addEventListener('error', done, { once: true });
            });

            setTimeout(reveal, 2000);
        },

        /*
         * Column width = (track - gaps) / columns, so `columns` of them exactly
         * fill one screenful. Re-measured on resize, which is when both the
         * breakpoint and the track width can change.
         */
        measure() {
            const track = this.$refs.track;
            const list = this.$refs.list;
            if (! track || ! list) { return; }

            const columns = window.innerWidth >= 1024 ? 4 : (window.innerWidth >= 640 ? 3 : 2);

            /* Three rows is a ceiling, not a fixed height: a category with one 
               row of cards should be one row tall. */
            const rows = Math.max(1, Math.min(3, Math.ceil(list.children.length / columns)));
            list.style.gridTemplateRows = 'repeat(' + rows + ', auto)';
            const gap = 16;
            /* Clamped to the track it was measured from. Without this a 
               container that fails to constrain the track feeds its own width 
               back in and the column grows without bound - it reached 3.6 
               million pixels before min-w-0 fixed the container. 
               A track with no width is not laid out yet - almost always a 
               hidden section. Measuring it would bake in the 120px floor and 
               leave the grid wrong once it is shown. */
            if (track.clientWidth < 1) { return; }

            const available = Math.min(track.clientWidth, window.innerWidth);
            const width = (available - gap * (columns - 1)) / columns;

            /* On the TRACK, not the list: the skeleton grid is the track's
               other child and must share the width, or its tiles hold the CSS
               fallback and the reveal reflows every column. */
            track.style.setProperty('--col-w', Math.max(120, Math.floor(width)) + 'px');

            /* Place every tile explicitly - see the note above for why `order` 
               cannot do this. A page is `columns` wide and three rows deep, and 
               an unfilled page keeps its empty slots rather than pulling the 
               next tiles forward. */
            const perPage = columns * rows;
            const items = list.children;

            for (let i = 0; i < items.length; i++) {
                const page = Math.floor(i / perPage);
                const spot = i % perPage;

                items[i].style.gridRow = (Math.floor(spot / columns) + 1);
                items[i].style.gridColumn = (page * columns + (spot % columns) + 1);
            }
        },

        refresh() {
            const track = this.$refs.track;
            if (! track) { return; }

            /* 4px of slack: sub-pixel scroll positions otherwise leave the 
               arrow enabled at the very end of a track. */
            this.canPrev = track.scrollLeft > 4;
            this.canNext = track.scrollLeft + track.clientWidth < track.scrollWidth - 4;

        },

        page(direction) {
            const track = this.$refs.track;
            if (! track) { return; }

            /* Exactly one trackful, which is exactly one page of columns. */
            track.scrollBy({ left: direction * track.clientWidth, behavior: 'smooth' });
        },
    }"
    {{-- measure() divides this section's own width, so it has to re-run when
         that width changes, not only when the window does. In the dashboard the
         pane narrows every time the sidebar opens, with no window resize to
         hear, which left the columns at their old width and cut the last card
         off at the panel edge until the sidebar was folded again or the page
         hard-reloaded. The observer catches that and anything else that resizes
         the pane. The width guard stops measure()'s own writes from
         re-triggering it. --}}
    x-init="
        $nextTick(() => { measure(); refresh(); settle(); });
        if (window.ResizeObserver) {
            let seen = 0, queued = false;
            $el._sectionObserver = new ResizeObserver(() => {
                const w = Math.round($el.getBoundingClientRect().width);
                if (! w || w === seen || queued) { return; }
                seen = w;
                // One measure per frame: the sidebar folds over a transition,
                // so the observer fires on every frame of it.
                queued = true;
                requestAnimationFrame(() => { queued = false; measure(); refresh(); });
            });
            $el._sectionObserver.observe($el);
        }
    "
    x-on:livewire:navigating.window="$el._sectionObserver?.disconnect()"
    x-effect="activeCategory; setTimeout(() => { measure(); refresh(); }, 80)"
    x-on:livewire:navigated.window="$nextTick(() => { measure(); refresh(); })"
    @resize.window.debounce.200ms="measure(); refresh()"
>
    <div class="mb-4 flex items-center justify-between gap-4">
        <h2 id="{{ $id }}" class="min-w-0 text-[19px] font-bold leading-[24px] tracking-tight text-zinc-900 sm:truncate sm:text-[25.6px] sm:leading-[31.5px] dark:text-white">
            {{ $title }}
        </h2>

        {{-- Hidden when the category fits in one page: a pair of permanently
             dead arrows reads as broken rather than as "nothing more here". --}}
        <div class="flex shrink-0 items-center gap-2" x-show="canPrev || canNext" x-cloak>
            <button
                type="button"
                @click="page(-1)"
                :disabled="! canPrev"
                aria-label="{{ __('products.previous_page', ['category' => $title]) }}"
                class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-[#ffffff] text-zinc-700 ring-1 ring-[#dee3e1] shadow-sm transition-colors duration-150 hover:bg-[#f4f6f5] hover:text-zinc-900 focus:outline-none focus-visible:ring-2 focus-visible:ring-blue-500/40 disabled:cursor-not-allowed disabled:opacity-30 dark:bg-white/5 dark:text-zinc-200 dark:ring-white/10 dark:hover:bg-white/10 dark:hover:text-white"
            >
                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                    <path d="M15 6l-6 6 6 6" />
                </svg>
            </button>

            <button
                type="button"
                @click="page(1)"
                :disabled="! canNext"
                aria-label="{{ __('products.next_page', ['category' => $title]) }}"
                class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-[#ffffff] text-zinc-700 ring-1 ring-[#dee3e1] shadow-sm transition-colors duration-150 hover:bg-[#f4f6f5] hover:text-zinc-900 focus:outline-none focus-visible:ring-2 focus-visible:ring-blue-500/40 disabled:cursor-not-allowed disabled:opacity-30 dark:bg-white/5 dark:text-zinc-200 dark:ring-white/10 dark:hover:bg-white/10 dark:hover:text-white"
            >
                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                    <path d="M9 6l6 6-6 6" />
                </svg>
            </button>
        </div>
    </div>

    <div
        x-ref="track"
        @scroll.debounce.100ms="refresh()"
        class="overflow-x-auto overflow-y-hidden [scrollbar-width:none] [&::-webkit-scrollbar]:hidden [-webkit-overflow-scrolling:touch] snap-x snap-mandatory"
    >
        {{-- grid-rows-3 + grid-flow-col fills top-to-bottom then starts a new
             column, so the row count is fixed and the catalogue grows sideways.
             --col-w comes from measure() above. --}}
        {{-- Skeleton and content share the grid classes and the tile markup,
             so they occupy the same boxes and the swap cannot shift anything -
             which is what sank the hand-drawn overlay this replaces. --}}
        <ul x-show="! ready" class="grid grid-flow-col grid-rows-[repeat(3,auto)] gap-x-4 gap-y-6 auto-cols-[var(--col-w,calc(50vw_-_24px))] sm:auto-cols-[var(--col-w,260px)]">
            @for ($i = 0; $i < 12; $i++)
                <x-shop.brand-tile :skeleton="true" />
            @endfor
        </ul>

        <ul x-ref="list" x-show="ready" x-cloak class="grid grid-flow-col grid-rows-[repeat(3,auto)] gap-x-4 gap-y-6 auto-cols-[var(--col-w,calc(50vw_-_24px))] sm:auto-cols-[var(--col-w,260px)]">
            {{ $slot }}
        </ul>
    </div>

</section>

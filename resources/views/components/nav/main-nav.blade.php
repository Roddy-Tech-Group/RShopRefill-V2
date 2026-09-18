{{--
    Primary navigation row - logo · search - plus a category shortcut bar.
    Account and cart live in x-nav.top-bar above it now.

    On page scroll the primary row collapses (slides up + fades) so only the
    top bar + category strip remain pinned with the parent sticky header.

    The row is a 3-column grid: logo and search sit together on the left; the
    third column carries only the mobile search trigger, so it is empty from md
    up. The search bar lines up with the category bar below.
--}}
<div
    x-data="{
        hidden: false,
        ticking: false,
        init() {
            // Every width collapses now, desktop included: the logo row is
            // what stays pinned, and the search row and category strip give
            // their space back once the page is scrolled.
            //
            // The two thresholds are far apart on purpose, and further apart
            // than the height the collapse removes. Collapsing shortens the
            // header, which pulls the page up under the cursor; if that lands
            // back under SHOW_AT the bar expands, which pushes it down again,
            // and the nav sits there flickering. The gap here (8px to 130px)
            // cannot be crossed by the ~100px the two rows give back, so the
            // loop has nowhere to close.
            //
            // HIDE_AT was 220, which meant most of a phone screen went by
            // before the bar gave its space back. 130 is the lowest this can
            // go while the gap still clears that ~100px with room to spare.
            const SHOW_AT = 8;
            const HIDE_AT = 130;
            const onScroll = () => {
                if (this.ticking) { return; }
                this.ticking = true;
                requestAnimationFrame(() => {
                    const y = window.scrollY;
                    if (y <= SHOW_AT)      { this.hidden = false; }
                    else if (y >= HIDE_AT) { this.hidden = true; }
                    this.publishHeight();
                    this.ticking = false;
                });
            };
            window.addEventListener('scroll', onScroll, { passive: true });
            window.addEventListener('resize', onScroll, { passive: true });
            onScroll();
            // The collapse animates, so the height keeps changing for ~350ms
            // after the class flips. Re-publish while it settles.
            this.$el.addEventListener('transitionend', () => this.publishHeight());
        },

        // Publishes the pinned header's real height as --nav-h. Anything that
        // has to sit under the bar reads that instead of hardcoding a number:
        // the bar is 56px or 64px depending on width, and taller again while
        // the search row and category strip are expanded, so every fixed offset
        // written against it was wrong the moment any of those changed.
        publishHeight() {
            const header = this.$el.closest('header');
            if (! header) { return; }
            document.documentElement.style.setProperty('--nav-h', Math.round(header.getBoundingClientRect().height) + 'px');
        },
    }"
    {{-- Transparent: the header behind it carries the page tone, and this is
         what makes the whole nav read as one colour with the page. It used to
         wear a white/30 backdrop-blur wash to frost content sliding underneath
         a STICKY header - nothing slides under it now. --}}
    class="bg-transparent"
>

    {{-- Mobile menu. The utility strip that used to sit above the logo -
         Help, region/currency, theme - now lives behind the menu button in the
         row below, which is where a phone expects to find secondary controls.

         Outside the collapsible wrapper on purpose: when the primary row
         collapses on scroll this stays reachable. --}}
    <div
        x-data="{ menuOpen: false, sub: null }"
        @keydown.escape.window="menuOpen = false"
        x-effect="menuOpen ? window.rshopScrollLock?.lock() : window.rshopScrollLock?.unlock()"
        class="md:hidden"
    >
        <div
            x-show="menuOpen"
            x-cloak
            x-transition:enter="transition-opacity ease-out duration-200"
            x-transition:enter-start="opacity-0"
            x-transition:enter-end="opacity-100"
            x-transition:leave="transition-opacity ease-in duration-150"
            x-transition:leave-start="opacity-100"
            x-transition:leave-end="opacity-0"
            @click="menuOpen = false"
            {{-- Frosted, not a flat scrim: the close button sits ON this layer
                 rather than in the panel, so the page behind has to fall back far
                 enough for a white glyph to read against it. Same treatment as the
                 cart and search panels. --}}
            class="fixed inset-0 z-[70] bg-zinc-900/45 backdrop-blur-md backdrop-saturate-150"
            aria-hidden="true"
        ></div>

        {{-- Close on the backdrop, not in the panel: inside, it sits level with
             the first category and competes with it. --}}
        <button
            type="button"
            x-show="menuOpen"
            x-cloak
            x-transition.opacity
            @click="menuOpen = false"
            aria-label="{{ __('common.close') }}"
            class="fixed right-4 top-4 z-[72] flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-[#dde3ee] text-zinc-600 transition-colors hover:bg-[#cdd5e4] hover:text-zinc-900 dark:bg-white/10 dark:text-zinc-300 dark:hover:bg-white/20 dark:hover:text-white"
        >
            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
            </svg>
        </button>

        <div
            x-show="menuOpen"
            x-cloak
            x-transition:enter="transition-transform duration-300 ease-[cubic-bezier(0.22,1,0.36,1)]"
            x-transition:enter-start="-translate-x-full"
            x-transition:enter-end="translate-x-0"
            x-transition:leave="transition-transform duration-200 ease-in"
            x-transition:leave-start="translate-x-0"
            x-transition:leave-end="-translate-x-full"
            class="fixed inset-y-0 left-0 z-[71] flex w-[86vw] max-w-[340px] flex-col overflow-x-hidden overflow-y-auto bg-[#ffffff] p-5 pt-[max(1.25rem,env(safe-area-inset-top))] pb-[max(1.25rem,env(safe-area-inset-bottom))] shadow-2xl dark:bg-[#0c1a36]"
            role="dialog"
            aria-modal="true"
        >
            @php
                // 'children' names the second page a row opens in place of
                // navigating; null rows are plain links. Gift Cards' content
                // is the same navMenu() payload the desktop hover panel
                // renders - memoized, so the second call is free.
                $sheetLinks = array_values(array_filter([
                    \App\Support\FeatureFlag::on('gift_cards')
                        ? ['label' => __('navigation.gift_cards'), 'url' => route('shop.gift-cards'), 'children' => 'gift-cards'] : null,
                    \App\Support\FeatureFlag::on('esims')
                        ? ['label' => __('navigation.esims'), 'url' => route('shop.esims'), 'children' => null] : null,
                    \App\Support\FeatureFlag::on('topups')
                        ? ['label' => __('navigation.mobile_refill'), 'url' => route('shop.topups'), 'children' => 'topups'] : null,
                    \App\Support\FeatureFlag::on('bills')
                        ? ['label' => __('navigation.bill_payments'), 'url' => route('shop.bills'), 'children' => null] : null,
                ]));

                $giftNav = \App\Support\FeatureFlag::on('gift_cards')
                    ? \App\Support\GiftCardCategories::navMenu($region ?? null)
                    : null;

                // On the gift-card listing every category section is already
                // on the page and switching is a class toggle, so a category
                // deep-link there re-fetches markup the browser is holding.
                // Dispatch the listing's own category-filtered event instead -
                // the same thing x-shop.category-picker does with clientFilter.
                // Everywhere else these stay ordinary wire:navigate links.
                $sheetFiltersInPlace = request()->routeIs('shop.gift-cards');

                $sheetRowClass = 'flex w-full items-center justify-between gap-3 py-3 text-[17px] font-bold text-zinc-900 transition-colors hover:text-blue-600 dark:text-white dark:hover:text-blue-400';
                $sheetSubLinkClass = 'block py-2.5 text-[16px] font-semibold text-zinc-800 transition-colors hover:text-blue-600 dark:text-zinc-100 dark:hover:text-blue-400';
                $sheetSubHeadingClass = 'mt-4 text-[12px] font-bold uppercase tracking-[0.08em] text-[#0c1a36] dark:text-blue-300';
                $sheetBackClass = '-ml-2 inline-flex items-center gap-1.5 self-start rounded-full px-2 py-2 text-[13px] font-semibold text-zinc-600 transition-colors hover:bg-[#f4f6f5] hover:text-zinc-900 focus:outline-none focus-visible:ring-2 focus-visible:ring-blue-500/40 dark:text-zinc-400 dark:hover:bg-white/10 dark:hover:text-white';
                $sheetPageTitleClass = 'py-2 text-[17px] font-bold text-zinc-900 dark:text-white';
            @endphp

            {{-- Paged, not an accordion: the sheet is 340px of thumb space,
                 and a drill-down that expands in place pushes everything
                 below it off screen. Tapping a row with children swaps the
                 whole sheet for that row's own page; Menu at its top comes
                 back. Enter-only transitions - forward slides in from the
                 right, back from the left - so the outgoing page never
                 stacks under the incoming one. --}}

            {{-- Root page: the category rows, then the secondary controls. --}}
            <div
                x-show="sub === null"
                x-transition:enter="transition duration-300 ease-[cubic-bezier(0.22,1,0.36,1)]"
                x-transition:enter-start="-translate-x-6 opacity-0"
                x-transition:enter-end="translate-x-0 opacity-100"
                class="flex flex-1 flex-col gap-5"
            >
                {{-- Categories first: this is the mega menu's home on a phone. --}}
                <nav class="flex flex-col" aria-label="{{ __('navigation.categories') }}">
                    @foreach ($sheetLinks as $link)
                        @if ($link['children'])
                            <button
                                type="button"
                                @click="sub = '{{ $link['children'] }}'"
                                aria-controls="mobile-{{ $link['children'] }}-menu"
                                class="{{ $sheetRowClass }} text-left"
                            >
                                {{ $link['label'] }}

                                <svg class="h-4 w-4 shrink-0 text-zinc-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5" aria-hidden="true">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7" />
                                </svg>
                            </button>
                        @else
                            <a
                                href="{{ $link['url'] }}"
                                wire:navigate
                                @click="menuOpen = false"
                                class="{{ $sheetRowClass }}"
                            >
                                {{ $link['label'] }}
                            </a>
                        @endif
                    @endforeach
                </nav>

                {{-- Secondary controls sit at the foot, behind a rule - reachable
                     with a thumb and clearly not part of the catalogue above. --}}
                <div class="mt-auto flex flex-col gap-1 pt-6">
                    <div @click="menuOpen = false">
                        <x-nav.invite-link />
                    </div>

                    <div class="mt-4 flex flex-col items-start gap-1 border-t border-[#dee3e1] pt-4 dark:border-white/10" @click="menuOpen = false">
                        <x-nav.utility-links />
                    </div>

                    {{-- Same arrangement as the footer: the Theme label
                         beside the segmented chip. The dropdown this replaced
                         popped a panel, which is one tap more than a control
                         that is already three buttons wide. --}}
                    <div class="mt-2 flex items-center gap-3">
                        <span class="text-sm font-semibold text-zinc-500" aria-hidden="true">{{ __('footer.theme') }}</span>
                        <x-theme-toggle variant="segmented" />
                    </div>
                </div>
            </div>

            {{-- Gift Cards page: the mega menu's catalogue. --}}
            @if (\App\Support\FeatureFlag::on('gift_cards'))
                <div
                    x-show="sub === 'gift-cards'"
                    x-cloak
                    x-transition:enter="transition duration-300 ease-[cubic-bezier(0.22,1,0.36,1)]"
                    x-transition:enter-start="translate-x-6 opacity-0"
                    x-transition:enter-end="translate-x-0 opacity-100"
                    id="mobile-gift-cards-menu"
                    class="flex flex-col"
                >
                    <button type="button" @click="sub = null" class="{{ $sheetBackClass }}">
                        <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 19.5L8.25 12l7.5-7.5" />
                        </svg>
                        {{ __('common.menu') }}
                    </button>

                    <p class="{{ $sheetPageTitleClass }}">{{ __('navigation.gift_cards') }}</p>

                    <a href="{{ route('shop.gift-cards') }}" wire:navigate @click="menuOpen = false" class="{{ $sheetSubLinkClass }}">
                        {{ __('products.all_gift_cards') }}
                    </a>
                    <a
                        href="{{ route('shop.gift-cards', ['subcategory' => 'popular']) }}"
                        @if ($sheetFiltersInPlace)
                            @click.prevent="menuOpen = false; $dispatch('category-filtered', { slug: 'popular' })"
                        @else
                            wire:navigate @click="menuOpen = false"
                        @endif
                        class="{{ $sheetSubLinkClass }}"
                    >
                        {{ __('products.popular_brands') }}
                    </a>
                    <a href="{{ route('shop.gift-cards', ['sort' => 'recent']) }}" wire:navigate @click="menuOpen = false" class="{{ $sheetSubLinkClass }}">
                        {{ __('products.recently_added') }}
                    </a>

                    @if ($giftNav && $giftNav['categories']->isNotEmpty())
                        <p class="{{ $sheetSubHeadingClass }}">{{ __('navigation.categories') }}</p>
                        @foreach ($giftNav['categories'] as $category)
                            <a
                                href="{{ route('shop.gift-cards', ['subcategory' => $category['slug']]) }}"
                                @if ($sheetFiltersInPlace)
                                    @click.prevent="menuOpen = false; $dispatch('category-filtered', { slug: @js($category['slug']) })"
                                @else
                                    wire:navigate @click="menuOpen = false"
                                @endif
                                class="{{ $sheetSubLinkClass }}"
                            >
                                {{ $category['name'] }}
                            </a>
                        @endforeach
                    @endif

                    @if ($giftNav && $giftNav['popular']->isNotEmpty())
                        <p class="{{ $sheetSubHeadingClass }}">{{ __('navigation.popular_in', ['country' => $giftNav['regionName']]) }}</p>
                        @foreach ($giftNav['popular'] as $brandKey)
                            <a href="{{ route('shop.brand', ['brandSlug' => \App\Models\Product::brandSlug($brandKey)]) }}" wire:navigate @click="menuOpen = false" class="{{ $sheetSubLinkClass }}">
                                {{ \App\Models\Product::brandDisplayName($brandKey) }}
                            </a>
                        @endforeach
                    @endif
                </div>
            @endif

            {{-- Mobile Refill page. Mirrors the filter pills on the top-ups
                 page: same labels, same URLs, so the menu and the page never
                 name a view differently. --}}
            @if (\App\Support\FeatureFlag::on('topups'))
                <div
                    x-show="sub === 'topups'"
                    x-cloak
                    x-transition:enter="transition duration-300 ease-[cubic-bezier(0.22,1,0.36,1)]"
                    x-transition:enter-start="translate-x-6 opacity-0"
                    x-transition:enter-end="translate-x-0 opacity-100"
                    id="mobile-topups-menu"
                    class="flex flex-col"
                >
                    <button type="button" @click="sub = null" class="{{ $sheetBackClass }}">
                        <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 19.5L8.25 12l7.5-7.5" />
                        </svg>
                        {{ __('common.menu') }}
                    </button>

                    <p class="{{ $sheetPageTitleClass }}">{{ __('navigation.mobile_refill') }}</p>

                    <a href="{{ route('shop.topups') }}" wire:navigate @click="menuOpen = false" class="{{ $sheetSubLinkClass }}">
                        All networks
                    </a>
                    <a href="{{ route('shop.topups', ['subcategory' => 'credit']) }}" wire:navigate @click="menuOpen = false" class="{{ $sheetSubLinkClass }}">
                        Credit only
                    </a>
                    <a href="{{ route('shop.topups', ['subcategory' => 'bundles']) }}" wire:navigate @click="menuOpen = false" class="{{ $sheetSubLinkClass }}">
                        Credit, Data &amp; Calls
                    </a>
                </div>
            @endif
        </div>

        {{-- The button itself sits in the logo row below, so it is rendered
             there and dispatches to this scope. --}}
        <span
            x-show="false"
            @open-mobile-menu.window="menuOpen = true; sub = null"
        ></span>
    </div>

    {{-- Primary nav row. This one STAYS: the logo, the hamburger and the
         account and cart controls are what a shopper reaches for at any point
         on the page, so they ride the sticky header. The search row and the
         category strip below are the parts that collapse on scroll. --}}
    <div>
    <div class="max-w-[1550px] mx-auto px-4 sm:px-6 lg:px-8">
        {{-- gap-2 on a phone, gap-4 from md.

             On a phone this gap sits between the menu button and the logo, and
             16px there measured much wider than it was: the button's icon is
             24px inside a 44px tap target, so ten of those pixels are already
             empty before the gap starts. From md the button is gone and the
             same gap separates the logo from the search bar, where 16px is
             right, so the two cases are set separately rather than compromised
             on one number. --}}
        {{-- 56px on a phone, where the bar is pinned and every pixel of it is
             pixels the page does not get; back to 64 from md. --}}
        <div class="grid grid-cols-[auto_auto_1fr] items-center h-[56px] gap-2 md:h-[64px] md:gap-4 relative z-10">

            {{-- Menu, phone only. Opens the sheet above; the logo keeps its own
                 grid column, so adding this cannot shift it. --}}
            <button
                type="button"
                @click="$dispatch('open-mobile-menu')"
                aria-label="{{ __('common.menu') }}"
                class="col-start-1 -ml-1 flex h-11 w-11 shrink-0 items-center justify-center rounded-[10px] text-zinc-900 transition-colors hover:bg-[#f4f6f5] focus:outline-none focus-visible:ring-2 focus-visible:ring-blue-500/40 md:hidden dark:text-zinc-200 dark:hover:bg-white/10"
            >
                <svg class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true">
                    <path d="M4 7h16M4 12h16M4 17h16" />
                </svg>
            </button>


            {{-- Logo. -ml-1 cancels the wordmark's own transparent left margin
                 so the mark lands ON the gutter, level with the category pill
                 below it and with the cart chip on the far side. The footer's
                 logo carries the same pull for the same reason.

                 The measurement, so nobody has to re-derive it: the artwork
                 carries 12px of transparency down the left of a 419px canvas,
                 which is about 4px in this band. -ml-3 (12px) overshot it by
                 ~8px, which is the whole reason the left and right gutters did
                 not look equal.

                 On a phone it is doing a second job: the gap to the menu button
                 is measured from the ink, not from the file's empty edge, so
                 without this the logo reads further from the button than the
                 gap actually is. --}}
            <a
                href="{{ route('home') }}"
                wire:navigate
                class="col-start-2 md:col-start-1 justify-self-start -ml-1 relative flex items-center rounded-[12px] group focus:outline-none focus-visible:ring-2 focus-visible:ring-blue-500/40"
                aria-label="{{ $siteName }} - Home"
            >
                {{-- The wordmark from md up, the R on its own below it. A phone
                     gives this the same row as the menu button and the cart, and
                     the full wordmark spent that width on type nobody reads at
                     that size.

                     <picture> rather than two <img> tags with hidden/md:block:
                     both tags would download, and this is a fetchpriority=high
                     image in the header. The source's media query means only the
                     matching file is fetched. --}}
                <span class="flex items-center h-11 md:h-12">
                    <picture class="flex h-full items-center">
                        <source media="(min-width: 768px)" srcset="{{ asset('assets/logo.webp').'?v=4' }}">
                        <img
                            src="{{ asset('assets/PWAicon.webp') }}"
                            alt="{{ $siteName }}"
                            fetchpriority="high"
                            class="site-logo h-9 w-auto object-contain transition-opacity duration-200 group-hover:opacity-90 md:h-full"
                        />
                    </picture>
                </span>
            </a>

            {{-- Live search (desktop). Hits /api/search/brands?q=… on every keystroke (debounced)
                 and renders a dropdown of matching brands. Pressing Enter navigates to
                 /gift-cards?q= for the full results page. --}}
            <div
                x-data="navBrandSearch()"
                @click.outside="open = false"
                {{-- Freeze the page while the panel is open. rshopScrollLock is
                     reference-counted, so the three search instances (and any modal
                     above them) can hold it at once without fighting. --}}
                x-effect="open ? window.rshopScrollLock?.lock() : window.rshopScrollLock?.unlock()"
                @keydown.escape.window="open = false"
                @keydown.window.ctrl.k.prevent="$refs.search.focus()"
                @keydown.window.meta.k.prevent="$refs.search.focus()"
                class="col-start-2 justify-self-start hidden md:block w-[480px] max-w-full relative"
            >
                <form
                    role="search"
                    method="GET"
                    action="{{ route('shop.gift-cards') }}"
                    @click="$refs.search.focus()"
                    :class="open ? 'border-blue-500 ring-2 ring-blue-500/15' : 'border-zinc-400 hover:border-zinc-500'"
                    class="group flex items-center gap-3 cursor-text rounded-[15px] border-2 bg-[#eff6ff] px-4 py-2 transition-all duration-200"
                >
                    <button type="submit" class="shrink-0 text-zinc-900 transition-colors hover:text-blue-600 focus:outline-none dark:text-zinc-200 dark:hover:text-blue-400" aria-label="Search">
                        <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" />
                        </svg>
                    </button>
                    <input
                        x-ref="search"
                        x-model="query"
                        @input="onInput()"
                        @focus="onFocus()"
                        name="q"
                        type="text"
                        placeholder="{{ __('navigation.search_placeholder') }}"
                        aria-label="{{ __('navigation.search_placeholder') }}"
                        autocomplete="off"
                        spellcheck="false"
                        class="flex-1 min-w-0 bg-transparent text-base text-zinc-800 placeholder:text-zinc-600 outline-none dark:text-white dark:placeholder:text-zinc-400"
                    />
                    {{-- Ctrl+K hint — shown while the field is empty; the clear button takes its place once typing starts. --}}
                    <kbd x-show="query.length === 0" class="pointer-events-none inline-flex shrink-0 items-center rounded-[5px] border border-zinc-300 bg-zinc-100 px-1.5 py-0.5 text-[11px] font-semibold tracking-wide text-zinc-500" aria-hidden="true">Ctrl K</kbd>
                    <button type="button" x-show="query.length > 0" @click="clear()" class="flex h-6 w-6 shrink-0 items-center justify-center rounded-[12px] bg-zinc-200 transition-colors hover:bg-zinc-300 focus:outline-none" aria-label="Clear">
                        <img src="{{ asset('assets/' . rawurlencode('x button.webp')) }}" alt="" class="w-4 h-4 object-contain" loading="lazy">
                    </button>
                </form>

                {{-- Results dropdown --}}
                <div
                    x-show="open"
                    x-transition:enter="transition ease-out duration-150"
                    x-transition:enter-start="opacity-0 -translate-y-1"
                    x-transition:enter-end="opacity-100 translate-y-0"
                    style="display:none;"
                    class="absolute left-0 right-0 top-full z-30 mt-2 overflow-hidden rounded-[10px] border border-zinc-200 bg-white shadow-2xl shadow-zinc-900/15"
                >
                    <x-nav.search-panel />
                </div>
            </div>

            {{-- Right actions: the mobile-only search trigger, then the utility
                 cluster (Help, region/currency, account, cart, theme). Those
                 share this line with the logo rather than sitting in a bar of
                 their own above it. --}}
            <div class="col-start-3 justify-self-end flex items-center gap-1">

                {{-- md and up: the full utility cluster on the logo line. --}}
                <div class="hidden items-center gap-1 md:flex">
                    <x-nav.utility-links :suggestion="true" />
                </div>

                {{-- Phones get the region pill only. Help and the theme picker
                     live in the menu sheet; this one stays out because it
                     answers "which country am I buying for" at a glance. --}}
                <div class="flex items-center md:hidden">
                    <x-nav.region-pill :compact="true" />
                </div>

                <x-nav.account-cart />

                {{-- Theme picker last, past Sign in and Cart, so it closes the
                     logo row on the right.

                     md and up ONLY. The utility row above the logo carries its
                     own copy below md, and this one had no breakpoint guard - so
                     on a phone both rendered and the picker appeared twice. --}}
                <span class="hidden md:inline-flex">
                    <x-theme-toggle class="h-9 w-9 shrink-0 rounded-full text-zinc-900 hover:bg-[#f4f6f5] focus:outline-none focus-visible:ring-2 focus-visible:ring-blue-500/40 dark:text-zinc-200 dark:hover:bg-white/10 dark:hover:text-white" />
                </span>

            </div>

        </div>

        {{-- Search, phones only: its own row, edge to edge. Same
             navBrandSearch() factory as the desktop field, so debouncing, the
             endpoint and result rendering stay in one place. --}}
        <div
            x-data="navBrandSearch()"
            @click.outside="open = false"
            x-effect="open ? window.rshopScrollLock?.lock() : window.rshopScrollLock?.unlock()"
            @keydown.escape="open = false"
            {{-- Collapses on scroll with the category strip: searching is an
                 arrival action, so it gives its space back once the shopper is
                 reading the page. Desktop never collapses (see init). --}}
            {{-- The bottom padding is bound, not static: max-height collapses the
                 CONTENT, but padding on the collapsing element itself keeps
                 rendering, so a static pb-3 left 12px of black hanging under
                 the pinned bar with nothing in it. --}}
            :class="hidden ? 'max-h-0 pb-0 opacity-0 overflow-hidden' : 'max-h-[120px] pb-3 opacity-100 overflow-visible'"
            class="relative [transition:max-height_200ms_cubic-bezier(0.4,0,0.2,1),opacity_120ms_ease-out,padding_200ms_cubic-bezier(0.4,0,0.2,1)] will-change-[max-height] md:hidden"
        >
            <form
                role="search"
                method="GET"
                action="{{ route('shop.gift-cards') }}"
                @click="$refs.mobileSearch.focus()"
                {{-- Reference shape: a full pill on a hairline, with the action as
                     a filled round button inside its right edge. The focus state
                     brightens the same hairline rather than swapping in a second,
                     thicker border, so the pill never changes size when tapped. --}}
                :class="open ? 'border-zinc-300 bg-[#ffffff] dark:border-white/25 dark:bg-[#0c1a36]' : 'border-zinc-200 bg-[#f4f6f5] dark:border-white/10 dark:bg-white/8'"
                class="flex cursor-text items-center gap-2 rounded-full border py-1 pl-4 pr-1 transition-colors duration-200"
            >
                <input
                    x-ref="mobileSearch"
                    x-model="query"
                    @input="onInput()"
                    @focus="onFocus()"
                    name="q"
                    type="text"
                    placeholder="{{ __('navigation.search_placeholder') }}"
                    aria-label="{{ __('navigation.search_placeholder') }}"
                    autocomplete="off"
                    spellcheck="false"
                    class="min-w-0 flex-1 bg-transparent text-[13px] text-zinc-900 outline-none placeholder:text-zinc-600 dark:text-white dark:placeholder:text-zinc-400"
                />

                {{-- Clear takes the magnifier's place once there is something to
                     clear; the form still submits on Enter. --}}
                <button
                    type="button"
                    x-show="query.length > 0"
                    x-cloak
                    @click.stop="clear(); $refs.mobileSearch.focus()"
                    class="flex h-7 w-7 shrink-0 items-center justify-center rounded-full bg-zinc-200 transition-colors hover:bg-zinc-300 focus:outline-none dark:bg-white/15"
                    aria-label="{{ __('common.clear') }}"
                >
                    <svg class="h-3.5 w-3.5 text-zinc-700 dark:text-zinc-200" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
                    </svg>
                </button>

                <button
                    type="submit"
                    x-show="query.length === 0"
                    class="flex h-7 w-7 shrink-0 items-center justify-center rounded-full bg-[#0c1337] text-white transition-colors hover:bg-[#1a2560] focus:outline-none dark:bg-[#fdfdfd] dark:text-[#0c1337] dark:hover:bg-[#e4e5f3]"
                    aria-label="{{ __('navigation.search_placeholder') }}"
                >
                    <svg class="h-[15px] w-[15px]" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" />
                    </svg>
                </button>
            </form>

            <div
                x-show="open"
                x-cloak
                x-transition:enter="transition ease-out duration-150"
                x-transition:enter-start="opacity-0 -translate-y-1"
                x-transition:enter-end="opacity-100 translate-y-0"
                class="absolute inset-x-0 top-full z-[60] max-h-[70vh] overflow-y-auto rounded-[10px] border border-zinc-200 bg-white shadow-2xl shadow-zinc-900/20 dark:border-white/10 dark:bg-[#0c1a36]"
            >
                <x-nav.search-panel list-class="" />
            </div>
        </div>
    </div>

    </div>{{-- /collapsible primary row wrapper --}}

    {{-- Category shortcut bar - left-aligned under the logo/search, horizontal
         scroll on mobile, with "Invite a Friend" pinned to the right of the
         same line. Pills, not underlined tabs. No outline on any of them - the
         shape is carried by fill alone: nothing at rest, a light wash on hover,
         and a solid fill on the open category.

         The rail itself carries no fill: the white bar shows through it, and
         the pills are what wear #dee3e1.

         Two nested rows: the outer one owns the vertical padding and holds the
         invite pill on the right, the inner one is the scroll track. The pill
         has to sit OUTSIDE that track, or it scrolls away with the categories
         on a narrow screen. --}}
    <div
        {{-- Same collapse as the search row above. --}}
        :class="hidden ? 'max-h-0 opacity-0 overflow-hidden' : 'max-h-[120px] opacity-100 overflow-visible'"
        class="[transition:max-height_200ms_cubic-bezier(0.4,0,0.2,1),opacity_120ms_ease-out] will-change-[max-height]"
    >
        <nav aria-label="Product categories" class="max-w-[1550px] mx-auto px-4 sm:px-6 lg:px-8">
            <div class="flex items-center gap-3 py-2.5">
            <div class="flex min-w-0 flex-1 gap-5 md:gap-2 overflow-x-auto justify-start [scrollbar-width:none] [&::-webkit-scrollbar]:hidden">
                @php
                    // No pill AT REST, and none at all on a phone: the reference
                    // reads as plain links, and a row of chips on a 390px screen
                    // leaves room for two. The wash only appears under a pointer.
                    //
                    // px-0 below md is what keeps the phone rail the width it is;
                    // the hover wash therefore only has room to read as a pill
                    // from md up, which is also the only place a pointer exists.
                    // Sized to x-nav.invite-link, the pill at the other end of this same row:
                    // px-3.5 py-1.5 on a 13px label. Two pills on one line reading at two
                    // different heights is what made the row look unfinished.
                    $catLinkClass = "inline-flex shrink-0 items-center rounded-full px-0 py-1.5 text-[13px] leading-none transition-colors duration-150 whitespace-nowrap focus:outline-none focus-visible:ring-2 focus-visible:ring-blue-500/30 md:px-4 md:py-2 md:text-[16px] md:leading-4";

                    // The active category is the OPEN page, resolved from the route —
                    // so the highlight is never statically stuck on Gift Cards.
                    $currentCat = match (true) {
                        request()->routeIs('shop.gift-cards', 'shop.brand') => 'Gift Cards',
                        request()->routeIs('shop.gomarket')                 => 'GoMarket',
                        request()->routeIs('shop.esims', 'shop.esim')       => 'eSIMs',
                        request()->routeIs('shop.topups', 'shop.topup')     => 'Mobile top up',
                        request()->routeIs('shop.bills', 'shop.bill')       => 'Bill payments',
                        default                                             => '',
                    };
                    // aria-current does double duty: it names the open page for a
                    // screen reader, and it is what the dark-mode CSS exemption in
                    // app.css keys off - the bar's blanket !important overrides would
                    // otherwise paint this pill's label and icon white on white.
                    $catLinkCurrent = fn (string $c) => $currentCat === $c ? 'page' : false;

                    // The hover wash is x-nav.invite-link's, to the shade: that pill
                    // sits on the same line, so anything else would read as two
                    // kinds of control. Active still underlines - it names the open
                    // page, and a hover state cannot carry that too.
                    // Active reads as a filled pill, not an underline: the row is
                    // already a row of pills on hover, so the open page should wear
                    // the same shape rather than a different kind of marker.
                    // px-4 unconditionally, since the base class only pads from md
                    // and a fill with no padding hugs the text.
                    $catLinkState = fn (string $c) => $currentCat === $c
                        ? 'bg-[#f4f6f5] px-3.5 font-bold text-zinc-900 md:px-4 dark:bg-white/12 dark:text-white'
                        : 'text-zinc-900 hover:bg-[#f4f6f5] dark:text-zinc-200 dark:hover:bg-white/8 dark:hover:text-white';

                    // When a category feature is OFF we keep the tab VISIBLE but greyed
                    // and non-clickable (it used to vanish). The greyed state signals
                    // "temporarily unavailable"; the purchase itself is blocked server-side.
                    $catOff = 'cursor-not-allowed text-zinc-400 dark:text-zinc-600';
                @endphp

                {{-- Gift Cards. Wrapped in the mega menu, which opens the
                     whole catalogue's navigation on hover or focus. The link
                     itself still navigates - the panel is an addition, not a
                     replacement, so a click behaves as it always did. --}}
                @if (\App\Support\FeatureFlag::on('gift_cards'))
                <x-nav.gift-cards-menu>
                    <x-slot:trigger>
                        <a href="{{ route('shop.gift-cards') }}" wire:navigate class="{{ $catLinkClass }} {{ $catLinkState('Gift Cards') }}" @if ($catLinkCurrent('Gift Cards')) aria-current="page" @endif>
                            {{ __('navigation.gift_cards') }}
                        </a>
                    </x-slot:trigger>
                </x-nav.gift-cards-menu>
                @else
                <span class="{{ $catLinkClass }} {{ $catOff }}" aria-disabled="true" title="Temporarily unavailable">
                    {{ __('navigation.gift_cards') }}
                </span>
                @endif

                {{-- GoMarket (coming soon) - hidden until its feature flag is switched on --}}
                @if (\App\Support\FeatureFlag::on('gomarket', false))
                <a href="{{ route('shop.gomarket') }}" wire:navigate class="{{ $catLinkClass }} {{ $catLinkState('GoMarket') }}" @if ($catLinkCurrent('GoMarket')) aria-current="page" @endif>
                    GoMarket
                </a>
                @endif

                {{-- eSIMs --}}
                @if (\App\Support\FeatureFlag::on('esims'))
                <a href="{{ route('shop.esims') }}" wire:navigate class="{{ $catLinkClass }} {{ $catLinkState('eSIMs') }}" @if ($catLinkCurrent('eSIMs')) aria-current="page" @endif>
                    {{ __('navigation.esims') }}
                </a>
                @else
                <span class="{{ $catLinkClass }} {{ $catOff }}" aria-disabled="true" title="Temporarily unavailable">
                    {{ __('navigation.esims') }}
                </span>
                @endif

                {{-- Mobile top up --}}
                @if (\App\Support\FeatureFlag::on('topups'))
                <a href="{{ route('shop.topups') }}" wire:navigate class="{{ $catLinkClass }} {{ $catLinkState('Mobile top up') }}" @if ($catLinkCurrent('Mobile top up')) aria-current="page" @endif>
                    {{ __('navigation.mobile_refill') }}
                </a>
                @else
                <span class="{{ $catLinkClass }} {{ $catOff }}" aria-disabled="true" title="Temporarily unavailable">
                    {{ __('navigation.mobile_refill') }}
                </span>
                @endif

                {{-- Bill payments --}}
                @if (\App\Support\FeatureFlag::on('bills'))
                <a href="{{ route('shop.bills') }}" wire:navigate class="{{ $catLinkClass }} {{ $catLinkState('Bill payments') }}" @if ($catLinkCurrent('Bill payments')) aria-current="page" @endif>
                    {{ __('navigation.bill_payments') }}
                </a>
                @else
                <span class="{{ $catLinkClass }} {{ $catOff }}" aria-disabled="true" title="Temporarily unavailable">
                    {{ __('navigation.bill_payments') }}
                </span>
                @endif

                {{-- Flights and Stays. Unlike the categories above, these are
                     removed rather than greyed when switched off: there is no
                     catalogue behind either, so a disabled pill would advertise
                     a service that does not exist. Their routes 404 in the same
                     state, so the link and the page vanish together. --}}
                @if (\App\Support\FeatureFlag::categoryVisible('flights'))
                    <a href="{{ route('shop.flights') }}" wire:navigate class="{{ $catLinkClass }} {{ $catLinkState('Flights') }}" @if ($catLinkCurrent('Flights')) aria-current="page" @endif>
                        {{ __('navigation.flights') }}
                    </a>
                @endif

                @if (\App\Support\FeatureFlag::categoryVisible('stays'))
                    <a href="{{ route('shop.stays') }}" wire:navigate class="{{ $catLinkClass }} {{ $catLinkState('Stays') }}" @if ($catLinkCurrent('Stays')) aria-current="page" @endif>
                        {{ __('navigation.stays') }}
                    </a>
                @endif

            </div>

            {{-- Invite a Friend, on the category line from md up. Below md it
                 does not render at all - the phone nav is already three rows
                 deep before the page starts.

                 From md the pill drops the referral panel instead of navigating,
                 the same panel the dashboard top bar uses. The pill sits at the
                 right end of this row, so the panel is right-anchored or it
                 would hang off the viewport. --}}
            <div
                class="relative hidden shrink-0 md:flex"
                x-data="{ referOpen: false }"
                @click.outside="referOpen = false"
                @keydown.escape.window="referOpen = false"
            >
                <x-nav.invite-link
                    :navigate="false"
                    x-on:click.prevent="referOpen = ! referOpen"
                    ::aria-expanded="referOpen ? 'true' : 'false'"
                    aria-haspopup="dialog"
                />

                <div
                    x-show="referOpen"
                    x-cloak
                    x-transition:enter="transition ease-out duration-200"
                    x-transition:enter-start="opacity-0 -translate-y-1"
                    x-transition:enter-end="opacity-100 translate-y-0"
                    x-transition:leave="transition ease-in duration-150"
                    x-transition:leave-start="opacity-100 translate-y-0"
                    x-transition:leave-end="opacity-0 -translate-y-1"
                    class="absolute right-0 top-full z-50 mt-3"
                >
                    <x-nav.referral-panel
                        role="dialog"
                        aria-label="Refer friends, earn Rcoin"
                        close="referOpen = false"
                    />
                </div>
            </div>
            </div>
        </nav>
    </div>

</div>

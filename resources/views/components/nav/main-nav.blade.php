{{--
    Primary navigation row - logo · search · account/cart - plus a category
    shortcut bar. On page scroll the primary row collapses (slides up + fades)
    so only the top bar + category strip remain pinned with the parent sticky
    header. The row is a 3-column grid so the search bar is page-centred,
    aligned with the centred category bar below.
--}}
<div
    x-data="{
        hidden: false,
        ticking: false,
        init() {
            // Desktop (lg+) keeps the full nav at all times - no collapse on
            // scroll. Only mobile / tablet collapses the primary row to free
            // vertical space. Position-based hysteresis (50-140px) prevents
            // the bar from flickering near the threshold on touch scrolls.
            const SHOW_AT = 50;
            const HIDE_AT = 140;
            const LG = 1024;
            const onScroll = () => {
                if (window.innerWidth >= LG) {
                    if (this.hidden) { this.hidden = false; }
                    return;
                }
                if (this.ticking) { return; }
                this.ticking = true;
                requestAnimationFrame(() => {
                    const y = window.scrollY;
                    if (y <= SHOW_AT)      { this.hidden = false; }
                    else if (y >= HIDE_AT) { this.hidden = true; }
                    this.ticking = false;
                });
            };
            window.addEventListener('scroll', onScroll, { passive: true });
            window.addEventListener('resize', onScroll, { passive: true });
            onScroll();
        },
    }"
    class="bg-white/30 backdrop-blur-sm dark:bg-[#0c1a36]/30"
>

    {{-- Primary nav row - collapses on scroll. Uses a wide hysteresis zone so
         a half-scroll never lands in a position where the bar flickers between
         collapsed and expanded. Transforms + max-height on a will-change layer
         so the GPU handles the animation cleanly. --}}
    <div
        :class="hidden ? 'max-h-0 opacity-0 overflow-hidden' : 'max-h-[140px] opacity-100 overflow-visible'"
        class="[transition:max-height_350ms_cubic-bezier(0.4,0,0.2,1),opacity_200ms_ease-out] will-change-[max-height]"
    >
    <div class="max-w-[1350px] mx-auto px-4 sm:px-6 lg:px-8">
        <div class="grid grid-cols-[1fr_auto_1fr] items-center h-[64px] gap-4 relative z-10">

            {{-- Logo (the source PNG is square with whitespace padding, so the
                 image is scaled up and clipped to its wordmark band) --}}
            <a
                href="{{ route('home') }}"
                wire:navigate
                class="col-start-1 justify-self-start -ml-3 relative flex items-center rounded-[12px] group focus:outline-none focus-visible:ring-2 focus-visible:ring-blue-500/40"
                aria-label="{{ $siteName }} - Home"
            >
                <span class="flex items-center h-10 md:h-12">
                    <img
                        src="{{ asset('assets/Rshoprefillslogo.webp') }}"
                        alt="{{ $siteName }}"
                        fetchpriority="high"
                        class="h-full w-auto object-contain transition-opacity duration-200 group-hover:opacity-90"
                    />
                </span>
                <span class="absolute left-1 top-full -mt-0.5 text-[10px] font-medium italic leading-none text-zinc-600" aria-hidden="true">Est. 2024</span>
            </a>

            {{-- Live search (desktop). Hits /api/search/brands?q=… on every keystroke (debounced)
                 and renders a dropdown of matching brands. Pressing Enter navigates to
                 /gift-cards?q= for the full results page. --}}
            <div
                x-data="navBrandSearch()"
                @click.outside="open = false"
                @keydown.escape.window="open = false"
                @keydown.window.ctrl.k.prevent="$refs.search.focus()"
                @keydown.window.meta.k.prevent="$refs.search.focus()"
                class="col-start-2 hidden md:block w-[480px] max-w-full relative"
            >
                <form
                    role="search"
                    method="GET"
                    action="{{ route('shop.gift-cards') }}"
                    @click="$refs.search.focus()"
                    :class="open ? 'border-blue-500 ring-2 ring-blue-500/15' : 'border-zinc-400 hover:border-zinc-500'"
                    class="group flex items-center gap-3 cursor-text rounded-[15px] border-2 bg-[#eff6ff] px-4 py-2 transition-all duration-200"
                >
                    <button type="submit" class="shrink-0 text-zinc-900 transition-colors hover:text-blue-600 focus:outline-none" aria-label="Search">
                        <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" />
                        </svg>
                    </button>
                    <input
                        x-ref="search"
                        x-model="query"
                        @input="onInput()"
                        @focus="if (query.length >= 2) open = true"
                        name="q"
                        type="text"
                        placeholder="Search brands, categories, countries"
                        aria-label="Search brands, categories, countries"
                        autocomplete="off"
                        spellcheck="false"
                        class="flex-1 min-w-0 bg-transparent text-base text-zinc-800 placeholder:text-zinc-600 outline-none"
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
                    class="absolute left-0 right-0 top-full z-30 mt-2 overflow-hidden rounded-[12px] border border-zinc-200 bg-white/80 shadow-2xl shadow-zinc-900/15 backdrop-blur-xl"
                >
                    {{-- Loading state --}}
                    <div x-show="loading && results.length === 0" class="px-5 py-6 text-center text-sm text-zinc-600">
                        <svg class="mx-auto h-5 w-5 animate-spin text-zinc-400" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z"/></svg>
                        <p class="mt-2">Searching…</p>
                    </div>

                    {{-- Results list --}}
                    <ul x-show="results.length > 0" class="max-h-[60vh] divide-y divide-zinc-100/80 overflow-y-auto [scrollbar-width:none] [&::-webkit-scrollbar]:hidden">
                        <template x-for="r in results" :key="r.url">
                            <li>
                                <a
                                    :href="r.url"
                                    wire:navigate
                                    @click="open = false"
                                    class="flex items-center gap-3 px-4 py-2.5 transition-colors hover:bg-zinc-100"
                                >
                                    <template x-if="r.logo">
                                        <span class="flex aspect-[16/10] w-20 shrink-0 items-center justify-center overflow-hidden rounded-[5px] bg-white shadow-sm ring-1 ring-zinc-200">
                                            <img :src="r.logo" :alt="r.name" class="h-full w-full object-cover">
                                        </span>
                                    </template>
                                    <template x-if="!r.logo">
                                        <span class="flex aspect-[16/10] w-20 shrink-0 items-center justify-center rounded-[5px] bg-white text-sm font-black uppercase text-zinc-700 shadow-sm ring-1 ring-zinc-200" x-text="r.name.substring(0, 2).toUpperCase()"></span>
                                    </template>
                                    <span class="flex min-w-0 flex-1 flex-col">
                                        <span class="truncate text-sm font-semibold text-zinc-900" x-text="r.name"></span>
                                        <span class="text-[11px] text-zinc-500" x-text="r.type"></span>
                                    </span>
                                </a>
                            </li>
                        </template>
                    </ul>

                    {{-- No results --}}
                    <div x-show="!loading && results.length === 0 && query.length >= 2" class="px-5 py-6 text-center text-sm text-zinc-600">
                        No brands match "<span class="font-semibold text-zinc-900" x-text="query"></span>"
                    </div>

                    {{-- Show all results footer --}}
                    <a
                        x-show="results.length > 0"
                        :href="'{{ route('shop.gift-cards') }}?q=' + encodeURIComponent(query)"
                        wire:navigate
                        @click="open = false"
                        class="block border-t border-zinc-100 px-5 py-3 text-center text-sm font-semibold text-blue-600 transition-colors hover:bg-zinc-100/70 hover:text-blue-700"
                    >
                        Show all results
                    </a>
                </div>
            </div>

            {{-- Right actions --}}
            <div class="col-start-3 justify-self-end flex items-center gap-2">

                {{-- Mobile search — opens a full-width search sheet that drops down
                     from under the nav. Uses the same navBrandSearch factory as the
                     desktop search so debouncing, /api/search/brands endpoint, and
                     result rendering all stay in one place. --}}
                <div
                    x-data="{ mobileSearchOpen: false }"
                    @keydown.escape.window="mobileSearchOpen = false"
                    class="md:hidden"
                >
                    <button
                        type="button"
                        @click="mobileSearchOpen = !mobileSearchOpen; if (mobileSearchOpen) $nextTick(() => $refs.mobileSearchInput?.focus())"
                        :aria-expanded="mobileSearchOpen.toString()"
                        class="flex h-11 w-11 items-center justify-center rounded-[10px] bg-zinc-200 text-zinc-900 dark:text-zinc-200 transition-colors duration-150 hover:bg-blue-600 hover:text-white focus:outline-none focus-visible:ring-2 focus-visible:ring-blue-500/50"
                        aria-label="Search"
                    >
                        <svg x-show="! mobileSearchOpen" class="h-[22px] w-[22px]" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" />
                        </svg>
                        <svg x-show="mobileSearchOpen" x-cloak class="h-[22px] w-[22px]" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/>
                        </svg>
                    </button>

                    {{-- Drop-down sheet — fixed under the nav so it overlays any scroll
                         position. Backdrop captures outside taps to close. --}}
                    <div
                        x-show="mobileSearchOpen"
                        x-cloak
                        x-transition:enter="transition-opacity duration-150"
                        x-transition:enter-start="opacity-0"
                        x-transition:enter-end="opacity-100"
                        @click="mobileSearchOpen = false"
                        class="fixed inset-0 z-[55] bg-zinc-900/40"
                        aria-hidden="true"
                    ></div>

                    <div
                        x-show="mobileSearchOpen"
                        x-cloak
                        x-transition:enter="transition ease-out duration-200"
                        x-transition:enter-start="opacity-0 -translate-y-2"
                        x-transition:enter-end="opacity-100 translate-y-0"
                        x-transition:leave="transition ease-in duration-150"
                        x-transition:leave-start="opacity-100 translate-y-0"
                        x-transition:leave-end="opacity-0 -translate-y-2"
                        x-data="navBrandSearch()"
                        @keydown.escape="mobileSearchOpen = false"
                        class="fixed inset-x-0 top-0 z-[60] bg-white px-4 pb-4 pt-[max(0.75rem,env(safe-area-inset-top))] shadow-2xl shadow-zinc-900/20 dark:bg-[#0c1a36]"
                    >
                        <form
                            role="search"
                            method="GET"
                            action="{{ route('shop.gift-cards') }}"
                            class="flex items-center gap-3 rounded-full border-2 border-blue-500 bg-[#eff6ff] px-4 py-2 ring-2 ring-blue-500/15 dark:bg-[#0c1a36] dark:border-blue-400"
                        >
                            <svg class="h-5 w-5 shrink-0 text-zinc-600 dark:text-zinc-300" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" />
                            </svg>
                            <input
                                x-ref="mobileSearchInput"
                                x-model="query"
                                @input="onInput()"
                                name="q"
                                type="text"
                                placeholder="Search brands, categories, countries"
                                aria-label="Search"
                                autocomplete="off"
                                spellcheck="false"
                                class="flex-1 min-w-0 bg-transparent text-base text-zinc-800 placeholder:text-zinc-500 outline-none dark:text-white dark:placeholder:text-zinc-400"
                            />
                            <button
                                type="button"
                                @click="mobileSearchOpen = false"
                                class="shrink-0 text-[12px] font-semibold text-blue-600 hover:text-blue-700 dark:text-blue-300"
                            >Cancel</button>
                        </form>

                        {{-- Results --}}
                        <div class="mt-3 max-h-[70vh] overflow-y-auto rounded-[12px] bg-white shadow-sm ring-1 ring-zinc-100 dark:bg-[#162a4a] dark:ring-white/10" x-show="query.length >= 2">
                            <div x-show="loading && results.length === 0" class="px-5 py-6 text-center text-sm text-zinc-600 dark:text-zinc-400">Searching…</div>
                            <ul x-show="results.length > 0" class="divide-y divide-zinc-100 dark:divide-white/10">
                                <template x-for="r in results" :key="r.url">
                                    <li>
                                        <a
                                            :href="r.url"
                                            wire:navigate
                                            @click="mobileSearchOpen = false"
                                            class="flex items-center gap-3 px-4 py-2.5 transition-colors hover:bg-zinc-100 dark:hover:bg-white/5"
                                        >
                                            <template x-if="r.logo">
                                                <span class="flex aspect-[16/10] w-16 shrink-0 items-center justify-center overflow-hidden rounded-[6px] bg-white ring-1 ring-zinc-200">
                                                    <img :src="r.logo" :alt="r.name" class="h-full w-full object-cover">
                                                </span>
                                            </template>
                                            <template x-if="!r.logo">
                                                <span class="flex aspect-[16/10] w-16 shrink-0 items-center justify-center rounded-[6px] bg-zinc-100 text-xs font-black uppercase text-zinc-700 ring-1 ring-zinc-200" x-text="r.name.substring(0, 2).toUpperCase()"></span>
                                            </template>
                                            <span class="flex min-w-0 flex-1 flex-col">
                                                <span class="truncate text-sm font-semibold text-zinc-900 dark:text-white" x-text="r.name"></span>
                                                <span class="text-[11px] text-zinc-500 dark:text-zinc-400" x-text="r.type"></span>
                                            </span>
                                        </a>
                                    </li>
                                </template>
                            </ul>
                            <div x-show="!loading && results.length === 0 && query.length >= 2" class="px-5 py-6 text-center text-sm text-zinc-600 dark:text-zinc-400">
                                No brands match "<span class="font-semibold text-zinc-900 dark:text-white" x-text="query"></span>"
                            </div>
                            <a
                                x-show="results.length > 0"
                                :href="'{{ route('shop.gift-cards') }}?q=' + encodeURIComponent(query)"
                                wire:navigate
                                @click="mobileSearchOpen = false"
                                class="block border-t border-zinc-100 px-5 py-3 text-center text-sm font-semibold text-blue-600 transition-colors hover:bg-zinc-50 dark:border-white/10 dark:text-blue-300 dark:hover:bg-white/5"
                            >
                                Show all results
                            </a>
                        </div>
                    </div>
                </div>

                {{-- Wallet — authed users see their balance, guests see "Wallet" and get prompted to log in.
                     A user can hold one wallet per currency; navWalletSummary() converts them all to a
                     single USD figure, shown compactly ($1.2k / $3.4M above $1,000) via Wallet::compactUsd. --}}
                @php
                    use App\Models\Wallet;

                    $walletSummary = auth()->check() ? auth()->user()->navWalletSummary() : null;
                    $walletAmount = $walletSummary['amount'] ?? 0;
                    $walletDisplay = Wallet::compactUsd((float) $walletAmount);

                    // Real photo if set, otherwise a deterministic initials avatar.
                    $authUser = auth()->user();
                    $accountAvatar = $authUser?->avatar_url ?: ($authUser?->initialsAvatar() ?? '');
                @endphp
                @auth
                    <a
                        href="{{ route('dashboard') }}"
                        wire:navigate
                        class="group hidden md:inline-flex h-10 items-center gap-2 rounded-full bg-zinc-200 px-4 text-sm font-semibold text-zinc-900 dark:text-zinc-200 transition-colors duration-150 hover:bg-blue-600 hover:text-white focus:outline-none focus-visible:ring-2 focus-visible:ring-blue-500/50"
                        aria-label="{{ $walletSummary['combined'] ? 'Total wallet balance in USD' : 'Wallet balance in USD' }}"
                        title="${{ number_format((float) $walletAmount, 2) }}"
                    >
                        <img src="{{ asset('assets/' . rawurlencode('transactions.svg')) }}" alt="" class="h-4 w-4 shrink-0 transition group-hover:brightness-0 group-hover:invert" loading="lazy">
                        {{ $walletDisplay }}
                    </a>
                @else
                    <button
                        type="button"
                        @click="$dispatch('open-auth-modal', { mode: 'login' })"
                        class="group hidden md:inline-flex h-10 items-center gap-2 rounded-full bg-zinc-200 px-4 text-sm font-semibold text-zinc-900 dark:text-zinc-200 transition-colors duration-150 hover:bg-blue-600 hover:text-white focus:outline-none focus-visible:ring-2 focus-visible:ring-blue-500/50"
                        aria-label="Wallet - sign in to view balance"
                    >
                        <img src="{{ asset('assets/' . rawurlencode('transactions.svg')) }}" alt="" class="h-4 w-4 shrink-0 transition group-hover:brightness-0 group-hover:invert" loading="lazy">
                        Wallet
                    </button>
                @endauth

                {{-- Account --}}
                @auth
                    {{-- Authenticated: hover/click opens the account dropdown.
                         Hover toggles unless the user clicked the trigger — then it stays open until they click outside. --}}
                    <div
                        x-data="{ open: false, locked: false }"
                        @mouseenter="if (!locked) open = true"
                        @mouseleave="if (!locked) open = false"
                        @click.outside="open = false; locked = false"
                        @keydown.escape.window="open = false; locked = false"
                        class="relative"
                    >
                        <button
                            type="button"
                            @click="locked = !locked; open = locked"
                            :aria-expanded="open.toString()"
                            aria-haspopup="menu"
                            class="flex items-center justify-center w-11 h-11 md:w-10 md:h-10 overflow-hidden rounded-[10px] bg-zinc-200 transition-all duration-150 ring-1 ring-zinc-200 hover:ring-2 hover:ring-blue-400 focus:outline-none focus-visible:ring-2 focus-visible:ring-blue-500/50"
                            aria-label="Your account"
                        >
                            <img src="{{ $accountAvatar }}" alt="{{ $authUser?->name ?? 'Account' }}" class="h-full w-full object-cover" loading="lazy">
                        </button>

                        {{-- KYC verified tick over the storefront account avatar. --}}
                        @if (($authUser?->kyc_status ?? null) === 'verified')
                            <x-ui.verified-badge class="pointer-events-none absolute -bottom-1.5 -right-1.5 h-4 w-4 drop-shadow-sm" />
                        @endif

                        <div
                            x-show="open"
                            x-transition:enter="transition ease-out duration-150"
                            x-transition:enter-start="opacity-0 -translate-y-1"
                            x-transition:enter-end="opacity-100 translate-y-0"
                            x-transition:leave="transition ease-in duration-100"
                            x-transition:leave-start="opacity-100 translate-y-0"
                            x-transition:leave-end="opacity-0 -translate-y-1"
                            style="display:none;"
                            class="absolute right-0 top-full z-50 mt-2 w-60 overflow-hidden rounded-[12px] bg-white/85 backdrop-blur-md shadow-xl shadow-zinc-900/10 ring-1 ring-zinc-200"
                            role="menu"
                        >
                            <div class="p-1.5">
                                <a href="{{ route('dashboard') }}" wire:navigate role="menuitem" class="group flex items-center gap-3 rounded-[12px] px-3 py-2.5 text-sm font-medium text-zinc-800 transition-colors hover:bg-blue-100">
                                    <svg class="h-5 w-5 shrink-0 text-zinc-700" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75" aria-hidden="true">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 6a3.75 3.75 0 11-7.5 0 3.75 3.75 0 017.5 0zM4.501 20.118a7.5 7.5 0 0114.998 0A17.933 17.933 0 0112 21.75c-2.676 0-5.216-.584-7.499-1.632z"/>
                                    </svg>
                                    Account
                                </a>
                                <a href="{{ route('dashboard.orders') }}" wire:navigate role="menuitem" class="group flex items-center gap-3 rounded-[12px] px-3 py-2.5 text-sm font-medium text-zinc-800 transition-colors hover:bg-blue-100">
                                    <svg class="h-5 w-5 shrink-0 text-zinc-700" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75" aria-hidden="true">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/>
                                    </svg>
                                    My orders
                                </a>
                                <a href="{{ route('dashboard.rewards') }}" wire:navigate role="menuitem" class="group flex items-center gap-3 rounded-[12px] px-3 py-2.5 text-sm font-medium text-zinc-800 transition-colors hover:bg-blue-100">
                                    <svg class="h-5 w-5 shrink-0 text-zinc-700" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75" aria-hidden="true">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M21 11.25v8.25a1.5 1.5 0 01-1.5 1.5H5.25a1.5 1.5 0 01-1.5-1.5v-8.25M12 4.875A2.625 2.625 0 109.375 7.5H12m0-2.625V7.5m0-2.625A2.625 2.625 0 1114.625 7.5H12m0 0V21m-8.625-9.75h18c.621 0 1.125-.504 1.125-1.125v-1.5c0-.621-.504-1.125-1.125-1.125h-18c-.621 0-1.125.504-1.125 1.125v1.5c0 .621.504 1.125 1.125 1.125z"/>
                                    </svg>
                                    Redeem
                                </a>
                                <a href="{{ route('dashboard.profile') }}" wire:navigate role="menuitem" class="group flex items-center gap-3 rounded-[12px] px-3 py-2.5 text-sm font-medium text-zinc-800 transition-colors hover:bg-blue-100">
                                    <svg class="h-5 w-5 shrink-0 text-zinc-700" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75" aria-hidden="true">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M9.594 3.94c.09-.542.56-.94 1.11-.94h2.593c.55 0 1.02.398 1.11.94l.213 1.281c.063.374.313.686.645.87.074.04.147.083.22.127.325.196.72.257 1.075.124l1.217-.456a1.125 1.125 0 011.37.49l1.296 2.247a1.125 1.125 0 01-.26 1.431l-1.003.827c-.293.241-.438.613-.43.992a6.759 6.759 0 010 .255c-.008.378.137.75.43.991l1.004.827c.424.35.534.955.26 1.43l-1.298 2.247a1.125 1.125 0 01-1.369.491l-1.217-.456c-.355-.133-.75-.072-1.076.124a6.57 6.57 0 01-.22.128c-.331.183-.581.495-.644.869l-.213 1.28c-.09.543-.56.941-1.11.941h-2.594c-.55 0-1.02-.398-1.11-.94l-.213-1.281c-.062-.374-.312-.686-.644-.87a6.52 6.52 0 01-.22-.127c-.325-.196-.72-.257-1.076-.124l-1.217.456a1.125 1.125 0 01-1.369-.49l-1.297-2.247a1.125 1.125 0 01.26-1.431l1.004-.827c.292-.24.437-.613.43-.991a6.932 6.932 0 010-.255c.007-.38-.138-.751-.43-.992l-1.004-.827a1.125 1.125 0 01-.26-1.43l1.297-2.247a1.125 1.125 0 011.37-.491l1.216.456c.356.133.751.072 1.076-.124.072-.044.146-.087.22-.128.332-.183.582-.495.644-.869l.214-1.281z"/>
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/>
                                    </svg>
                                    Settings
                                </a>
                                <a href="{{ route('dashboard.kyc') }}" wire:navigate role="menuitem" class="group flex items-center gap-3 rounded-[12px] px-3 py-2.5 text-sm font-medium text-zinc-800 transition-colors hover:bg-blue-100">
                                    <svg class="h-5 w-5 shrink-0 text-zinc-700" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75" aria-hidden="true">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75L11.25 15 15 9.75M21 12c0 1.268-.63 2.39-1.593 3.068a3.745 3.745 0 01-1.043 3.296 3.745 3.745 0 01-3.296 1.043A3.745 3.745 0 0112 21c-1.268 0-2.39-.63-3.068-1.593a3.746 3.746 0 01-3.296-1.043 3.745 3.745 0 01-1.043-3.296A3.745 3.745 0 013 12c0-1.268.63-2.39 1.593-3.068a3.745 3.745 0 011.043-3.296 3.746 3.746 0 013.296-1.043A3.746 3.746 0 0112 3c1.268 0 2.39.63 3.068 1.593a3.746 3.746 0 013.296 1.043 3.746 3.746 0 011.043 3.296A3.745 3.745 0 0121 12z"/>
                                    </svg>
                                    Limits
                                </a>
                            </div>

                            <div class="border-t border-zinc-100 p-1.5">
                                <form method="POST" action="{{ route('logout') }}" class="w-full">
                                    @csrf
                                    <button type="submit" class="flex w-full items-center gap-3 rounded-[12px] px-3 py-2.5 text-left text-sm font-medium text-red-600 transition-colors hover:bg-red-50 dark:text-red-400 dark:hover:bg-red-500/15">
                                        <x-ui.logout-icon class="h-5 w-5" />
                                        Logout
                                    </button>
                                </form>
                            </div>
                        </div>
                    </div>
                @else
                    {{-- Guest: hover/click opens a Login + Sign up dropdown.
                         Hover toggles unless the user clicked the trigger — then it stays open until they click outside. --}}
                    <div
                        x-data="{ open: false, locked: false, tip: false, initTip() { if (sessionStorage.getItem('rshopAuthTip')) { return } sessionStorage.setItem('rshopAuthTip', '1'); this.tip = true; setTimeout(() => { this.tip = false }, 10000) } }"
                        x-init="initTip()"
                        @mouseenter="if (!locked && !tip) open = true"
                        @mouseleave="if (!locked) open = false"
                        @click.outside="open = false; locked = false"
                        @keydown.escape.window="open = false; locked = false; tip = false"
                        class="relative"
                    >
                        <button
                            type="button"
                            @click="locked = !locked; open = locked; tip = false"
                            :aria-expanded="open.toString()"
                            aria-haspopup="menu"
                            class="flex items-center justify-center w-11 h-11 md:w-10 md:h-10 rounded-[10px] bg-zinc-200 text-zinc-900 hover:bg-blue-600 hover:text-white/70 dark:text-zinc-200 transition-colors duration-150 focus:outline-none focus-visible:ring-2 focus-visible:ring-blue-500/50"
                            aria-label="Sign in"
                        >
                            {{-- Same profile glyph as the user dashboard (assets/user.svg), inlined so
                                 it inherits the button colour (dark on the chip, white on hover/dark). --}}
                            <svg class="h-5 w-5" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
                                <path fill-rule="evenodd" clip-rule="evenodd" d="M10.264 9.757a4.752 4.752 0 1 0 0 -9.504 4.752 4.752 0 0 0 0 9.504Zm0.19 9.804c0 0.569 0.172 1.097 0.467 1.535H1.484c-0.575 0 -1.036 -0.486 -0.94 -1.053 0.753 -4.424 4.55 -8.18 9.715 -8.18 2.82 0 5.231 1.118 6.963 2.853a2.498 2.498 0 0 0 -0.437 1.412v0.683h-3.582a2.75 2.75 0 0 0 -2.75 2.75Zm8.081 -1.25V16.13a0.75 0.75 0 0 1 1.28 -0.53l3.435 3.433a0.75 0.75 0 0 1 0 1.061l-3.434 3.434a0.75 0.75 0 0 1 -1.28 -0.53v-2.186h-5.333a1.25 1.25 0 0 1 0 -2.5h5.332Z"/>
                            </svg>
                        </button>

                        <div
                            x-show="open"
                            x-transition:enter="transition ease-out duration-150"
                            x-transition:enter-start="opacity-0 -translate-y-1"
                            x-transition:enter-end="opacity-100 translate-y-0"
                            x-transition:leave="transition ease-in duration-100"
                            x-transition:leave-start="opacity-100 translate-y-0"
                            x-transition:leave-end="opacity-0 -translate-y-1"
                            style="display:none;"
                            class="absolute right-0 top-full z-50 mt-2 w-52 overflow-hidden rounded-[12px] bg-white/70 backdrop-blur-md shadow-xl shadow-zinc-900/10 ring-2 ring-blue-400 dark:bg-[#1d3252]/70 dark:ring-blue-500/60"
                            role="menu"
                        >
                            <div class="p-1">
                                <button
                                    type="button"
                                    @click="$dispatch('open-auth-modal', { mode: 'login' })"
                                    role="menuitem"
                                    class="flex w-full items-center gap-3 rounded-[12px] px-3 py-2.5 text-sm font-medium text-zinc-900 transition-colors hover:bg-blue-600 hover:text-white dark:text-zinc-100"
                                >
                                    <img src="{{ asset('assets/' . rawurlencode('Login.svg')) }}" alt="" class="h-5 w-5 shrink-0" loading="lazy">
                                    Login
                                </button>
                                <button
                                    type="button"
                                    @click="$dispatch('open-auth-modal', { mode: 'register' })"
                                    role="menuitem"
                                    class="flex w-full items-center gap-3 rounded-[12px] px-3 py-2.5 text-sm font-medium text-zinc-900 transition-colors hover:bg-blue-600 hover:text-white dark:text-zinc-100"
                                >
                                    <img src="{{ asset('assets/' . rawurlencode('create an account.svg')) }}" alt="" class="h-5 w-5 shrink-0" loading="lazy">
                                    Create Account
                                </button>
                            </div>
                        </div>

                        {{-- First-visit nudge pointing at the account icon (guests only).
                             Shows once per visit (sessionStorage), auto-dismisses after 5s,
                             and closes on the X, Esc, or a click outside. --}}
                        <div
                            x-show="tip"
                            x-cloak
                            x-transition:enter="transition ease-out duration-200"
                            x-transition:enter-start="opacity-0 -translate-y-1"
                            x-transition:enter-end="opacity-100 translate-y-0"
                            x-transition:leave="transition ease-in duration-150"
                            x-transition:leave-end="opacity-0 -translate-y-1"
                            @click.outside="tip = false"
                            style="display:none;"
                            class="absolute right-0 top-full z-[60] mt-2 w-max"
                            role="status"
                        >
                            <span class="absolute -top-1 right-4 h-2.5 w-2.5 rotate-45 rounded-[2px] bg-blue-600"></span>
                            <div class="relative flex items-center gap-2 rounded-[12px] bg-blue-600 px-3 py-2 text-xs font-semibold text-white shadow-lg shadow-blue-600/30">
                                <span class="whitespace-nowrap">Login / Sign up here</span>
                                <button type="button" @click="tip = false" aria-label="Dismiss" class="-mr-1 ml-0.5 inline-flex rounded-full p-0.5 text-white/80 transition-colors hover:bg-white/20 hover:text-white">
                                    <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" aria-hidden="true"><path stroke-linecap="round" d="M6 6l12 12M18 6L6 18"/></svg>
                                </button>
                            </div>
                        </div>
                    </div>
                @endauth

                {{-- Cart. State lives in the global Alpine cart store ($store.cart): the popup
                     drops open automatically when an item is added (store.add sets open = true). --}}
                <div
                    x-data="{ locked: false }"
                    @keydown.escape.window="$store.cart.open = false; locked = false"
                    class="relative"
                >
                    <button
                        type="button"
                        @click="$store.cart.open = ! $store.cart.open; locked = $store.cart.open"
                        :aria-expanded="$store.cart.open.toString()"
                        aria-haspopup="menu"
                        class="relative flex items-center justify-center w-11 h-11 md:w-10 md:h-10 rounded-[10px] bg-zinc-200 text-zinc-900 hover:bg-blue-600 hover:text-white/70 dark:text-zinc-200 transition-colors duration-150 focus:outline-none focus-visible:ring-2 focus-visible:ring-blue-500/50"
                        aria-label="Shopping cart"
                    >
                        {{-- Inline cart glyph (assets/new cart.svg) so it inherits the button
                             colour like the account icon - dark on the chip, white on hover/dark. --}}
                        <svg class="h-[22px] w-[22px] md:h-6 md:w-6" viewBox="0 0 48 48" fill="currentColor" aria-hidden="true">
                            <path fill-rule="evenodd" clip-rule="evenodd" d="M9.46921 1.50002 4.81105 1.5c-1.70012 -0.00001 -3.22552 1.24207 -3.29654 3.01531 -0.00898 0.22443 -0.01424 0.45416 -0.01424 0.68468 0 0.23052 0.00526 0.46026 0.01424 0.68469 0.07102 1.77324 1.59641 3.01531 3.29652 3.01531h3.09742c0.81086 2.85591 1.53526 5.57971 2.24995 8.26701 1.1393 4.2838 2.254 8.4755 3.6552 12.9624 0.637 2.0396 2.2953 3.6044 4.4646 3.8693 2.0845 0.2546 5.2956 0.5013 9.9125 0.5013 4.7453 0 7.8991 -0.2607 9.8721 -0.523 1.9377 -0.2577 3.4449 -1.6102 4.0768 -3.4213 1.4923 -4.2772 2.8177 -9.5127 3.6841 -14.4093 0.4752 -2.6865 -1.6429 -5.0464 -4.3338 -5.0464H16.5108l-1.7915 -5.73892c-0.717 -2.2969 -2.8439 -3.86106 -5.25009 -3.86106ZM35 22.5c0 -1.1046 -0.8954 -2 -2 -2h-3v-3c0 -1.1046 -0.8954 -2 -2 -2s-2 0.8954 -2 2v3h-3c-1.1046 0 -2 0.8954 -2 2s0.8954 2 2 2h3v3c0 1.1046 0.8954 2 2 2s2 -0.8954 2 -2v-3h3c1.1046 0 2 -0.8954 2 -2Z"/>
                            <path d="M42.5 41.5c0 -2.7614 -2.2386 -5 -5 -5s-5 2.2386 -5 5 2.2386 5 5 5 5 -2.2386 5 -5Z"/>
                            <path d="M18.5 36.5c2.7614 0 5 2.2386 5 5s-2.2386 5 -5 5 -5 -2.2386 -5 -5 2.2386 -5 5 -5Z"/>
                        </svg>
                        <span
                            x-show="$store.cart.count > 0"
                            x-text="$store.cart.count"
                            x-cloak
                            class="absolute -top-1 -right-1 inline-flex items-center justify-center min-w-[18px] h-[18px] px-1 rounded-[12px] bg-blue-600 text-[10px] font-bold text-white leading-none"
                        ></span>
                    </button>

                    {{-- Cart popup. Teleported to <body> and fixed top-right so it
                         pops up on its own even when the primary nav row is
                         collapsed on scroll (that row goes opacity-0/overflow-hidden,
                         which would otherwise hide the popup). A transparent
                         full-screen layer behind it closes it on an outside tap. --}}
                    <template x-teleport="body">
                        <div>
                            <div
                                x-show="$store.cart.open"
                                x-transition.opacity.duration.150ms
                                @click="$store.cart.open = false; locked = false"
                                style="display:none;"
                                class="fixed inset-0 z-[55]"
                            ></div>
                            <div
                                x-show="$store.cart.open"
                                x-transition:enter="transition ease-out duration-150"
                                x-transition:enter-start="opacity-0 -translate-y-1"
                                x-transition:enter-end="opacity-100 translate-y-0"
                                x-transition:leave="transition ease-in duration-100"
                                x-transition:leave-start="opacity-100 translate-y-0"
                                x-transition:leave-end="opacity-0 -translate-y-1"
                                style="display:none;"
                                {{-- Mobile: a bottom sheet (inset-x-3 bottom-3) so it pops up in
                                     view even when the shopper added from far down the page and the
                                     primary nav has collapsed off-screen. sm+ restores the exact
                                     desktop top-right card. --}}
                                class="fixed inset-x-3 bottom-3 z-[56] max-w-[calc(100vw-1.5rem)] overflow-hidden rounded-[14px] bg-white/80 px-3 py-2 backdrop-blur-2xl backdrop-saturate-150 shadow-xl shadow-zinc-900/15 ring-1 ring-zinc-200 sm:inset-x-auto sm:bottom-auto sm:right-4 sm:top-[84px] sm:w-[340px] lg:right-[max(2rem,calc((100vw-1350px)/2+2rem))] dark:bg-[#0c1a36]/80 dark:ring-white/10"
                                role="menu"
                            >
                        {{-- Empty state --}}
                        <div x-show="$store.cart.count === 0" class="flex flex-col items-center px-3 py-5 text-center">
                            <h3 class="text-xl font-bold text-zinc-900 dark:text-white">Your cart is empty</h3>
                            <x-illo name="emptyCart" class="mx-auto mt-4 w-full max-w-[230px]" />
                            <p class="mt-3 text-sm text-zinc-600 dark:text-zinc-400">Your cart needs items</p>
                        </div>

                        {{-- Populated state --}}
                        <div x-show="$store.cart.count > 0" x-cloak>
                            <div class="flex items-center justify-between px-3 pt-3">
                                <h3 class="text-lg font-bold text-zinc-900 dark:text-white">Your cart</h3>
                                <span class="text-sm text-zinc-600 dark:text-zinc-400" x-text="$store.cart.count + ' item' + ($store.cart.count === 1 ? '' : 's')"></span>
                            </div>

                            <ul class="mt-3 max-h-72 space-y-1 overflow-y-auto px-0 [scrollbar-width:none] [&::-webkit-scrollbar]:hidden">
                                <template x-for="item in $store.cart.items" :key="item.id">
                                    <li class="flex items-center gap-3 rounded-[12px] px-3 py-2.5">
                                        <span class="flex h-16 w-24 shrink-0 items-center justify-center overflow-hidden rounded-[12px] bg-white shadow-sm ring-1 ring-zinc-200 dark:ring-white/15">
                                            <template x-if="item.logo">
                                                <img :src="item.logo" alt="" class="h-full w-full object-cover">
                                            </template>
                                            <template x-if="!item.logo">
                                                <span class="text-sm font-black uppercase text-zinc-700" x-text="item.name.substring(0,2).toUpperCase()"></span>
                                            </template>
                                        </span>
                                        <span class="min-w-0 flex-1">
                                            <span class="block truncate text-sm font-bold text-zinc-900 dark:text-white" x-text="item.name"></span>
                                            <span class="mt-0.5 block text-xs font-semibold text-zinc-700 dark:text-zinc-300" x-text="$store.cart.pay(item.unit_price)"></span>
                                        </span>
                                        {{-- Quantity counter --}}
                                        <span class="flex shrink-0 items-center gap-1.5">
                                            <button type="button" @click="$store.cart.setQty(item.id, item.quantity - 1)" class="flex h-7 w-7 items-center justify-center rounded-[12px] text-zinc-600 dark:text-zinc-300 ring-1 ring-zinc-200 dark:ring-white/15 transition-colors hover:bg-zinc-100 dark:hover:bg-white/10 hover:text-zinc-900 dark:hover:text-white" aria-label="Decrease">
                                                <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" d="M5 12h14"/></svg>
                                            </button>
                                            <span class="w-5 text-center text-sm font-bold tabular-nums text-zinc-900 dark:text-white" x-text="item.quantity"></span>
                                            <button type="button" @click="$store.cart.setQty(item.id, item.quantity + 1)" class="flex h-7 w-7 items-center justify-center rounded-[12px] text-zinc-600 dark:text-zinc-300 ring-1 ring-zinc-200 dark:ring-white/15 transition-colors hover:bg-zinc-100 dark:hover:bg-white/10 hover:text-zinc-900 dark:hover:text-white" aria-label="Increase">
                                                <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M12 5v14M5 12h14"/></svg>
                                            </button>
                                        </span>
                                    </li>
                                </template>
                            </ul>

                            <div class="mt-3 flex gap-2 rounded-[12px] bg-zinc-50 px-3 py-3 dark:bg-white/5">
                                <a href="{{ route('shop.cart') }}" wire:navigate @click="$store.cart.open = false; locked = false" class="flex-1 inline-flex items-center justify-center rounded-[12px] bg-white px-4 py-3.5 text-sm font-semibold text-zinc-700 ring-1 ring-zinc-200 transition-colors hover:bg-zinc-100 dark:bg-white/10 dark:text-zinc-200 dark:ring-white/15 dark:hover:bg-white/15">
                                    View cart
                                </a>
                                <a :href="'{{ route('shop.checkout') }}' + ($store.cart.showUsd ? '?currency=' + $store.cart.currency : '')" wire:navigate @click="$store.cart.open = false" class="flex-1 inline-flex items-center justify-center rounded-[12px] bg-blue-600 px-4 py-3.5 text-sm font-semibold text-white transition-colors hover:bg-blue-700">
                                    Checkout
                                </a>
                            </div>
                        </div>
                    </div>
                        </div>
                    </template>
                </div>

            </div>

        </div>
    </div>

    </div>{{-- /collapsible primary row wrapper --}}

    {{-- Category shortcut bar - horizontal scroll on mobile, centred on desktop. --}}
    <div>
        <nav aria-label="Product categories" class="max-w-[1350px] mx-auto px-4 sm:px-6 lg:px-8">
            <div class="flex h-[44px] gap-1 overflow-x-auto justify-start md:justify-center [scrollbar-width:none] [&::-webkit-scrollbar]:hidden">
                @php
                    $catLinkClass = "group relative flex h-full shrink-0 items-center gap-2 px-3 text-sm font-medium transition-colors duration-150 whitespace-nowrap focus:outline-none focus-visible:ring-2 focus-visible:ring-blue-500/30 after:content-[''] after:absolute after:bottom-1.5 after:left-3 after:right-3 after:h-0.5 after:rounded-[12px]";
                    $catSvgClass = "w-[25px] h-[25px] shrink-0 text-zinc-900 dark:text-white";

                    // The active category is the OPEN page, resolved from the route —
                    // so the highlight is never statically stuck on Gift Cards.
                    $currentCat = match (true) {
                        request()->routeIs('shop.gift-cards', 'shop.brand') => 'Gift Cards',
                        request()->routeIs('shop.sell-gift-cards')          => 'Sell Gift Cards',
                        request()->routeIs('shop.gomarket')                 => 'GoMarket',
                        request()->routeIs('shop.esims', 'shop.esim')       => 'eSIMs',
                        request()->routeIs('shop.topups', 'shop.topup')     => 'Mobile top up',
                        request()->routeIs('shop.bills', 'shop.bill')       => 'Bill payments',
                        default                                             => '',
                    };
                    $catLinkState = fn (string $c) => $currentCat === $c
                        ? 'text-zinc-900 dark:text-white font-semibold after:bg-zinc-900 dark:after:bg-white'
                        : 'text-zinc-800 dark:text-zinc-300 hover:text-zinc-900 dark:hover:text-white after:bg-transparent';
                    $catIconState = fn (string $c) => $currentCat === $c ? 'opacity-100' : 'opacity-50';

                    // When a category feature is OFF we keep the tab VISIBLE but greyed
                    // and non-clickable (it used to vanish). The greyed state signals
                    // "temporarily unavailable"; the purchase itself is blocked server-side.
                    $catOff = 'cursor-not-allowed text-zinc-400 dark:text-zinc-600 after:bg-transparent';
                @endphp

                @php $catImgClass = 'w-[22px] h-[22px] shrink-0 dark:[filter:brightness(0)_invert(1)]'; @endphp

                {{-- Buy Gift Cards --}}
                @if (\App\Support\FeatureFlag::on('gift_cards'))
                <a href="{{ route('shop.gift-cards') }}" wire:navigate class="{{ $catLinkClass }} {{ $catLinkState('Gift Cards') }}">
                    <img src="{{ asset('assets/' . rawurlencode('gift cards.svg')) }}" alt="" class="{{ $catImgClass }} {{ $catIconState('Gift Cards') }}" loading="lazy">
                    Buy Gift Cards
                </a>
                @else
                <span class="{{ $catLinkClass }} {{ $catOff }}" aria-disabled="true" title="Temporarily unavailable">
                    <img src="{{ asset('assets/' . rawurlencode('gift cards.svg')) }}" alt="" class="{{ $catImgClass }} opacity-40 grayscale" loading="lazy">
                    Buy Gift Cards
                </span>
                @endif

                {{-- Sell Gift Cards (coming soon) --}}
                <a href="{{ route('shop.sell-gift-cards') }}" wire:navigate class="{{ $catLinkClass }} {{ $catLinkState('Sell Gift Cards') }}">
                    <img src="{{ asset('assets/' . rawurlencode('gift cards.svg')) }}" alt="" class="{{ $catImgClass }} {{ $catIconState('Sell Gift Cards') }}" loading="lazy">
                    Sell Gift Cards
                </a>

                {{-- GoMarket (coming soon) --}}
                <a href="{{ route('shop.gomarket') }}" wire:navigate class="{{ $catLinkClass }} {{ $catLinkState('GoMarket') }}">
                    <img src="{{ asset('assets/' . rawurlencode('Shop.svg')) }}" alt="" class="{{ $catImgClass }} {{ $catIconState('GoMarket') }}" loading="lazy">
                    GoMarket
                </a>

                {{-- eSIMs --}}
                @if (\App\Support\FeatureFlag::on('esims'))
                <a href="{{ route('shop.esims') }}" wire:navigate class="{{ $catLinkClass }} {{ $catLinkState('eSIMs') }}">
                    <img src="{{ asset('assets/' . rawurlencode('esim.svg')) }}" alt="" class="{{ $catImgClass }} {{ $catIconState('eSIMs') }}" loading="lazy">
                    eSIMs
                </a>
                @else
                <span class="{{ $catLinkClass }} {{ $catOff }}" aria-disabled="true" title="Temporarily unavailable">
                    <img src="{{ asset('assets/' . rawurlencode('esim.svg')) }}" alt="" class="{{ $catImgClass }} opacity-40 grayscale" loading="lazy">
                    eSIMs
                </span>
                @endif

                {{-- Mobile top up --}}
                @if (\App\Support\FeatureFlag::on('topups'))
                <a href="{{ route('shop.topups') }}" wire:navigate class="{{ $catLinkClass }} {{ $catLinkState('Mobile top up') }}">
                    <img src="{{ asset('assets/' . rawurlencode('topup1.svg')) }}" alt="" class="{{ $catImgClass }} {{ $catIconState('Mobile top up') }}" loading="lazy">
                    Mobile top up
                </a>
                @else
                <span class="{{ $catLinkClass }} {{ $catOff }}" aria-disabled="true" title="Temporarily unavailable">
                    <img src="{{ asset('assets/' . rawurlencode('topup1.svg')) }}" alt="" class="{{ $catImgClass }} opacity-40 grayscale" loading="lazy">
                    Mobile top up
                </span>
                @endif

                {{-- Bill payments --}}
                @if (\App\Support\FeatureFlag::on('bills'))
                <a href="{{ route('shop.bills') }}" wire:navigate class="{{ $catLinkClass }} {{ $catLinkState('Bill payments') }}">
                    <img src="{{ $currentCat === 'Bill payments' ? asset('assets/' . rawurlencode('bill payment.svg')) : asset('assets/' . rawurlencode('Bills 2.svg')) }}" alt="" class="{{ $catImgClass }} {{ $catIconState('Bill payments') }}" loading="lazy">
                    Bill payments
                </a>
                @else
                <span class="{{ $catLinkClass }} {{ $catOff }}" aria-disabled="true" title="Temporarily unavailable">
                    <img src="{{ asset('assets/' . rawurlencode('Bills 2.svg')) }}" alt="" class="{{ $catImgClass }} opacity-40 grayscale" loading="lazy">
                    Bill payments
                </span>
                @endif

                {{-- Flights --}}
                <a href="{{ route('shop.flights') }}" wire:navigate class="{{ $catLinkClass }} {{ $catLinkState('Flights') }}">
                    <img src="{{ asset('assets/' . rawurlencode('flight 2.svg')) }}" alt="" class="{{ $catImgClass }} {{ $catIconState('Flights') }}" loading="lazy">
                    Flights
                </a>

                {{-- Stays --}}
                <a href="{{ route('shop.stays') }}" wire:navigate class="{{ $catLinkClass }} {{ $catLinkState('Stays') }}">
                    <img src="{{ asset('assets/' . rawurlencode('stay 2.svg')) }}" alt="" class="{{ $catImgClass }} {{ $catIconState('Stays') }}" loading="lazy">
                    Stays
                </a>

            </div>
        </nav>
    </div>

</div>

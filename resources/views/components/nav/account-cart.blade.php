{{--
    Account (or "Sign in" when a guest) and the cart, as one unit. Both wear the
    same fill as the locale pill next to them - #dee3e1 on the light page,
    white/8 in dark - so the whole utility line reads as one set of chips on
    the page tone rather than a mix of fills.

    #dee3e1 is the storefront's ONE grey: chips, buttons and every border use
    it, over a #ffffff page. Two values, no third tone.

    Rendered once, inside the logo row's right-hand column, at every width -
    on mobile these sit beside the logo as icon-only buttons, next to the
    mobile search trigger.

    Unlike x-nav.utility-links this must NOT be duplicated: the cart popup
    teleports to <body> and both halves read one Alpine store, so a second copy
    would render a second popup.

    No wrapper element on purpose: the parent supplies the flex row.
--}}
{{-- No wallet pill here. It is a dashboard feature - see the
     Wallet entry in layouts/dashboard - and does not belong in
     the storefront nav. The per-render balance lookup
     (navWalletSummary) went with it.

     The PHP block below stays: it also builds the account
     avatar, which the dropdown under it needs. Dropping the
     whole block took that variable with it and 500'd every
     signed-in page - guests were unaffected, which is exactly
     why it was easy to miss.

     (Directive names are spelled out in prose here on purpose -
     a literal one inside a Blade comment can still be picked up
     by the compiler.) --}}
@php
    // Real photo if set, otherwise a deterministic initials avatar.
    $authUser = auth()->user();
    $accountAvatar = $authUser?->avatar_url ?: ($authUser?->initialsAvatar() ?? '');
@endphp

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
            {{-- Circular, not the project 12px square: this is a person, and
                 an avatar is the one control on the bar that reads as a face
                 rather than a button. overflow-hidden already clips a real
                 photo to the shape, so the crop follows for free. --}}
            class="flex items-center justify-center w-11 h-11 md:w-10 md:h-10 overflow-hidden rounded-full bg-zinc-200 transition-all duration-150 ring-1 ring-zinc-200 hover:ring-2 hover:ring-blue-400 focus:outline-none focus-visible:ring-2 focus-visible:ring-blue-500/50"
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
            class="absolute right-0 top-full z-50 mt-2 w-60 overflow-hidden rounded-[12px] bg-white/85 backdrop-blur-md shadow-xl shadow-zinc-900/10 ring-1 ring-zinc-200 dark:bg-[#0c1a36]/90 dark:ring-white/10"
            role="menu"
        >
            <div class="p-1.5">
                <a href="{{ route('dashboard') }}" wire:navigate role="menuitem" class="group flex items-center gap-3 rounded-[12px] px-3 py-2.5 text-sm font-medium text-zinc-800 transition-colors hover:bg-blue-100 dark:text-zinc-200 dark:hover:bg-white/10 dark:hover:text-white">
                    <svg class="h-5 w-5 shrink-0 text-zinc-700 dark:text-zinc-300" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 6a3.75 3.75 0 11-7.5 0 3.75 3.75 0 017.5 0zM4.501 20.118a7.5 7.5 0 0114.998 0A17.933 17.933 0 0112 21.75c-2.676 0-5.216-.584-7.499-1.632z"/>
                    </svg>
                    {{ __('navigation.account') }}
                </a>
                <a href="{{ route('dashboard.orders') }}" wire:navigate role="menuitem" class="group flex items-center gap-3 rounded-[12px] px-3 py-2.5 text-sm font-medium text-zinc-800 transition-colors hover:bg-blue-100 dark:text-zinc-200 dark:hover:bg-white/10 dark:hover:text-white">
                    <svg class="h-5 w-5 shrink-0 text-zinc-700 dark:text-zinc-300" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/>
                    </svg>
                    {{ __('navigation.my_orders') }}
                </a>
                <a href="{{ route('dashboard.rewards') }}" wire:navigate role="menuitem" class="group flex items-center gap-3 rounded-[12px] px-3 py-2.5 text-sm font-medium text-zinc-800 transition-colors hover:bg-blue-100 dark:text-zinc-200 dark:hover:bg-white/10 dark:hover:text-white">
                    <svg class="h-5 w-5 shrink-0 text-zinc-700 dark:text-zinc-300" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M21 11.25v8.25a1.5 1.5 0 01-1.5 1.5H5.25a1.5 1.5 0 01-1.5-1.5v-8.25M12 4.875A2.625 2.625 0 109.375 7.5H12m0-2.625V7.5m0-2.625A2.625 2.625 0 1114.625 7.5H12m0 0V21m-8.625-9.75h18c.621 0 1.125-.504 1.125-1.125v-1.5c0-.621-.504-1.125-1.125-1.125h-18c-.621 0-1.125.504-1.125 1.125v1.5c0 .621.504 1.125 1.125 1.125z"/>
                    </svg>
                    {{ __('navigation.redeem') }}
                </a>
                <a href="{{ route('dashboard.profile') }}" wire:navigate role="menuitem" class="group flex items-center gap-3 rounded-[12px] px-3 py-2.5 text-sm font-medium text-zinc-800 transition-colors hover:bg-blue-100 dark:text-zinc-200 dark:hover:bg-white/10 dark:hover:text-white">
                    <svg class="h-5 w-5 shrink-0 text-zinc-700 dark:text-zinc-300" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M9.594 3.94c.09-.542.56-.94 1.11-.94h2.593c.55 0 1.02.398 1.11.94l.213 1.281c.063.374.313.686.645.87.074.04.147.083.22.127.325.196.72.257 1.075.124l1.217-.456a1.125 1.125 0 011.37.49l1.296 2.247a1.125 1.125 0 01-.26 1.431l-1.003.827c-.293.241-.438.613-.43.992a6.759 6.759 0 010 .255c-.008.378.137.75.43.991l1.004.827c.424.35.534.955.26 1.43l-1.298 2.247a1.125 1.125 0 01-1.369.491l-1.217-.456c-.355-.133-.75-.072-1.076.124a6.57 6.57 0 01-.22.128c-.331.183-.581.495-.644.869l-.213 1.28c-.09.543-.56.941-1.11.941h-2.594c-.55 0-1.02-.398-1.11-.94l-.213-1.281c-.062-.374-.312-.686-.644-.87a6.52 6.52 0 01-.22-.127c-.325-.196-.72-.257-1.076-.124l-1.217.456a1.125 1.125 0 01-1.369-.49l-1.297-2.247a1.125 1.125 0 01.26-1.431l1.004-.827c.292-.24.437-.613.43-.991a6.932 6.932 0 010-.255c.007-.38-.138-.751-.43-.992l-1.004-.827a1.125 1.125 0 01-.26-1.43l1.297-2.247a1.125 1.125 0 011.37-.491l1.216.456c.356.133.751.072 1.076-.124.072-.044.146-.087.22-.128.332-.183.582-.495.644-.869l.214-1.281z"/>
                        <path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/>
                    </svg>
                    {{ __('navigation.settings') }}
                </a>
                <a href="{{ route('dashboard.kyc') }}" wire:navigate role="menuitem" class="group flex items-center gap-3 rounded-[12px] px-3 py-2.5 text-sm font-medium text-zinc-800 transition-colors hover:bg-blue-100 dark:text-zinc-200 dark:hover:bg-white/10 dark:hover:text-white">
                    <svg class="h-5 w-5 shrink-0 text-zinc-700 dark:text-zinc-300" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75L11.25 15 15 9.75M21 12c0 1.268-.63 2.39-1.593 3.068a3.745 3.745 0 01-1.043 3.296 3.745 3.745 0 01-3.296 1.043A3.745 3.745 0 0112 21c-1.268 0-2.39-.63-3.068-1.593a3.746 3.746 0 01-3.296-1.043 3.745 3.745 0 01-1.043-3.296A3.745 3.745 0 013 12c0-1.268.63-2.39 1.593-3.068a3.745 3.745 0 011.043-3.296 3.746 3.746 0 013.296-1.043A3.746 3.746 0 0112 3c1.268 0 2.39.63 3.068 1.593a3.746 3.746 0 013.296 1.043 3.746 3.746 0 011.043 3.296A3.745 3.745 0 0121 12z"/>
                    </svg>
                    {{ __('navigation.limits') }}
                </a>
            </div>

            <div class="border-t border-zinc-100 p-1.5">
                <form method="POST" action="{{ route('logout') }}" class="w-full">
                    @csrf
                    <button type="submit" class="flex w-full items-center gap-3 rounded-[12px] px-3 py-2.5 text-left text-sm font-medium text-red-600 transition-colors hover:bg-red-50 dark:text-red-400 dark:hover:bg-red-500/15">
                        <x-ui.logout-icon class="h-5 w-5" />
                        {{ __('navigation.logout') }}
                    </button>
                </form>
            </div>
        </div>
    </div>
@else
    {{-- Guest: a direct link to the sign-in PAGE.

         This was a dropdown whose two items dispatched
         open-auth-modal. Sign-in and create-account are real
         pages now, so the dropdown was a click of indirection in
         front of a link - and create-account is reachable from
         the sign-in page itself. --}}
    <a
        href="{{ route('login') }}"
        wire:navigate
        class="flex min-h-11 items-center gap-2 rounded-[10px] px-3 py-2 nav-text text-zinc-900 transition-colors duration-150 bg-[#f4f6f5] md:bg-transparent md:min-h-0 hover:bg-[#e8ecea] md:hover:bg-[#f4f6f5] dark:bg-white/8 md:dark:bg-transparent dark:hover:bg-white/15 md:dark:hover:bg-white/10 dark:hover:text-white focus:outline-none focus-visible:ring-2 focus-visible:ring-blue-500/40 dark:text-zinc-200"
        aria-label="{{ __('navigation.sign_in') }}"
    >
        {{-- Inline, not the Login.svg file it replaced: an <img> cannot take
             currentColor, so the old mark stayed dark when the chip inverts on
             hover. This one follows the button's ink. --}}
        <svg class="h-5 w-5 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
            <path d="M15 3h3a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-3" />
            <path d="M10 17l5-5-5-5" />
            <path d="M15 12H3" />
        </svg>
        {{-- Label from sm+ only: the mobile row is tight and the
             glyph plus aria-label already identify the control. --}}
        <span class="hidden sm:inline">{{ __('navigation.sign_in') }}</span>
    </a>
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
        class="relative flex min-h-11 items-center gap-2 rounded-[10px] px-3 py-2 nav-text text-zinc-900 transition-colors duration-150 bg-[#f4f6f5] md:bg-transparent md:min-h-0 hover:bg-[#e8ecea] md:hover:bg-[#f4f6f5] dark:bg-white/8 md:dark:bg-transparent dark:hover:bg-white/15 md:dark:hover:bg-white/10 dark:hover:text-white focus:outline-none focus-visible:ring-2 focus-visible:ring-blue-500/50 dark:text-zinc-200"
        aria-label="{{ __('navigation.cart') }}"
    >
        {{-- Cart glyph, drawn to the same stroke weight as the sign-in mark
                             beside it - the old one was a heavy filled 48x48 path and read
                             noticeably darker next to it. Inherits the button colour. --}}
                        <svg class="h-5 w-5 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                            <path d="M2.5 3.5h1.8a1 1 0 0 1 .97.76L6 8m0 0 1.7 6.4a1.5 1.5 0 0 0 1.45 1.1h7.3a1.5 1.5 0 0 0 1.45-1.1L20 8H6Z" />
                            <circle cx="9.5" cy="19.5" r="1.5" />
                            <circle cx="17" cy="19.5" r="1.5" />
                        </svg>
        {{-- Label from sm+ only, matching Login: the mobile row
             is tight and the glyph plus aria-label already
             identify the control. --}}
        <span class="hidden sm:inline">{{ __('navigation.cart') }}</span>
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
            <h3 class="text-xl font-bold text-zinc-900 dark:text-white">{{ __('checkout.cart_empty') }}</h3>
            <x-illo name="emptyCart" class="mx-auto mt-4 w-full max-w-[230px]" />
            <p class="mt-3 text-sm text-zinc-600 dark:text-zinc-400">{{ __('checkout.cart_needs_items') }}</p>
        </div>

        {{-- Populated state --}}
        <div x-show="$store.cart.count > 0" x-cloak>
            <div class="flex items-center justify-between px-3 pt-3">
                <h3 class="text-lg font-bold text-zinc-900 dark:text-white">{{ __('checkout.your_cart') }}</h3>
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
                                <span class="text-sm font-black uppercase text-zinc-700 dark:text-zinc-300" x-text="item.name.substring(0,2).toUpperCase()"></span>
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

            {{-- Cart actions, as a matched pair of pills.

                 Checkout keeps the blue fill rather than going grey with its
                 neighbour: greyed, the cart would have no primary action left on
                 what is a purchase path. Shape, padding and weight match, so the
                 two still read as one control group. --}}
            <div class="mt-3 flex gap-2 rounded-[12px] bg-zinc-50 px-3 py-3 dark:bg-white/5">
                <a href="{{ route('shop.cart') }}" wire:navigate @click="$store.cart.open = false; locked = false" class="flex-1 inline-flex items-center justify-center rounded-full bg-[#f4f6f5] px-4 py-3.5 text-sm font-bold text-zinc-900 transition-colors hover:bg-[#e8ecea] focus:outline-none focus-visible:ring-2 focus-visible:ring-blue-500/40 dark:bg-white/10 dark:text-white dark:hover:bg-white/15">
                    {{ __('checkout.view_cart') }}
                </a>
                <a :href="'{{ route('shop.checkout') }}' + ($store.cart.showUsd ? '?currency=' + $store.cart.currency : '')" wire:navigate @click="$store.cart.open = false" class="flex-1 inline-flex items-center justify-center rounded-full bg-blue-600 px-4 py-3.5 text-sm font-bold text-white transition-colors hover:bg-blue-700 focus:outline-none focus-visible:ring-2 focus-visible:ring-blue-500/40">
                    Checkout
                </a>
            </div>
        </div>
    </div>
        </div>
    </template>
</div>


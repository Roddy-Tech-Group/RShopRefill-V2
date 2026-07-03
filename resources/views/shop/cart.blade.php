{{--
    Cart page — /cart. Store-driven: it renders entirely from the global Alpine
    cart store ($store.cart), the same store the nav popup and product page use.
    The store hydrates from /cart/data on load; `hydrated` gates the empty state
    so an empty cart never flashes before the real data arrives.
--}}
@php
    // When reached under /dashboard/shop/* keep links inside the dashboard chrome
    // (x-shop.layout renders the dashboard layout for that URL prefix).
    $inDashboard = request()->is('dashboard/shop*');
    $shopRoute = fn (string $name, $params = []) => route(($inDashboard ? 'dashboard.shop.' : 'shop.').$name, $params);
@endphp

<x-shop.layout :title="'Your Cart | '.$siteName">

    <div class="min-h-full bg-zinc-100 dark:bg-[#0c1a36]">
    <div class="mx-auto w-full max-w-7xl px-4 py-6 sm:px-6 lg:px-8 lg:py-10">

        <h1 class="text-[24px] font-bold leading-tight text-zinc-900 dark:text-white">Your Cart</h1>

        {{-- Loading state — shown until the store's first fetch resolves --}}
        <div x-show="!$store.cart.hydrated" class="pure-card mt-8 flex items-center justify-center rounded-[20px] bg-white/70 dark:bg-[#0c1a36]/60 py-24 ring-1 ring-zinc-200 backdrop-blur-xl dark:ring-white/10">
            <svg class="h-7 w-7 animate-spin text-blue-600" fill="none" viewBox="0 0 24 24" aria-hidden="true">
                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
            </svg>
        </div>

        {{-- Empty state --}}
        <div x-show="$store.cart.hydrated && $store.cart.count === 0" x-cloak class="pure-card mt-8 rounded-[20px] bg-white/70 dark:bg-[#0c1a36]/60 px-6 py-16 text-center ring-1 ring-zinc-200 backdrop-blur-xl dark:ring-white/10">
            <x-illo name="emptyCart" class="mx-auto w-full max-w-sm" />
            <p class="mt-4 text-base font-semibold text-zinc-900 dark:text-white">Your cart is empty</p>
            <p class="mt-1 text-sm text-zinc-600 dark:text-zinc-400">Browse the catalog and add a gift card to get started.</p>
            <a href="{{ $shopRoute('gift-cards') }}" wire:navigate class="mt-5 inline-flex items-center gap-1.5 rounded-[12px] bg-blue-600 px-5 py-2.5 text-sm font-semibold text-white transition-colors hover:bg-blue-700">
                Visit Shop
            </a>
        </div>

        {{-- Populated cart --}}
        <div x-show="$store.cart.hydrated && $store.cart.count > 0" x-cloak class="mt-6 grid grid-cols-1 gap-6 lg:mt-8 lg:grid-cols-3 lg:gap-8">

            {{-- LEFT: line items --}}
            <div class="lg:col-span-2">
                <div class="pure-card overflow-hidden rounded-[20px] bg-white/70 dark:bg-[#0c1a36]/60 ring-1 ring-zinc-200 backdrop-blur-xl dark:ring-white/10">
                    <div class="flex items-center justify-between px-5 py-4 sm:px-6">
                        <h2 class="text-base font-bold text-zinc-900 dark:text-white">Items</h2>
                        <span class="text-sm text-zinc-600 dark:text-zinc-400" x-text="$store.cart.count + ' item' + ($store.cart.count === 1 ? '' : 's')"></span>
                    </div>

                    <ul class="divide-y divide-zinc-100 dark:divide-white/10">
                        <template x-for="item in $store.cart.items" :key="item.id">
                            <li class="flex items-start gap-4 px-5 py-4 sm:px-6">
                                {{-- Logo --}}
                                <span class="flex h-14 w-14 shrink-0 items-center justify-center overflow-hidden rounded-[12px] bg-white ring-1 ring-zinc-200 dark:ring-white/15">
                                    <template x-if="item.logo">
                                        <img :src="item.logo" alt="" class="h-full w-full object-cover">
                                    </template>
                                    {{-- No logo (eSIMs): country flag, globe for Global plans. --}}
                                    <template x-if="!item.logo && item.flag">
                                        <img :src="item.flag" alt="" class="h-7 w-10 rounded-[4px] object-cover ring-1 ring-zinc-200 dark:ring-white/15" loading="lazy">
                                    </template>
                                    <template x-if="!item.logo && !item.flag && item.is_global">
                                        <svg class="h-7 w-7 text-blue-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5" aria-hidden="true">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 21a9.004 9.004 0 008.716-6.747M12 21a9.004 9.004 0 01-8.716-6.747M12 21c2.485 0 4.5-4.03 4.5-9S14.485 3 12 3m0 18c-2.485 0-4.5-4.03-4.5-9S9.515 3 12 3m0 0a9.004 9.004 0 018.716 6.747M12 3a9.004 9.004 0 00-8.716 6.747M21.75 12H2.25"/>
                                        </svg>
                                    </template>
                                    <template x-if="!item.logo && !item.flag && !item.is_global">
                                        <span class="text-xs font-black text-zinc-700" x-text="item.name.substring(0,2).toUpperCase()"></span>
                                    </template>
                                </span>

                                {{-- Details --}}
                                <div class="min-w-0 flex-1">
                                    <p class="truncate text-sm font-bold text-zinc-900 dark:text-white" x-text="item.name"></p>
                                    <p class="mt-0.5 truncate text-xs text-zinc-500 dark:text-zinc-400">
                                        <span x-show="item.face_label" x-text="$store.cart.unitLabel(item)"></span><span x-show="item.face_label && item.country"> &middot; </span><span x-text="item.country_name || item.country"></span>
                                    </p>
                                    <p class="mt-0.5 text-xs text-zinc-600 dark:text-zinc-400">
                                        <span x-text="$store.cart.pay(item.unit_price)"></span>
                                        <span x-show="$store.cart.showUsd" class="text-zinc-400" x-text="'(' + $store.cart.usd(item.unit_price_usd) + ')'"></span>
                                        <span class="text-zinc-400"> each</span>
                                    </p>

                                    {{-- Quantity stepper + remove --}}
                                    <div class="mt-2.5 flex items-center gap-3">
                                        <span class="inline-flex items-center gap-1.5">
                                            <button type="button" @click="$store.cart.setQty(item.id, item.quantity - 1)" class="flex h-7 w-7 items-center justify-center rounded-[12px] text-zinc-600 dark:text-zinc-400 ring-1 ring-zinc-200 dark:ring-white/15 transition-colors hover:bg-zinc-100 dark:hover:bg-white/10 hover:text-zinc-900 dark:hover:text-white" aria-label="Decrease quantity">
                                                <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" d="M5 12h14"/></svg>
                                            </button>
                                            <span class="w-6 text-center text-sm font-bold tabular-nums text-zinc-900 dark:text-white" x-text="item.quantity"></span>
                                            <button type="button" @click="$store.cart.setQty(item.id, item.quantity + 1)" class="flex h-7 w-7 items-center justify-center rounded-[12px] text-zinc-600 dark:text-zinc-400 ring-1 ring-zinc-200 dark:ring-white/15 transition-colors hover:bg-zinc-100 dark:hover:bg-white/10 hover:text-zinc-900 dark:hover:text-white" aria-label="Increase quantity">
                                                <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M12 5v14M5 12h14"/></svg>
                                            </button>
                                        </span>
                                        <button type="button" @click="$store.cart.remove(item.id)" class="text-xs font-semibold text-red-600 transition-colors hover:text-red-700">
                                            Remove
                                        </button>
                                    </div>
                                </div>

                                {{-- Line total --}}
                                <div class="shrink-0 text-right">
                                    <p class="text-sm font-bold tabular-nums text-zinc-900 dark:text-white" x-text="$store.cart.pay(item.line_total)"></p>
                                    <p x-show="$store.cart.showUsd" class="text-xs tabular-nums text-zinc-500 dark:text-zinc-400" x-text="$store.cart.usd(item.line_total_usd)"></p>
                                </div>
                            </li>
                        </template>
                    </ul>
                </div>

                <a href="{{ $shopRoute('gift-cards') }}" wire:navigate class="mt-4 inline-flex items-center text-sm font-semibold text-blue-600 transition-colors hover:text-blue-700">
                    Continue shopping
                </a>
            </div>

            {{-- RIGHT: summary (sticky) --}}
            <aside class="lg:col-span-1">
                <div class="pure-card lg:sticky lg:top-6 flex flex-col gap-4 rounded-[20px] bg-white/70 dark:bg-[#0c1a36]/60 p-5 ring-1 ring-zinc-200 backdrop-blur-xl dark:ring-white/10 sm:p-6">
                    <h2 class="text-base font-bold text-zinc-900 dark:text-white">Order summary</h2>

                    <div class="space-y-2.5 border-t border-zinc-100 dark:border-white/10 pt-4 text-sm">
                        <div class="flex items-start justify-between">
                            <span class="text-zinc-600 dark:text-zinc-400">Subtotal</span>
                            <span class="text-right">
                                <span class="block tabular-nums text-zinc-900 dark:text-white" x-text="$store.cart.pay($store.cart.subtotal)"></span>
                                <span x-show="$store.cart.showUsd" class="block text-xs tabular-nums text-zinc-500 dark:text-zinc-400" x-text="'(' + $store.cart.usd($store.cart.subtotalUsd) + ' USD)'"></span>
                            </span>
                        </div>
                        <div class="flex items-start justify-between border-t border-zinc-100 dark:border-white/10 pt-2.5 text-base font-bold text-zinc-900 dark:text-white">
                            <span>Total</span>
                            <span class="text-right">
                                <span class="block tabular-nums" x-text="$store.cart.pay($store.cart.subtotal)"></span>
                                <span x-show="$store.cart.showUsd" class="block text-xs font-medium tabular-nums text-zinc-500 dark:text-zinc-400" x-text="'(' + $store.cart.usd($store.cart.subtotalUsd) + ' USD)'"></span>
                            </span>
                        </div>
                    </div>

                    <a
                        :href="'{{ $shopRoute('checkout') }}' + ($store.cart.showUsd ? '?currency=' + $store.cart.currency : '')"
                        wire:navigate
                        class="flex w-full items-center justify-center rounded-[12px] bg-blue-600 px-4 py-3.5 text-base font-bold text-white shadow-md transition-colors hover:bg-blue-700"
                    >
                        Proceed to checkout
                    </a>

                    <p class="text-center text-xs text-zinc-600 dark:text-zinc-400">Transaction fees are added by the payment provider at checkout.</p>
                </div>
            </aside>

        </div>
    </div>
    </div>

</x-shop.layout>

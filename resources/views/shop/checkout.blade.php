@php
    use App\Models\CurrencyRate;

    /**
     * Order details (left) is store-driven — it renders from the global Alpine cart
     * store, so quantity/remove are live. The payment panel (right) is a plain form
     * that POSTs to checkout.process; the backend resolves the cart server-side.
     */

    // Post to the dashboard mirror when rendered under /dashboard/shop/* so the
    // server keeps the whole cart -> checkout -> order flow (and the order page's
    // redirect_url) inside the dashboard chrome instead of the public storefront.
    $checkoutProcessUrl = request()->routeIs('dashboard.*')
        ? route('dashboard.shop.checkout.process')
        : route('checkout.process');

    // Crypto coins offered at checkout: enabled by us AND currently accepted by
    // the gateway on our merchant account (scopePayable). A coin the gateway has
    // stopped accepting must not be offered — it would fail at payment creation.
    $cryptoCoins = CurrencyRate::query()->payable()->crypto()
        ->orderBy('sort_order')->orderBy('code')
        ->get(['code', 'name', 'rate_per_usd', 'icon_path', 'network', 'min_amount_usd']);

    // Code-keyed map the coin picker reads from. minUsd gates which coins can be
    // used for this order — minimums differ by more than two orders of magnitude
    // across our coins, so offering all of them on a small basket guarantees a
    // rejection at the worst possible moment.
    $cryptoRatesForJs = $cryptoCoins->mapWithKeys(fn ($c) => [$c->code => [
        'code' => $c->code,
        'name' => $c->name,
        'perUsd' => (float) $c->rate_per_usd,
        'decimals' => $c->rate_per_usd < 0.01 ? 8 : ($c->rate_per_usd < 1 ? 4 : 2),
        // null means "not synced yet" and is treated as no minimum: a missing sync
        // must not empty the checkout, and the gateway still rejects a genuinely
        // sub-minimum payment.
        'minUsd' => $c->min_amount_usd !== null ? (float) $c->min_amount_usd : null,
    ]]);

    // Mobile-money networks. Static for now.
    $momoNetworks = ['MTN', 'Orange'];

    // Customer's wallet balances keyed by currency code. The wallet panel reads
    // the balance for the active display currency directly from this array.
    // (Method tabs themselves are driven by the JS `allMethods` array in
    // checkoutPage() — currency, login state and Apple Pay availability decide
    // which tiles render, so there is no parallel PHP `$methods` list.)
    $user = auth()->user();
    $walletBalances = [];
    if ($user) {
        foreach ($user->wallets as $w) {
            $walletBalances[strtoupper($w->currency->value)] = (float) $w->balance;
        }
    }

    $fieldClass = 'mt-1.5 w-full rounded-[12px] border border-zinc-200 bg-white px-3 py-2.5 text-base text-zinc-900 dark:text-white outline-none transition-colors focus:border-blue-500 focus:ring-2 focus:ring-blue-500/15 dark:border-white/15 dark:bg-white/5 dark:placeholder:text-zinc-500';

    $rcoinConfig = [
        // Master kill-switch. When off the buyer sees no Rcoin earn lines and
        // no redemption toggle - the programme is invisible storefront-side.
        'enabled' => (bool) \App\Models\Setting::get('rcoin_enabled', true),
        'cashback_percentage' => (float) \App\Models\Setting::get('cashback_percentage', 0.5),
        'usd_rate' => (float) \App\Models\Setting::rcoinUsdRate(),
        // Redemption rules - checkout toggle reads these to decide whether to
        // show the "Use Rcoin" switch and how many Rcoin can be applied.
        'redemption_enabled' => (bool) \App\Models\Setting::get('redemption_enabled', true),
        'redemption_min'     => (int) \App\Models\Setting::get('redemption_min_rcoin', 2000),
        'redemption_max_pct' => (float) \App\Models\Setting::get('redemption_max_percentage', 30.0),
    ];

    // Processing-fee disclosure rates (config/payment_fees.php). The gateway
    // charges the customer these on top of the order amount; the totals block
    // surfaces them up front so the statement never surprises anyone.
    $paymentFees = [
        'fee_free' => array_values((array) config('payment_fees.fee_free_methods', [])),
        'methods'  => (array) config('payment_fees.methods', []),
        'default'  => (array) config('payment_fees.default', ['transaction' => 0.0, 'international' => 0.0]),
    ];

    // The crypto network-fee / service-fee config that used to live here has been
    // removed along with the client-side fee engine it fed. Those figures were only
    // ever added to the on-screen total and never to the amount sent to the gateway,
    // so they made the checkout page disagree with the payment page. The gateway
    // already grosses its own fees into the amount it quotes, which is now the single
    // figure the customer sees.
@endphp

<x-shop.layout :title="'Checkout | '.$siteName">

    <script src="https://checkout.flutterwave.com/v3.js"></script>

    <div class="min-h-full bg-zinc-100 dark:bg-[#0c1a36]">
    <div class="mx-auto w-full max-w-7xl px-4 py-6 sm:px-6 lg:px-8 lg:py-10">

        @if (session('checkout_status'))
            <div class="mt-4 rounded-[12px] bg-emerald-50 px-4 py-3 text-sm font-medium text-emerald-800 ring-1 ring-emerald-200">
                {{ session('checkout_status') }}
            </div>
        @endif

        {{-- features.checkout_enabled kill-switch banner. Mirrors the server-side
             abort at CheckoutController::process() so users see the paused state
             upfront instead of bouncing off a 503 on submit. --}}
        <x-paused-banner
            flag="checkout"
            title="Checkout is temporarily paused"
            message="New orders are currently unavailable. Your cart is saved and you can try again shortly."
            class="mt-4"
        />

        <div x-data="checkoutPage(@js($cryptoRatesForJs), @js($walletBalances), @js(auth()->check()), @js($rcoinConfig), @js(auth()->user()?->hasTransactionPin() ?? false), @js($paymentFees))">

            {{-- Loading — until the cart store's first fetch resolves --}}
            <div x-show="!$store.cart.hydrated" class="pure-card flex items-center justify-center rounded-[20px] bg-white/70 dark:bg-[#0c1a36]/60 py-24 ring-1 ring-zinc-200 backdrop-blur-xl dark:ring-white/10">
                <svg class="h-7 w-7 animate-spin text-blue-600" fill="none" viewBox="0 0 24 24" aria-hidden="true">
                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
                </svg>
            </div>

            {{-- Empty cart - same animated inline illustration as the cart
                 page and the nav/dashboard cart popups. --}}
            <div x-show="$store.cart.hydrated && $store.cart.count === 0" x-cloak class="pure-card rounded-[20px] bg-white/70 dark:bg-[#0c1a36]/60 px-6 py-20 text-center ring-1 ring-zinc-200 backdrop-blur-xl dark:ring-white/10">
                <x-illo name="emptyCart" class="mx-auto w-full max-w-[280px]" />
                <p class="mt-4 text-base font-semibold text-zinc-900 dark:text-white">Your cart is empty</p>
                <p class="mt-1 text-sm text-zinc-600 dark:text-zinc-400">Add a Product before heading to checkout.</p>
                <a href="{{ request()->routeIs('dashboard.*') ? route('dashboard.shop.gift-cards') : route('shop.gift-cards') }}" wire:navigate class="mt-5 inline-flex items-center gap-1.5 rounded-[12px] bg-blue-600 px-5 py-2.5 text-sm font-semibold text-white transition-colors hover:bg-blue-700">
                    Visit Shop
                </a>
            </div>

            {{-- Checkout --}}
            <div x-show="$store.cart.hydrated && $store.cart.count > 0" x-cloak class="grid grid-cols-1 gap-6 lg:grid-cols-2 lg:gap-6">

                {{-- ─── LEFT: Order details ─── --}}
                <section class="pure-card self-start rounded-[20px] bg-white/70 dark:bg-[#0c1a36]/60 p-5 ring-1 ring-zinc-200 backdrop-blur-xl dark:ring-white/10 sm:p-6">
                    <h2 class="text-lg font-bold text-zinc-900 dark:text-white">Order details</h2>

                    <ul class="mt-4 divide-y divide-zinc-100">
                        <template x-for="item in $store.cart.items" :key="item.id">
                            <li class="flex items-start gap-3 py-4">
                                {{-- Brand tile — matches the catalog product card (16:10, edge-to-edge logo). --}}
                                <span class="flex aspect-[16/10] w-28 shrink-0 items-center justify-center overflow-hidden rounded-[12px] bg-white shadow-sm ring-1 ring-zinc-200 sm:w-32">
                                    <template x-if="item.logo">
                                        <img :src="item.logo" alt="" class="h-full w-full object-cover">
                                    </template>
                                    {{-- No logo (eSIMs): country flag, globe for Global plans. --}}
                                    <template x-if="!item.logo && item.flag">
                                        <img :src="item.flag" alt="" class="h-12 w-[72px] rounded-[6px] object-cover ring-1 ring-zinc-200" loading="lazy">
                                    </template>
                                    <template x-if="!item.logo && !item.flag && item.is_global">
                                        <svg class="h-10 w-10 text-blue-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5" aria-hidden="true">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 21a9.004 9.004 0 008.716-6.747M12 21a9.004 9.004 0 01-8.716-6.747M12 21c2.485 0 4.5-4.03 4.5-9S14.485 3 12 3m0 18c-2.485 0-4.5-4.03-4.5-9S9.515 3 12 3m0 0a9.004 9.004 0 018.716 6.747M12 3a9.004 9.004 0 00-8.716 6.747M21.75 12H2.25"/>
                                        </svg>
                                    </template>
                                    <template x-if="!item.logo && !item.flag && !item.is_global">
                                        <span class="text-lg font-black uppercase text-zinc-700" x-text="item.name.substring(0,2).toUpperCase()"></span>
                                    </template>
                                </span>

                                <div class="min-w-0 flex-1">
                                    <p class="truncate text-sm font-bold text-zinc-900 dark:text-white" x-text="item.name"></p>
                                    <p class="mt-0.5 truncate text-xs text-zinc-500" x-text="item.country_name || item.country"></p>
                                    <p class="mt-0.5 text-xs text-zinc-600 dark:text-zinc-400">
                                        <span x-show="item.face_label" x-text="$store.cart.unitLabel(item)"></span>
                                    </p>
                                    {{-- Top-up: show the recipient phone the buyer entered. --}}
                                    <p
                                        x-show="item.category_slug === 'mobile-airtime' && item.recipient_phone"
                                        x-cloak
                                        class="mt-0.5 inline-flex items-center gap-1.5 text-xs font-semibold text-emerald-700"
                                    >
                                        <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M2.25 6.75c0 8.284 6.716 15 15 15h2.25a2.25 2.25 0 002.25-2.25v-1.372c0-.516-.351-.966-.852-1.091l-4.423-1.106c-.44-.11-.902.055-1.173.417l-.97 1.293c-.282.376-.769.542-1.21.38a12.035 12.035 0 01-7.143-7.143c-.162-.441.004-.928.38-1.21l1.293-.97c.363-.271.527-.734.417-1.173L6.963 3.102a1.125 1.125 0 00-1.091-.852H4.5A2.25 2.25 0 002.25 4.5v2.25z"/>
                                        </svg>
                                        <span x-text="item.recipient_phone"></span>
                                    </p>
                                    <p x-show="rcoinConfig.enabled" class="mt-1 inline-flex items-center gap-1 text-xs text-zinc-600 dark:text-zinc-400">
                                        <span x-text="item.quantity"></span> x
                                        <img src="{{ asset('assets/favicon.ico') }}" alt="" class="h-3.5 w-3.5 object-contain">
                                        <span class="font-semibold text-zinc-900 dark:text-white" x-text="Math.floor(((item.line_total_usd || 0) * (rcoinConfig.cashback_percentage / 100)) / rcoinConfig.usd_rate)"></span> Points
                                    </p>
                                </div>

                                <div class="flex shrink-0 flex-col items-end gap-2">
                                    {{-- Quantity dropdown --}}
                                    <div x-data="{ qtyOpen: false }" @click.outside="qtyOpen = false" class="relative">
                                        <button type="button" @click="qtyOpen = !qtyOpen" class="flex h-9 w-16 items-center justify-between rounded-[12px] border border-zinc-200 bg-white px-2.5 text-sm font-bold text-zinc-900 dark:text-white transition-colors hover:border-zinc-400">
                                            <span x-text="item.quantity"></span>
                                            <svg class="h-4 w-4 text-zinc-500" :class="qtyOpen && 'rotate-180'" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7"/></svg>
                                        </button>
                                        <div x-show="qtyOpen" x-cloak x-transition.opacity class="absolute right-0 z-20 mt-1 max-h-52 w-16 overflow-y-auto rounded-[12px] bg-white/90 p-1 shadow-lg shadow-zinc-900/10 ring-1 ring-zinc-200 backdrop-blur-xl [scrollbar-width:none] [&::-webkit-scrollbar]:hidden">
                                            <template x-for="n in 10" :key="n">
                                                <button type="button" @click="$store.cart.setQty(item.id, n); qtyOpen = false"
                                                    :class="n === item.quantity ? 'bg-blue-50 text-blue-700' : 'text-zinc-700 hover:bg-zinc-100'"
                                                    class="flex w-full items-center justify-center rounded-[12px] px-2 py-1.5 text-sm font-medium tabular-nums transition-colors"
                                                    x-text="n"></button>
                                            </template>
                                        </div>
                                    </div>
                                    <button type="button" @click="$store.cart.remove(item.id)" class="text-xs font-semibold text-red-600 transition-colors hover:text-red-700">
                                        Remove
                                    </button>
                                </div>
                            </li>
                        </template>
                    </ul>

                    {{-- eSIM coverage notice — eSIMs only have service inside
                         their coverage area, and "US Data eSIM" bought from home
                         is the classic blind-buy mistake. Shown whenever the
                         cart contains an eSIM, mirroring the gift-card notice. --}}
                    <div
                        x-show="$store.cart.items.some((i) => i.category_slug === 'esims')"
                        x-cloak
                        class="mt-3 flex items-start gap-2.5 rounded-[12px] bg-blue-50 px-4 py-3.5 dark:bg-blue-500/10"
                    >
                        <svg class="mt-0.5 h-5 w-5 shrink-0 text-blue-600 dark:text-blue-300" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 21a9.004 9.004 0 008.716-6.747M12 21a9.004 9.004 0 01-8.716-6.747M12 21c2.485 0 4.5-4.03 4.5-9S14.485 3 12 3m0 18c-2.485 0-4.5-4.03-4.5-9S9.515 3 12 3m0 0a9.004 9.004 0 018.716 6.747M12 3a9.004 9.004 0 00-8.716 6.747M21.75 12H2.25"/>
                        </svg>
                        <p class="text-sm text-blue-800 dark:text-blue-200">eSIMs are region-based and only work inside their coverage area. Pick the plan for the country or region you are traveling to, or choose a Global plan for worldwide connectivity. You can install it from anywhere; it connects once you arrive.</p>
                    </div>

                    {{-- Points + total --}}
                    <div class="mt-4 space-y-2 border-t border-zinc-100 dark:border-white/10 pt-4 text-sm">
                        <div x-show="rcoinConfig.enabled" class="flex items-center justify-between">
                            <span class="inline-flex items-center gap-1.5 text-zinc-600 dark:text-zinc-400">
                                Points you earn
                                <img src="{{ asset('assets/favicon.ico') }}" alt="" class="h-4 w-4 object-contain">
                            </span>
                            <span x-data="valueFlip()" x-effect="points(); flash()" class="inline-block font-bold text-zinc-900 dark:text-white" x-text="points()">0</span>
                        </div>

                        {{-- Rcoin redemption — three states (off / partial / full).
                             Hidden when redemption is disabled, customer is a guest,
                             or balance < min. Mode picker reveals when the box is
                             ticked AND balance is enough to cover the whole order. --}}
                        <div
                            x-show="rcoinConfig.enabled && rcoinConfig.redemption_enabled && isLoggedIn && (walletBalances.RCOIN || 0) >= rcoinConfig.redemption_min"
                            x-cloak
                            class="rounded-[12px] border border-blue-200 bg-blue-50/60 p-3 dark:border-blue-500/30 dark:bg-blue-500/10"
                        >
                            {{-- Hidden input — its value is whatever the user picked.
                                 Empty string = off; '1' = partial; 'full' = entire bill.
                                 Backend reads apply_rcoin === 'full' for the full path. --}}
                            <input type="hidden" name="apply_rcoin" :value="applyRcoin" form="checkout-form">

                            <label class="flex cursor-pointer items-start gap-3">
                                <input
                                    type="checkbox"
                                    :checked="applyRcoin !== ''"
                                    @change="applyRcoin = $event.target.checked ? '1' : ''"
                                    class="mt-0.5 h-4 w-4 shrink-0 rounded text-blue-600 focus:ring-blue-500"
                                >
                                <span class="min-w-0 flex-1">
                                    <span class="flex flex-wrap items-baseline justify-between gap-x-3">
                                        <span class="text-sm font-bold text-zinc-900 dark:text-white">Use my Rcoin</span>
                                        <span class="text-[11px] text-zinc-600 dark:text-zinc-400">
                                            Balance: <span class="font-semibold tabular-nums text-zinc-900 dark:text-white" x-text="Number(walletBalances.RCOIN || 0).toLocaleString()"></span>
                                        </span>
                                    </span>
                                    <span class="mt-0.5 block text-[11px] leading-relaxed text-zinc-600 dark:text-zinc-300">
                                        Apply up to <span class="font-semibold" x-text="rcoinConfig.redemption_max_pct + '%'"></span> of your order
                                        — save up to
                                        <span class="font-bold text-blue-700 dark:text-blue-300" x-text="'$' + (Math.min((walletBalances.RCOIN || 0) * rcoinConfig.usd_rate, ($store.cart.subtotalUsd || 0) * (rcoinConfig.redemption_max_pct / 100))).toFixed(2)"></span>
                                        on this purchase.
                                    </span>
                                </span>
                            </label>

                            {{-- Mode picker — only when checked + balance ≥ order total.
                                 Lets the user upgrade from "partial" to "pay full bill". --}}
                            <div
                                x-show="applyRcoin !== '' && (walletBalances.RCOIN || 0) * rcoinConfig.usd_rate >= ($store.cart.subtotalUsd || 0)"
                                x-cloak
                                class="mt-3 flex items-center gap-1 rounded-[12px] bg-white/70 p-1 dark:bg-white/5"
                            >
                                <button type="button" @click="applyRcoin = '1'" :class="applyRcoin === '1' ? 'bg-blue-600 text-white' : 'text-zinc-700 dark:text-zinc-300'" class="flex-1 rounded-[12px] px-3 py-1.5 text-xs font-semibold transition-colors">
                                    Apply up to <span x-text="rcoinConfig.redemption_max_pct + '%'"></span>
                                </button>
                                <button type="button" @click="applyRcoin = 'full'" :class="applyRcoin === 'full' ? 'bg-blue-600 text-white' : 'text-zinc-700 dark:text-zinc-300'" class="flex-1 rounded-[12px] px-3 py-1.5 text-xs font-semibold transition-colors">
                                    Pay full bill
                                </button>
                            </div>
                        </div>

                        <div class="flex items-start justify-between border-t border-zinc-100 dark:border-white/10 pt-2 text-base font-bold text-zinc-900 dark:text-white">
                            <span>Total estimate</span>
                            <span class="text-right">
                                <span x-data="valueFlip()" x-effect="$store.cart.subtotal; flash()" class="block tabular-nums" x-text="$store.cart.pay($store.cart.subtotal)"></span>
                                <span x-show="$store.cart.showUsd" class="block text-xs font-medium tabular-nums text-zinc-500" x-text="'(' + $store.cart.usd($store.cart.subtotalUsd) + ' USD)'"></span>
                            </span>
                        </div>
                    </div>
                </section>

                {{-- ─── RIGHT: Select payment method.
                     `id="checkout-form"` so the Rcoin redemption checkbox in
                     the left column can post into this form via its
                     `form="checkout-form"` attribute. --}}
                <form id="checkout-form" method="POST" action="{{ $checkoutProcessUrl }}" @submit.prevent="submitCheckout($event)" class="pure-card rounded-[20px] bg-white/70 dark:bg-[#0c1a36]/60 p-5 ring-1 ring-zinc-200 backdrop-blur-xl dark:ring-white/10 sm:p-6">
                    @csrf
                    <input type="hidden" name="payment_method" :value="method">
                    <input type="hidden" name="crypto_coin" :value="crypto">
                    <input type="hidden" name="currency" :value="$store.cart.currency">

                    <h2 class="text-lg font-bold text-zinc-900 dark:text-white">Select payment method</h2>

                    {{-- Checkout Currency Selector --}}
                    <div class="mt-4" x-data="{ ccyOpen: false }">
                        <label class="text-sm font-semibold text-zinc-900 dark:text-white">Checkout currency</label>
                        <p class="mt-0.5 text-xs text-zinc-600 dark:text-zinc-400">Select the currency you wish to pay with.</p>
                        <div class="relative mt-1.5" @click.outside="ccyOpen = false">
                            <button
                                type="button"
                                @click="ccyOpen = !ccyOpen"
                                class="flex w-full items-center justify-between gap-2 rounded-[12px] border border-zinc-200 bg-white px-3 py-2.5 text-base font-medium text-zinc-900 dark:text-white outline-none transition-colors hover:border-zinc-300 focus:outline-none"
                            >
                                <span class="flex items-center gap-2">
                                    <span class="font-bold" x-text="$store.cart.currency"></span>
                                    <span class="text-zinc-600 dark:text-zinc-400">&middot;</span>
                                    <span x-text="$store.cart.currency === 'USD' ? 'United States Dollar' : ($store.cart.currency === 'EUR' ? 'Euro' : ($store.cart.currency === 'GBP' ? 'British Pound' : ($store.cart.currency === 'NGN' ? 'Nigerian Naira' : ($store.cart.currency === 'XAF' ? 'Central African CFA Franc' : ''))))"></span>
                                </span>
                                <svg class="h-4 w-4 shrink-0 text-zinc-500 transition-transform duration-150" :class="ccyOpen && 'rotate-180'" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5" aria-hidden="true">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7"/>
                                </svg>
                            </button>

                            <div
                                x-show="ccyOpen"
                                x-cloak
                                x-transition:enter="transition ease-out duration-150"
                                x-transition:enter-start="opacity-0 -translate-y-1"
                                x-transition:enter-end="opacity-100 translate-y-0"
                                x-transition:leave="transition ease-in duration-100"
                                x-transition:leave-start="opacity-100 translate-y-0"
                                x-transition:leave-end="opacity-0 -translate-y-1"
                                class="absolute left-0 right-0 top-full z-20 mt-1 overflow-hidden rounded-[12px] border border-zinc-200 bg-white/80 p-1 shadow-xl shadow-zinc-900/10 backdrop-blur-xl"
                                role="listbox"
                            >
                                @foreach (array_filter(\App\Domain\Shared\Enums\Currency::cases(), fn ($c) => $c->value !== 'RCOIN') as $c)
                                    <button
                                        type="button"
                                        @click="
                                            localStorage.setItem('locale.currency', '{{ $c->value }}');
                                            window.dispatchEvent(new Event('currency-changed'));
                                            ccyOpen = false;
                                        "
                                        class="flex w-full items-center justify-between gap-2 rounded-[12px] px-3 py-2.5 text-left text-sm font-medium transition-colors"
                                        :class="$store.cart.currency === '{{ $c->value }}' ? 'bg-blue-50 text-blue-700' : 'text-zinc-700 hover:bg-zinc-100'"
                                    >
                                        <span>{{ $c->value }} &middot; {{ $c->label() }}</span>
                                        <template x-if="$store.cart.currency === '{{ $c->value }}'">
                                            <svg class="h-4 w-4 shrink-0 text-blue-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5" aria-hidden="true">
                                                <path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5"/>
                                            </svg>
                                        </template>
                                    </button>
                                @endforeach
                            </div>
                        </div>
                    </div>

                    {{-- Delivery email - doubles as a gifting field: drop a
                         friend's email here to send the redemption codes
                         straight to them instead of yourself. --}}
                    <div class="mt-4">
                        <label for="delivery_email" class="text-sm font-semibold text-zinc-900 dark:text-white">Delivery email or send as a gift</label>
                        <p class="mt-0.5 text-xs text-zinc-600 dark:text-zinc-400">Codes are emailed to this address right after payment. Use a friend's email to send it as a gift.</p>
                        <input id="delivery_email" name="delivery_email" type="email" required
                            value="{{ old('delivery_email', auth()->user()?->email) }}"
                            placeholder="you@example.com or friend@example.com" class="{{ $fieldClass }}">
                        @error('delivery_email') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                    </div>

                    {{-- Gateway outage notice. Server-rendered from the same
                         flag map the tiles and guards read. Tiles stay visible
                         but grey so this reads as a temporary issue, never as
                         "we do not support cards". --}}
                    @php
                        // The fiat rail counts as down only when NO fiat
                        // gateway (primary or backup) is enabled; a live
                        // backup keeps the rail open even with Flutterwave
                        // off, which is the whole point of the failover.
                        $downRails = [];
                        if (! \App\Domain\Payment\Support\FiatGatewayRouter::anyFiatGatewayEnabled()) {
                            $downRails[] = 'Card and bank payments';
                        }
                        if (! \App\Support\FeatureFlag::gatewayEnabled('nowpayments')) {
                            $downRails[] = 'Crypto payments';
                        }
                        if (! \App\Support\FeatureFlag::gatewayEnabled('wallet')) {
                            $downRails[] = 'Wallet payments';
                        }
                        $allGatewaysDown = count($downRails) === 3;
                    @endphp
                    @if ($downRails !== [])
                        <div class="mt-5 flex items-start gap-2.5 rounded-[12px] bg-amber-50 p-3.5 ring-1 ring-amber-200 dark:bg-amber-500/10 dark:ring-amber-500/30">
                            <svg class="mt-0.5 h-4 w-4 shrink-0 text-amber-600 dark:text-amber-400" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v4m0 4h.01M10.29 3.86l-8.02 13.9A1.5 1.5 0 0 0 3.57 20h16.86a1.5 1.5 0 0 0 1.3-2.24l-8.02-13.9a1.5 1.5 0 0 0-2.6 0z"/>
                            </svg>
                            <p class="text-xs leading-relaxed text-amber-800 dark:text-amber-200">
                                @if ($allGatewaysDown)
                                    Checkout is temporarily paused for maintenance. Your cart is saved, please check back shortly.
                                @else
                                    {{ implode(' and ', $downRails) }} {{ count($downRails) > 1 ? 'are' : 'is' }} temporarily unavailable. We are working on it, please use another payment method or check back shortly.
                                @endif
                            </p>
                        </div>
                    @endif

                    {{-- Method tabs --}}
                    <div class="mt-5 grid grid-cols-2 sm:grid-cols-3 gap-2">
                        <template x-for="m in getFilteredMethods()" :key="m.key">
                            <button type="button" @click="if (! isMethodDisabled(m.key)) method = m.key"
                                :disabled="isMethodDisabled(m.key)"
                                :aria-disabled="isMethodDisabled(m.key)"
                                :class="isMethodDisabled(m.key)
                                    ? 'border-zinc-200 opacity-45 grayscale cursor-not-allowed'
                                    : (method === m.key ? 'border-blue-600 bg-blue-50 ring-1 ring-blue-500/20' : 'border-zinc-200 hover:border-zinc-300')"
                                class="flex items-start gap-2.5 rounded-[12px] border bg-white px-3 py-2.5 text-left transition duration-150 active:scale-[0.98] disabled:active:scale-100">
                                <span class="flex h-8 w-8 shrink-0 items-center justify-center overflow-hidden rounded-[12px] bg-zinc-100 ring-1 ring-zinc-200 dark:bg-white/5 dark:ring-white/15">
                                    <template x-if="m.icon">
                                        {{-- Card + Apple Pay icons are monochrome glyphs: force them
                                             black in light mode, white in dark mode. The other payment
                                             icons (MoMo, crypto, wallet, bank) keep their brand colors. --}}
                                        <img :src="m.icon" alt=""
                                             :class="['h-5 w-5 object-contain', (m.key === 'card' || m.key === 'apple_pay') ? 'brightness-0 dark:invert' : '']"
                                             loading="lazy">
                                    </template>
                                </span>
                                <span class="min-w-0 flex-1">
                                    <span class="block text-sm font-bold text-zinc-900 dark:text-white" x-text="m.label"></span>
                                    <span class="mt-0.5 block text-[11px] leading-tight text-zinc-500" x-text="m.desc"></span>
                                </span>
                            </button>
                        </template>
                    </div>

                    {{-- Card --}}
                    <div x-show="method === 'card'" x-collapse class="mt-5 space-y-3">
                        @if(config('services.flutterwave.direct_charge_enabled', false))
                            <div>
                                <label for="card_name" class="text-sm font-semibold text-zinc-900 dark:text-white">Name on card</label>
                                <input id="card_name" name="card_name" type="text" autocomplete="cc-name" placeholder="Full name" x-model="cardDetails.card_holder" class="{{ $fieldClass }}">
                            </div>
                            <div>
                                <label for="card_number" class="text-sm font-semibold text-zinc-900 dark:text-white">Card number</label>
                                <div class="relative">
                                    <input 
                                        id="card_number" 
                                        name="card_number" 
                                        type="text" 
                                        inputmode="numeric" 
                                        autocomplete="cc-number" 
                                        placeholder="1234 1234 1234 1234" 
                                        x-model="cardDetails.card_number"
                                        @input="detectCardType"
                                        class="{{ $fieldClass }} pr-16 tabular-nums"
                                    >
                                    <div class="absolute inset-y-0 right-0 flex items-center pr-3 pointer-events-none mt-1.5">
                                        <span class="text-[10px] font-extrabold px-1.5 py-0.5 rounded-[12px] tracking-wider uppercase bg-zinc-100 text-zinc-500 border border-zinc-200" 
                                              x-text="cardBrand === 'unknown' ? 'Card' : cardBrand"
                                              :class="{
                                                  'bg-blue-50 text-blue-600 border-blue-200': cardBrand === 'visa',
                                                  'bg-amber-50 text-amber-700 border-amber-200': cardBrand === 'mastercard',
                                                  'bg-emerald-50 text-emerald-600 border-emerald-200': cardBrand === 'verve',
                                                  'bg-indigo-50 text-indigo-600 border-indigo-200': cardBrand === 'amex',
                                                  'bg-purple-50 text-purple-600 border-purple-200': cardBrand === 'discover',
                                                  'bg-rose-50 text-rose-600 border-rose-200': cardBrand === 'jcb'
                                              }"
                                        ></span>
                                    </div>
                                </div>
                            </div>
                            <div class="grid grid-cols-2 gap-3">
                                <div>
                                    <label for="card_expiry" class="text-sm font-semibold text-zinc-900 dark:text-white">Expiry</label>
                                    <input 
                                        id="card_expiry" 
                                        name="card_expiry" 
                                        type="text" 
                                        inputmode="numeric" 
                                        autocomplete="cc-exp" 
                                        placeholder="MM / YY" 
                                        x-model="cardExpiryRaw"
                                        @input="formatExpiry"
                                        class="{{ $fieldClass }} tabular-nums"
                                    >
                                </div>
                                <div>
                                    <label for="card_cvc" class="text-sm font-semibold text-zinc-900 dark:text-white">CVC</label>
                                    <input 
                                        id="card_cvc" 
                                        name="card_cvc" 
                                        type="text" 
                                        inputmode="numeric" 
                                        autocomplete="cc-csc" 
                                        placeholder="123" 
                                        x-model="cardDetails.cvv"
                                        maxlength="4"
                                        class="{{ $fieldClass }} tabular-nums"
                                    >
                                </div>
                            </div>
                        @else
                            <div class="rounded-[12px] border border-zinc-200 bg-zinc-50 p-4 text-center dark:border-zinc-700/60 dark:bg-white/5">
                                {{-- Brand marks keep their real colours in dark mode:
                                     no-dark-invert opts them out of the global
                                     .dark img[src$=".svg"] invert filter, and the
                                     white chips keep them legible on the dark panel. --}}
                                <div class="mb-3 flex justify-center gap-3">
                                    <span class="flex h-9 items-center rounded-[6px] bg-white px-2 ring-1 ring-zinc-200 dark:ring-white/20">
                                        <img src="/assets/visa.svg" alt="Visa" class="no-dark-invert h-6 object-contain">
                                    </span>
                                    <span class="flex h-9 items-center rounded-[6px] bg-white px-2 ring-1 ring-zinc-200 dark:ring-white/20">
                                        <img src="/assets/mastercard.svg" alt="Mastercard" class="no-dark-invert h-6 object-contain">
                                    </span>
                                </div>
                                <p class="text-sm font-medium text-zinc-700 dark:text-zinc-300">
                                    Secure and encrypted payment with bank-level security.
                                </p>
                            </div>
                        @endif
                    </div>

                    {{-- Mobile money --}}
                    <div x-show="method === 'mobile_money'" x-collapse x-cloak class="mt-5 space-y-3">
                        {{-- Network — custom glass dropdown (replaces the native <select>). --}}
                        <div>
                            <label class="text-sm font-semibold text-zinc-900 dark:text-white">Network</label>
                            <div
                                x-data="{ open: false, value: @js($momoNetworks[0]) }"
                                @click.outside="open = false"
                                @keydown.escape="open = false"
                                class="relative mt-1.5"
                            >
                                <input type="hidden" name="momo_network" :value="value">
                                <button
                                    type="button"
                                    @click="open = !open"
                                    :class="open ? 'border-blue-500 ring-2 ring-blue-500/15' : 'border-zinc-200 hover:border-zinc-400'"
                                    class="flex w-full items-center justify-between gap-2 rounded-[12px] border bg-white px-3 py-2.5 text-base text-zinc-900 dark:text-white transition-colors"
                                >
                                    <span x-text="value"></span>
                                    <svg class="h-4 w-4 shrink-0 text-zinc-500 transition-transform duration-200" :class="{ 'rotate-180': open }" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5" aria-hidden="true">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7"/>
                                    </svg>
                                </button>
                                <div
                                    x-show="open"
                                    x-cloak
                                    x-transition:enter="transition ease-out duration-150"
                                    x-transition:enter-start="opacity-0 -translate-y-1"
                                    x-transition:enter-end="opacity-100 translate-y-0"
                                    x-transition:leave="transition ease-in duration-100"
                                    x-transition:leave-end="opacity-0"
                                    class="absolute left-0 right-0 top-full z-20 mt-1 overflow-hidden rounded-[12px] border border-zinc-200 bg-white/80 p-1 shadow-xl shadow-zinc-900/10 backdrop-blur-xl"
                                    role="listbox"
                                >
                                    @foreach ($momoNetworks as $net)
                                        <button
                                            type="button"
                                            @click="value = @js($net); open = false"
                                            :class="value === @js($net) ? 'bg-blue-50 text-blue-700' : 'text-zinc-800 hover:bg-zinc-200'"
                                            class="flex w-full items-center justify-between rounded-[12px] px-3 py-2.5 text-left text-base font-medium transition-colors"
                                        >
                                            {{ $net }}
                                            <svg x-show="value === @js($net)" class="h-4 w-4 shrink-0 text-blue-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5" aria-hidden="true">
                                                <path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5"/>
                                            </svg>
                                        </button>
                                    @endforeach
                                </div>
                            </div>
                        </div>
                        <div>
                            <label for="momo_phone" class="text-sm font-semibold text-zinc-900 dark:text-white">Mobile money number</label>
                            <input id="momo_phone" name="momo_phone" type="tel" autocomplete="tel" placeholder="Phone number" class="{{ $fieldClass }}">
                        </div>
                    </div>

                    {{-- Crypto --}}
                    <div x-show="method === 'crypto'" x-collapse x-cloak class="mt-5">
                        <p class="text-sm font-semibold text-zinc-900 dark:text-white">Pay with</p>
                        {{-- Ticker plus network. Six of the ten coins are stablecoin
                             variants that differ only by chain, so showing the ticker
                             alone would make USDT on BNB Chain and USDT on Tron look
                             like the same option — and sending on the wrong chain
                             loses the funds. --}}
                        <div class="mt-1.5 grid grid-cols-3 gap-2 sm:grid-cols-4">
                            @forelse ($cryptoCoins as $coin)
                                @php
                                    // "Tether (BNB Chain)" -> "BNB Chain"
                                    $network = str($coin->name)->after('(')->before(')')->toString();
                                    $asset = str($coin->name)->before(' (')->toString();
                                @endphp
                                <button type="button"
                                    @click="coinUsable('{{ $coin->code }}') && (crypto = '{{ $coin->code }}')"
                                    :disabled="!coinUsable('{{ $coin->code }}')"
                                    :title="coinUsable('{{ $coin->code }}') ? '' : coinMinLabel('{{ $coin->code }}')"
                                    :class="!coinUsable('{{ $coin->code }}')
                                        ? 'cursor-not-allowed border-zinc-200 opacity-40'
                                        : (crypto === '{{ $coin->code }}' ? 'border-blue-600 bg-blue-50 ring-1 ring-blue-500/20' : 'border-zinc-200 hover:border-zinc-300')"
                                    class="flex flex-col items-center gap-1 rounded-[12px] border bg-white px-2 py-2.5 text-center transition-colors">
                                    @if ($coin->iconUrl())
                                        <img src="{{ $coin->iconUrl() }}" alt="" class="h-6 w-6 rounded-[12px]">
                                    @else
                                        <span class="flex h-6 w-6 items-center justify-center rounded-[12px] bg-amber-500 text-[10px] font-black text-white">{{ substr($coin->code, 0, 1) }}</span>
                                    @endif
                                    <span class="text-xs font-bold leading-tight text-zinc-900 dark:text-white">{{ $asset }}</span>
                                    @if ($network)
                                        <span class="text-[10px] font-medium leading-tight text-zinc-500 dark:text-zinc-400">{{ $network }}</span>
                                    @endif
                                    {{-- The minimum is stated on the tile rather than hidden
                                         behind a rejection, so the reason a coin is greyed
                                         out is visible without having to try it. --}}
                                    <template x-if="!coinUsable('{{ $coin->code }}')">
                                        <span class="text-[9px] font-semibold leading-tight text-amber-700" x-text="coinMinLabel('{{ $coin->code }}')"></span>
                                    </template>
                                </button>
                            @empty
                                <p class="col-span-full text-sm text-zinc-600 dark:text-zinc-400">No crypto currencies are enabled.</p>
                            @endforelse
                        </div>
                        <template x-if="unusableCoinCount() > 0">
                            <p class="mt-2 text-xs text-amber-700 dark:text-amber-400">
                                Some coins are unavailable for this order size — each blockchain sets its own minimum.
                            </p>
                        </template>
                        <p class="mt-3 text-xs text-zinc-600 dark:text-zinc-400">
                            Pick a coin and continue, the next step shows the exact wallet address and amount to send.
                        </p>
                    </div>

                    {{-- Wallet --}}
                    <div x-show="method === 'wallet'" x-collapse x-cloak class="mt-5 space-y-3">
                        <div class="rounded-[12px] border border-zinc-200 bg-zinc-50 p-4">
                            <div class="flex justify-between items-center">
                                <span class="text-sm font-medium text-zinc-600 dark:text-zinc-400">Available Balance</span>
                                <span class="text-base font-bold text-zinc-900 dark:text-white" x-text="$store.cart.pay(walletBalances[$store.cart.currency] || 0)"></span>
                            </div>
                            <div x-show="!hasSufficientWalletBalance()" class="mt-3 text-xs text-red-600 font-medium">
                                Insufficient balance to pay for this order. Please fund your wallet or select a different payment method.
                            </div>
                        </div>
                    </div>

                    {{-- Coupon — live preview. Apply validates against the cart and
                         shows the discount on the total before payment; the same
                         code submits with the form so the server re-applies it. --}}
                    <div class="mt-5" x-data="{ open: false }">
                        <button type="button" @click="open = !open" class="text-sm font-semibold text-zinc-900 dark:text-white underline underline-offset-2 transition-colors hover:text-blue-700">
                            I have a coupon
                        </button>
                        <div x-show="open" x-collapse x-cloak class="mt-2">
                            <div class="flex items-stretch gap-2">
                                <input
                                    name="coupon_code"
                                    type="text"
                                    x-model="couponCode"
                                    @input="couponApplied && removeCoupon()"
                                    @keydown.enter.prevent="applyCoupon()"
                                    placeholder="Coupon code"
                                    class="{{ $fieldClass }} !mt-0 flex-1 uppercase"
                                >
                                <button
                                    type="button"
                                    @click="couponApplied ? removeCoupon() : applyCoupon()"
                                    :disabled="couponLoading || (! couponApplied && ! couponCode)"
                                    class="shrink-0 rounded-[12px] px-4 text-sm font-semibold text-white transition-colors disabled:cursor-not-allowed disabled:opacity-50"
                                    :class="couponApplied ? 'bg-zinc-600 hover:bg-zinc-700' : 'bg-blue-600 hover:bg-blue-700'"
                                >
                                    <span x-show="! couponLoading" x-text="couponApplied ? 'Remove' : 'Apply'"></span>
                                    <span x-show="couponLoading">…</span>
                                </button>
                            </div>
                            <p x-show="couponError" x-cloak class="mt-1.5 text-xs font-medium text-red-600" x-text="couponError"></p>
                            <p x-show="couponApplied" x-cloak class="mt-1.5 inline-flex items-center gap-1 text-xs font-semibold text-emerald-600">
                                <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5"/></svg>
                                <span x-text="'Coupon ' + couponApplied + ' applied'"></span>
                            </p>
                        </div>
                    </div>

                    {{-- Hosted-redirect methods (USSD, Pay With Bank, Bank QR,
                         Mobile Wallet) all share the same "you'll finish on
                         the next screen" panel. Each gets a tiny method-specific
                         hint so the customer knows what to expect (dial code,
                         bank login, QR scan, e-wallet pin). --}}
                    @php
                        $hostedMethods = [
                            'ussd'           => ['title' => 'USSD',           'hint' => 'You will dial a short code on your phone to authorise the payment.'],
                            'pay_with_bank'  => ['title' => 'Pay With Bank',  'hint' => 'You will sign in to your internet or mobile banking to complete the payment.'],
                            'bank_qr'        => ['title' => 'Bank QR (NQR)',  'hint' => 'You will scan a QR code with your bank app to confirm the payment.'],
                            'mobile_wallet'  => ['title' => 'Mobile Wallet',  'hint' => 'You will authorise the payment inside your mobile wallet app.'],
                        ];
                    @endphp
                    @foreach ($hostedMethods as $key => $meta)
                        <div x-show="method === '{{ $key }}'" x-collapse x-cloak class="mt-5 rounded-[12px] bg-blue-50 px-4 py-4 ring-1 ring-blue-100">
                            <p class="text-sm font-bold text-blue-900">{{ $meta['title'] }}</p>
                            <p class="mt-1 text-xs leading-relaxed text-blue-800/90">{{ $meta['hint'] }}</p>
                            <p class="mt-2 text-[11px] text-blue-700/80">After you tap Continue we will redirect you to our payment partner to finish the payment safely.</p>
                        </div>
                    @endforeach

                    @error('payment_method') <p class="mt-3 text-xs text-red-600">{{ $message }}</p> @enderror

                    {{-- Fee breakdown - rendered only when the selected method
                         carries a gateway processing fee, so fee-free methods
                         (wallet) keep the simple single-line total. --}}
                    <div x-show="processingFee() > 0 && method !== 'crypto'" x-cloak class="mt-5 border-t border-zinc-100 dark:border-white/10 pt-5">
                        <div class="flex items-center justify-between text-sm">
                            <span class="font-medium text-zinc-600 dark:text-zinc-400">Subtotal</span>
                            <span class="font-semibold tabular-nums text-zinc-900 dark:text-white" x-text="$store.cart.pay($store.cart.subtotal)"></span>
                        </div>
                        <div class="mt-2 flex items-center justify-between text-sm">
                            <span class="font-medium text-zinc-600 dark:text-zinc-400">Processing fee</span>
                            <span class="font-semibold tabular-nums text-zinc-900 dark:text-white" x-text="$store.cart.pay(processingFee())"></span>
                        </div>
                        <div x-show="couponApplied" x-cloak class="mt-2 flex items-center justify-between text-sm">
                            <span class="font-medium text-emerald-600" x-text="'Discount (' + couponApplied + ')'"></span>
                            <span class="font-semibold tabular-nums text-emerald-600" x-text="'-' + $store.cart.pay(couponDiscountDisplay)"></span>
                        </div>
                    </div>

                    {{-- Discount line when there is no processing fee (wallet / crypto) --}}
                    <div x-show="couponApplied && processingFee() <= 0" x-cloak class="mt-4 flex items-center justify-between border-t border-zinc-100 dark:border-white/10 pt-4 text-sm">
                        <span class="font-medium text-emerald-600" x-text="'Discount (' + couponApplied + ')'"></span>
                        <span class="font-semibold tabular-nums text-emerald-600" x-text="'-' + $store.cart.pay(couponDiscountDisplay)"></span>
                    </div>

                    {{-- Total.
                         The crypto "Network Fee" and "Crypto Processing Fee" lines that
                         used to sit above this were display-only: they were never added
                         to the amount sent to the gateway, so the figure quoted here
                         disagreed with the one on the payment screen by roughly 4%.
                         They cannot simply be made real either — the gateway already
                         grosses the amount up to cover its own fees, so adding our own
                         estimate on top would charge the customer for the same fees
                         twice. The order total below is the price of the goods; the
                         exact crypto amount is quoted once, on the payment screen. --}}
                    <div class="mt-5 flex items-center justify-between border-t border-zinc-100 dark:border-white/10 pt-5" :class="processingFee() > 0 && 'mt-3 border-t-0 pt-0'">
                        {{-- "Total amount", not "Total amount to pay": for crypto the
                             figure actually payable is quoted on the next screen, straight
                             from the gateway, and promising this is the amount to pay would
                             recreate the two-numbers problem in words. --}}
                        <span class="text-base font-bold text-zinc-900 dark:text-white">Total amount</span>
                        <span x-data="valueFlip()" x-effect="totalLabel(); flash()" class="inline-block text-lg font-extrabold tabular-nums text-zinc-900 dark:text-white" x-text="totalLabel()">0.00</span>
                    </div>

                    {{-- The "exact amount is confirmed on the next step" notice that used to
                         sit here said the same thing as the line under the coin picker, so it
                         was saying it twice on one screen. The picker line is the better place
                         for it — it sits with the choice it describes. --}}

                    {{-- Crypto safety warning --}}
                    <div x-show="method === 'crypto'" x-cloak class="mt-2 flex items-start gap-2 rounded-[10px] bg-red-50 px-3.5 py-3 dark:bg-red-900/20">
                        <svg class="mt-0.5 h-4 w-4 shrink-0 text-red-600 dark:text-red-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m9-.75a9 9 0 11-18 0 9 9 0 0118 0zm-9 3.75h.008v.008H12v-.008z"/>
                        </svg>
                        <p class="text-xs text-red-700 dark:text-red-300">
                            Send the exact amount shown to the address provided on the next step. Sending a
                            <span class="font-bold">different coin</span> or on a
                            <span class="font-bold">different network</span> will result in
                            <span class="font-bold">loss of your funds</span>.
                        </p>
                    </div>


                    {{-- Continue is signed-in-only: guest purchase is removed.
                         A guest hits the sign-in wall instead; their cart
                         merges into the account at sign-in and the modal
                         returns them right here to pay. --}}
                    @auth
                        <button type="submit" :disabled="!canContinue() || submitting || backoffRemaining > 0"
                            class="mt-4 flex w-full items-center justify-center gap-2 rounded-[12px] bg-blue-600 px-4 py-3.5 text-base font-bold text-white shadow-md transition-colors hover:bg-blue-700 disabled:cursor-not-allowed disabled:opacity-50 disabled:hover:bg-blue-600">
                            <template x-if="backoffRemaining > 0">
                                <span class="flex items-center gap-2" x-text="'Please wait ' + backoffRemaining + ' seconds before trying again'"></span>
                            </template>
                            <template x-if="!submitting && backoffRemaining === 0">
                                <span class="flex items-center gap-2">
                                    Continue to payment
                                </span>
                            </template>
                            <template x-if="submitting && backoffRemaining === 0">
                                <span class="flex items-center gap-2">
                                    <svg class="h-5 w-5 animate-spin" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                                        <circle class="opacity-30" cx="12" cy="12" r="9" stroke="currentColor" stroke-width="3"/>
                                        <path class="opacity-90" fill="currentColor" d="M12 3a9 9 0 0 1 9 9h-3a6 6 0 0 0-6-6V3z"/>
                                    </svg>
                                    Processing
                                </span>
                            </template>
                        </button>
                    @else
                        <button type="button" @click="$dispatch('open-auth-modal', { mode: 'login', returnTo: window.location.pathname + window.location.search })" class="mt-4 flex w-full items-center justify-center rounded-[12px] bg-blue-600 px-4 py-3.5 text-base font-bold text-white shadow-md transition-colors hover:bg-blue-700">
                            Sign in to pay
                        </button>
                        <p class="mt-2 text-center text-xs text-zinc-600 dark:text-zinc-400">You need an account to complete a purchase. Your cart is saved and comes with you.</p>
                    @endauth

                    <p class="mt-3 flex items-center justify-center gap-1.5 text-xs text-zinc-600 dark:text-zinc-400">
                        <svg class="h-4 w-4 text-emerald-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75L11.25 15 15 9.75M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
                        </svg>
                        Secure checkout. Codes delivered instantly.
                    </p>
                </form>

            </div>
            
            {{-- Checkout Payment Wizard Modal --}}
            <div
                x-show="open"
                x-cloak
                x-on:keydown.escape.window="closeModal()"
                class="fixed inset-0 z-[80] flex items-center justify-center p-4"
            >
                <!-- Backdrop -->
                <div
                    x-show="open"
                    x-transition:enter="ease-out duration-300"
                    x-transition:enter-start="opacity-0"
                    x-transition:enter-end="opacity-100"
                    x-transition:leave="ease-in duration-200"
                    x-transition:leave-start="opacity-100"
                    x-transition:leave-end="opacity-0"
                    class="fixed inset-0 bg-zinc-950/40 backdrop-blur-md"
                    @click="closeModal()"
                ></div>

                <!-- Modal Content Wrapper. Widens during 3DS so the bank's authentication
                     iframe (designed for ~600x800) isn't squeezed into a 448px shell. -->
                <div
                    x-show="open"
                    x-transition:enter="ease-out duration-300"
                    x-transition:enter-start="opacity-0 translate-y-4 sm:translate-y-0 sm:scale-95"
                    x-transition:enter-end="opacity-100 translate-y-0 sm:scale-100"
                    x-transition:leave="ease-in duration-200"
                    x-transition:leave-start="opacity-100 translate-y-0 sm:scale-100"
                    x-transition:leave-end="opacity-0 translate-y-4 sm:translate-y-0 sm:scale-95"
                    :class="paymentState === 'action_3ds' ? 'max-w-lg' : 'max-w-md'"
                    class="relative w-full transform overflow-hidden rounded-[24px] bg-white p-6 shadow-2xl transition-all duration-300 border border-zinc-100"
                    role="dialog"
                    aria-modal="true"
                >
                    <!-- Close button -->
                    <x-close-button @click="closeModal()" class="absolute right-4 top-4" />

                    <!-- Active Payment Session Details & Wizard -->
                    <div x-show="session || paymentState === 'error' || paymentState === 'kyc_required' || paymentState === 'email_notice'" class="mt-2">
                        <!-- Countdown timer banner -->
                        <div x-show="['awaiting_transfer', 'awaiting_confirmation', 'action_pin', 'action_otp', 'action_3ds'].includes(paymentState)" 
                             class="mb-5 flex items-center justify-between rounded-[12px] bg-amber-50 px-4 py-2.5 text-xs font-semibold text-amber-800 ring-1 ring-amber-200 dark:bg-amber-500/10 dark:text-amber-200 dark:ring-amber-500/30">
                            <span class="flex items-center gap-1.5">
                                <svg class="h-4 w-4 animate-pulse text-amber-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6h4.5m4.5 0a9 9 0 11-18 0 9 9 0 0118 0z"/>
                                </svg>
                                Complete payment within:
                            </span>
                            <span class="font-extrabold text-sm tabular-nums text-amber-700" x-text="timerText"></span>
                        </div>

                        <!-- Card Auth: PIN Challenge -->
                        <div x-show="paymentState === 'action_pin'">
                            <h3 class="text-sm font-bold text-zinc-900 dark:text-white mb-2">Card PIN Required</h3>
                            <p class="text-xs text-zinc-600 dark:text-zinc-400 mb-4">Enter your card 4-digit security PIN to authorize payment.</p>
                            <div class="space-y-4">
                                <input type="password" x-model="pinValue" maxlength="4" placeholder="••••" class="w-full rounded-[12px] border border-zinc-200 px-3 py-3 text-center text-lg font-bold tracking-widest text-zinc-900 dark:text-white focus:border-blue-500 focus:ring-2 focus:ring-blue-500/15">
                                <button type="button" @click="paySession('card', cardDetails)" class="w-full rounded-[12px] bg-blue-600 py-3 text-sm font-semibold text-white hover:bg-blue-700">
                                    Confirm PIN
                                </button>
                            </div>
                        </div>

                        <!-- Wallet Auth: Transaction PIN Challenge -->
                        <div x-show="paymentState === 'wallet_pin'">
                            <h3 class="text-sm font-bold text-zinc-900 dark:text-white mb-2">Transaction PIN Required</h3>
                            <p class="text-xs text-zinc-600 dark:text-zinc-400 mb-4">Enter your 4-digit transaction PIN to authorize this payment from your wallet balance.</p>
                            <div class="space-y-4">
                                <input type="password" inputmode="numeric" x-ref="walletPinInput" x-model="walletPin" maxlength="4" placeholder="••••"
                                    @keydown.enter.prevent="authorizeWalletPayment()"
                                    class="w-full rounded-[12px] border border-zinc-200 px-3 py-3 text-center text-lg font-bold tracking-widest text-zinc-900 dark:text-white focus:border-blue-500 focus:ring-2 focus:ring-blue-500/15">
                                <p x-show="errorMessage" x-cloak class="text-center text-xs text-red-600" x-text="errorMessage"></p>
                                <button type="button" @click="authorizeWalletPayment()" :disabled="authorizingWallet"
                                    class="flex w-full items-center justify-center gap-2 rounded-[12px] bg-blue-600 py-3 text-sm font-semibold text-white hover:bg-blue-700 disabled:opacity-60">
                                    <svg x-show="authorizingWallet" class="h-4 w-4 animate-spin" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
                                    </svg>
                                    <span x-text="authorizingWallet ? 'Authorizing...' : 'Authorize payment'"></span>
                                </button>
                                <a href="{{ route('dashboard.security') }}" class="block text-center text-[11px] font-medium text-zinc-500 underline underline-offset-2 hover:text-zinc-700">Manage your transaction PIN</a>
                            </div>
                        </div>

                        <!-- Card Auth: OTP Challenge -->
                        <div x-show="paymentState === 'action_otp'">
                            <h3 class="text-sm font-bold text-zinc-900 dark:text-white mb-2">OTP Verification</h3>
                            <p class="text-xs text-zinc-600 dark:text-zinc-400 mb-4" x-text="actionMessage"></p>
                            <div class="space-y-4">
                                <input type="text" x-model="otpValue" placeholder="123456" class="w-full rounded-[12px] border border-zinc-200 px-3 py-3 text-center text-lg font-bold tracking-widest text-zinc-900 dark:text-white focus:border-blue-500 focus:ring-2 focus:ring-blue-500/15">
                                <button type="button" @click="paySession('card', cardDetails)" class="w-full rounded-[12px] bg-blue-600 py-3 text-sm font-semibold text-white hover:bg-blue-700">
                                    Verify OTP
                                </button>
                            </div>
                        </div>

                        <!-- Card Auth: 3D Secure Redirect -->
                        <div x-show="paymentState === 'action_3ds'">
                            <h3 class="text-sm font-bold text-zinc-900 dark:text-white mb-2">Secure Verification</h3>
                            <p class="text-xs text-zinc-600 dark:text-zinc-400 mb-4">Please complete the secure authentication inside the window below.</p>

                            <div class="w-full overflow-hidden rounded-[12px] border border-zinc-200 bg-zinc-50 h-[55vh] min-h-[400px] max-h-[520px]">
                                <iframe :src="session?.payment_payload?.redirect_url" class="h-full w-full border-0" allow="payment"></iframe>
                            </div>

                            <button type="button" @click="verifyPayment()" :disabled="verifying" class="mt-4 flex w-full items-center justify-center gap-2 rounded-[12px] bg-blue-600 py-3 text-sm font-semibold text-white hover:bg-blue-700 disabled:opacity-60">
                                <svg x-show="verifying" class="h-4 w-4 animate-spin" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
                                </svg>
                                <span x-text="verifying ? 'Verifying...' : 'I Have Completed Payment'"></span>
                            </button>
                            <p x-show="errorMessage" x-cloak class="mt-2 text-center text-xs text-amber-600" x-text="errorMessage"></p>
                        </div>

                        <!-- Bank Transfer virtual accounts display or Crypto invoice -->
                        <div x-show="paymentState === 'awaiting_transfer'">
                            <!-- If Bank Transfer details -->
                            <template x-if="session?.payment_payload?.bank_details || session?.payment_payload?.account_number">
                                <div>
                                    <h3 class="text-sm font-bold text-zinc-900 dark:text-white mb-2">Virtual Bank Transfer</h3>
                                    <p class="text-xs text-zinc-600 dark:text-zinc-400 mb-4">Please make a transfer to the temporary virtual account below:</p>

                                    <div class="bg-zinc-50 border border-zinc-200 rounded-[12px] p-4 space-y-3 shadow-inner">
                                        <div class="flex justify-between items-center text-xs">
                                            <span class="text-zinc-500 font-medium">Bank Name</span>
                                            <span class="font-bold text-zinc-900 dark:text-white" x-text="bankDetails?.bank_name"></span>
                                        </div>
                                        <div class="flex justify-between items-center text-xs">
                                            <span class="text-zinc-500 font-medium">Account Number</span>
                                            <div class="flex items-center gap-2">
                                                <span class="font-extrabold text-zinc-900 dark:text-white text-sm tracking-wider tabular-nums bg-white px-2 py-1 rounded-[12px] border border-zinc-150" x-text="bankDetails?.account_number"></span>
                                                <button type="button" @click="copyToClipboard(bankDetails?.account_number, 'account')" class="text-blue-600 hover:text-blue-800 text-[10px] font-bold bg-blue-50 px-2 py-1 rounded-[12px] transition-all">
                                                    <span x-show="!copiedStates['account']">Copy</span>
                                                    <span x-show="copiedStates['account']" class="text-emerald-600 font-bold">Copied!</span>
                                                </button>
                                            </div>
                                        </div>
                                        <div class="flex justify-between items-center text-xs">
                                            <span class="text-zinc-500 font-medium">Account Name</span>
                                            <span class="font-bold text-zinc-900 dark:text-white" x-text="bankDetails?.account_name || 'Roddy Technologies'"></span>
                                        </div>
                                        <div class="flex justify-between items-center text-xs border-t border-zinc-200 pt-3">
                                            <span class="text-zinc-500 font-medium">Amount</span>
                                            <span class="font-extrabold text-blue-700 text-base tabular-nums" x-text="session?.currency + ' ' + Number(bankDetails?.amount || session?.amount).toFixed(2)"></span>
                                        </div>
                                    </div>
                                </div>
                            </template>

                            <!-- If Crypto details -->
                            <template x-if="session?.payment_payload?.qr_payload || session?.payment_payload?.pay_address">
                                <div>
                                    <h3 class="text-sm font-bold text-zinc-900 dark:text-white text-center mb-1">Crypto Payment Details</h3>

                                    {{-- The amount leads, because it is the one figure that
                                         must be exact. It comes straight from the gateway and
                                         is the same number quoted for the rest of this
                                         payment — it is never recalculated locally. --}}
                                    <div class="mt-3 rounded-[12px] border border-blue-200 bg-blue-50 px-4 py-3 text-center dark:border-blue-500/30 dark:bg-blue-900/20">
                                        <span class="block text-[10px] font-bold uppercase tracking-wider text-blue-700/70 dark:text-blue-300/70">Send exactly</span>
                                        <div class="mt-1 flex items-center justify-center gap-2">
                                            <span class="text-xl font-extrabold tabular-nums tracking-tight text-blue-900 dark:text-blue-100" x-text="session?.payment_payload?.pay_amount"></span>
                                            <span class="text-sm font-bold uppercase text-blue-700 dark:text-blue-300" x-text="session?.payment_payload?.pay_currency"></span>
                                            <button type="button" @click="copyToClipboard(session?.payment_payload?.pay_amount, 'amount_crypto')" class="rounded-[12px] bg-white/80 px-2 py-1 text-[10px] font-bold text-blue-700 transition-all hover:bg-white">
                                                <span x-show="!copiedStates['amount_crypto']">Copy</span>
                                                <span x-show="copiedStates['amount_crypto']" class="font-bold text-emerald-600">Copied!</span>
                                            </button>
                                        </div>
                                        <p class="mt-1 text-[10px] text-blue-700/80 dark:text-blue-300/80">All fees included. Your wallet must send this exact amount.</p>
                                    </div>

                                    <div class="mt-4 flex flex-col items-center gap-4 bg-zinc-50 p-4 border border-zinc-200 rounded-[12px] shadow-inner">
                                        <!-- QR Code, rendered by us -->
                                        <div class="flex shrink-0 flex-col items-center rounded-[12px] bg-white p-3 border border-zinc-150 shadow-sm">
                                            <img
                                                :src="session?.id ? ('/api/payment-sessions/' + session.id + '/crypto-qr') : ''"
                                                alt="Payment QR code"
                                                class="h-32 w-32 object-contain"
                                            />
                                            <span class="mt-1.5 text-[9px] font-bold uppercase tracking-wider text-zinc-400">Scan to pay</span>
                                        </div>

                                        <!-- Details -->
                                        <div class="w-full space-y-3.5 text-xs">
                                            <div>
                                                <span class="text-[10px] font-semibold text-zinc-400 uppercase tracking-wider block mb-1">Cryptocurrency / Network</span>
                                                <div class="flex items-center gap-1.5">
                                                    <span class="rounded-[12px] bg-blue-50 px-2 py-0.5 font-bold text-blue-700 uppercase" x-text="session?.payment_payload?.pay_currency"></span>
                                                    <span class="text-[10px] font-medium text-zinc-500 uppercase" x-text="'Network: ' + (session?.payment_payload?.network || '')"></span>
                                                </div>
                                            </div>
                                            <div>
                                                <span class="text-[10px] font-semibold text-zinc-400 uppercase tracking-wider block mb-1">Deposit Address</span>
                                                <div class="mt-1 flex items-center gap-1.5">
                                                    <input type="text" readonly :value="session?.payment_payload?.pay_address" class="w-full bg-white px-2.5 py-1.5 rounded-[12px] border border-zinc-200 text-[10px] text-zinc-800 font-mono select-all outline-none">
                                                    <button type="button" @click="copyToClipboard(session?.payment_payload?.pay_address, 'address')" class="text-blue-600 hover:text-blue-800 text-[10px] font-bold shrink-0 bg-blue-50 px-2.5 py-1.5 rounded-[12px] transition-all border border-blue-100">
                                                        <span x-show="!copiedStates['address']">Copy</span>
                                                        <span x-show="copiedStates['address']" class="text-emerald-600 font-bold">Copied!</span>
                                                    </button>
                                                </div>
                                            </div>

                                            {{-- Memo / destination tag. Coins that require one cannot be
                                                 credited automatically without it, so when the gateway
                                                 returns a tag it is shown as loudly as the address. --}}
                                            <template x-if="session?.payment_payload?.payin_extra_id">
                                                <div class="rounded-[12px] border border-amber-300 bg-amber-50 p-2.5 dark:border-amber-500/30 dark:bg-amber-900/20">
                                                    <span class="mb-1 block text-[10px] font-bold uppercase tracking-wider text-amber-800 dark:text-amber-300">Memo / Destination tag — required</span>
                                                    <div class="mt-1 flex items-center gap-1.5">
                                                        <input type="text" readonly :value="session?.payment_payload?.payin_extra_id" class="w-full rounded-[12px] border border-amber-200 bg-white px-2.5 py-1.5 font-mono text-[10px] text-zinc-800 outline-none select-all">
                                                        <button type="button" @click="copyToClipboard(session?.payment_payload?.payin_extra_id, 'memo')" class="shrink-0 rounded-[12px] border border-amber-200 bg-amber-100 px-2.5 py-1.5 text-[10px] font-bold text-amber-800 transition-all">
                                                            <span x-show="!copiedStates['memo']">Copy</span>
                                                            <span x-show="copiedStates['memo']" class="font-bold text-emerald-600">Copied!</span>
                                                        </button>
                                                    </div>
                                                    <p class="mt-1.5 text-[10px] text-amber-800/90 dark:text-amber-300/90">Without this tag your payment cannot be matched to your order automatically.</p>
                                                </div>
                                            </template>
                                        </div>
                                    </div>
                                </div>
                            </template>

                            {{-- Short payment. Most shortfalls are not the customer's fault —
                                 exchanges routinely deduct a withdrawal fee from the amount
                                 sent — so this states plainly what is outstanding and offers a
                                 one-tap way to settle it, rather than leaving them on a spinner
                                 waiting for a confirmation that is never coming. --}}
                            <template x-if="session?.underpayment">
                                <div class="mt-5 rounded-[12px] border border-amber-300 bg-amber-50 px-4 py-4 dark:border-amber-500/30 dark:bg-amber-900/20">
                                    <p class="text-sm font-bold text-amber-900 dark:text-amber-200">Your payment was short</p>
                                    <div class="mt-2 space-y-1 text-xs text-amber-800 dark:text-amber-300">
                                        <div class="flex justify-between gap-3">
                                            <span>Expected</span>
                                            <span class="font-semibold tabular-nums" x-text="session.underpayment.expected + ' ' + session.underpayment.currency"></span>
                                        </div>
                                        <div class="flex justify-between gap-3">
                                            <span>Received</span>
                                            <span class="font-semibold tabular-nums" x-text="session.underpayment.received + ' ' + session.underpayment.currency"></span>
                                        </div>
                                        <div class="flex justify-between gap-3 border-t border-amber-300/60 pt-1 font-bold">
                                            <span>Still owing</span>
                                            <span class="tabular-nums" x-text="session.underpayment.shortfall + ' ' + session.underpayment.currency"></span>
                                        </div>
                                    </div>
                                    <p class="mt-2 text-[11px] text-amber-800/90 dark:text-amber-300/90">
                                        This usually happens when an exchange deducts its withdrawal fee from the amount sent.
                                    </p>

                                    <template x-if="session.underpayment.can_top_up">
                                        <button type="button" @click="requestTopUp()" :disabled="topUpLoading"
                                            class="mt-3 w-full rounded-[12px] bg-amber-600 px-4 py-2.5 text-sm font-bold text-white transition-colors hover:bg-amber-700 disabled:opacity-60">
                                            <span x-show="!topUpLoading">Pay the balance</span>
                                            <span x-show="topUpLoading">Creating payment…</span>
                                        </button>
                                    </template>
                                    <template x-if="!session.underpayment.can_top_up">
                                        <p class="mt-3 text-[11px] font-semibold text-amber-900 dark:text-amber-200">
                                            Please contact support with your order number to complete this payment.
                                        </p>
                                    </template>
                                    <p x-show="topUpError" x-cloak class="mt-2 text-[11px] font-semibold text-red-700 dark:text-red-400" x-text="topUpError"></p>
                                </div>
                            </template>

                            {{-- A lapsed quote is not a failed payment: coins already sent still
                                 credit once they confirm. Saying otherwise would panic customers
                                 whose transaction is simply still on-chain. --}}
                            <div x-show="quoteExpired && !session?.underpayment" x-cloak class="mt-5 rounded-[12px] border border-amber-300 bg-amber-50 px-4 py-3 text-center dark:border-amber-500/30 dark:bg-amber-900/20">
                                <p class="text-xs font-bold text-amber-900 dark:text-amber-200">This quote has expired</p>
                                <p class="mt-1 text-[11px] text-amber-800 dark:text-amber-300">
                                    If you already sent the payment, keep this page open — it will still be credited once the network confirms it.
                                    If you have not paid yet, start a new checkout to get a fresh amount.
                                </p>
                            </div>

                            <div x-show="!quoteExpired && !session?.underpayment" class="flex flex-col items-center mt-5 text-center">
                                <svg class="h-5 w-5 animate-spin text-blue-600" fill="none" viewBox="0 0 24 24">
                                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
                                </svg>
                                <p class="text-xs font-semibold text-zinc-800 mt-2">Waiting for transfer...</p>
                                <p class="text-[10px] text-zinc-500 mt-1">Status updates automatically as soon as payment is detected.</p>
                            </div>
                        </div>

                        <!-- Mobile money push prompt -->
                        <div x-show="paymentState === 'awaiting_confirmation'">
                            <h3 class="text-sm font-bold text-zinc-900 dark:text-white mb-2">Authorize on Phone</h3>
                            <p class="text-xs text-zinc-600 dark:text-zinc-400 mb-4" x-text="actionMessage"></p>

                            <div class="flex flex-col items-center py-6 text-center">
                                <span class="flex h-16 w-16 items-center justify-center rounded-[12px] bg-blue-50 ring-8 ring-blue-100/50 mb-5">
                                    <svg class="h-8 w-8 text-blue-600 animate-bounce" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 18h.01M8 21h8a2 2 0 002-2V5a2 2 0 00-2-2H8a2 2 0 00-2 2v14a2 2 0 002 2z"/>
                                    </svg>
                                </span>
                                <svg class="h-6 w-6 animate-spin text-blue-600" fill="none" viewBox="0 0 24 24">
                                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
                                </svg>
                                <p class="text-xs font-semibold text-zinc-800 mt-3">Verifying payment authorization...</p>
                            </div>
                        </div>

                        <!-- Processing state -->
                        <div x-show="paymentState === 'processing'" class="flex flex-col items-center py-8 text-center">
                            <svg class="h-10 w-10 animate-spin text-blue-600" fill="none" viewBox="0 0 24 24">
                                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
                            </svg>
                            <p class="mt-4 text-sm font-bold text-zinc-900 dark:text-white" x-text="method === 'apple_pay' ? 'Authorizing with Apple Pay...' : 'Processing transaction...'"></p>
                            <p class="mt-1 text-xs text-zinc-500">Please do not close this window.</p>
                        </div>

                        <!-- Success state: same animated tick as the order-complete
                             page so every payment method celebrates identically. -->
                        <div x-show="paymentState === 'success'" class="flex flex-col items-center py-8 text-center">
                            <x-success-tick />
                            <h3 class="mt-4 text-base font-bold text-zinc-950">Payment Complete!</h3>
                            <p class="mt-1.5 text-xs text-zinc-600 dark:text-zinc-400 font-medium">Your order is confirmed. Redirecting now...</p>
                        </div>

                        <!-- Error state: animated red cross, the tick's counterpart. -->
                        <div x-show="paymentState === 'error'" class="flex flex-col items-center py-6 text-center">
                            <x-error-cross />
                            <h3 class="mt-4 text-sm font-bold text-zinc-900 dark:text-white">Payment Failed</h3>
                            <p class="mt-1.5 text-xs text-red-600 px-4" x-html="errorMessage"></p>

                            <button type="button" @click="closeModal()" class="mt-6 rounded-[12px] bg-zinc-100 px-5 py-2.5 text-xs font-semibold text-zinc-800 hover:bg-zinc-200">
                                Close &amp; Modify Details
                            </button>
                        </div>

                        {{-- Email not verified: a pre-payment requirement, shown as
                             a calm notice (amber envelope), never the red failure
                             state. Nothing was written; the link was already re-sent. --}}
                        <div x-show="paymentState === 'email_notice'" x-cloak class="flex flex-col items-center py-6 text-center">
                            <div class="flex h-12 w-12 items-center justify-center rounded-full bg-amber-50 dark:bg-amber-500/15">
                                <svg class="h-6 w-6 text-amber-600 dark:text-amber-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75" aria-hidden="true">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M21.75 6.75v10.5a2.25 2.25 0 01-2.25 2.25h-15a2.25 2.25 0 01-2.25-2.25V6.75m19.5 0A2.25 2.25 0 0019.5 4.5h-15a2.25 2.25 0 00-2.25 2.25m19.5 0v.243a2.25 2.25 0 01-1.07 1.916l-7.5 4.615a2.25 2.25 0 01-2.36 0L3.32 8.91a2.25 2.25 0 01-1.07-1.916V6.75"/>
                                </svg>
                            </div>
                            <h3 class="mt-4 text-sm font-bold text-zinc-900 dark:text-white">Verify your email to continue</h3>
                            <p class="mt-1.5 text-xs text-zinc-600 dark:text-zinc-300 px-4" x-text="errorMessage"></p>

                            <p x-show="resendFlash" x-cloak class="mt-3 rounded-[10px] bg-emerald-50 px-3 py-2 text-xs text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-300" x-text="resendFlash"></p>

                            <div class="mt-6 flex items-center justify-center gap-2.5">
                                <button
                                    type="button"
                                    @click="resendVerification()"
                                    :disabled="resendRemaining > 0 || resendBusy"
                                    class="rounded-[12px] px-5 py-2.5 text-xs font-semibold text-zinc-700 ring-1 ring-zinc-300 transition-colors hover:bg-zinc-100 disabled:cursor-not-allowed disabled:opacity-50 dark:text-zinc-200 dark:ring-white/15 dark:hover:bg-white/5"
                                    x-text="resendBusy ? 'Sending...' : (resendRemaining > 0 ? 'Resend ' + resendLabel(resendRemaining) : 'Resend email')"
                                ></button>
                                <button type="button" @click="closeModal()" class="rounded-[12px] bg-blue-600 px-8 py-2.5 text-xs font-semibold text-white hover:bg-blue-700">
                                    Got it
                                </button>
                            </div>
                        </div>

                        {{-- KYC step-up: the risk/KYC gate asked for identity
                             verification. The customer taps Verify to open Smile ID;
                             the checkout resubmits automatically once verified. --}}
                        <div x-show="paymentState === 'kyc_required'" x-cloak class="flex flex-col items-center py-6 text-center">
                            <div class="flex h-12 w-12 items-center justify-center rounded-full bg-blue-50 dark:bg-blue-500/15">
                                <svg class="h-6 w-6 text-blue-600 dark:text-blue-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 3l7 3v5c0 4.5-3 7.9-7 9-4-1.1-7-4.5-7-9V6l7-3z"/>
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M9.5 12l1.8 1.8L15 10"/>
                                </svg>
                            </div>
                            <h3 class="mt-4 text-sm font-bold text-zinc-900 dark:text-white">Quick verification needed</h3>
                            <p class="mt-1.5 text-xs text-zinc-600 dark:text-zinc-300 px-4" x-text="kycMessage"></p>

                            <button type="button" @click="verifyIdentity()" class="mt-6 rounded-[12px] bg-blue-600 px-8 py-2.5 text-xs font-semibold text-white hover:bg-blue-700">
                                Verify
                            </button>
                            <button type="button" @click="closeModal()" class="mt-2.5 text-xs font-medium text-zinc-500 hover:text-zinc-700 dark:text-zinc-400 dark:hover:text-zinc-200">
                                Not now
                            </button>
                        </div>
                    </div>
                </div>
            </div>

        </div>
    </div>
    </div>

    <script>
        window.checkoutPage = function (cryptoRates, walletBalances, isLoggedIn, rcoinConfig, hasTransactionPin, paymentFees) {
            return {
                method: 'card',
                crypto: '',
                submitting: false,
                cryptoRates: cryptoRates || {},
                walletBalances: walletBalances || {},
                isLoggedIn: isLoggedIn || false,
                hasTransactionPin: hasTransactionPin || false,
                paymentFees: paymentFees || { fee_free: [], methods: {}, default: { transaction: 0, international: 0 } },

                // Coupon live-preview state. couponApplied holds the validated
                // code once Apply succeeds; the discount is shown on the total
                // and the same code submits with the form for server re-apply.
                couponCode: '',
                couponApplied: '',
                couponDiscountUsd: 0,
                couponDiscountDisplay: 0,
                couponError: '',
                couponLoading: false,

                // Set when the gateway's quote window lapses before the customer sends.
                // Distinct from an error: coins sent late still credit on confirmation.
                quoteExpired: false,

                // Underpayment recovery.
                topUpLoading: false,
                topUpError: '',

                async applyCoupon() {
                    const code = (this.couponCode || '').trim();
                    if (! code || this.couponLoading) { return; }
                    this.couponLoading = true;
                    this.couponError = '';
                    try {
                        const res = await fetch('/coupon/preview', {
                            method: 'POST',
                            headers: {
                                'Content-Type': 'application/json',
                                'Accept': 'application/json',
                                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '',
                            },
                            credentials: 'same-origin',
                            body: JSON.stringify({ code, currency: this.$store.cart.currency || 'USD' }),
                        });
                        const json = await res.json();
                        if (json.valid) {
                            this.couponApplied = json.code;
                            this.couponDiscountUsd = Number(json.discount_usd) || 0;
                            this.couponDiscountDisplay = Number(json.discount_display) || 0;
                            this.couponError = '';
                        } else {
                            this.removeCoupon();
                            this.couponError = json.message || 'This coupon could not be applied.';
                        }
                    } catch (e) {
                        this.removeCoupon();
                        this.couponError = 'Could not check that coupon. Please try again.';
                    } finally {
                        this.couponLoading = false;
                    }
                },

                removeCoupon() {
                    this.couponApplied = '';
                    this.couponDiscountUsd = 0;
                    this.couponDiscountDisplay = 0;
                },

                // Rcoin redemption state — three values:
                //   ''     = off (default)
                //   '1'    = partial, capped at redemption_max_percentage
                //   'full' = pay the entire order with Rcoin (rewards-page
                //            convert flow). Only enabled when balance × rate
                //            ≥ order total.
                // CheckoutService reads `apply_rcoin` and branches accordingly.
                applyRcoin: '',

                // Gateway kill switches, resolved server-side so the greyed
                // tiles and the server guard can never disagree. Tiles for a
                // disabled gateway stay VISIBLE but grey: disappearing tiles
                // read as "this store does not take cards", grey reads as
                // what it is, a temporary outage.
                //
                // Fiat methods come pre-resolved per currency by
                // FiatGatewayRouter (Flutterwave first, then any enabled
                // backup), so a tile only greys when NO gateway can charge
                // that method in the selected currency. Crypto and wallet
                // keep the simple one-flag check.
                disabledGateways: @js(\App\Support\FeatureFlag::disabledGateways()),
                methodGateways: @js(\App\Support\FeatureFlag::METHOD_GATEWAYS),
                fiatMethods: @js(array_keys(\App\Domain\Payment\Support\FiatGatewayRouter::METHOD_PRIORITY)),
                fiatUnavailableByCurrency: @js(\App\Domain\Payment\Support\FiatGatewayRouter::unavailableFiatMethodsByCurrency()),

                // Methods this cart's compliance categories make ineligible, per
                // currency. Resolved server-side by the payment eligibility gate
                // and empty while it runs in shadow mode, so the tiles only change
                // once enforcement is switched on. Greys a tile whose only serving
                // gateways are not approved to charge what is in the cart.
                complianceIneligibleByCurrency: @js($complianceIneligibleByCurrency ?? []),

                isMethodDisabled(key) {
                    const currency = this.$store.cart.currency;

                    const ineligible = this.complianceIneligibleByCurrency[currency]
                        ?? this.complianceIneligibleByCurrency['__fallback']
                        ?? [];
                    if (ineligible.includes(key)) {
                        return true;
                    }

                    if (this.fiatMethods.includes(key)) {
                        const unavailable = this.fiatUnavailableByCurrency[currency]
                            ?? this.fiatUnavailableByCurrency['__fallback']
                            ?? [];
                        return unavailable.includes(key);
                    }
                    return this.disabledGateways.includes(this.methodGateways[key] ?? '');
                },

                allMethods: [
                    { key: 'card',          label: 'Card',          desc: 'Visa, Mastercard',           icon: '/assets/credit%20card%20payment.webp' },
                    { key: 'mobile_money',  label: 'Mobile Money',  desc: 'MTN, Orange, Vodafone',      icon: '/assets/MOMO.svg' },
                    { key: 'bank_transfer', label: 'Bank Transfer', desc: 'Pay via virtual account',    icon: '/assets/Bank%20transfer.webp' },
                    // New: covers Flutterwave's "Pay With Bank" (internet banking
                    // redirect) for NGN / GBP / EUR.
                    { key: 'pay_with_bank', label: 'Pay With Bank', desc: 'Internet & mobile banking',  icon: '/assets/Bank%20transfer.webp' },
                    // New: USSD dial codes (Nigeria). Customer dials a code on
                    // their phone to authorise the payment.
                    { key: 'ussd',          label: 'USSD',          desc: 'Dial a code on your phone',  icon: '/assets/credit%20card%20payment.webp' },
                    // New: scan-to-pay QR (Nigerian banks).
                    { key: 'bank_qr',       label: 'Bank QR',       desc: 'Scan to pay in your bank app', icon: '/assets/credit%20card%20payment.webp' },
                    // New: NGN digital wallets (OPay / eNaira). Provider name
                    // hidden per project convention; admin reconciliation still
                    // sees the specific provider on the order.
                    { key: 'mobile_wallet', label: 'Mobile Wallet', desc: 'Pay with a Nigerian e-wallet', icon: '/assets/Wallet.svg' },
                    { key: 'apple_pay',     label: 'Apple Pay',     desc: 'Pay via Apple Wallet',       icon: '/assets/apply%20pay.webp' },
                    { key: 'crypto',        label: 'Crypto',        desc: 'USDT, BTC, ETH and more',    icon: '/assets/USDT.svg' },
                    { key: 'wallet',        label: 'Wallet',        desc: 'Pay with wallet balance',    icon: '/assets/Wallet.svg' }
                ],

                getFilteredMethods() {
                    const currency = this.$store.cart.currency;
                    // Currency -> allowed method keys. Mirrors what's enabled on
                    // the Flutterwave dashboard so we never offer a method that
                    // their modal would then reject. Order matters: it's the
                    // order tabs render in the grid.
                    // Ordering is by DOMINANT local method first so the customer
                    // sees their familiar option at top-left of the grid:
                    //   - Francophone Africa (XAF/XOF) → MTN MoMo first
                    //   - Nigeria (NGN) → Bank Transfer first (most-used FW method)
                    //   - Ghana / Kenya / Uganda / Rwanda → Mobile money first
                    //   - US / EU / UK → Card first, crypto for the savvy users
                    const mapping = {
                        'USD': ['card', 'crypto', 'apple_pay', 'wallet'],
                        'EUR': ['card', 'pay_with_bank', 'crypto', 'apple_pay', 'wallet'],
                        'GBP': ['card', 'pay_with_bank', 'crypto', 'apple_pay', 'wallet'],
                        'NGN': ['bank_transfer', 'card', 'pay_with_bank', 'ussd', 'bank_qr', 'mobile_wallet', 'apple_pay', 'crypto', 'wallet'],
                        'GHS': ['mobile_money', 'card', 'apple_pay', 'crypto', 'wallet'],
                        'XAF': ['mobile_money', 'card', 'apple_pay', 'crypto', 'wallet'],
                        'XOF': ['mobile_money', 'card', 'apple_pay', 'crypto', 'wallet'],
                        'KES': ['mobile_money', 'card', 'apple_pay', 'crypto', 'wallet'],
                        'UGX': ['mobile_money', 'card', 'apple_pay', 'crypto', 'wallet'],
                        'RWF': ['mobile_money', 'card', 'apple_pay', 'crypto', 'wallet'],
                    };
                    const allowedKeys = mapping[currency] || ['card', 'apple_pay', 'crypto'];
                    return this.allMethods.filter(m => {
                        if (m.key === 'wallet' && !this.isLoggedIn) {
                            return false;
                        }
                        if (m.key === 'apple_pay' && !this.applePayAvailable) {
                            return false;
                        }
                        return allowedKeys.includes(m.key);
                    });
                },

                // Wizard State
                open: false,
                session: null,
                selectedMethod: null,
                cardDetails: {
                    card_number: '',
                    cvv: '',
                    expiry_month: '',
                    expiry_year: '',
                    card_holder: ''
                },
                pinValue: '',
                otpValue: '',
                walletPin: '',
                authorizingWallet: false,
                momoDetails: {
                    phone_number: '',
                    network: ''
                },
                paymentState: 'idle',
                errorMessage: '',
                actionMessage: '',
                // KYC step-up: the Smile session + the form to resubmit once the
                // customer taps Verify and finishes identity verification.
                kycMessage: '',
                kycSmile: null,
                pendingForm: null,
                // Email-verification notice: server-driven resend countdown
                // (escalating 30s -> 1m -> 2m -> 5m -> 10m; UI mirrors it).
                resendRemaining: 0,
                resendTimerId: null,
                resendBusy: false,
                resendFlash: '',
                bankDetails: null,
                pollInterval: null,
                verifying: false,
                redirectUrl: '',

                cardBrand: 'unknown',
                cardExpiryRaw: '',
                timerInterval: null,
                timerText: '30:00',
                copiedStates: {},

                // Apple Pay availability — shown on Apple devices that have it
                // set up. We check two APIs:
                //   1. ApplePaySession (Safari-native, always preferred)
                //   2. PaymentRequest with 'https://apple.com/apple-pay'
                //      (Chrome / Edge on iOS 16+, other W3C-compliant browsers)
                // Either one proving capable is enough — our backend uses
                // Flutterwave's redirect flow, not a local ApplePaySession.
                applePayAvailable: false,

                init() {
                    this.rcoinConfig = rcoinConfig;
                    this.resetCardDetails();
                    this.detectApplePay();

                    // If the default method's gateway is switched off, land the
                    // customer on the first live one instead of a grey tile.
                    if (this.isMethodDisabled(this.method)) {
                        const alive = this.getFilteredMethods().filter(m => ! this.isMethodDisabled(m.key));
                        this.method = alive[0]?.key ?? null;
                    }
                    
                    this.$watch('$store.cart.currency', (newVal) => {
                        // Never auto-select a greyed-out (disabled gateway) tile.
                        const methods = this.getFilteredMethods().filter(m => ! this.isMethodDisabled(m.key));
                        const currentSupported = methods.some(m => m.key === this.method);
                        if (!currentSupported && methods.length > 0) {
                            this.method = methods[0].key;
                        }
                    });

                    // A basket or coupon change can push the order under the selected
                    // coin's minimum. Re-validating here means the customer is told
                    // immediately, rather than at the moment the server rejects them.
                    this.$watch('couponDiscountUsd', () => this.enforceCoinMinimum());
                    this.$watch('$store.cart.subtotalUsd', () => this.enforceCoinMinimum());
                },

                /**
                 * Detect Apple Pay support across browsers:
                 *  - Safari: window.ApplePaySession.canMakePayments()
                 *  - Chrome/Edge on iOS 16+: PaymentRequest with apple-pay method
                 * Our backend uses Flutterwave's redirect flow, so we only need
                 * to know the device *can* use Apple Pay — we don't create a
                 * local ApplePaySession ourselves.
                 */
                async detectApplePay() {
                    // 1. Safari-native check (fastest, synchronous)
                    try {
                        if (window.ApplePaySession
                            && typeof window.ApplePaySession.canMakePayments === 'function'
                            && window.ApplePaySession.canMakePayments()) {
                            this.applePayAvailable = true;
                            return;
                        }
                    } catch (_) {}

                    // 2. W3C PaymentRequest fallback (Chrome / Edge on iOS 16+)
                    try {
                        if (window.PaymentRequest) {
                            const methods = [{ supportedMethods: 'https://apple.com/apple-pay' }];
                            const details = { total: { label: 'Check', amount: { currency: 'USD', value: '0.01' } } };
                            const request = new PaymentRequest(methods, details);
                            if (typeof request.canMakePayment === 'function') {
                                this.applePayAvailable = await request.canMakePayment();
                                return;
                            }
                        }
                    } catch (_) {}

                    this.applePayAvailable = false;
                },

                detectCardType() {
                    let num = this.cardDetails.card_number.replace(/\D/g, '');
                    
                    // Auto-format card number as they type (e.g. 1234 5678 1234 5678)
                    let formatted = '';
                    for (let i = 0; i < num.length; i++) {
                        if (i > 0 && i % 4 === 0) {
                            formatted += ' ';
                        }
                        formatted += num[i];
                    }
                    this.cardDetails.card_number = formatted;

                    // Detect brand
                    if (num.startsWith('4')) {
                        this.cardBrand = 'visa';
                    } else if (/^(5[1-5]|222[1-9]|22[3-9]|2[3-6]|27[0-1]|2720)/.test(num)) {
                        this.cardBrand = 'mastercard';
                    } else if (/^(506[0-1]|507[8-9]|6500)/.test(num)) {
                        this.cardBrand = 'verve';
                    } else if (/^(34|37)/.test(num)) {
                        this.cardBrand = 'amex';
                    } else if (/^(6011|65)/.test(num)) {
                        this.cardBrand = 'discover';
                    } else if (/^(35)/.test(num)) {
                        this.cardBrand = 'jcb';
                    } else {
                        this.cardBrand = 'unknown';
                    }
                },

                formatExpiry() {
                    let exp = this.cardExpiryRaw.replace(/\D/g, '');
                    if (exp.length > 2) {
                        this.cardExpiryRaw = exp.slice(0, 2) + ' / ' + exp.slice(2, 4);
                    } else {
                        this.cardExpiryRaw = exp;
                    }
                    this.cardDetails.expiry_month = exp.slice(0, 2);
                    this.cardDetails.expiry_year = exp.slice(2, 4);
                },

                startCountdown() {
                    if (this.timerInterval) clearInterval(this.timerInterval);

                    // The gateway's own expiry wins. The session window is a provisional
                    // value set before a coin is chosen, and running the countdown on it
                    // used to expire the screen while the customer's transaction was
                    // still confirming on-chain.
                    const expiresAt = this.session?.payment_payload?.expires_at || this.session?.expires_at;
                    let targetTime;

                    if (expiresAt) {
                        targetTime = new Date(expiresAt).getTime();
                    } else {
                        targetTime = Date.now() + (20 * 60 * 1000);
                    }

                    const updateTimer = () => {
                        const now = Date.now();
                        const diff = targetTime - now;

                        if (diff <= 0) {
                            clearInterval(this.timerInterval);
                            this.timerText = '00:00';
                            // A lapsed quote is not a failed payment. Coins already sent
                            // are still credited when they confirm, so this must not
                            // present as an error that implies the money is lost.
                            this.quoteExpired = true;
                            return;
                        }

                        const minutes = Math.floor(diff / 60000);
                        const seconds = Math.floor((diff % 60000) / 1000);
                        this.timerText = `${minutes.toString().padStart(2, '0')}:${seconds.toString().padStart(2, '0')}`;
                    };
                    
                    updateTimer();
                    this.timerInterval = setInterval(updateTimer, 1000);
                },

                resetCardDetails() {
                    this.cardDetails = {
                        card_number: '',
                        cvv: '',
                        expiry_month: '',
                        expiry_year: '',
                        card_holder: ''
                    };
                    this.momoDetails = {
                        phone_number: '',
                        network: ''
                    };
                    this.cardBrand = 'unknown';
                    this.cardExpiryRaw = '';
                    this.copiedStates = {};
                },

                // Ask the server for a follow-up payment covering the outstanding
                // balance. The response is a fresh session carrying a new address and
                // amount, so the existing payment panel simply re-renders against it.
                async requestTopUp() {
                    if (this.topUpLoading || ! this.session?.id) {
                        return;
                    }

                    this.topUpLoading = true;
                    this.topUpError = '';

                    try {
                        const res = await fetch(`/api/payment-sessions/${this.session.id}/top-up`, {
                            method: 'POST',
                            headers: {
                                'Content-Type': 'application/json',
                                'Accept': 'application/json',
                                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '',
                            },
                        });
                        const body = await res.json();

                        if (! res.ok) {
                            this.topUpError = body.message || 'Could not create a top-up payment.';
                            return;
                        }

                        this.session = body.data || body;
                        this.quoteExpired = false;
                        this.startCountdown();
                    } catch (e) {
                        this.topUpError = 'Network error. Please try again.';
                    } finally {
                        this.topUpLoading = false;
                    }
                },

                coinMeta() {
                    return this.cryptoRates[this.crypto] || null;
                },

                // Order value in USD, which is the unit every coin minimum is
                // expressed in.
                orderUsd() {
                    return Math.max(0, Number(this.$store.cart.subtotalUsd || 0) - Number(this.couponDiscountUsd || 0));
                },

                // Whether this order clears the gateway's minimum for a coin.
                // Unknown minimum (never synced) counts as usable: an empty picker
                // is a worse failure than letting the gateway do the rejecting.
                coinUsable(code) {
                    const coin = this.cryptoRates[code];
                    if (! coin || coin.minUsd == null) {
                        return true;
                    }
                    const usd = this.orderUsd();

                    // An empty basket should not grey out the whole picker before the
                    // customer has anything to pay for.
                    return usd <= 0 || usd >= coin.minUsd;
                },

                coinMinLabel(code) {
                    const coin = this.cryptoRates[code];
                    if (! coin || coin.minUsd == null) {
                        return '';
                    }

                    return 'Min $' + Number(coin.minUsd).toFixed(2);
                },

                unusableCoinCount() {
                    return Object.keys(this.cryptoRates).filter((code) => ! this.coinUsable(code)).length;
                },

                // Clear a selection that the basket no longer supports — otherwise
                // removing an item could leave a now-invalid coin selected and the
                // customer would only find out when the server rejects it.
                enforceCoinMinimum() {
                    if (this.crypto && ! this.coinUsable(this.crypto)) {
                        this.crypto = '';
                    }
                },

                // Estimated gateway processing fee for the selected method
                // (config/payment_fees.php). The provider charges the customer
                // this on top of the order amount, so we disclose it before
                // payment. The international surcharge applies when the
                // checkout currency is not NGN (the account's home market).
                processingFee() {
                    const method = this.method;
                    if (method === 'crypto' || (this.paymentFees.fee_free || []).includes(method)) {
                        return 0;
                    }
                    const amount = Number(this.$store.cart.subtotal || 0);
                    if (! (amount > 0)) {
                        return 0;
                    }
                    const rates = (this.paymentFees.methods || {})[method] || this.paymentFees.default || { transaction: 0, international: 0 };
                    const international = (this.$store.cart.currency || 'NGN') !== 'NGN';
                    const pct = Number(rates.transaction || 0) + (international ? Number(rates.international || 0) : 0);

                    return Math.round(amount * pct) / 100;
                },

                // The order total, in the customer's display currency, for every
                // method including crypto.
                //
                // This used to render a locally-computed crypto figure for crypto
                // checkouts, which then disagreed with the amount the gateway actually
                // invoiced on the very next screen. There is no way to compute that
                // number correctly here: the gateway does not publish the formula it
                // uses to gross the amount up for fees, so the payable amount can only
                // be read back from the payment it creates. Quoting the order total in
                // fiat and the crypto amount exactly once — on the payment screen,
                // straight from the gateway — is the only way both screens can agree.
                totalLabel() {
                    const display = Math.max(0, Number(this.$store.cart.subtotal || 0) + this.processingFee() - Number(this.couponDiscountDisplay || 0));
                    return this.$store.cart.pay(display);
                },

                points() {
                    return this.$store.cart.estimated_rcoin_reward || 0;
                },

                hasSufficientWalletBalance() {
                    const balance = Number(this.walletBalances[this.$store.cart.currency] || 0);
                    const total = Number(this.$store.cart.subtotal);
                    return balance >= total;
                },
                
                backoffRemaining: 0,
                backoffInterval: null,
                
                startBackoff(seconds) {
                    this.backoffRemaining = seconds;
                    if (this.backoffInterval) clearInterval(this.backoffInterval);
                    this.backoffInterval = setInterval(() => {
                        if (this.backoffRemaining > 0) {
                            this.backoffRemaining--;
                        } else {
                            clearInterval(this.backoffInterval);
                        }
                    }, 1000);
                },

                canContinue() {
                    if (! this.$store.cart.hydrated || this.$store.cart.count === 0) {
                        return false;
                    }
                    // Never submit toward a switched-off gateway; the server
                    // guard would refuse it anyway, this just says so earlier.
                    if (! this.method || this.isMethodDisabled(this.method)) {
                        return false;
                    }
                    if (this.method === 'crypto') {
                        return !! this.crypto;
                    }
                    if (this.method === 'wallet') {
                        return this.hasSufficientWalletBalance();
                    }
                    return true;
                },

                async submitCheckout(e) {
                    if (this.method === 'wallet' && !this.hasTransactionPin) {
                        this.submitting = false;
                        this.paymentState = 'error';
                        this.errorMessage = 'You must set up a Wallet Transaction PIN before you can pay with your wallet. <a href="/dashboard/password" class="underline font-bold text-blue-600 hover:text-blue-700">Click here to set it up</a>.';
                        this.open = true;
                        return;
                    }

                    this.submitting = true;
                    this.errorMessage = '';
                    try {
                        const formData = new FormData(e.target);
                        const response = await fetch('{{ $checkoutProcessUrl }}', {
                            method: 'POST',
                            headers: {
                                'Accept': 'application/json',
                                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '',
                            },
                            body: formData
                        });

                        // Server may respond with HTML (e.g. a 302 redirect to the
                        // checkout page on an empty-cart / non-JSON-aware fallback,
                        // or a Laravel exception page). Detect that BEFORE calling
                        // .json() so the customer sees a useful message instead of
                        // the misleading "Network connection failed" caught below.
                        const contentType = response.headers.get('content-type') || '';
                        if (! contentType.includes('application/json')) {
                            this.submitting = false;
                            this.errorMessage = response.ok
                                ? 'Checkout returned an unexpected response. Refresh the page and try again.'
                                : 'Checkout failed (HTTP ' + response.status + '). Refresh the page and try again.';
                            alert(this.errorMessage);
                            return;
                        }

                        const resData = await response.json();

                        // KYC step-up: identity verification is required to
                        // continue. Show the message with a Verify button that the
                        // customer taps to open the Smile flow; the same submission
                        // is retried once they finish. (No auto-launch: an explicit
                        // tap is clearer and more trustworthy than a camera modal
                        // opening unprompted.)
                        if (response.status === 422 && resData.kyc_required) {
                            this.submitting = false;
                            this.kycMessage = resData.message || 'To continue with this purchase, please complete a quick identity verification.';
                            if (window.rshopKyc) {
                                this.kycSmile = resData.smile;
                                this.pendingForm = e.target;
                                this.paymentState = 'kyc_required';
                            } else {
                                this.errorMessage = this.kycMessage;
                                this.paymentState = 'error';
                            }
                            this.open = true;
                            return;
                        }

                        // Email not verified yet: a pre-payment requirement, not a
                        // failure. Nothing was written; the server already re-sent
                        // the verification link. Show a calm notice, never the red
                        // "Payment Failed" state.
                        if (response.status === 403 && resData.email_unverified) {
                            this.submitting = false;
                            this.errorMessage = resData.message || 'Please verify your email to continue.';
                            this.resendFlash = '';
                            // The server just auto-sent; its schedule says when a
                            // resend becomes available.
                            this.startResendCountdown(resData.resend_retry_after || 30);
                            this.paymentState = 'email_notice';
                            this.open = true;
                            return;
                        }

                        if (!response.ok) {
                            this.submitting = false;
                            if (response.status === 429 && resData.is_rate_limited) {
                                this.startBackoff(resData.wait_seconds);
                                this.errorMessage = resData.error || 'Too many failed payment attempts.';
                            } else {
                                this.errorMessage = resData.message || 'Checkout failed. Please try again.';
                            }
                            // Show error modal instead of alert
                            this.paymentState = 'error';
                            this.open = true;
                            return;
                        }

                        const orderNumber = resData.order_number;
                        const redirectUrl = resData.redirect_url;
                        const sessionData = resData.payment_session;

                        this.redirectUrl = redirectUrl;

                        if (!sessionData) {
                            window.location.href = redirectUrl;
                            return;
                        }

                        this.session = sessionData;
                        this.open = true;

                        if (this.method === 'wallet') {
                            if (sessionData.status === 'confirmed') {
                                // No transaction PIN set — the wallet settled
                                // synchronously at checkout. Go to the order page.
                                window.location.href = this.redirectUrl;
                            } else {
                                // A transaction PIN is required to authorize the
                                // wallet debit. Prompt for it inside the modal.
                                this.paymentState = 'wallet_pin';
                                this.walletPin = '';
                                this.errorMessage = '';
                            }
                            return;
                        }

                        if (this.method === 'card') {
                            this.paymentState = 'processing';
                            
                            // If the custom card form is present (direct charge enabled), collect its data.
                            // Otherwise, the backend will return inline initialization data.
                            const cardNameEl = document.getElementById('card_name');
                            if (cardNameEl) {
                                const cardHolder = cardNameEl.value || '';
                                const cardNumber = document.getElementById('card_number')?.value || '';
                                const cardExpiry = document.getElementById('card_expiry')?.value || '';
                                const cardCvc = document.getElementById('card_cvc')?.value || '';
                                
                                let expiryMonth = '';
                                let expiryYear = '';
                                if (cardExpiry.includes('/')) {
                                    const parts = cardExpiry.split('/');
                                    expiryMonth = parts[0].trim();
                                    expiryYear = parts[1].trim();
                                } else if (cardExpiry.length === 4) {
                                    expiryMonth = cardExpiry.substring(0, 2);
                                    expiryYear = cardExpiry.substring(2, 4);
                                }
    
                                this.cardDetails = {
                                    card_number: cardNumber.replace(/\s+/g, ''),
                                    cvv: cardCvc,
                                    expiry_month: expiryMonth,
                                    expiry_year: expiryYear,
                                    card_holder: cardHolder
                                };
                                await this.paySession('card', this.cardDetails);
                            } else {
                                await this.paySession('card', {});
                            }
                        } else if (this.method === 'mobile_money') {
                            const networkInput = document.querySelector('input[name="momo_network"]')?.value || 'MTN';
                            const phoneInput = document.getElementById('momo_phone')?.value || '';

                            this.momoDetails = {
                                phone_number: phoneInput,
                                network: networkInput
                            };

                            this.paymentState = 'processing';
                            await this.paySession('mobile_money', this.momoDetails);
                        } else if (this.method === 'crypto') {
                            this.paymentState = 'processing';
                            await this.paySession('crypto', { pay_currency: this.crypto });
                        } else if (this.method === 'apple_pay') {
                            this.paymentState = 'processing';
                            await this.paySession('apple_pay', {});
                        } else if (this.method === 'bank_transfer') {
                            this.paymentState = 'processing';
                            await this.paySession('bank_transfer', {});
                        } else if (['ussd', 'pay_with_bank', 'bank_qr', 'mobile_wallet'].includes(this.method)) {
                            // Hosted-redirect family. paySession() will receive
                            // status === 'awaiting_redirect' from the API and
                            // send the customer to Flutterwave's hosted page.
                            this.paymentState = 'processing';
                            await this.paySession(this.method, {});
                        }
                    } catch (err) {
                        this.submitting = false;
                        // fetch() itself throws TypeError on a real network failure;
                        // anything else lands here too (response parse errors, etc.)
                        // — show what we actually know instead of always blaming
                        // the customer's internet.
                        console.error('Checkout submit failed:', err);
                        const msg = (err && err.name === 'TypeError')
                            ? 'Network connection failed. Please check your internet.'
                            : 'Could not complete checkout: ' + (err && err.message ? err.message : 'unexpected error') + '. Refresh the page and try again.';
                        alert(msg);
                    }
                },

                async paySession(method, dataPayload = {}) {
                    this.paymentState = 'processing';
                    this.errorMessage = '';
                    try {
                        let body = {
                            method: method,
                            details: dataPayload
                        };
                        if (this.pinValue) {
                            body.pin = this.pinValue;
                        }
                        if (this.otpValue) {
                            body.otp = this.otpValue;
                            body.flw_ref = this.session.payment_payload?.flw_ref || '';
                        }

                        let response = await fetch(`/api/payment-sessions/${this.session.id}/pay`, {
                            method: 'POST',
                            headers: {
                                'Content-Type': 'application/json',
                                'Accept': 'application/json',
                                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || ''
                            },
                            body: JSON.stringify(body)
                        });

                        let resData;
                        try {
                            resData = await response.json();
                        } catch (jsonErr) {
                            resData = { message: 'Server error (' + response.status + '): ' + response.statusText };
                        }

                        if (!response.ok) {
                            this.paymentState = 'error';
                            this.errorMessage = resData.message || 'Payment initiation failed.';
                            return;
                        }

                        this.handlePayResponse(resData.data || resData);
                    } catch (err) {
                        this.paymentState = 'error';
                        this.errorMessage = 'Network connection failed. Please check your internet.';
                    }
                },

                /**
                 * Wallet authorization: verify the customer's 4-digit transaction
                 * PIN to obtain a short-lived auth token, then authorize the wallet
                 * debit through the pay endpoint.
                 */
                async authorizeWalletPayment() {
                    const pin = (this.$refs.walletPinInput?.value || this.walletPin || '').trim();
                    if (!/^\d{4}$/.test(pin)) {
                        this.errorMessage = 'Enter your 4-digit transaction PIN.';
                        return;
                    }
                    this.authorizingWallet = true;
                    this.errorMessage = '';
                    try {
                        const res = await fetch('/api/wallets/pin/verify', {
                            method: 'POST',
                            headers: {
                                'Content-Type': 'application/json',
                                'Accept': 'application/json',
                                'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]')?.content || ''
                            },
                            body: JSON.stringify({ pin })
                        });
                        const data = await res.json().catch(() => ({}));
                        if (!res.ok) {
                            this.authorizingWallet = false;
                            // 422 returns { message, errors: { pin: [...] } }; 429 is a lockout/throttle.
                            this.errorMessage = (data.errors?.pin?.[0]) || data.message || 'Could not verify your PIN.';
                            this.walletPin = '';
                            if (this.$refs.walletPinInput) this.$refs.walletPinInput.value = '';
                            return;
                        }
                        this.authorizingWallet = false;
                        this.walletPin = '';
                        if (this.$refs.walletPinInput) this.$refs.walletPinInput.value = '';
                        // Authorize the debit with the single-use token.
                        await this.paySession('wallet', { auth_token: data.auth_token });
                    } catch (e) {
                        this.authorizingWallet = false;
                        this.errorMessage = 'Network error verifying your PIN. Please try again.';
                    }
                },

                handlePayResponse(sessionData) {
                    this.session = sessionData;
                    const status = sessionData.status;

                    if (status === 'confirmed') {
                        this.paymentState = 'success';
                        setTimeout(() => { window.location.href = this.redirectUrl; }, 2000);
                    } else if (status === 'awaiting_redirect') {
                        // Hosted-checkout methods (USSD / Pay With Bank /
                        // Bank QR / Mobile Wallet). The API has returned a
                        // Flutterwave-hosted URL; send the customer there and
                        // they'll come back through /checkout/return.
                        const url = sessionData.payment_payload?.redirect_url;
                        if (url) {
                            window.location.href = url;
                            return;
                        }
                        this.paymentState = 'error';
                        this.errorMessage = 'Could not start the payment. Please try a different method.';
                    } else if (status === 'failed') {
                        this.paymentState = 'error';
                        this.errorMessage = sessionData.payment_payload?.failure_reason || 'Transaction could not be completed.';
                    } else if (status === 'awaiting_payment') {
                        const inlineData = sessionData.payment_payload?.inline;
                        if (inlineData) {
                            this.openFlutterwaveInline(inlineData);
                        } else {
                            this.paymentState = 'error';
                            this.errorMessage = 'Could not initialize card payment.';
                        }
                    } else if (status === 'awaiting_customer_action') {
                        const action = sessionData.payment_payload?.action;
                        if (action === 'pin') {
                            this.paymentState = 'action_pin';
                            this.pinValue = '';
                            this.startCountdown();
                        } else if (action === 'otp') {
                            this.paymentState = 'action_otp';
                            this.otpValue = '';
                            this.actionMessage = sessionData.payment_payload?.message || 'Verification code sent';
                            this.startCountdown();
                        } else if (action === 'redirect') {
                            this.paymentState = 'action_3ds';
                            this.startStatusPolling();
                            this.startCountdown();
                        }
                    } else if (status === 'awaiting_transfer') {
                        this.paymentState = 'awaiting_transfer';
                        this.bankDetails = sessionData.payment_payload?.bank_details || sessionData.payment_payload || null;
                        this.startStatusPolling();
                        this.startCountdown();
                    } else if (status === 'awaiting_confirmation') {
                        this.paymentState = 'awaiting_confirmation';
                        this.actionMessage = sessionData.payment_payload?.message || 'Please accept the billing prompt on your device.';
                        this.startStatusPolling();
                        this.startCountdown();
                    }
                },

                openFlutterwaveInline(data) {
                    const self = this;

                    // Ad blockers / CDN failures leave the SDK undefined —
                    // report it as a terminal frontend failure instead of
                    // throwing an unexplained JS error.
                    if (typeof FlutterwaveCheckout === 'undefined') {
                        if (window.PaymentTelemetry && self.session?.id) {
                            window.PaymentTelemetry.recordFailure(self.session.id, 'sdk_load_failed', { provider: 'flutterwave' });
                        }
                        this.paymentState = 'error';
                        this.errorMessage = 'The payment window could not load. Please disable any ad blocker and try again.';
                        this.open = true;
                        this.submitting = false;
                        return;
                    }

                    this.paymentState = 'processing';
                    this.open = false; // Hide our modal to prevent z-index/click interception issues

                    // Watch for JS exceptions / offline / tab-close while the
                    // provider popup owns the flow.
                    const disarmTelemetry = window.PaymentTelemetry
                        ? window.PaymentTelemetry.armSession(self.session?.id, { provider: 'flutterwave' })
                        : function () {};

                    FlutterwaveCheckout({
                        public_key: data.public_key,
                        tx_ref: data.tx_ref,
                        amount: data.amount,
                        currency: data.currency,
                        customer: data.customer,
                        customizations: data.customizations,
                        callback: async function(response) {
                            // Payment completed inside the popup
                            disarmTelemetry();
                            self.paymentState = 'processing';
                            try {
                                let verifyRes = await fetch(
                                    `/api/payment-sessions/${self.session.id}/verify`,
                                    {
                                        method: 'POST',
                                        headers: {
                                            'Content-Type': 'application/json',
                                            'Accept': 'application/json',
                                            'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]')?.content || ''
                                        },
                                        body: JSON.stringify({
                                            transaction_id: response.transaction_id
                                        })
                                    }
                                );
                                let verifyData = await verifyRes.json();
                                if (verifyData.status === 'confirmed') {
                                    self.paymentState = 'success';
                                    setTimeout(() => { window.location.href = self.redirectUrl; }, 2000);
                                } else {
                                    self.paymentState = 'error';
                                    self.errorMessage = verifyData.message || 'Payment could not be verified.';
                                    self.open = true; // Re-open modal to show error
                                }
                            } catch (e) {
                                // Verification request itself failed — a client
                                // connectivity signal, not a payment verdict.
                                if (window.PaymentTelemetry && self.session?.id) {
                                    window.PaymentTelemetry.recordSignal(self.session.id, 'network_timeout', { provider: 'flutterwave', step: 'verify' });
                                }
                                self.paymentState = 'error';
                                self.errorMessage = 'Could not verify payment. Please check your connection.';
                                self.open = true; // Re-open modal to show error
                            }
                        },
                        onclose: function() {
                            // User closed the popup without completing
                            disarmTelemetry();
                            if (self.paymentState !== 'success') {
                                if (window.PaymentTelemetry && self.session?.id) {
                                    window.PaymentTelemetry.recordFailure(self.session.id, 'popup_closed', { provider: 'flutterwave' });
                                }
                                self.paymentState = 'idle';
                                self.open = true; // Re-open our modal if user cancelled
                                self.submitting = false;
                            }
                        }
                    });
                },

                startStatusPolling() {
                    if (this.pollInterval) clearInterval(this.pollInterval);
                    this.pollInterval = setInterval(async () => {
                        try {
                            let res = await fetch(`/api/payment-sessions/${this.session.id}/status`);
                            let data = await res.json();

                            // Carry the underpayment summary onto the live session so the
                            // balance-owing panel can appear. Without this a short payment
                            // leaves the customer on a spinner forever, waiting for a
                            // confirmation that will never arrive.
                            if (data.underpayment) {
                                this.session = { ...this.session, underpayment: data.underpayment };
                                clearInterval(this.pollInterval);
                                return;
                            }

                            if (data.status === 'confirmed') {
                                clearInterval(this.pollInterval);
                                this.paymentState = 'success';
                                setTimeout(() => { window.location.href = this.redirectUrl; }, 2000);
                            } else if (data.status === 'failed') {
                                clearInterval(this.pollInterval);
                                this.paymentState = 'error';
                                this.errorMessage = data.payment_payload?.failure_reason || 'Transaction failed.';
                            }
                        } catch (e) {}
                    }, 4500);
                },

                /**
                 * Actively verify with the gateway. The passive /status poll only
                 * reads our DB, which never flips until the gateway webhook lands
                 * (unreachable in local dev). /verify queries the gateway directly
                 * and, on success, confirms the session + fulfils the order.
                 */
                async verifyPayment() {
                    if (this.verifying) {
                        return;
                    }
                    this.verifying = true;
                    this.errorMessage = '';
                    try {
                        let response = await fetch(`/api/payment-sessions/${this.session.id}/verify`, {
                            method: 'POST',
                            headers: {
                                'Content-Type': 'application/json',
                                'Accept': 'application/json',
                                'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]')?.content || ''
                            },
                            body: JSON.stringify({})
                        });
                        let data = await response.json();
                        if (data.status === 'confirmed') {
                            if (this.pollInterval) {
                                clearInterval(this.pollInterval);
                            }
                            this.paymentState = 'success';
                            setTimeout(() => { window.location.href = this.redirectUrl; }, 1800);
                            return;
                        }
                        this.verifying = false;
                        this.errorMessage = data.message || 'We could not confirm your payment yet. If you just completed it, wait a few seconds and try again.';
                    } catch (e) {
                        this.verifying = false;
                        this.errorMessage = 'Could not reach the server to verify. Please check your connection and try again.';
                    }
                },

                copyToClipboard(text, key = 'default') {
                    navigator.clipboard.writeText(text).then(() => {
                        this.copiedStates[key] = true;
                        setTimeout(() => {
                            this.copiedStates[key] = false;
                        }, 2000);
                    }).catch(() => {
                        alert('Failed to copy to clipboard.');
                    });
                },

                /**
                 * Close the wizard. Confirms first if a payment is mid-flight —
                 * the customer may have already entered a card / PIN / OTP, started
                 * a 3DS challenge, or is awaiting a bank/momo confirmation. Closing
                 * mid-flight aborts the attempt but the backend session remains
                 * authoritative (webhooks still settle if the bank completes).
                 */
                closeModal() {
                    const inFlight = [
                        'action_pin',
                        'action_otp',
                        'action_3ds',
                        'awaiting_transfer',
                        'awaiting_confirmation',
                        'processing',
                    ];
                    if (this.session && inFlight.includes(this.paymentState)) {
                        const ok = window.confirm('A payment is in progress. Closing this window will cancel the attempt. Continue?');
                        if (! ok) {
                            return;
                        }
                    }
                    this.open = false;
                    this.submitting = false;
                    this.session = null;
                    this.paymentState = 'idle';
                    if (this.pollInterval) {
                        clearInterval(this.pollInterval);
                    }
                    if (this.timerInterval) {
                        clearInterval(this.timerInterval);
                    }
                    if (this.resendTimerId) {
                        clearInterval(this.resendTimerId);
                        this.resendTimerId = null;
                    }
                },

                // --- Verification-email resend (escalating server cooldown) ---
                startResendCountdown(seconds) {
                    if (this.resendTimerId) {
                        clearInterval(this.resendTimerId);
                    }
                    this.resendRemaining = Math.max(0, Math.ceil(seconds));
                    this.resendTimerId = setInterval(() => {
                        if (this.resendRemaining > 0) {
                            this.resendRemaining--;
                        } else {
                            clearInterval(this.resendTimerId);
                            this.resendTimerId = null;
                        }
                    }, 1000);
                },

                // "30s", "1m", "1m 30s" — compact human countdown.
                resendLabel(s) {
                    if (s < 60) return s + 's';
                    const m = Math.floor(s / 60), r = s % 60;
                    return r ? (m + 'm ' + r + 's') : (m + 'm');
                },

                async resendVerification() {
                    if (this.resendRemaining > 0 || this.resendBusy) return;
                    this.resendBusy = true;
                    this.resendFlash = '';
                    try {
                        const res = await fetch('{{ route('verification.send') }}', {
                            method: 'POST',
                            headers: {
                                'Accept': 'application/json',
                                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '',
                            },
                        });
                        const data = await res.json();
                        if (data.verified) {
                            // Already verified in another tab: just retry checkout.
                            this.closeModal();
                            return;
                        }
                        this.resendFlash = res.ok
                            ? (data.message || 'Verification link sent.')
                            : (data.message || 'Please wait before requesting another link.');
                        this.startResendCountdown(data.retry_after || 30);
                    } catch (e) {
                        this.resendFlash = 'Network error. Please try again.';
                    } finally {
                        this.resendBusy = false;
                    }
                },

                // Open the Smile ID verification flow. On success, resubmit the
                // exact same checkout so the now-verified customer proceeds.
                verifyIdentity() {
                    if (! window.rshopKyc || ! this.kycSmile) {
                        this.errorMessage = this.kycMessage;
                        this.paymentState = 'error';
                        return;
                    }
                    const form = this.pendingForm;
                    window.rshopKyc.launch(this.kycSmile, () => {
                        if (form) {
                            form.requestSubmit();
                        }
                    });
                }
            };
        };
    </script>

    @include('partials.kyc-verification-modal')

</x-shop.layout>

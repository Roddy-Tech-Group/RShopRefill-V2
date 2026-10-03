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
    // Rendered inside the dashboard chrome, the frame pads the page itself, so
    // the wrapper below adds none of its own.
    $inDashboard = request()->is('dashboard/shop*');

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

    // Mobile-money networks per currency, from the same map the charge reads,
    // so the picker follows the live checkout currency and never offers a
    // network the market's rail will refuse.
    $momoNetworksByCurrency = \App\Domain\Payment\Support\MobileMoneyRail::networksByCurrency();

    // Currencies whose mobile-money charge is taken in another currency (USD
    // rides the Francophone rail in CFA francs), with the rate the total is
    // converted at, so the panel can say what the account will be debited
    // before the customer commits. The charge converts server-side at the
    // live rate, so the figure is an estimate and is worded as one. A rail
    // currency with no active rate is left out: the charge would refuse it
    // and a made-up figure would be worse than none.
    $momoChargeByCurrency = [];
    foreach (\App\Domain\Payment\Support\MobileMoneyRail::chargeCurrencyByCurrency() as $priced => $charged) {
        $chargedPerUsd = CurrencyRate::query()->active()->where('code', $charged)->value('rate_per_usd');
        $pricedPerUsd = CurrencyRate::query()->active()->where('code', $priced)->value('rate_per_usd');
        if ($chargedPerUsd === null || $pricedPerUsd === null || (float) $pricedPerUsd <= 0) {
            continue;
        }
        $momoChargeByCurrency[$priced] = [
            'currency' => $charged,
            'symbol' => \App\Domain\Shared\Services\Money::symbol($charged),
            'rate' => (float) $chargedPerUsd / (float) $pricedPerUsd,
            'precision' => \App\Domain\Payment\Support\MobileMoneyRail::precision($charged),
        ];
    }

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

    // A pause or lock the customer is already serving, so a refreshed page
    // starts the countdown straight away instead of offering Continue to
    // payment and only then refusing it. Null when nothing is being served.
    $paymentHold = app(\App\Domain\Payment\Services\PaymentSessionService::class)->currentHold($user, 'checkout');

    // See contact.blade.php: a pill with a 2px edge, recoloured on focus. No
    // ring - a ring is drawn outside the box and pushed the field's neighbours
    // on every click.
    $fieldClass = 'mt-1.5 w-full rounded-full border-2 border-zinc-200 bg-white px-4 py-2 text-base text-zinc-900 dark:text-white outline-none transition-colors focus:border-edge dark:border-white/15 dark:bg-white/5 dark:placeholder:text-zinc-500 dark:focus:border-edge';

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

<x-shop.layout :title="__('checkout.checkout').' | '.$siteName">

    <script src="https://checkout.flutterwave.com/v3.js"></script>

    <div class="min-h-full bg-zinc-100 dark:bg-[#0c1a36]">
    <div class="mx-auto w-full max-w-7xl {{ $inDashboard ? '' : 'px-4 py-6 sm:px-6 lg:px-8 lg:py-10' }}">

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
            :title="__('checkout.paused_title')"
            :message="__('checkout.paused_message')"
            class="mt-4"
        />

        <div x-data="checkoutPage(@js($cryptoRatesForJs), @js($walletBalances), @js(auth()->check()), @js($rcoinConfig), @js(auth()->user()?->hasTransactionPin() ?? false), @js($paymentFees), @js($paymentHold), @js($walletOptions ?? []))">

            {{-- Loading — until the cart store's first fetch resolves.
                 The page is rendered from the Alpine cart store, so the server
                 sends none of this: a shopper who has just committed to paying
                 met an empty card with a spinner in it.

                 A skeleton of the page it stands in for, not a generic box.
                 It repeats the real grid and both cards - the same column
                 spans, the same card classes, the same 16:10 tile, the same
                 74px method tiles and 44px action - so the swap into real
                 content lands on the boxes the skeleton already drew instead
                 of resizing the page under the cursor. Same discipline as the
                 gift-card listing's skeleton, which shares its grid classes
                 with the content for exactly this reason.

                 No x-cloak, deliberately: this is the one block that must
                 paint before Alpine boots. The two states below carry it, so
                 they stay hidden until the store can say which is true.

                 aria-hidden: it is a picture of content that is not there
                 yet, and the live region that matters is what replaces it. --}}
            <div x-show="!$store.cart.hydrated" class="grid grid-cols-1 gap-5 lg:grid-cols-5 lg:items-start" aria-hidden="true">

                {{-- Order details, mirroring the section below. --}}
                <section class="pure-card self-start rounded-[20px] bg-white/70 dark:bg-[#0c1a36]/60 p-5 ring-1 ring-zinc-200 backdrop-blur-xl dark:ring-white/10 sm:p-6 lg:sticky lg:top-6 lg:col-span-2">
                    <x-skeleton class="h-5 w-40" />

                    {{-- Two rows: enough to read as a list without inventing a
                         basket size we do not know yet. --}}
                    <ul class="mt-4 divide-y divide-zinc-100 dark:divide-white/10">
                        @for ($row = 0; $row < 2; $row++)
                            <li class="flex items-start gap-3 py-4">
                                <x-skeleton rounded="rounded-[12px]" class="aspect-[16/10] w-28 shrink-0 sm:w-32" />
                                <div class="min-w-0 flex-1">
                                    <x-skeleton class="h-4 w-3/4" />
                                    <x-skeleton class="mt-2 h-3 w-2/5" />
                                    <x-skeleton class="mt-2 h-3 w-1/2" />
                                </div>
                            </li>
                        @endfor
                    </ul>

                    {{-- The totals that close the column. --}}
                    <div class="mt-2 space-y-2.5 border-t border-zinc-100 pt-4 dark:border-white/10">
                        <x-skeleton class="h-4 w-full" />
                        <x-skeleton class="h-4 w-3/5" />
                        <x-skeleton class="h-5 w-2/5" />
                    </div>
                </section>

                {{-- Payment, mirroring the form below: a heading, the method
                     tiles at their real size in their real grid, and the
                     action. --}}
                <div class="pure-card rounded-[20px] bg-white/70 dark:bg-[#0c1a36]/60 p-5 ring-1 ring-zinc-200 backdrop-blur-xl dark:ring-white/10 sm:p-6 lg:col-span-3">
                    <x-skeleton class="h-5 w-44" />

                    <div class="mt-3 grid grid-cols-2 gap-2 sm:grid-cols-3">
                        @for ($tile = 0; $tile < 6; $tile++)
                            <x-skeleton rounded="rounded-[12px]" class="h-[74px] w-full" />
                        @endfor
                    </div>

                    <x-skeleton class="mt-6 h-4 w-1/3" />
                    <x-skeleton rounded="rounded-full" class="mt-4 h-11 w-full" />
                </div>
            </div>

            {{-- Empty cart - same animated inline illustration as the cart
                 page and the nav/dashboard cart popups. --}}
            <div x-show="$store.cart.hydrated && $store.cart.count === 0" x-cloak class="pure-card rounded-[20px] bg-white/70 dark:bg-[#0c1a36]/60 px-6 py-20 text-center ring-1 ring-zinc-200 backdrop-blur-xl dark:ring-white/10">
                <x-illo name="emptyCart" class="mx-auto w-full max-w-[280px]" />
                <p class="mt-4 text-base font-semibold text-zinc-900 dark:text-white">{{ __('checkout.cart_empty') }}</p>
                <p class="mt-1 text-sm text-zinc-600 dark:text-zinc-400">{{ __('checkout.checkout_empty_hint') }}</p>
                <a href="{{ request()->routeIs('dashboard.*') ? route('dashboard.shop.gift-cards') : route('shop.gift-cards') }}" wire:navigate class="mt-5 inline-flex h-11 items-center gap-1.5 rounded-full bg-blue-600 px-5 text-sm font-semibold text-white transition-colors hover:bg-blue-700">
                    {{ __('checkout.visit_shop') }}
                </a>
            </div>

            {{-- Checkout --}}
            <div x-show="$store.cart.hydrated && $store.cart.count > 0" x-cloak class="grid grid-cols-1 gap-5 lg:grid-cols-5 lg:items-start">

                {{-- ─── LEFT: Order details ─── --}}
                <section class="pure-card self-start rounded-[20px] bg-white/70 dark:bg-[#0c1a36]/60 p-5 ring-1 ring-zinc-200 backdrop-blur-xl dark:ring-white/10 sm:p-6 lg:sticky lg:top-6 lg:col-span-2">
                    <h2 class="text-lg font-bold text-zinc-900 dark:text-white">{{ __('checkout.order_details') }}</h2>

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
                                    <p class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">
                                        {{ __('checkout.item_price') }}
                                        <span class="font-bold tabular-nums text-zinc-900 dark:text-white" x-text="$store.cart.pay(item.line_total)"></span>
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
                                    <p x-show="rcoinConfig.enabled" class="mt-1 inline-flex items-center gap-1 text-[11px] text-zinc-500 dark:text-zinc-400">
                                        <span x-text="item.quantity"></span> x
                                        <img src="{{ asset('assets/favicon.ico') }}" alt="" class="h-3.5 w-3.5 object-contain">
                                        <span class="font-semibold text-zinc-900 dark:text-white" x-text="Math.floor(((item.line_total_usd || 0) * (rcoinConfig.cashback_percentage / 100)) / rcoinConfig.usd_rate)"></span> {{ __('checkout.points') }}
                                    </p>
                                </div>

                                {{-- Remove is a plain X: the word in red was the loudest thing
                                     in the order. It sits beside the quantity only where the card
                                     is wide enough for both: from sm while the order is the page's
                                     only column, and again from xl once the page has split in two.
                                     Between them it stacks, because the dashboard renders this same
                                     card beside its rail, and there the pair side by side left the
                                     brand name, country and value no width at all. --}}
                                <div class="flex shrink-0 flex-col items-end gap-1 self-start sm:flex-row sm:items-center sm:gap-1.5 lg:flex-col lg:items-end lg:gap-1 xl:flex-row xl:items-center xl:gap-1.5">
                                    {{-- Quantity dropdown --}}
                                    <div x-data="{ qtyOpen: false }" @click.outside="qtyOpen = false" class="relative">
                                        <button type="button" @click="qtyOpen = !qtyOpen" class="flex h-9 w-16 items-center justify-between rounded-full border border-zinc-200 bg-white px-2.5 text-sm font-bold text-zinc-900 dark:text-white transition-colors hover:border-zinc-400">
                                            <span x-text="item.quantity"></span>
                                            <svg class="h-4 w-4 text-zinc-500" :class="qtyOpen && 'rotate-180'" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7"/></svg>
                                        </button>
                                        <div x-show="qtyOpen" x-cloak x-transition.opacity class="absolute right-0 z-20 mt-1 max-h-52 w-16 overflow-y-auto rounded-[12px] bg-white/90 p-1 shadow-lg shadow-zinc-900/10 ring-1 ring-zinc-200 backdrop-blur-xl [scrollbar-width:none] [&::-webkit-scrollbar]:hidden">
                                            <template x-for="n in 10" :key="n">
                                                <button type="button" @click="$store.cart.setQty(item.id, n); qtyOpen = false"
                                                    :class="n === item.quantity ? 'bg-blue-50 text-blue-700' : 'text-zinc-700 hover:bg-zinc-100'"
                                                    class="flex w-full items-center justify-center rounded-full px-2 py-1.5 text-sm font-medium tabular-nums transition-colors"
                                                    x-text="n"></button>
                                            </template>
                                        </div>
                                    </div>
                                    <button
                                        type="button"
                                        @click="$store.cart.remove(item.id)"
                                        aria-label="{{ __('checkout.remove') }}"
                                        title="{{ __('checkout.remove') }}"
                                        class="flex h-9 w-9 items-center justify-center rounded-full text-zinc-500 transition-colors hover:bg-zinc-100 hover:text-zinc-900 focus:outline-none focus-visible:ring-2 focus-visible:ring-blue-500/40 dark:text-zinc-400 dark:hover:bg-white/10 dark:hover:text-white"
                                    >
                                        <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/>
                                        </svg>
                                    </button>
                                </div>
                            </li>
                        </template>
                    </ul>

                    {{-- eSIM coverage notice — eSIMs only have service inside
                         their coverage area, and "US Data eSIM" bought from home
                         is the classic blind-buy mistake.

                         Every eSIM EXCEPT a Global one. The notice tells a buyer
                         to pick the plan for the country they are travelling to,
                         or else to choose a Global plan - which reads as a warning
                         about a decision they have already made, and made well. A
                         worldwide plan has no coverage area to check against.

                         Still shown for a mixed basket: a Global plan sitting
                         beside a country plan does not make the country plan any
                         less worth checking. is_global is the cart payload's own
                         flag (CartWebController), set from country_code === 'WW'. --}}
                    <div
                        x-show="$store.cart.items.some((i) => i.category_slug === 'esims' && ! i.is_global)"
                        x-cloak
                        class="mt-3 flex items-start gap-2.5 rounded-[12px] bg-blue-50 px-4 py-3.5 dark:bg-blue-500/10"
                    >
                        <svg class="mt-0.5 h-5 w-5 shrink-0 text-blue-600 dark:text-blue-300" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 21a9.004 9.004 0 008.716-6.747M12 21a9.004 9.004 0 01-8.716-6.747M12 21c2.485 0 4.5-4.03 4.5-9S14.485 3 12 3m0 18c-2.485 0-4.5-4.03-4.5-9S9.515 3 12 3m0 0a9.004 9.004 0 018.716 6.747M12 3a9.004 9.004 0 00-8.716 6.747M21.75 12H2.25"/>
                        </svg>
                        <p class="text-sm text-blue-800 dark:text-blue-200">{{ __('checkout.esim_coverage_notice') }}</p>
                    </div>

                    {{-- Points + total --}}
                    <div class="mt-4 space-y-2 border-t border-zinc-100 dark:border-white/10 pt-4 text-sm">
                        <div x-show="rcoinConfig.enabled" class="flex items-center justify-between text-xs">
                            <span class="inline-flex items-center gap-1.5 text-zinc-500 dark:text-zinc-400">
                                {{ __('checkout.points_you_earn') }}
                                <img src="{{ asset('assets/favicon.ico') }}" alt="" class="h-4 w-4 object-contain">
                            </span>
                            <span x-data="valueFlip()" x-effect="points(); flash()" class="inline-block font-semibold text-zinc-700 dark:text-zinc-200" x-text="points()">0</span>
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

                            {{-- A switch rather than a tick box, drawn over the same
                                 checkbox: the input, its binding and what it posts are
                                 untouched, so only what the customer sees has changed. --}}
                            <label class="flex cursor-pointer items-start gap-3">
                                <span class="relative order-last mt-0.5 inline-flex h-6 w-11 shrink-0 items-center">
                                    <input
                                        type="checkbox"
                                        role="switch"
                                        :checked="applyRcoin !== ''"
                                        @change="applyRcoin = $event.target.checked ? '1' : ''"
                                        class="peer sr-only"
                                    >
                                    <span class="absolute inset-0 rounded-full bg-zinc-200 transition-colors peer-checked:bg-blue-600 peer-focus-visible:ring-2 peer-focus-visible:ring-blue-500/40 dark:bg-zinc-700" aria-hidden="true"></span>
                                    <span class="theme-static absolute left-0.5 h-5 w-5 rounded-full bg-white shadow transition-transform peer-checked:translate-x-5" aria-hidden="true"></span>
                                </span>
                                <span class="min-w-0 flex-1">
                                    <span class="flex flex-wrap items-baseline justify-between gap-x-3">
                                        <span class="text-sm font-bold text-zinc-900 dark:text-white">{{ __('checkout.use_my_rcoin') }}</span>
                                        <span class="text-[11px] text-zinc-600 dark:text-zinc-400">
                                            {{ __('checkout.balance_label') }} <span class="font-semibold tabular-nums text-zinc-900 dark:text-white" x-text="Number(walletBalances.RCOIN || 0).toLocaleString()"></span>
                                        </span>
                                    </span>
                                    {{-- The live figures are passed in as markup placeholders so a
                                         translator can move them within the sentence without ever
                                         carrying the Alpine bindings themselves. --}}
                                    <span class="mt-0.5 block text-[11px] leading-relaxed text-zinc-600 dark:text-zinc-300">
                                        {!! __('checkout.rcoin_apply_up_to_order', ['percent' => '<span class="font-semibold" x-text="rcoinConfig.redemption_max_pct + \'%\'"></span>']) !!}
                                        — {!! __('checkout.rcoin_save_up_to', ['amount' => '<span class="font-bold text-blue-700 dark:text-blue-300" x-text="\'$\' + (Math.min((walletBalances.RCOIN || 0) * rcoinConfig.usd_rate, ($store.cart.subtotalUsd || 0) * (rcoinConfig.redemption_max_pct / 100))).toFixed(2)"></span>']) !!}
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
                                <button type="button" @click="applyRcoin = '1'" :class="applyRcoin === '1' ? 'bg-blue-600 text-white' : 'text-zinc-700 dark:text-zinc-300'" class="flex-1 rounded-full px-3 py-1.5 text-xs font-semibold transition-colors">
                                    {!! __('checkout.rcoin_apply_up_to', ['percent' => '<span x-text="rcoinConfig.redemption_max_pct + \'%\'"></span>']) !!}
                                </button>
                                <button type="button" @click="applyRcoin = 'full'" :class="applyRcoin === 'full' ? 'bg-blue-600 text-white' : 'text-zinc-700 dark:text-zinc-300'" class="flex-1 rounded-full px-3 py-1.5 text-xs font-semibold transition-colors">
                                    {{ __('checkout.rcoin_pay_full_bill') }}
                                </button>
                            </div>
                        </div>

                        <div class="flex items-start justify-between border-t border-zinc-100 dark:border-white/10 pt-2 text-base font-bold text-zinc-900 dark:text-white">
                            <span>{{ __('checkout.total_estimate') }}</span>
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
                <form id="checkout-form" method="POST" action="{{ $checkoutProcessUrl }}" @submit.prevent="submitCheckout($event)" class="pure-card rounded-[20px] bg-white/70 dark:bg-[#0c1a36]/60 p-5 ring-1 ring-zinc-200 backdrop-blur-xl dark:ring-white/10 sm:p-6 lg:col-span-3">
                    @csrf
                    <input type="hidden" name="payment_method" :value="method">
                    <input type="hidden" name="crypto_coin" :value="crypto">
                    <input type="hidden" name="currency" :value="$store.cart.currency">

                    <h2 class="text-xl font-bold tracking-tight text-zinc-900 dark:text-white">{{ __('checkout.select_payment_method') }}</h2>

                    {{-- Delivery email - doubles as a gifting field: drop a
                         friend's email here to send the redemption codes
                         straight to them instead of yourself. --}}
                    <section class="mt-5 border-b border-t border-zinc-100 py-5 dark:border-white/10" aria-labelledby="delivery-section-title">
                        <h3 id="delivery-section-title" class="text-sm font-bold text-zinc-900 dark:text-white">{{ __('checkout.delivery_section') }}</h3>
                        <div class="relative mt-2 inline-grid grid-cols-2 rounded-full bg-zinc-100 p-1 dark:bg-white/5" role="group" aria-label="{{ __('checkout.delivery_section') }}">
                            <span
                                aria-hidden="true"
                                class="pointer-events-none absolute inset-y-1 left-1 w-[calc(50%-0.25rem)] rounded-full bg-white shadow-sm ring-1 ring-zinc-950/5 transition-transform duration-300 ease-out will-change-transform motion-reduce:transition-none dark:bg-white/15 dark:ring-white/10"
                                :class="deliveryMode === 'gift' ? 'translate-x-full' : 'translate-x-0'"
                            ></span>
                            <button type="button" @click="deliveryMode = 'self'" :aria-pressed="(deliveryMode === 'self').toString()"
                                :class="deliveryMode === 'self' ? 'text-zinc-950 dark:text-white' : 'text-zinc-600 hover:text-zinc-900 dark:text-zinc-300 dark:hover:text-white'"
                                class="relative z-10 h-8 rounded-full px-4 text-xs font-bold transition-colors duration-200 focus:outline-none focus-visible:ring-2 focus-visible:ring-blue-500/40">
                                {{ __('checkout.delivery_for_me') }}
                            </button>
                            <button type="button" @click="deliveryMode = 'gift'" :aria-pressed="(deliveryMode === 'gift').toString()"
                                :class="deliveryMode === 'gift' ? 'text-zinc-950 dark:text-white' : 'text-zinc-600 hover:text-zinc-900 dark:text-zinc-300 dark:hover:text-white'"
                                class="relative z-10 h-8 rounded-full px-4 text-xs font-bold transition-colors duration-200 focus:outline-none focus-visible:ring-2 focus-visible:ring-blue-500/40">
                                {{ __('checkout.delivery_as_gift') }}
                            </button>
                        </div>
                        <label for="delivery_email" class="mt-3 block text-sm font-semibold text-zinc-900 dark:text-white"
                            x-text="deliveryMode === 'gift' ? @js(__('checkout.recipient_email_label')) : @js(__('checkout.your_delivery_email_label'))"></label>
                        <p id="delivery-email-hint" class="mt-0.5 text-[13px] text-zinc-600 dark:text-zinc-400"
                            x-text="deliveryMode === 'gift' ? @js(__('checkout.recipient_email_hint')) : @js(__('checkout.your_delivery_email_hint'))"></p>
                        <input id="delivery_email" name="delivery_email" type="email" required
                            value="{{ old('delivery_email', auth()->user()?->email) }}"
                            :placeholder="deliveryMode === 'gift' ? @js(__('checkout.recipient_email_placeholder')) : @js(__('checkout.delivery_email_placeholder'))"
                            aria-describedby="delivery-email-hint" autocomplete="email" class="{{ $fieldClass }}">
                        @error('delivery_email') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                    </section>

                    <div class="mt-5">
                        <h3 class="text-sm font-bold text-zinc-900 dark:text-white">{{ __('checkout.payment_method') }}</h3>
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
                            $downRails[] = __('checkout.outage_rail_card_bank');
                        }
                        if (! \App\Support\FeatureFlag::gatewayEnabled('nowpayments')) {
                            $downRails[] = __('checkout.outage_rail_crypto');
                        }
                        if (! \App\Support\FeatureFlag::gatewayEnabled('wallet')) {
                            $downRails[] = __('checkout.outage_rail_wallet');
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
                                    {{ __('checkout.outage_all_paused') }}
                                @else
                                    {{-- Two whole sentences rather than a swapped verb, so
                                         languages that agree differently are not forced
                                         into English grammar. --}}
                                    {{ __(count($downRails) > 1 ? 'checkout.outage_many_unavailable' : 'checkout.outage_one_unavailable', ['rails' => implode(__('checkout.outage_rails_joiner'), $downRails)]) }}
                                @endif
                            </p>
                        </div>
                    @endif

                    {{-- Method tabs --}}
                    <div class="mt-3 grid grid-cols-2 gap-2 sm:grid-cols-3">
                        <template x-for="m in getFilteredMethods()" :key="m.key">
                            <button type="button" @click="if (! isMethodDisabled(m.key)) method = m.key"
                                :disabled="isMethodDisabled(m.key)"
                                :aria-disabled="isMethodDisabled(m.key)"
                                :aria-pressed="(method === m.key).toString()"
                                :class="isMethodDisabled(m.key)
                                    ? 'border-zinc-200 opacity-45 grayscale cursor-not-allowed'
                                    : (method === m.key ? 'border-blue-600 bg-blue-50/60 ring-1 ring-blue-600 dark:border-blue-500 dark:bg-blue-500/10 dark:ring-blue-500' : 'border-zinc-200 hover:border-zinc-300')"
                                {{-- rounded-[12px], not rounded-full: this tile is 74px tall
                                     and holds two lines, so a full radius resolves to half
                                     its height and draws an ellipse rather than a pill - the
                                     label wraps against the curve and the tick floats off
                                     the corner. It is the same radius as the coin tiles in
                                     the crypto panel below, which is the selector it sits
                                     beside, and the storefront's card default. --}}
                                class="relative flex min-h-[74px] items-start gap-2.5 rounded-[12px] border bg-white px-3 py-3 text-left transition duration-150 active:scale-[0.98] disabled:active:scale-100 dark:bg-white/5">
                                <span class="flex h-9 w-9 shrink-0 items-center justify-center overflow-hidden rounded-[12px] bg-zinc-100 ring-1 ring-zinc-200 dark:bg-white/5 dark:ring-white/15">
                                    <template x-if="m.icon">
                                        {{-- Every method uses a reusable SVG asset now. Monochrome
                                             artwork follows the global dark-mode SVG treatment;
                                             branded coin artwork keeps its own colour via app.css. --}}
                                        <img :src="m.icon" alt="" class="h-5 w-5 object-contain" width="20" height="20" loading="lazy">
                                    </template>
                                </span>
                                <span class="min-w-0 flex-1">
                                    <span class="block text-sm font-bold text-zinc-900 dark:text-white" x-text="m.label"></span>
                                    <span class="mt-0.5 block text-xs leading-tight text-zinc-500 dark:text-zinc-400" x-text="m.desc"></span>
                                </span>
                                <svg x-show="method === m.key" x-cloak class="absolute right-2 top-2 h-4 w-4 text-blue-600 dark:text-blue-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5" aria-hidden="true">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5"/>
                                </svg>
                            </button>
                        </template>
                    </div>

                    {{-- Card --}}
                    <div x-show="method === 'card'" x-collapse class="mt-5 space-y-3">
                        @if(config('services.flutterwave.direct_charge_enabled', false))
                            <div>
                                <label for="card_name" class="text-sm font-semibold text-zinc-900 dark:text-white">{{ __('checkout.card_name_label') }}</label>
                                <input id="card_name" name="card_name" type="text" autocomplete="cc-name" placeholder="{{ __('checkout.card_name_placeholder') }}" x-model="cardDetails.card_holder" class="{{ $fieldClass }}">
                            </div>
                            <div>
                                <label for="card_number" class="text-sm font-semibold text-zinc-900 dark:text-white">{{ __('checkout.card_number_label') }}</label>
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
                                              x-text="cardBrand === 'unknown' ? @js(__('checkout.method_card')) : cardBrand"
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
                                    <label for="card_expiry" class="text-sm font-semibold text-zinc-900 dark:text-white">{{ __('checkout.card_expiry_label') }}</label>
                                    <input
                                        id="card_expiry"
                                        name="card_expiry"
                                        type="text"
                                        inputmode="numeric"
                                        autocomplete="cc-exp"
                                        placeholder="{{ __('checkout.card_expiry_placeholder') }}"
                                        x-model="cardExpiryRaw"
                                        @input="formatExpiry"
                                        class="{{ $fieldClass }} tabular-nums"
                                    >
                                </div>
                                <div>
                                    <label for="card_cvc" class="text-sm font-semibold text-zinc-900 dark:text-white">{{ __('checkout.card_cvc_label') }}</label>
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
                                    {{ __('checkout.card_secure_note') }}
                                </p>
                            </div>
                        @endif
                    </div>

                    {{-- Mobile money --}}
                    <div
                        x-show="method === 'mobile_money'"
                        x-collapse
                        x-cloak
                        x-data="{
                            networksByCurrency: @js($momoNetworksByCurrency),
                            chargeByCurrency: @js($momoChargeByCurrency),
                            networkIcons: { MTN: '/assets/mtn.svg', Orange: '/assets/orange.svg' },
                            get momoNetworks() { return this.networksByCurrency[this.$store.cart.currency] ?? []; },
                            networkIcon(network) { return this.networkIcons[network] ?? null; },
                            // The rail currency and rate when the charge is not in the
                            // page's own currency; null wherever it is.
                            get momoCharge() { return this.chargeByCurrency[this.$store.cart.currency] ?? null; },
                            // Rounded up to whole francs, the way the charge is.
                            momoChargeLabel() {
                                const charge = this.momoCharge;
                                if (! charge) return '';
                                const factor = Math.pow(10, charge.precision);
                                const amount = Math.ceil(this.totalDue() * charge.rate * factor) / factor;
                                return charge.symbol + amount.toLocaleString('en-US', { minimumFractionDigits: charge.precision, maximumFractionDigits: charge.precision });
                            }
                        }"
                        class="mt-5 space-y-3"
                    >
                        {{-- Network chips. There are only a few choices per market, so
                             keeping every option visible is faster to scan than opening
                             a dropdown. Hidden for rails that take the phone number alone
                             (M-Pesa, Rwanda). The hidden input remains the submitted value. --}}
                        <div x-show="momoNetworks.length > 0">
                            <p id="momo-network-label" class="text-sm font-semibold text-zinc-900 dark:text-white">{{ __('checkout.momo_network_label') }}</p>
                            <div
                                x-data="{ value: '' }"
                                x-init="value = momoNetworks[0] ?? ''; $watch('momoNetworks', list => value = list[0] ?? '')"
                                class="mt-2"
                            >
                                <input type="hidden" name="momo_network" :value="value">
                                <div class="flex flex-wrap gap-2" role="radiogroup" aria-labelledby="momo-network-label">
                                    <template x-for="net in momoNetworks" :key="net">
                                        <button
                                            type="button"
                                            role="radio"
                                            :aria-checked="(value === net).toString()"
                                            @click="value = net"
                                            :class="value === net
                                                ? 'border-blue-600 ring-1 ring-blue-600 dark:border-blue-500 dark:ring-blue-500'
                                                : 'border-zinc-200 hover:border-zinc-300 dark:border-white/10 dark:hover:border-white/25'"
                                            class="flex h-11 items-center gap-2.5 rounded-full border bg-white pl-2 pr-4 text-sm font-bold text-zinc-900 transition-colors focus:outline-none focus-visible:ring-2 focus-visible:ring-blue-500/40 dark:bg-white/5 dark:text-white"
                                        >
                                            <template x-if="networkIcon(net)">
                                                <img :src="networkIcon(net)" alt="" class="no-dark-invert h-7 w-7 shrink-0 rounded-[6px] object-contain" width="28" height="28" loading="lazy">
                                            </template>
                                            <template x-if="! networkIcon(net)">
                                                <span class="flex h-7 w-7 shrink-0 items-center justify-center rounded-full bg-zinc-100 text-xs font-black text-zinc-600 dark:bg-white/10 dark:text-zinc-200" x-text="net.charAt(0)"></span>
                                            </template>
                                            <span x-text="net"></span>
                                        </button>
                                    </template>
                                </div>
                            </div>
                        </div>
                        <div>
                            <label for="momo_phone" class="text-sm font-semibold text-zinc-900 dark:text-white">{{ __('checkout.momo_number_label') }}</label>
                            <input id="momo_phone" name="momo_phone" type="tel" autocomplete="tel" placeholder="{{ __('checkout.momo_phone_placeholder') }}" class="{{ $fieldClass }}">
                        </div>
                        {{-- The rail takes CFA francs, not dollars: a USD order is
                             converted at charge time. Said here, beside the number
                             that will be debited, so the figure on the phone is never
                             a surprise. Hidden wherever the charge is in the page's
                             own currency. --}}
                        <p x-show="momoCharge" x-cloak class="text-[13px] text-zinc-600 dark:text-zinc-400" x-text="@js(__('checkout.momo_charge_hint')).replace(':amount', momoChargeLabel())"></p>
                    </div>

                    {{-- Crypto --}}
                    <div x-show="method === 'crypto'" x-collapse x-cloak class="mt-5">
                        <p class="text-sm font-semibold text-zinc-900 dark:text-white">{{ __('checkout.crypto_pay_with') }}</p>
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
                                        : (crypto === '{{ $coin->code }}' ? 'border-blue-600 ring-1 ring-blue-600 dark:border-blue-500 dark:ring-blue-500' : 'border-zinc-200 hover:border-zinc-300')"
                                    class="flex flex-col items-center gap-1 rounded-[12px] border bg-white px-2 py-2.5 text-center transition-colors">
                                    @if ($coin->iconUrl())
                                        <img src="{{ $coin->iconUrl() }}" alt="" class="h-8 w-8 rounded-full">
                                    @else
                                        <span class="flex h-8 w-8 items-center justify-center rounded-full bg-amber-500 text-xs font-black text-white">{{ substr($coin->code, 0, 1) }}</span>
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
                                <p class="col-span-full text-sm text-zinc-600 dark:text-zinc-400">{{ __('checkout.crypto_none_enabled') }}</p>
                            @endforelse
                        </div>
                        <template x-if="unusableCoinCount() > 0">
                            <p class="mt-2 text-xs text-amber-700 dark:text-amber-400">
                                {{ __('checkout.crypto_some_unavailable') }} — {{ __('checkout.crypto_chain_minimum') }}
                            </p>
                        </template>
                        <p class="mt-3 text-xs text-zinc-600 dark:text-zinc-400">
                            {{ __('checkout.crypto_pick_hint') }}
                        </p>
                    </div>

                    {{-- Wallet. Pays from whichever wallet covers the order, not only
                         the one in the cart's currency: the wallets that cover it are
                         offered, the best one is picked, and the customer may switch.
                         The server makes the same choice with the same rates and is
                         the one that decides. --}}
                    <div x-show="method === 'wallet'" x-collapse x-cloak class="mt-5 space-y-3">
                        <input type="hidden" name="wallet_currency" :value="method === 'wallet' ? walletCurrency : ''">

                        <div class="rounded-[12px] border border-zinc-200 bg-zinc-50 p-4 dark:border-white/10 dark:bg-white/5">
                            <template x-if="coveringWallets().length > 0">
                                <div>
                                    <p class="text-sm font-medium text-zinc-600 dark:text-zinc-400">Pay from</p>
                                    <div class="mt-2 space-y-2">
                                        <template x-for="wallet in coveringWallets()" :key="wallet.currency">
                                            <label class="flex cursor-pointer items-center justify-between gap-3 rounded-[10px] border px-3 py-2.5 transition-colors"
                                                   :class="walletCurrency === wallet.currency ? 'border-blue-500 bg-white ring-2 ring-blue-500/15 dark:bg-white/10' : 'border-zinc-200 bg-white hover:border-zinc-300 dark:border-white/10 dark:bg-white/5'">
                                                <span class="flex items-center gap-3">
                                                    <input type="radio" class="h-4 w-4 accent-blue-600" :value="wallet.currency" x-model="walletCurrency">
                                                    <span>
                                                        <span class="block text-sm font-semibold text-zinc-900 dark:text-white" x-text="wallet.currency + ' wallet'"></span>
                                                        <span class="block text-xs text-zinc-500 dark:text-zinc-400" x-text="'Balance ' + walletMoney(wallet.currency, wallet.balance)"></span>
                                                    </span>
                                                </span>
                                                <span class="text-sm font-bold tabular-nums text-zinc-900 dark:text-white" x-text="walletMoney(wallet.currency, walletCharge(wallet))"></span>
                                            </label>
                                        </template>
                                    </div>
                                </div>
                            </template>
                            <template x-if="coveringWallets().length === 0">
                                <div>
                                    <div class="flex items-center justify-between" x-show="walletOptions.length > 0">
                                        <span class="text-sm font-medium text-zinc-600 dark:text-zinc-400">Your wallets</span>
                                        <span class="text-sm font-semibold tabular-nums text-zinc-900 dark:text-white" x-text="walletOptions.map((w) => walletMoney(w.currency, w.balance)).join(' + ')"></span>
                                    </div>
                                    <div class="text-xs font-medium text-red-600" :class="walletOptions.length > 0 ? 'mt-3' : ''">
                                        None of your wallets has enough to cover this order. Add funds or choose another payment method.
                                    </div>
                                </div>
                            </template>
                        </div>
                    </div>

                    {{-- Coupon — live preview. Apply validates against the cart and
                         shows the discount on the total before payment; the same
                         code submits with the form so the server re-applies it. --}}
                    <div class="mt-5" x-data="{ open: false }">
                        <button
                            type="button"
                            @click="open = !open"
                            :aria-expanded="open.toString()"
                            class="flex w-full items-center gap-2.5 rounded-full border border-zinc-200 bg-transparent px-4 py-3 text-left text-sm font-semibold text-zinc-900 transition-colors hover:border-zinc-300 hover:bg-zinc-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-blue-500/40 dark:border-white/10 dark:text-white dark:hover:border-white/20 dark:hover:bg-white/5"
                        >
                            <svg class="h-5 w-5 shrink-0 text-zinc-500 dark:text-zinc-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75" aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M16.5 6v.75m0 3v.75m0 3v.75m0 3V18m-9-5.25h5.25M7.5 15h3M3.375 5.25c-.621 0-1.125.504-1.125 1.125v3.026a2.999 2.999 0 010 5.198v3.026c0 .621.504 1.125 1.125 1.125h17.25c.621 0 1.125-.504 1.125-1.125v-3.026a2.999 2.999 0 010-5.198V6.375c0-.621-.504-1.125-1.125-1.125H3.375z"/>
                            </svg>
                            <span class="flex-1">{{ __('checkout.coupon_toggle') }}</span>
                            <svg class="h-4 w-4 shrink-0 text-zinc-600 dark:text-zinc-300" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" aria-hidden="true">
                                <path d="M5 12h14"/>
                                <path d="M12 5v14" class="origin-center transition-transform duration-200" :class="open && 'scale-y-0'"/>
                            </svg>
                        </button>
                        <div x-show="open" x-collapse x-cloak class="mt-2">
                            <div class="flex items-stretch gap-2">
                                <input
                                    name="coupon_code"
                                    type="text"
                                    x-model="couponCode"
                                    @input="couponApplied && removeCoupon()"
                                    @keydown.enter.prevent="applyCoupon()"
                                    placeholder="{{ __('checkout.coupon_placeholder') }}"
                                    class="{{ $fieldClass }} !mt-0 flex-1 uppercase"
                                >
                                <button
                                    type="button"
                                    @click="couponApplied ? removeCoupon() : applyCoupon()"
                                    :disabled="couponLoading || (! couponApplied && ! couponCode)"
                                    class="h-11 shrink-0 rounded-full px-5 text-sm font-semibold text-white transition-colors disabled:cursor-not-allowed disabled:opacity-50"
                                    :class="couponApplied ? 'bg-zinc-600 hover:bg-zinc-700' : 'bg-blue-600 hover:bg-blue-700'"
                                >
                                    <span x-show="! couponLoading" x-text="couponApplied ? @js(__('checkout.remove')) : @js(__('checkout.coupon_apply'))"></span>
                                    <span x-show="couponLoading">…</span>
                                </button>
                            </div>
                            <p x-show="couponError" x-cloak class="mt-1.5 text-xs font-medium text-red-600" x-text="couponError"></p>
                            <p x-show="couponApplied" x-cloak class="mt-1.5 inline-flex items-center gap-1 text-xs font-semibold text-emerald-600">
                                <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5"/></svg>
                                <span x-text="@js(__('checkout.coupon_applied')).replace(':code', () => couponApplied)"></span>
                            </p>
                        </div>
                    </div>

                    {{-- Hosted-redirect methods (USSD, Pay With Bank, Bank QR,
                         Mobile Wallet) all share the same "you'll finish on
                         the next screen" panel. Each gets a tiny method-specific
                         hint so the customer knows what to expect (dial code,
                         bank login, QR scan, e-wallet pin). --}}
                    @php
                        // USSD and NQR are scheme names, the same in every language.
                        $hostedMethods = [
                            'ussd'           => ['title' => 'USSD',                                     'hint' => __('checkout.hosted_ussd_hint')],
                            'pay_with_bank'  => ['title' => __('checkout.method_pay_with_bank'),        'hint' => __('checkout.hosted_pay_with_bank_hint')],
                            'bank_qr'        => ['title' => __('checkout.method_bank_qr').' (NQR)',     'hint' => __('checkout.hosted_bank_qr_hint')],
                            'mobile_wallet'  => ['title' => __('checkout.method_mobile_wallet'),        'hint' => __('checkout.hosted_mobile_wallet_hint')],
                        ];
                    @endphp
                    @foreach ($hostedMethods as $key => $meta)
                        <div x-show="method === '{{ $key }}'" x-collapse x-cloak class="mt-5 rounded-[12px] bg-blue-50 px-4 py-4 ring-1 ring-blue-100">
                            <p class="text-sm font-bold text-blue-900">{{ $meta['title'] }}</p>
                            <p class="mt-1 text-xs leading-relaxed text-blue-800/90">{{ $meta['hint'] }}</p>
                            <p class="mt-2 text-[11px] text-blue-700/80">{{ __('checkout.hosted_redirect_note') }}</p>
                        </div>
                    @endforeach

                    @error('payment_method') <p class="mt-3 text-xs text-red-600">{{ $message }}</p> @enderror

                    {{-- Fee breakdown - rendered only when the selected method
                         carries a gateway processing fee, so fee-free methods
                         (wallet) keep the simple single-line total. --}}
                    <div x-show="processingFee() > 0 && method !== 'crypto'" x-cloak class="mt-5 border-t border-zinc-100 dark:border-white/10 pt-5">
                        <div class="flex items-center justify-between text-sm">
                            <span class="font-medium text-zinc-600 dark:text-zinc-400">{{ __('checkout.subtotal') }}</span>
                            <span class="font-semibold tabular-nums text-zinc-900 dark:text-white" x-text="$store.cart.pay($store.cart.subtotal)"></span>
                        </div>
                        <div class="mt-2 flex items-center justify-between gap-4 text-sm">
                            <span class="inline-flex min-w-0 items-center gap-1.5 font-medium text-zinc-600 dark:text-zinc-400">
                                <span x-text="feeLabel()"></span>
                                <span class="text-xs text-zinc-400" x-text="'(' + feeRate() + '%)'" :title="@js(__('checkout.fee_rate_note')).replace(':rate', () => feeRate())"></span>
                            </span>
                            <span class="font-semibold tabular-nums text-zinc-900 dark:text-white" x-text="$store.cart.pay(processingFee())"></span>
                        </div>
                        <div x-show="couponApplied" x-cloak class="mt-2 flex items-center justify-between text-sm">
                            <span class="font-medium text-emerald-600" x-text="@js(__('checkout.discount_with_code')).replace(':code', () => couponApplied)"></span>
                            <span class="font-semibold tabular-nums text-emerald-600" x-text="'-' + $store.cart.pay(couponDiscountDisplay)"></span>
                        </div>
                    </div>

                    {{-- Discount line when there is no processing fee (wallet / crypto) --}}
                    <div x-show="couponApplied && processingFee() <= 0" x-cloak class="mt-4 flex items-center justify-between border-t border-zinc-100 dark:border-white/10 pt-4 text-sm">
                        <span class="font-medium text-emerald-600" x-text="@js(__('checkout.discount_with_code')).replace(':code', () => couponApplied)"></span>
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
                    <div class="mt-5 flex items-start justify-between gap-4 border-t border-zinc-100 dark:border-white/10 pt-5" :class="processingFee() > 0 && 'mt-3 border-t-0 pt-0'">
                        {{-- "Total amount", not "Total amount to pay": for crypto the
                             figure actually payable is quoted on the next screen, straight
                             from the gateway, and promising this is the amount to pay would
                             recreate the two-numbers problem in words. --}}
                        <span class="text-base font-bold text-zinc-900 dark:text-white">
                            {{ __('checkout.total_amount') }}
                            {{-- The charge currency, stated where the figure is. It used to
                                 be the chooser's hint; with the chooser gone this is the
                                 only place on the page that says it, and on a phone the
                                 nav's region pill shows a flag with no code beside it. --}}
                            <span class="mt-0.5 block text-[11px] font-medium text-zinc-500 dark:text-zinc-400">{{ __('checkout.checkout_currency_hint') }}</span>
                        </span>
                        <span x-data="valueFlip()" x-effect="totalLabel(); flash()" class="inline-block text-lg font-extrabold tabular-nums text-zinc-900 dark:text-white" x-text="totalLabel()" aria-live="polite">0.00</span>
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
                            {!! __('checkout.crypto_warning', [
                                'coin' => '<span class="font-bold">'.e(__('checkout.crypto_warning_coin')).'</span>',
                                'network' => '<span class="font-bold">'.e(__('checkout.crypto_warning_network')).'</span>',
                                'loss' => '<span class="font-bold">'.e(__('checkout.crypto_warning_loss')).'</span>',
                            ]) !!}
                        </p>
                    </div>


                    {{-- Continue is signed-in-only: guest purchase is removed.
                         A guest hits the sign-in wall instead; their cart
                         merges into the account at sign-in and the modal
                         returns them right here to pay. --}}
                    <div class="mt-4">
                    @auth
                        {{-- A pause disables the button while it counts down. A lock
                             leaves it tappable: tapping reopens the lock notice, the
                             only place a locked customer can reach support. Paying
                             from wallet balance is never held, so choosing wallet
                             shows the ordinary button whatever is being served. --}}
                        <button type="submit" :disabled="!canContinue() || submitting || (backoffApplies() && ! backoffLocked)"
                            class="flex h-11 w-full items-center justify-center gap-2 rounded-full bg-blue-600 px-4 text-sm font-semibold text-white shadow-md transition-colors hover:bg-blue-700 focus:outline-none focus-visible:ring-2 focus-visible:ring-blue-500/40 disabled:cursor-not-allowed disabled:opacity-50 disabled:hover:bg-blue-600">
                            <template x-if="backoffApplies()">
                                <span class="flex items-center gap-2" x-text="backoffLabel()"></span>
                            </template>
                            <template x-if="!submitting && ! backoffApplies()">
                                <span class="flex items-center gap-2" x-text="@js(__('checkout.continue_with_method')).replace(':method', () => methodLabel()).replace(':amount', () => totalLabel())"></span>
                            </template>
                            <template x-if="submitting && ! backoffApplies()">
                                <span class="flex items-center gap-2">
                                    <svg class="h-5 w-5 animate-spin" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                                        <circle class="opacity-30" cx="12" cy="12" r="9" stroke="currentColor" stroke-width="3"/>
                                        <path class="opacity-90" fill="currentColor" d="M12 3a9 9 0 0 1 9 9h-3a6 6 0 0 0-6-6V3z"/>
                                    </svg>
                                    {{ __('checkout.processing_short') }}
                                </span>
                            </template>
                        </button>
                    @else
                        <button type="button" @click="$dispatch('open-auth-modal', { mode: 'login', returnTo: window.location.pathname + window.location.search })" class="flex h-11 w-full items-center justify-center rounded-full bg-blue-600 px-4 text-sm font-semibold text-white shadow-md transition-colors hover:bg-blue-700 focus:outline-none focus-visible:ring-2 focus-visible:ring-blue-500/40"
                            x-text="@js(__('checkout.sign_in_to_continue_amount')).replace(':amount', () => totalLabel())">
                        </button>
                        <p class="mt-2 text-center text-xs text-zinc-600 dark:text-zinc-400">
                            {{ __('checkout.sign_in_required_note') }}
                            <button type="button" @click="$dispatch('open-auth-modal', { mode: 'register', returnTo: window.location.pathname + window.location.search })" class="ml-1 font-bold text-blue-600 hover:text-blue-700 dark:text-blue-400">
                                {{ __('checkout.create_account') }}
                            </button>
                        </p>
                    @endauth

                    <p class="mt-3 flex items-center justify-center gap-1.5 text-xs text-zinc-600 dark:text-zinc-400">
                        <svg class="h-4 w-4 text-emerald-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75L11.25 15 15 9.75M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
                        </svg>
                        {{ __('checkout.secure_checkout_note') }}
                    </p>
                    </div>
                </form>

            </div>
            
            {{-- Checkout Payment Wizard Modal --}}
            <div
                x-show="open"
                x-cloak
                {{-- Only a real Escape keypress closes the wizard. app.js fires a
                     synthetic Escape on page scroll to collapse dropdowns, and an
                     untrusted one must never abort a payment in flight. --}}
                x-on:keydown.escape.window="$event.isTrusted && open && requestClose()"
                {{-- Closing the tab or reloading mid-payment gets the browser's
                     own "leave this page?" check, the one guard a page can put
                     on that path. --}}
                x-on:beforeunload.window="if (paymentInFlight()) { $event.preventDefault(); $event.returnValue = '' }"
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
                    @click="requestClose()"
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
                    <x-close-button @click="requestClose()" class="absolute right-4 top-4" :aria-label="__('common.close')" />

                    {{-- Cancel guard. A backdrop click, Escape or the close button
                         while a payment is in flight lands here rather than closing:
                         a prompt over the wizard, with staying as the easy choice
                         (the blue button, and the one that takes focus) and the
                         cancel a quiet red text button beneath it. Nothing is charged
                         until a payment completes, so the copy says so. It replaces a
                         browser confirm, which was easy to dismiss without reading. --}}
                    <div
                        x-show="cancelPrompt"
                        x-cloak
                        x-transition.opacity.duration.150ms
                        @click.stop
                        @keydown.escape.stop="cancelPrompt = false"
                        class="absolute inset-0 z-10 flex items-center justify-center rounded-[24px] bg-white/95 p-6 backdrop-blur-sm"
                        role="alertdialog"
                        aria-modal="true"
                        aria-labelledby="checkout-cancel-title"
                        aria-describedby="checkout-cancel-body"
                    >
                        <div class="w-full max-w-sm text-center">
                            <div class="mx-auto flex h-12 w-12 items-center justify-center rounded-full bg-amber-50 dark:bg-amber-500/15">
                                <svg class="h-6 w-6 text-amber-600 dark:text-amber-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75" aria-hidden="true">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m9-.75a9 9 0 11-18 0 9 9 0 0118 0zm-9 3.75h.008v.008H12v-.008z"/>
                                </svg>
                            </div>
                            <h3 id="checkout-cancel-title" class="mt-4 text-base font-bold text-zinc-900 dark:text-white">{{ __('checkout.cancel_prompt_title') }}</h3>
                            <p id="checkout-cancel-body" class="mt-1.5 text-xs leading-relaxed text-zinc-600 dark:text-zinc-300">{{ __('checkout.cancel_prompt_body') }}</p>
                            <div class="mt-6 flex flex-col gap-2.5">
                                <button type="button" x-ref="keepPayingButton" @click="cancelPrompt = false" class="inline-flex h-11 w-full items-center justify-center rounded-full bg-blue-600 text-sm font-semibold text-white hover:bg-blue-700">
                                    {{ __('checkout.cancel_prompt_keep') }}
                                </button>
                                <button type="button" @click="cancelPayment()" :disabled="cancelling" class="inline-flex h-11 w-full items-center justify-center rounded-full text-sm font-semibold text-red-600 hover:bg-red-50 disabled:opacity-60 dark:hover:bg-red-500/10">
                                    <span x-text="cancelling ? @js(__('checkout.cancel_prompt_cancelling')) : @js(__('checkout.cancel_prompt_cancel'))"></span>
                                </button>
                            </div>
                        </div>
                    </div>

                    <!-- Active Payment Session Details & Wizard -->
                    {{-- Every panel that can open without a payment session must be
                         listed here, or the modal opens empty. backoff_notice (the
                         card-testing lock) never has a session. --}}
                    <div x-show="session || paymentState === 'error' || paymentState === 'kyc_required' || paymentState === 'email_notice' || paymentState === 'backoff_notice' || paymentState === 'wallet_pin_setup'" class="mt-2">
                        <!-- Countdown timer banner -->
                        <div x-show="['awaiting_transfer', 'awaiting_confirmation', 'action_pin', 'action_otp', 'action_3ds'].includes(paymentState)" 
                             class="mb-5 flex items-center justify-between rounded-[12px] bg-amber-50 px-4 py-2.5 text-xs font-semibold text-amber-800 ring-1 ring-amber-200 dark:bg-amber-500/10 dark:text-amber-200 dark:ring-amber-500/30">
                            <span class="flex items-center gap-1.5">
                                <svg class="h-4 w-4 animate-pulse text-amber-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6h4.5m4.5 0a9 9 0 11-18 0 9 9 0 0118 0z"/>
                                </svg>
                                {{ __('checkout.wizard_timer_label') }}
                            </span>
                            <span class="font-extrabold text-sm tabular-nums text-amber-700" x-text="timerText"></span>
                        </div>

                        <!-- Card Auth: PIN Challenge -->
                        <div x-show="paymentState === 'action_pin'">
                            <h3 class="text-sm font-bold text-zinc-900 dark:text-white mb-2">{{ __('checkout.card_pin_title') }}</h3>
                            <p class="text-xs text-zinc-600 dark:text-zinc-400 mb-4">{{ __('checkout.card_pin_hint') }}</p>
                            <div class="space-y-4">
                                <input type="password" x-model="pinValue" maxlength="4" placeholder="••••" class="w-full rounded-full border-2 border-zinc-200 px-3 py-3 text-center text-lg font-bold tracking-widest text-zinc-900 dark:text-white focus:border-blue-500 focus:ring-2 focus:ring-blue-500/15 focus:border-edge dark:focus:border-edge transition-colors">
                                <button type="button" @click="paySession('card', cardDetails)" class="flex h-11 w-full items-center justify-center rounded-full bg-blue-600 text-sm font-semibold text-white hover:bg-blue-700">
                                    {{ __('checkout.card_pin_confirm') }}
                                </button>
                            </div>
                        </div>

                        <!-- Wallet Auth: Transaction PIN Challenge -->
                        <div x-show="paymentState === 'wallet_pin'">
                            <h3 class="text-sm font-bold text-zinc-900 dark:text-white mb-2">{{ __('checkout.wallet_pin_title') }}</h3>
                            <p class="text-xs text-zinc-600 dark:text-zinc-400 mb-4">{{ __('checkout.wallet_pin_hint') }}</p>
                            <div class="space-y-4">
                                <input type="password" inputmode="numeric" x-ref="walletPinInput" x-model="walletPin" maxlength="4" placeholder="••••"
                                    @keydown.enter.prevent="authorizeWalletPayment()"
                                    class="w-full rounded-full border-2 border-zinc-200 px-3 py-3 text-center text-lg font-bold tracking-widest text-zinc-900 dark:text-white focus:border-blue-500 focus:ring-2 focus:ring-blue-500/15 focus:border-edge dark:focus:border-edge transition-colors">
                                <p x-show="errorMessage" x-cloak class="text-center text-xs text-red-600" x-text="errorMessage"></p>
                                <button type="button" @click="authorizeWalletPayment()" :disabled="authorizingWallet"
                                    class="flex h-11 w-full items-center justify-center gap-2 rounded-full bg-blue-600 text-sm font-semibold text-white hover:bg-blue-700 disabled:opacity-60">
                                    <svg x-show="authorizingWallet" class="h-4 w-4 animate-spin" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
                                    </svg>
                                    <span x-text="authorizingWallet ? @js(__('checkout.wallet_authorizing')) : @js(__('checkout.wallet_authorize'))"></span>
                                </button>
                                <a href="{{ route('dashboard.security') }}" class="block text-center text-[11px] font-medium text-zinc-500 underline underline-offset-2 hover:text-zinc-700">{{ __('checkout.wallet_manage_pin') }}</a>
                            </div>
                        </div>

                        {{-- Wallet: no PIN yet. Setting one here keeps the customer in
                             the checkout: the PIN is saved through the same endpoint the
                             security page uses, then the kept submission is sent again
                             and the modal moves on to the PIN step. A calm blue lock,
                             not the red failure state: nothing failed, something is
                             missing. --}}
                        <div x-show="paymentState === 'wallet_pin_setup'" x-cloak class="flex flex-col items-center py-4 text-center">
                            <div class="flex h-12 w-12 items-center justify-center rounded-full bg-blue-50 dark:bg-blue-500/15">
                                <svg class="h-6 w-6 text-blue-600 dark:text-blue-300" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75" aria-hidden="true">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M16.5 10.5V6.75a4.5 4.5 0 1 0-9 0v3.75m-.75 11.25h10.5a2.25 2.25 0 0 0 2.25-2.25v-6.75a2.25 2.25 0 0 0-2.25-2.25H6.75a2.25 2.25 0 0 0-2.25 2.25v6.75a2.25 2.25 0 0 0 2.25 2.25Z"/>
                                </svg>
                            </div>
                            <h3 class="mt-4 text-sm font-bold text-zinc-900 dark:text-white">{{ __('checkout.wallet_pin_setup_title') }}</h3>
                            <p class="mt-1.5 px-4 text-xs text-zinc-600 dark:text-zinc-300">{{ __('checkout.wallet_pin_setup_hint') }}</p>
                            <div class="mt-5 w-full space-y-3 px-2">
                                <label class="sr-only" for="checkout-new-pin">{{ __('checkout.wallet_pin_setup_new') }}</label>
                                <input id="checkout-new-pin" type="password" inputmode="numeric" autocomplete="new-password" x-ref="newPinInput" x-model="newPin" maxlength="4" placeholder="{{ __('checkout.wallet_pin_setup_new') }}"
                                    class="w-full rounded-full border-2 border-zinc-200 px-3 py-3 text-center text-lg font-bold tracking-widest text-zinc-900 outline-none focus:border-blue-500 focus:ring-2 focus:ring-blue-500/15 dark:text-white">
                                <label class="sr-only" for="checkout-new-pin-confirmation">{{ __('checkout.wallet_pin_setup_confirm') }}</label>
                                <input id="checkout-new-pin-confirmation" type="password" inputmode="numeric" autocomplete="new-password" x-model="newPinConfirmation" maxlength="4" placeholder="{{ __('checkout.wallet_pin_setup_confirm') }}"
                                    @keydown.enter.prevent="savePinAndContinue()"
                                    class="w-full rounded-full border-2 border-zinc-200 px-3 py-3 text-center text-lg font-bold tracking-widest text-zinc-900 outline-none focus:border-blue-500 focus:ring-2 focus:ring-blue-500/15 dark:text-white">
                                <p x-show="pinSetupError" x-cloak role="alert" class="text-center text-xs text-red-600" x-text="pinSetupError"></p>
                                <button type="button" @click="savePinAndContinue()" :disabled="settingUpPin"
                                    class="flex h-11 w-full items-center justify-center gap-2 rounded-full bg-blue-600 text-sm font-semibold text-white hover:bg-blue-700 disabled:opacity-60">
                                    <svg x-show="settingUpPin" class="h-4 w-4 animate-spin" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
                                    </svg>
                                    <span x-text="settingUpPin ? @js(__('checkout.wallet_pin_setup_saving')) : @js(__('checkout.wallet_pin_setup_save'))"></span>
                                </button>
                                <button type="button" @click="closeModal()" class="block w-full text-center text-[11px] font-medium text-zinc-500 underline underline-offset-2 hover:text-zinc-700">{{ __('checkout.wallet_pin_setup_later') }}</button>
                            </div>
                        </div>

                        <!-- Card Auth: OTP Challenge -->
                        <div x-show="paymentState === 'action_otp'">
                            <h3 class="text-sm font-bold text-zinc-900 dark:text-white mb-2">{{ __('checkout.otp_title') }}</h3>
                            <p class="text-xs text-zinc-600 dark:text-zinc-400 mb-4" x-text="actionMessage"></p>
                            <div class="space-y-4">
                                <input type="text" x-model="otpValue" placeholder="123456" class="w-full rounded-full border-2 border-zinc-200 px-3 py-3 text-center text-lg font-bold tracking-widest text-zinc-900 dark:text-white focus:border-blue-500 focus:ring-2 focus:ring-blue-500/15 focus:border-edge dark:focus:border-edge transition-colors">
                                <button type="button" @click="paySession('card', cardDetails)" class="flex h-11 w-full items-center justify-center rounded-full bg-blue-600 text-sm font-semibold text-white hover:bg-blue-700">
                                    {{ __('checkout.otp_verify') }}
                                </button>
                            </div>
                        </div>

                        <!-- Card Auth: 3D Secure Redirect -->
                        <div x-show="paymentState === 'action_3ds'">
                            <h3 class="text-sm font-bold text-zinc-900 dark:text-white mb-2">{{ __('checkout.threeds_title') }}</h3>
                            <p class="text-xs text-zinc-600 dark:text-zinc-400 mb-4">{{ __('checkout.threeds_hint') }}</p>

                            <div class="w-full overflow-hidden rounded-[12px] border border-zinc-200 bg-zinc-50 h-[55vh] min-h-[400px] max-h-[520px]">
                                <iframe :src="session?.payment_payload?.redirect_url" class="h-full w-full border-0" allow="payment"></iframe>
                            </div>

                            <button type="button" @click="verifyPayment()" :disabled="verifying" class="mt-4 flex h-11 w-full items-center justify-center gap-2 rounded-full bg-blue-600 text-sm font-semibold text-white hover:bg-blue-700 disabled:opacity-60">
                                <svg x-show="verifying" class="h-4 w-4 animate-spin" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
                                </svg>
                                <span x-text="verifying ? @js(__('checkout.threeds_verifying')) : @js(__('checkout.threeds_completed'))"></span>
                            </button>
                            <p x-show="errorMessage" x-cloak class="mt-2 text-center text-xs text-amber-600" x-text="errorMessage"></p>
                        </div>

                        <!-- Bank Transfer virtual accounts display or Crypto invoice -->
                        <div x-show="paymentState === 'awaiting_transfer'">
                            <!-- If Bank Transfer details -->
                            <template x-if="session?.payment_payload?.bank_details || session?.payment_payload?.account_number">
                                <div>
                                    <h3 class="text-sm font-bold text-zinc-900 dark:text-white mb-2">{{ __('checkout.bank_transfer_title') }}</h3>
                                    <p class="text-xs text-zinc-600 dark:text-zinc-400 mb-4">{{ __('checkout.bank_transfer_hint') }}</p>

                                    <div class="bg-zinc-50 border border-zinc-200 rounded-[12px] p-4 space-y-3 shadow-inner">
                                        <div class="flex justify-between items-center text-xs">
                                            <span class="text-zinc-500 font-medium">{{ __('checkout.bank_name') }}</span>
                                            <span class="font-bold text-zinc-900 dark:text-white" x-text="bankDetails?.bank_name"></span>
                                        </div>
                                        <div class="flex justify-between items-center text-xs">
                                            <span class="text-zinc-500 font-medium">{{ __('checkout.account_number') }}</span>
                                            <div class="flex items-center gap-2">
                                                <span class="font-extrabold text-zinc-900 dark:text-white text-sm tracking-wider tabular-nums bg-white px-2 py-1 rounded-[12px] border border-zinc-150" x-text="bankDetails?.account_number"></span>
                                                <button type="button" @click="copyToClipboard(bankDetails?.account_number, 'account')" class="text-blue-600 hover:text-blue-800 text-[10px] font-bold bg-blue-50 px-2 py-1 rounded-full transition-all">
                                                    <span x-show="!copiedStates['account']">{{ __('common.copy') }}</span>
                                                    <span x-show="copiedStates['account']" class="text-emerald-600 font-bold">{{ __('checkout.copied') }}</span>
                                                </button>
                                            </div>
                                        </div>
                                        <div class="flex justify-between items-center text-xs">
                                            <span class="text-zinc-500 font-medium">{{ __('checkout.account_name') }}</span>
                                            <span class="font-bold text-zinc-900 dark:text-white" x-text="bankDetails?.account_name || 'Roddy Technologies LTD'"></span>
                                        </div>
                                        <div class="flex justify-between items-center text-xs border-t border-zinc-200 pt-3">
                                            <span class="text-zinc-500 font-medium">{{ __('checkout.amount') }}</span>
                                            <span class="font-extrabold text-blue-700 text-base tabular-nums" x-text="session?.currency + ' ' + Number(bankDetails?.amount || session?.amount).toFixed(2)"></span>
                                        </div>
                                    </div>
                                </div>
                            </template>

                            <!-- If Crypto details -->
                            <template x-if="session?.payment_payload?.qr_payload || session?.payment_payload?.pay_address">
                                <div>
                                    <h3 class="text-sm font-bold text-zinc-900 dark:text-white text-center mb-1">{{ __('checkout.crypto_details_title') }}</h3>

                                    {{-- The amount leads, because it is the one figure that
                                         must be exact. It comes straight from the gateway and
                                         is the same number quoted for the rest of this
                                         payment — it is never recalculated locally. --}}
                                    <div class="mt-3 rounded-[12px] border border-blue-200 bg-blue-50 px-4 py-3 text-center dark:border-blue-500/30 dark:bg-blue-900/20">
                                        <span class="block text-[10px] font-bold uppercase tracking-wider text-blue-700/70 dark:text-blue-300/70">{{ __('checkout.crypto_send_exactly') }}</span>
                                        <div class="mt-1 flex items-center justify-center gap-2">
                                            <span class="text-xl font-extrabold tabular-nums tracking-tight text-blue-900 dark:text-blue-100" x-text="session?.payment_payload?.pay_amount"></span>
                                            <span class="text-sm font-bold uppercase text-blue-700 dark:text-blue-300" x-text="session?.payment_payload?.pay_currency"></span>
                                            <button type="button" @click="copyToClipboard(session?.payment_payload?.pay_amount, 'amount_crypto')" class="rounded-full bg-white/80 px-2 py-1 text-[10px] font-bold text-blue-700 transition-all hover:bg-white">
                                                <span x-show="!copiedStates['amount_crypto']">{{ __('common.copy') }}</span>
                                                <span x-show="copiedStates['amount_crypto']" class="font-bold text-emerald-600">{{ __('checkout.copied') }}</span>
                                            </button>
                                        </div>
                                        <p class="mt-1 text-[10px] text-blue-700/80 dark:text-blue-300/80">{{ __('checkout.crypto_fees_included') }}</p>
                                    </div>

                                    <div class="mt-4 flex flex-col items-center gap-4 bg-zinc-50 p-4 border border-zinc-200 rounded-[12px] shadow-inner">
                                        <!-- QR Code, rendered by us -->
                                        <div class="flex shrink-0 flex-col items-center rounded-[12px] bg-white p-3 border border-zinc-150 shadow-sm">
                                            <img
                                                :src="session?.id ? ('/api/payment-sessions/' + session.id + '/crypto-qr') : ''"
                                                alt="{{ __('checkout.crypto_qr_alt') }}"
                                                class="h-32 w-32 object-contain"
                                            />
                                            <span class="mt-1.5 text-[9px] font-bold uppercase tracking-wider text-zinc-400">{{ __('checkout.crypto_scan_to_pay') }}</span>
                                        </div>

                                        <!-- Details -->
                                        <div class="w-full space-y-3.5 text-xs">
                                            <div>
                                                <span class="text-[10px] font-semibold text-zinc-400 uppercase tracking-wider block mb-1">{{ __('checkout.crypto_currency_network') }}</span>
                                                <div class="flex items-center gap-1.5">
                                                    <span class="rounded-[12px] bg-blue-50 px-2 py-0.5 font-bold text-blue-700 uppercase" x-text="session?.payment_payload?.pay_currency"></span>
                                                    <span class="text-[10px] font-medium text-zinc-500 uppercase" x-text="@js(__('checkout.crypto_network_value')).replace(':network', () => session?.payment_payload?.network || '')"></span>
                                                </div>
                                            </div>
                                            <div>
                                                <span class="text-[10px] font-semibold text-zinc-400 uppercase tracking-wider block mb-1">{{ __('checkout.crypto_deposit_address') }}</span>
                                                <div class="mt-1 flex items-center gap-1.5">
                                                    <input type="text" readonly :value="session?.payment_payload?.pay_address" class="w-full bg-white px-2.5 py-1.5 rounded-full border-2 border-zinc-200 text-[10px] text-zinc-800 font-mono select-all outline-none focus:border-edge dark:focus:border-edge transition-colors">
                                                    <button type="button" @click="copyToClipboard(session?.payment_payload?.pay_address, 'address')" class="text-blue-600 hover:text-blue-800 text-[10px] font-bold shrink-0 bg-blue-50 px-2.5 py-1.5 rounded-full transition-all border border-blue-100">
                                                        <span x-show="!copiedStates['address']">{{ __('common.copy') }}</span>
                                                        <span x-show="copiedStates['address']" class="text-emerald-600 font-bold">{{ __('checkout.copied') }}</span>
                                                    </button>
                                                </div>
                                            </div>

                                            {{-- Memo / destination tag. Coins that require one cannot be
                                                 credited automatically without it, so when the gateway
                                                 returns a tag it is shown as loudly as the address. --}}
                                            <template x-if="session?.payment_payload?.payin_extra_id">
                                                <div class="rounded-[12px] border border-amber-300 bg-amber-50 p-2.5 dark:border-amber-500/30 dark:bg-amber-900/20">
                                                    <span class="mb-1 block text-[10px] font-bold uppercase tracking-wider text-amber-800 dark:text-amber-300">{{ __('checkout.crypto_memo_label') }} — {{ __('checkout.crypto_memo_required') }}</span>
                                                    <div class="mt-1 flex items-center gap-1.5">
                                                        <input type="text" readonly :value="session?.payment_payload?.payin_extra_id" class="w-full rounded-full border-2 border-amber-200 bg-white px-2.5 py-1.5 font-mono text-[10px] text-zinc-800 outline-none select-all border-zinc-200 dark:border-white/10 focus:border-edge dark:focus:border-edge transition-colors">
                                                        <button type="button" @click="copyToClipboard(session?.payment_payload?.payin_extra_id, 'memo')" class="shrink-0 rounded-full border border-amber-200 bg-amber-100 px-2.5 py-1.5 text-[10px] font-bold text-amber-800 transition-all">
                                                            <span x-show="!copiedStates['memo']">{{ __('common.copy') }}</span>
                                                            <span x-show="copiedStates['memo']" class="font-bold text-emerald-600">{{ __('checkout.copied') }}</span>
                                                        </button>
                                                    </div>
                                                    <p class="mt-1.5 text-[10px] text-amber-800/90 dark:text-amber-300/90">{{ __('checkout.crypto_memo_hint') }}</p>
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
                                    <p class="text-sm font-bold text-amber-900 dark:text-amber-200">{{ __('checkout.underpaid_title') }}</p>
                                    <div class="mt-2 space-y-1 text-xs text-amber-800 dark:text-amber-300">
                                        <div class="flex justify-between gap-3">
                                            <span>{{ __('checkout.underpaid_expected') }}</span>
                                            <span class="font-semibold tabular-nums" x-text="session.underpayment.expected + ' ' + session.underpayment.currency"></span>
                                        </div>
                                        <div class="flex justify-between gap-3">
                                            <span>{{ __('checkout.underpaid_received') }}</span>
                                            <span class="font-semibold tabular-nums" x-text="session.underpayment.received + ' ' + session.underpayment.currency"></span>
                                        </div>
                                        <div class="flex justify-between gap-3 border-t border-amber-300/60 pt-1 font-bold">
                                            <span>{{ __('checkout.underpaid_owing') }}</span>
                                            <span class="tabular-nums" x-text="session.underpayment.shortfall + ' ' + session.underpayment.currency"></span>
                                        </div>
                                    </div>
                                    <p class="mt-2 text-[11px] text-amber-800/90 dark:text-amber-300/90">
                                        {{ __('checkout.underpaid_reason') }}
                                    </p>

                                    <template x-if="session.underpayment.can_top_up">
                                        <button type="button" @click="requestTopUp()" :disabled="topUpLoading"
                                            class="mt-3 w-full rounded-full bg-amber-600 px-4 py-2.5 text-sm font-bold text-white transition-colors hover:bg-amber-700 disabled:opacity-60">
                                            <span x-show="!topUpLoading">{{ __('checkout.underpaid_pay_balance') }}</span>
                                            <span x-show="topUpLoading">{{ __('checkout.underpaid_creating') }}</span>
                                        </button>
                                    </template>
                                    <template x-if="!session.underpayment.can_top_up">
                                        <p class="mt-3 text-[11px] font-semibold text-amber-900 dark:text-amber-200">
                                            {{ __('checkout.underpaid_contact_support') }}
                                        </p>
                                    </template>
                                    <p x-show="topUpError" x-cloak class="mt-2 text-[11px] font-semibold text-red-700 dark:text-red-400" x-text="topUpError"></p>
                                </div>
                            </template>

                            {{-- A lapsed quote is not a failed payment: coins already sent still
                                 credit once they confirm. Saying otherwise would panic customers
                                 whose transaction is simply still on-chain. --}}
                            <div x-show="quoteExpired && !session?.underpayment" x-cloak class="mt-5 rounded-[12px] border border-amber-300 bg-amber-50 px-4 py-3 text-center dark:border-amber-500/30 dark:bg-amber-900/20">
                                <p class="text-xs font-bold text-amber-900 dark:text-amber-200">{{ __('checkout.quote_expired_title') }}</p>
                                <p class="mt-1 text-[11px] text-amber-800 dark:text-amber-300">
                                    {{ __('checkout.quote_expired_sent') }} — {{ __('checkout.quote_expired_credited') }}
                                    {{ __('checkout.quote_expired_not_paid') }}
                                </p>
                            </div>

                            <div x-show="!quoteExpired && !session?.underpayment" class="flex flex-col items-center mt-5 text-center">
                                <svg class="h-5 w-5 animate-spin text-blue-600" fill="none" viewBox="0 0 24 24">
                                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
                                </svg>
                                <p class="text-xs font-semibold text-zinc-800 mt-2">{{ __('checkout.awaiting_transfer') }}</p>
                                <p class="text-[10px] text-zinc-500 mt-1">{{ __('checkout.awaiting_transfer_hint') }}</p>
                            </div>
                        </div>

                        <!-- Mobile money push prompt -->
                        <div x-show="paymentState === 'awaiting_confirmation'">
                            <h3 class="text-sm font-bold text-zinc-900 dark:text-white mb-2">{{ __('checkout.momo_authorize_title') }}</h3>
                            <p class="text-xs leading-relaxed text-zinc-600 dark:text-zinc-400 mb-4" x-text="actionMessage"></p>

                            <div class="flex flex-col items-center py-4 text-center">
                                <span class="flex h-16 w-16 items-center justify-center rounded-[12px] bg-blue-50 ring-8 ring-blue-100/50 mb-5">
                                    <svg class="h-8 w-8 text-blue-600 animate-bounce" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 18h.01M8 21h8a2 2 0 002-2V5a2 2 0 00-2-2H8a2 2 0 00-2 2v14a2 2 0 002 2z"/>
                                    </svg>
                                </span>
                                <svg class="h-6 w-6 animate-spin text-blue-600" fill="none" viewBox="0 0 24 24">
                                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
                                </svg>
                                <p class="text-xs font-semibold text-zinc-800 dark:text-zinc-200 mt-3">{{ __('checkout.momo_verifying') }}</p>
                            </div>

                            {{-- No prompt? The operator's own way to approve the
                                 pending payment: the dial code large enough to
                                 read off the screen, the steps beneath it. Hidden
                                 for a market with no code on file. --}}
                            <div x-show="momoApproval" x-cloak class="mt-2 rounded-[12px] bg-[#f4f6f5] p-4 text-left dark:bg-white/5" data-momo-approval>
                                <p class="text-xs font-bold text-zinc-900 dark:text-white">{{ __('checkout.momo_no_prompt_title') }}</p>
                                <p class="mt-1 text-xs leading-relaxed text-zinc-600 dark:text-zinc-300" x-text="@js(__('checkout.momo_no_prompt_dial')).replace(':code', momoApproval?.code || '').replace(':network', momoApproval?.network || '')"></p>
                                <p class="mt-2 font-mono text-2xl font-black tracking-[0.2em] text-blue-600 dark:text-blue-300" x-text="momoApproval?.code"></p>
                                <ol class="mt-2 list-decimal space-y-1 pl-4 text-xs leading-relaxed text-zinc-600 dark:text-zinc-300">
                                    <template x-for="step in (momoApproval?.steps || [])" :key="step">
                                        <li x-text="step"></li>
                                    </template>
                                </ol>
                            </div>
                            <p class="mt-3 text-center text-[11px] text-zinc-500 dark:text-zinc-400">{{ __('checkout.momo_keep_open') }}</p>
                        </div>

                        <!-- Processing state -->
                        <div x-show="paymentState === 'processing'" class="flex flex-col items-center py-8 text-center">
                            <svg class="h-10 w-10 animate-spin text-blue-600" fill="none" viewBox="0 0 24 24">
                                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
                            </svg>
                            <p class="mt-4 text-sm font-bold text-zinc-900 dark:text-white" x-text="method === 'apple_pay' ? @js(__('checkout.processing_apple_pay')) : @js(__('checkout.processing_transaction'))"></p>
                            <p class="mt-1 text-xs text-zinc-500">{{ __('checkout.do_not_close') }}</p>
                        </div>

                        <!-- Success state: same animated tick as the order-complete
                             page so every payment method celebrates identically. -->
                        <div x-show="paymentState === 'success'" class="flex flex-col items-center py-8 text-center">
                            <x-success-tick />
                            <h3 class="mt-4 text-base font-bold text-zinc-950">{{ __('checkout.payment_complete') }}</h3>
                            <p class="mt-1.5 text-xs text-zinc-600 dark:text-zinc-400 font-medium">{{ __('checkout.payment_complete_hint') }}</p>
                        </div>

                        <!-- Error state: animated red cross, the tick's counterpart. -->
                        <div x-show="paymentState === 'error'" class="flex flex-col items-center py-6 text-center">
                            <x-error-cross />
                            <h3 class="mt-4 text-sm font-bold text-zinc-900 dark:text-white">{{ __('checkout.payment_failed_title') }}</h3>
                            <p class="mt-1.5 text-xs text-red-600 px-4" x-html="errorMessage" @click="if ($event.target.closest('[data-pin-setup]')) { startPinSetup() }"></p>

                            @include('shop._payment-failure-help')

                            <button type="button" @click="closeModal()" class="mt-6 rounded-full bg-zinc-100 px-5 py-2.5 text-xs font-semibold text-zinc-800 hover:bg-zinc-200">
                                {{ __('checkout.close_modify_details') }}
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
                            <h3 class="mt-4 text-sm font-bold text-zinc-900 dark:text-white">{{ __('checkout.email_notice_title') }}</h3>
                            <p class="mt-1.5 text-xs text-zinc-600 dark:text-zinc-300 px-4" x-text="errorMessage"></p>

                            <p x-show="resendFlash" x-cloak class="mt-3 rounded-[10px] bg-emerald-50 px-3 py-2 text-xs text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-300" x-text="resendFlash"></p>

                            <div class="mt-6 flex items-center justify-center gap-2.5">
                                <button
                                    type="button"
                                    @click="resendVerification()"
                                    :disabled="resendRemaining > 0 || resendBusy"
                                    class="rounded-full px-5 py-2.5 text-xs font-semibold text-zinc-700 ring-1 ring-zinc-300 transition-colors hover:bg-zinc-100 disabled:cursor-not-allowed disabled:opacity-50 dark:text-zinc-200 dark:ring-white/15 dark:hover:bg-white/5"
                                    x-text="resendBusy ? @js(__('checkout.resend_sending')) : (resendRemaining > 0 ? @js(__('checkout.resend_in')).replace(':time', () => resendLabel(resendRemaining)) : @js(__('checkout.resend_email')))"
                                ></button>
                                <button type="button" @click="closeModal()" class="inline-flex h-10 items-center rounded-full bg-blue-600 px-8 text-xs font-semibold text-white hover:bg-blue-700">
                                    {{ __('checkout.got_it') }}
                                </button>
                            </div>
                        </div>

                        {{-- Payments paused: the card-testing limiter held this
                             attempt. Deliberately NOT the red failure state, which
                             would read as another decline and push a real customer
                             to try one more card. Amber, a live countdown, and a
                             way to reach a human, because the only person who ever
                             sees the six hour hold and deserves help is the
                             customer it caught by mistake. --}}
                        <div x-show="paymentState === 'backoff_notice'" x-cloak class="flex flex-col items-center py-6 text-center">
                            <div class="flex h-12 w-12 items-center justify-center rounded-full bg-amber-50 dark:bg-amber-500/15">
                                <svg class="h-6 w-6 text-amber-600 dark:text-amber-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75" aria-hidden="true">
                                    <circle cx="12" cy="12" r="9"/>
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 7.5V12l2.75 1.75"/>
                                </svg>
                            </div>
                            <h3 class="mt-4 text-sm font-bold text-zinc-900 dark:text-white">Suspicious payments detected</h3>
                            <p class="mt-1.5 text-xs text-zinc-600 dark:text-zinc-300 px-4" x-text="backoffMessage"></p>

                            <p x-show="backoffRemaining > 0" x-cloak class="mt-3 rounded-[10px] bg-amber-50 px-3 py-2 text-xs font-semibold text-amber-700 dark:bg-amber-500/10 dark:text-amber-300" x-text="'Try again in ' + backoffCountdown()"></p>

                            <div class="mt-6 flex items-center justify-center gap-2.5">
                                <a href="{{ route('shop.contact') }}" class="rounded-full px-5 py-2.5 text-xs font-semibold text-zinc-700 ring-1 ring-zinc-300 transition-colors hover:bg-zinc-100 dark:text-zinc-200 dark:ring-white/15 dark:hover:bg-white/5">
                                    Contact support
                                </a>
                                <button type="button" @click="closeModal()" class="inline-flex h-10 items-center rounded-full bg-blue-600 px-8 text-xs font-semibold text-white hover:bg-blue-700">
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
                            <h3 class="mt-4 text-sm font-bold text-zinc-900 dark:text-white">{{ __('checkout.kyc_title') }}</h3>
                            <p class="mt-1.5 text-xs text-zinc-600 dark:text-zinc-300 px-4" x-text="kycMessage"></p>

                            <button type="button" @click="verifyIdentity()" class="mt-6 inline-flex h-10 items-center rounded-full bg-blue-600 px-8 text-xs font-semibold text-white hover:bg-blue-700">
                                {{ __('checkout.kyc_verify') }}
                            </button>
                            <button type="button" @click="closeModal()" class="mt-2.5 text-xs font-medium text-zinc-500 hover:text-zinc-700 dark:text-zinc-400 dark:hover:text-zinc-200">
                                {{ __('checkout.kyc_not_now') }}
                            </button>
                        </div>
                    </div>
                </div>
            </div>

        </div>
    </div>
    </div>

    <script>
        window.checkoutPage = function (cryptoRates, walletBalances, isLoggedIn, rcoinConfig, hasTransactionPin, paymentFees, initialHold = null, walletOptions = []) {
            return {
                method: 'card',
                crypto: '',
                submitting: false,
                cryptoRates: cryptoRates || {},
                walletBalances: walletBalances || {},
                // Every wallet the customer can pay from: {currency, balance,
                // per_usd}. The one that pays is walletCurrency; empty until a
                // covering wallet is chosen (see ensureWalletChoice).
                walletOptions: Array.isArray(walletOptions) ? walletOptions : [],
                walletCurrency: '',
                isLoggedIn: isLoggedIn || false,
                hasTransactionPin: hasTransactionPin || false,
                paymentFees: paymentFees || { fee_free: [], methods: {}, default: { transaction: 0, international: 0 } },
                deliveryMode: 'self',

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
                            this.couponError = json.message || @js(__('checkout.coupon_not_applied'));
                        }
                    } catch (e) {
                        this.removeCoupon();
                        this.couponError = @js(__('checkout.coupon_check_failed'));
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

                // Labels and descriptions are copy; keys and icons are not. Card
                // network and brand names (Visa, MTN, Apple Pay) and the USSD
                // scheme name read the same in every language, so they stay literal.
                allMethods: [
                    { key: 'card',          label: @js(__('checkout.method_card')),          desc: 'Visa, Mastercard',           icon: '/assets/payment-card.svg' },
                    { key: 'mobile_money',  label: @js(__('checkout.method_mobile_money')),  desc: 'MTN, Orange, Vodafone, M-Pesa', icon: '/assets/payment-mobile-money.svg' },
                    { key: 'bank_transfer', label: @js(__('checkout.method_bank_transfer')), desc: @js(__('checkout.method_bank_transfer_desc')), icon: '/assets/payment-bank-transfer.svg' },
                    // New: covers Flutterwave's "Pay With Bank" (internet banking
                    // redirect) for NGN / GBP / EUR.
                    { key: 'pay_with_bank', label: @js(__('checkout.method_pay_with_bank')), desc: @js(__('checkout.method_pay_with_bank_desc')), icon: '/assets/payment-pay-with-bank.svg' },
                    // New: USSD dial codes (Nigeria). Customer dials a code on
                    // their phone to authorise the payment.
                    { key: 'ussd',          label: 'USSD',                                   desc: @js(__('checkout.method_ussd_desc')), icon: '/assets/payment-ussd.svg' },
                    // New: scan-to-pay QR (Nigerian banks).
                    { key: 'bank_qr',       label: @js(__('checkout.method_bank_qr')),       desc: @js(__('checkout.method_bank_qr_desc')), icon: '/assets/payment-bank-qr.svg' },
                    // New: NGN digital wallets (OPay / eNaira). Provider name
                    // hidden per project convention; admin reconciliation still
                    // sees the specific provider on the order.
                    { key: 'mobile_wallet', label: @js(__('checkout.method_mobile_wallet')), desc: @js(__('checkout.method_mobile_wallet_desc')), icon: '/assets/payment-mobile-wallet.svg' },
                    { key: 'apple_pay',     label: 'Apple Pay',                              desc: @js(__('checkout.method_apple_pay_desc')), icon: '/assets/payment-apple-pay.svg' },
                    { key: 'crypto',        label: @js(__('checkout.method_crypto')),        desc: @js(__('checkout.method_crypto_desc')), icon: '/assets/USDT.svg' },
                    { key: 'wallet',        label: @js(__('checkout.method_wallet')),        desc: @js(__('checkout.method_wallet_desc')), icon: '/assets/Wallet.svg' }
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
                    // Apple Pay only in USD, GBP and NGN: the gateway refuses
                    // it in every other currency before looking at anything
                    // else (mirrors SupportedPaymentMethods).
                    // USD also carries mobile money: it rides the Francophone
                    // rail in CFA francs (MobileMoneyRail), second in the grid
                    // so a card-first market keeps its card first.
                    const mapping = {
                        'USD': ['card', 'mobile_money', 'crypto', 'wallet'],
                        'EUR': ['card', 'pay_with_bank', 'crypto', 'wallet'],
                        'GBP': ['card', 'pay_with_bank', 'crypto', 'wallet'],
                        'NGN': ['bank_transfer', 'card', 'pay_with_bank', 'ussd', 'bank_qr', 'mobile_wallet', 'crypto', 'wallet'],
                        'GHS': ['mobile_money', 'card', 'crypto', 'wallet'],
                        'XAF': ['mobile_money', 'card', 'crypto', 'wallet'],
                        'XOF': ['mobile_money', 'card', 'crypto', 'wallet'],
                        'KES': ['mobile_money', 'card', 'crypto', 'wallet'],
                        'UGX': ['mobile_money', 'card', 'crypto', 'wallet'],
                        'RWF': ['mobile_money', 'card', 'crypto', 'wallet'],
                    };
                    // A currency with no list of its own gets card only, matching
                    // SupportedPaymentMethods::FALLBACK. Offering Crypto here drew a
                    // 422 from the pay step, and with no currency chooser on this
                    // page there is no longer any way out of it.
                    const allowedKeys = mapping[currency] || ['card'];
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
                // The PIN setup step, for a wallet payment with no PIN yet.
                newPin: '',
                newPinConfirmation: '',
                settingUpPin: false,
                pinSetupError: '',
                momoDetails: {
                    phone_number: '',
                    network: ''
                },
                paymentState: 'idle',
                errorMessage: '',
                // The mobile money waiting panel: the operator's dial code and
                // steps when no prompt arrives, and the number and amount the
                // prompt was sent to.
                momoApproval: null,
                momoMeta: null,
                // The failed session's customer-safe reason code, read by the
                // "Having trouble?" help under the failed state.
                failureCode: null,
                // Why a payment failed, by the session's customer-safe reason
                // code, in this customer's language. The server's own sentence
                // is the fallback for a code without copy.
                failureCopy: @js(\App\Domain\Payment\Support\CustomerFailureCopy::map()),
                // The cancel guard over the wizard, and its in-flight request.
                cancelPrompt: false,
                cancelling: false,
                actionMessage: '',
                // Whether the current hold is the six hour device lock rather
                // than a short pause. Drives the heading only; the sentence
                // itself comes from the server so the two can never disagree.
                backoffLocked: false,
                // The lock notice's sentence, kept apart from errorMessage,
                // which every new attempt clears.
                backoffMessage: '',
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

                    // A pause or lock already being served when the page loaded:
                    // start the countdown now, so a refresh never offers Continue
                    // to payment only to refuse it a moment later.
                    if (initialHold && initialHold.wait_seconds > 0) {
                        this.backoffLocked = !! initialHold.locked;
                        this.backoffMessage = initialHold.message || '';
                        this.startBackoff(initialHold.wait_seconds);
                    }

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

                    // Freeze the page behind the payment wizard, like every other
                    // modal in the app. This is more than cosmetic: body{position:fixed}
                    // is the signal two global handlers use to stand down while a
                    // dialog is open - the scroll-driven dropdown auto-close in
                    // resources/js/app.js and the installed PWA's pull-to-refresh.
                    // Unlocked, the on-screen keyboard that opens on the wallet
                    // transaction-PIN step scrolls the page behind the modal, and
                    // those handlers close it (or reload the page) mid-payment.
                    // $watch only fires on a real change, so lock/unlock stay
                    // balanced against the shared reference counter.
                    this.$watch('open', (isOpen) => {
                        if (isOpen) {
                            window.rshopScrollLock?.lock();
                        } else {
                            window.rshopScrollLock?.unlock();
                        }
                    });
                    // The paying wallet follows the order: whatever changes what the
                    // wallet would be charged re-checks which wallets still cover it.
                    this.ensureWalletChoice();
                    this.$watch('$store.cart.currency', () => this.ensureWalletChoice());
                    this.$watch('$store.cart.subtotalUsd', () => this.ensureWalletChoice());
                    this.$watch('couponDiscountUsd', () => this.ensureWalletChoice());
                    this.$watch('applyRcoin', () => this.ensureWalletChoice());
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
                            this.topUpError = body.message || @js(__('checkout.topup_failed'));
                            return;
                        }

                        this.session = body.data || body;
                        this.quoteExpired = false;
                        this.startCountdown();
                    } catch (e) {
                        this.topUpError = @js(__('checkout.network_error_retry'));
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

                    // A function replacement, so the "$" in the amount is never read
                    // as a String.replace substitution pattern.
                    return @js(__('checkout.crypto_min')).replace(':amount', () => '$' + Number(coin.minUsd).toFixed(2));
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

                methodLabel() {
                    return this.allMethods.find((candidate) => candidate.key === this.method)?.label || @js(__('checkout.payment_method'));
                },

                feeRate() {
                    if (this.method === 'crypto' || (this.paymentFees.fee_free || []).includes(this.method)) {
                        return 0;
                    }
                    const rates = (this.paymentFees.methods || {})[this.method] || this.paymentFees.default || { transaction: 0, international: 0 };
                    const international = (this.$store.cart.currency || 'NGN') !== 'NGN';
                    const rate = Number(rates.transaction || 0) + (international ? Number(rates.international || 0) : 0);
                    return Number(rate.toFixed(1));
                },

                feeLabel() {
                    return @js(__('checkout.method_processing_fee')).replace(':method', () => this.methodLabel());
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
                // The figure the order is charged for, in the display currency.
                totalDue() {
                    return Math.max(0, Number(this.$store.cart.subtotal || 0) + this.processingFee() - Number(this.couponDiscountDisplay || 0));
                },

                totalLabel() {
                    return this.$store.cart.pay(this.totalDue());
                },

                points() {
                    return this.$store.cart.estimated_rcoin_reward || 0;
                },

                // What the wallet will be asked for, in USD: the order less the
                // coupon and any Rcoin being applied. The server recomputes this
                // with the same rates and decides; this only keeps the tile honest.
                walletPayableUsd() {
                    return Math.max(0, this.orderUsd() - this.rcoinDiscountUsd());
                },

                rcoinDiscountUsd() {
                    if (! this.applyRcoin || ! this.isLoggedIn || ! this.rcoinConfig.enabled || ! this.rcoinConfig.redemption_enabled) {
                        return 0;
                    }
                    const balanceUsd = Number(this.walletBalances.RCOIN || 0) * Number(this.rcoinConfig.usd_rate || 0);
                    const cap = this.applyRcoin === 'full'
                        ? this.orderUsd()
                        : this.orderUsd() * (Number(this.rcoinConfig.redemption_max_pct || 0) / 100);

                    return Math.max(0, Math.min(balanceUsd, cap));
                },

                // What a given wallet would be charged, in its own currency.
                walletCharge(wallet) {
                    return Math.round(this.walletPayableUsd() * Number(wallet.per_usd || 0) * 100) / 100;
                },

                walletCovers(wallet) {
                    const charge = this.walletCharge(wallet);
                    return charge > 0 && Number(wallet.balance || 0) + 0.000001 >= charge;
                },

                // The wallets that cover the order, best first: the cart's own
                // currency, then USD, then the rest. The first one is the default.
                coveringWallets() {
                    const rank = (wallet) => wallet.currency === this.$store.cart.currency ? 0 : (wallet.currency === 'USD' ? 1 : 2);

                    return this.walletOptions
                        .filter((wallet) => this.walletCovers(wallet))
                        .sort((a, b) => rank(a) - rank(b) || a.currency.localeCompare(b.currency));
                },

                // Keep the chosen wallet valid as the cart, currency, coupon or
                // Rcoin choice changes: a wallet that no longer covers the order is
                // dropped for the best one that does, and a valid choice is kept.
                ensureWalletChoice() {
                    const covering = this.coveringWallets();
                    if (covering.some((wallet) => wallet.currency === this.walletCurrency)) {
                        return;
                    }
                    this.walletCurrency = covering.length > 0 ? covering[0].currency : '';
                },

                // Amounts read "XAF 50,000", the currency code the wallet is
                // named by, never the browser's own symbol for it ("FCFA").
                walletMoney(currency, amount) {
                    const value = Number(amount || 0);
                    try {
                        return new Intl.NumberFormat(undefined, { style: 'currency', currency: currency, currencyDisplay: 'code' })
                            .format(value)
                            .replace(/ /g, ' ');
                    } catch (e) {
                        return currency + ' ' + value.toFixed(2);
                    }
                },

                // Pure on purpose (it runs inside template bindings): some wallet
                // covers the order. Which one is walletCurrency, kept in step by
                // ensureWalletChoice; empty means the server picks the default.
                hasSufficientWalletBalance() {
                    return this.coveringWallets().length > 0;
                },
                
                backoffRemaining: 0,
                backoffInterval: null,
                // When the hold ends, as a wall-clock time, and how long it was
                // when it started. The end time keeps the countdown true after
                // the tab has been in the background (browsers slow timers
                // there to once a minute); the starting length decides the
                // wording, so it never changes halfway through a countdown.
                backoffEndsAt: 0,
                backoffTotal: 0,

                startBackoff(seconds) {
                    const total = Math.max(0, Math.round(Number(seconds) || 0));

                    this.backoffTotal = total;
                    this.backoffEndsAt = Date.now() + total * 1000;
                    this.backoffRemaining = total;

                    if (this.backoffInterval) clearInterval(this.backoffInterval);
                    this.backoffInterval = setInterval(() => {
                        this.backoffRemaining = Math.max(0, Math.ceil((this.backoffEndsAt - Date.now()) / 1000));

                        if (this.backoffRemaining === 0) {
                            clearInterval(this.backoffInterval);
                        }
                    }, 1000);
                },

                /**
                 * The time left as a running clock: 5:59:59 for the lock, 4:59
                 * for the pauses. Exact to the second, so it never rounds to an
                 * odd "about 60 minutes".
                 */
                backoffCountdown() {
                    const left = Math.max(0, this.backoffRemaining);
                    const hours = Math.floor(left / 3600);
                    const minutes = Math.floor((left % 3600) / 60);
                    const seconds = left % 60;
                    const pad = (value) => String(value).padStart(2, '0');

                    return hours > 0
                        ? hours + ':' + pad(minutes) + ':' + pad(seconds)
                        : minutes + ':' + pad(seconds);
                },

                /**
                 * Whether a running hold covers the method picked right now.
                 * Wallet balance is never held, so it never shows a countdown.
                 */
                backoffApplies() {
                    return this.backoffRemaining > 0 && this.method !== 'wallet';
                },

                /**
                 * The pay button's label while a hold is running. The five
                 * minute pause says why; the one minute pause and the lock just
                 * show the clock, the lock's reason being on its notice. Chosen
                 * from the hold's starting length, so the wording stays put
                 * while the clock runs down.
                 */
                backoffLabel() {
                    if (! this.backoffLocked && this.backoffTotal > 60) {
                        return @js(__('checkout.backoff_too_many')).replace(':time', this.backoffCountdown());
                    }

                    return @js(__('checkout.backoff_try_again')).replace(':time', this.backoffCountdown());
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
                    // A lock already known to this page: show its notice again
                    // without asking the server, which would only refuse.
                    if (this.backoffLocked && this.backoffApplies()) {
                        this.paymentState = 'backoff_notice';
                        this.open = true;
                        return;
                    }

                    if (this.method === 'wallet' && !this.hasTransactionPin) {
                        this.submitting = false;
                        // The link in the message is a button: it opens the PIN
                        // setup step in this modal rather than sending the customer
                        // to their settings, and the submission is kept so it can
                        // be sent again once the PIN exists.
                        this.pendingForm = e.target;
                        this.paymentState = 'error';
                        this.errorMessage = @js(__('checkout.wallet_pin_missing', ['link' => '<button type="button" data-pin-setup class="underline font-bold text-blue-600 hover:text-blue-700">'.e(__('checkout.wallet_pin_missing_link')).'</button>']));
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
                                ? @js(__('checkout.checkout_unexpected_response'))
                                : @js(__('checkout.checkout_http_failed')).replace(':status', response.status);
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
                            this.kycMessage = resData.message || @js(__('checkout.kyc_default_message'));
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
                            this.errorMessage = resData.message || @js(__('checkout.email_verify_default'));
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
                                // Not a failure: nothing was charged and nothing
                                // was even attempted. The red cross would read as
                                // another decline and send them to try one more
                                // card, which is the behaviour being throttled.
                                this.startBackoff(resData.wait_seconds);
                                this.backoffLocked = !! resData.locked;

                                // A short pause interrupts nobody: the pay button
                                // carries its own countdown and the customer stays
                                // on their checkout. Only the six hour lock, which
                                // they cannot wait out, is worth a panel and a way
                                // to reach a human.
                                if (this.backoffLocked) {
                                    this.backoffMessage = resData.message || resData.error || @js(__('checkout.backoff_locked'));
                                    this.paymentState = 'backoff_notice';
                                    this.open = true;
                                }

                                return;
                            }

                            this.errorMessage = resData.message || @js(__('checkout.checkout_failed_retry'));
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
                            // Empty for rails that take no network (M-Pesa, Rwanda); the
                            // server validates against the market's rail, never a default.
                            const networkInput = document.querySelector('input[name="momo_network"]')?.value || '';
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
                            ? @js(__('checkout.network_failed'))
                            : @js(__('checkout.checkout_could_not_complete')).replace(':error', () => (err && err.message ? err.message : @js(__('checkout.unexpected_error'))));
                        alert(msg);
                    }
                },

                async paySession(method, dataPayload = {}) {
                    this.paymentState = 'processing';
                    this.errorMessage = '';
                    this.failureCode = null;
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
                            resData = { message: @js(__('checkout.server_error')).replace(':status', response.status).replace(':text', () => response.statusText) };
                        }

                        if (!response.ok) {
                            this.paymentState = 'error';
                            this.errorMessage = resData.message || @js(__('checkout.payment_initiation_failed'));
                            return;
                        }

                        this.handlePayResponse(resData.data || resData);
                    } catch (err) {
                        this.paymentState = 'error';
                        this.errorMessage = @js(__('checkout.network_failed'));
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
                        this.errorMessage = @js(__('checkout.wallet_pin_enter'));
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
                            this.errorMessage = (data.errors?.pin?.[0]) || data.message || @js(__('checkout.wallet_pin_verify_failed'));
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
                        this.errorMessage = @js(__('checkout.wallet_pin_network_error'));
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
                        this.errorMessage = @js(__('checkout.payment_start_failed'));
                    } else if (status === 'failed') {
                        this.paymentState = 'error';
                        this.errorMessage = this.failureMessage(sessionData.payment_payload, @js(__('checkout.transaction_not_completed')));
                    } else if (status === 'awaiting_payment') {
                        const inlineData = sessionData.payment_payload?.inline;
                        if (inlineData) {
                            this.openFlutterwaveInline(inlineData);
                        } else {
                            this.paymentState = 'error';
                            this.errorMessage = @js(__('checkout.card_init_failed'));
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
                            this.actionMessage = sessionData.payment_payload?.message || @js(__('checkout.otp_code_sent'));
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
                        this.actionMessage = sessionData.payment_payload?.message || @js(__('checkout.momo_accept_prompt'));
                        this.momoApproval = sessionData.payment_payload?.approval || null;
                        this.momoMeta = sessionData.payment_payload?.mobile_money || null;
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
                        this.errorMessage = @js(__('checkout.payment_window_failed'));
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
                                    self.errorMessage = verifyData.message || @js(__('checkout.payment_not_verified'));
                                    self.open = true; // Re-open modal to show error
                                }
                            } catch (e) {
                                // Verification request itself failed — a client
                                // connectivity signal, not a payment verdict.
                                if (window.PaymentTelemetry && self.session?.id) {
                                    window.PaymentTelemetry.recordSignal(self.session.id, 'network_timeout', { provider: 'flutterwave', step: 'verify' });
                                }
                                self.paymentState = 'error';
                                self.errorMessage = @js(__('checkout.payment_verify_connection'));
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
                                this.errorMessage = this.failureMessage(data.payment_payload, @js(__('checkout.transaction_failed')));
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
                        this.errorMessage = data.message || @js(__('checkout.payment_not_confirmed_yet'));
                    } catch (e) {
                        this.verifying = false;
                        this.errorMessage = @js(__('checkout.verify_unreachable'));
                    }
                },

                copyToClipboard(text, key = 'default') {
                    navigator.clipboard.writeText(text).then(() => {
                        this.copiedStates[key] = true;
                        setTimeout(() => {
                            this.copiedStates[key] = false;
                        }, 2000);
                    }).catch(() => {
                        alert(@js(__('checkout.copy_failed')));
                    });
                },

                /**
                 * What to tell the customer about a failed session: the sentence
                 * for its reason code in their language, else the reason the
                 * server failed it with, else the page's generic line.
                 */
                failureMessage(payload, fallback) {
                    this.failureCode = payload?.failure_code || null;

                    return this.failureCopy[payload?.failure_code] || payload?.failure_reason || fallback;
                },

                /**
                 * A live session in any step short of a verdict. Success and the
                 * notices (email, identity, the card-testing hold) carry no
                 * attempt worth guarding; a failure is already over.
                 */
                paymentInFlight() {
                    return !! this.session && ! ['idle', 'success', 'error', 'backoff_notice', 'email_notice', 'kyc_required'].includes(this.paymentState);
                },

                /**
                 * Every casual way out of the wizard (backdrop, Escape, the close
                 * button) comes through here. Mid-payment it raises the prompt
                 * and puts focus on "keep paying"; otherwise it simply closes.
                 */
                requestClose() {
                    if (this.cancelPrompt) {
                        return;
                    }
                    if (this.paymentInFlight()) {
                        this.cancelPrompt = true;
                        this.$nextTick(() => this.$refs.keepPayingButton?.focus());
                        return;
                    }
                    this.closeModal();
                },

                /**
                 * The deliberate cancel. Tells the server so the attempt is filed
                 * as cancelled (or as the decline it really was), then closes.
                 * The server's answer never blocks the close: the customer asked
                 * to leave and the session expires on its own regardless.
                 */
                async cancelPayment() {
                    const id = this.session?.id;
                    this.cancelling = true;
                    try {
                        if (id) {
                            await fetch(`/api/payment-sessions/${id}/cancel`, {
                                method: 'POST',
                                headers: {
                                    'Accept': 'application/json',
                                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '',
                                },
                            });
                        }
                    } catch (e) {
                        // Nothing to do: see above.
                    } finally {
                        this.cancelling = false;
                        this.closeModal();
                    }
                },

                closeModal() {
                    this.cancelPrompt = false;
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

                // --- Wallet PIN setup, inside the checkout ---
                startPinSetup() {
                    this.newPin = '';
                    this.newPinConfirmation = '';
                    this.pinSetupError = '';
                    this.settingUpPin = false;
                    this.paymentState = 'wallet_pin_setup';
                    this.open = true;
                    this.$nextTick(() => this.$refs.newPinInput?.focus());
                },

                /**
                 * Save the PIN through the same endpoint the security page uses,
                 * then send the kept submission again: the server opens the wallet
                 * session and the modal moves on to the PIN step, where the
                 * customer types the PIN they just chose. Nothing about the order
                 * changed, so the same form is simply submitted once more.
                 */
                async savePinAndContinue() {
                    const pin = (this.newPin || '').trim();
                    const confirmation = (this.newPinConfirmation || '').trim();
                    if (!/^\d{4}$/.test(pin)) {
                        this.pinSetupError = @js(__('checkout.wallet_pin_setup_format'));
                        return;
                    }
                    if (pin !== confirmation) {
                        this.pinSetupError = @js(__('checkout.wallet_pin_setup_mismatch'));
                        return;
                    }
                    this.settingUpPin = true;
                    this.pinSetupError = '';
                    try {
                        const res = await fetch('/api/wallets/pin/setup', {
                            method: 'POST',
                            headers: {
                                'Content-Type': 'application/json',
                                'Accept': 'application/json',
                                'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]')?.content || ''
                            },
                            body: JSON.stringify({ pin, pin_confirmation: confirmation })
                        });
                        const data = await res.json().catch(() => ({}));
                        if (!res.ok) {
                            this.settingUpPin = false;
                            // 422 returns { message, errors: { pin: [...] } }.
                            this.pinSetupError = (data.errors?.pin?.[0]) || data.message || @js(__('checkout.wallet_pin_setup_failed'));
                            return;
                        }
                        this.hasTransactionPin = true;
                        this.newPin = '';
                        this.newPinConfirmation = '';
                        const form = this.pendingForm || document.getElementById('checkout-form');
                        this.pendingForm = null;
                        if (form) {
                            // The button stays in its saving state until the new
                            // submission replaces this step with the next one.
                            form.requestSubmit();
                        } else {
                            this.settingUpPin = false;
                            this.closeModal();
                        }
                    } catch (err) {
                        this.settingUpPin = false;
                        this.pinSetupError = @js(__('checkout.wallet_pin_network_error'));
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
                            ? (data.message || @js(__('checkout.verification_link_sent')))
                            : (data.message || @js(__('checkout.verification_wait')));
                        this.startResendCountdown(data.retry_after || 30);
                    } catch (e) {
                        this.resendFlash = @js(__('checkout.network_error_retry'));
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

<?php

use App\Domain\Shared\Enums\Currency;
use App\Models\Product;
use Livewire\Attributes\Lazy;
use Livewire\Volt\Component;

/**
 * Customer dashboard overview - the main content area of /dashboard.
 *
 * Rendered as a #[Lazy] component: the server returns the skeleton placeholder
 * instantly, then this component boots in a follow-up request, queries its data,
 * and swaps the real content in. The blue mobile hero + sidebar + bottom nav are
 * the layout shell (rendered immediately by dashboard.blade.php).
 */
new #[Lazy] class extends Component
{
    /** Skeleton shown while the component lazy-boots. */
    public function placeholder()
    {
        return view('components.skeletons.dashboard-overview');
    }

    public function with(): array
    {
        $user = auth()->user();

        // ── Wallet data ──────────────────────────────────────────────
        // The default wallet is the funded one; with several funded, the
        // largest USD-equivalent balance leads (User::defaultWallet()).
        $primaryWallet = $user->defaultWallet();

        $walletBalance = (float) ($primaryWallet?->balance ?? 0);
        $walletCurrencyCode = $primaryWallet?->currency?->value ?? 'USD';
        $walletCurrencyCase = Currency::tryFrom($walletCurrencyCode);
        $walletSymbol = $walletCurrencyCase?->symbol() ?? '$';

        // Exclude RCOIN: it's a rewards balance shown via the loyalty/tier
        // section below ($rcoinWallet), not a fundable wallet, so it must not
        // appear as a top-up-able wallet card. The default wallet leads the list.
        $allWallets = $user->wallets()->where('is_active', true)->where('currency', '!=', 'RCOIN')->get()
            ->sortByDesc(fn ($w) => $primaryWallet && $w->id === $primaryWallet->id)
            ->values();

        // How many rows the Recent Transactions card can draw on. The desktop
        // card stretches to match the left column's height, so the number it
        // can show depends on the viewport - it asks for a pool and renders
        // what fits, scrolling the rest. Both source queries take this many,
        // because either could supply the whole merged list.
        $recentTransactionPool = 25;

        // Recent money activity - the wallet ledger (top-ups, wallet purchases,
        // Rcoin credits) MERGED with paid gateway order payments (card / mobile
        // money / crypto), which never touch a wallet and were otherwise
        // invisible here. Wallet-gateway attempts are excluded: their ledger
        // debit already covers them. Uniform row shape for the blade.
        $walletRecent = $user->walletTransactions()->latest()->limit($recentTransactionPool)->get()->map(fn ($txn) => (object) [
            'key' => 'txn-'.$txn->id,
            'is_credit' => $txn->type === \App\Domain\Shared\Enums\WalletTransactionType::Credit,
            'title' => $txn->description ?: ($txn->transaction_category?->label() ?? 'Wallet transaction'),
            'sub' => $txn->transaction_category?->label() ?? $txn->type->label(),
            'amount' => (float) $txn->amount,
            'symbol' => $txn->currency?->symbol() ?? '',
            'date' => $txn->created_at,
        ]);

        $paymentRecent = \App\Models\PaymentAttempt::query()
            ->where('user_id', $user->id)
            ->whereNotNull('order_id')
            ->where('gateway', '!=', 'wallet')
            ->whereIn('payment_status', [
                \App\Domain\Payment\Enums\PaymentStatus::Paid,
                \App\Domain\Payment\Enums\PaymentStatus::Refunded,
                \App\Domain\Payment\Enums\PaymentStatus::PartiallyRefunded,
            ])
            ->with('order:id,order_number')
            ->latest()
            ->limit($recentTransactionPool)
            ->get()
            ->map(fn ($attempt) => (object) [
                'key' => 'pay-'.$attempt->id,
                'is_credit' => false,
                'title' => $attempt->customerMethodLabel(),
                'sub' => $attempt->order?->order_number ? 'Order '.$attempt->order->order_number : 'Order payment',
                'amount' => (float) $attempt->amount,
                'symbol' => \App\Models\Product::currencySymbol($attempt->currency),
                'date' => $attempt->confirmed_at ?? $attempt->created_at,
            ]);

        $recentTransactions = $walletRecent->concat($paymentRecent)->sortByDesc('date')->take($recentTransactionPool)->values();

        // Latest 5 orders for the Recent Orders card. Mobile renders all 5; the
        // desktop card slices ->take(3) to keep its right-rail compact.
        $recentOrders = $user->orders()->with('items')->latest()->limit(5)->get();

        // Popular gift cards - the exact same curated, region-resolved row the
        // storefront shows: config/popular_brands.php order, the visitor's own-country
        // brands floated to the top, USA rows shown to everyone. Sourced from
        // Product::popularForRegion so the dashboard and storefront never diverge.
        $region = strtoupper((string) (request()->attributes->get('region') ?: 'US'));

        $popularProducts = Product::popularForRegion($region);

        // Last-resort fallback so the row never renders empty in a region where none
        // of the curated brands are stocked - any active gift-card brands there.
        if ($popularProducts->isEmpty()) {
            $fallbackIds = Product::query()
                ->where('is_active', true)
                ->where('country_code', $region)
                ->whereNotNull('brand_key')
                ->whereHas('category', fn ($q) => $q->where('slug', 'gift-cards'))
                ->groupBy('brand_key')
                ->selectRaw('MIN(id) as id')
                ->pluck('id');

            $popularProducts = Product::query()
                ->whereIn('id', $fallbackIds)
                ->with('variants')
                ->orderByDesc('is_popular')
                ->orderByDesc('is_featured')
                ->limit(5)
                ->get();
        }

        // Symbol/label map covering fiat + crypto.
        $symbolFor = function (string $code): string {
            return match (strtoupper($code)) {
                'USD' => '$',  'NGN'  => '₦', 'GHS'  => '₵', 'GBP' => '£',
                'XAF' => 'XAF ','XOF' => 'CFA','EUR' => '€',
                'KES' => 'KSh','ZAR'  => 'R', 'UGX'  => 'USh','TZS' => 'TSh',
                'RWF' => 'FRw','ZMW'  => 'K', 'MWK'  => 'MK', 'ETB' => 'Br',
                'EGP' => 'E£', 'MAD'  => 'DH',
                'BTC' => '₿',  'USDT' => '₮', 'BUSD' => '$', 'SOL' => '◎',
                'BNB' => 'B',  'LTC'  => 'Ł', 'ETH'  => 'Ξ',
                default => '',
            };
        };

        $isCryptoCode = fn (string $code) => in_array(strtoupper($code), ['BTC', 'USDT', 'BUSD', 'SOL', 'BNB', 'LTC', 'ETH', 'USDC'], true);

        // Icon resolver: maps a currency code to an asset filename.
        $iconFor = function (string $code): ?string {
            return match (strtoupper($code)) {
                'NGN'  => 'NGN.svg',
                'GHS'  => 'GH.svg',
                'XAF'  => 'XAF.svg',
                'BTC'  => 'BTC.svg',
                'USDT' => 'USDT.svg',
                'BUSD' => 'USDT.svg',
                'SOL'  => 'SOLANA.svg',
                'BNB'  => 'BNB.svg',
                'LTC'  => 'LTC.svg',
                default => null,
            };
        };

        // Wallet card bg - every wallet uses the same colour as the USD card.
        $colorFor = fn (string $code): string => 'bg-blue-800';

        $walletsPayload = $allWallets->map(function ($w) use ($symbolFor, $isCryptoCode, $iconFor, $colorFor) {
            $code = $w->currency?->value ?? 'USD';
            $iconFile = $iconFor($code);

            return [
                'code'      => $code,
                'symbol'    => $symbolFor($code),
                'label'     => Currency::tryFrom((string) $code)?->label() ?? $code,
                'balance'   => (float) $w->balance,
                'formatted' => $symbolFor($code) . number_format((float) $w->balance, 2),
                'type'      => $isCryptoCode((string) $code) ? 'crypto' : 'fiat',
                'icon'      => $iconFile ? asset('assets/' . rawurlencode($iconFile)) : \App\Models\Product::flagUrl(match (strtoupper((string) $code)) { 'USD' => 'US', 'GBP' => 'GB', 'EUR' => 'EU', 'KES' => 'KE', 'ZAR' => 'ZA', 'UGX' => 'UG', 'TZS' => 'TZ', 'RWF' => 'RW', 'ZMW' => 'ZM', 'MWK' => 'MW', 'ETB' => 'ET', 'EGP' => 'EG', 'MAD' => 'MA', default => '' }),
                'color'     => $colorFor($code),
            ];
        })->values();

        $rcoinWallet = $user->wallets()->where('currency', 'RCOIN')->first();
        $rcoinBalance = (int) ($rcoinWallet?->balance ?? 0);

        // Loyalty tier - mirrors the ladder in /dashboard/rewards exactly.
        // Higher tiers carry meaningful perks (badge colour + future
        // multiplier auto-bumps). Single source of truth so the dashboard
        // and rewards page can never disagree.
        $tierLadder = [
            ['name' => 'Bronze',   'min' => 0,    'color' => 'bg-amber-700'],
            ['name' => 'Silver',   'min' => 1000, 'color' => 'bg-zinc-500'],
            ['name' => 'Gold',     'min' => 1500, 'color' => 'bg-amber-500'],
            ['name' => 'Platinum', 'min' => 3000, 'color' => 'bg-zinc-600'],
            ['name' => 'Diamond',  'min' => 6000, 'color' => 'bg-cyan-600'],
        ];
        $currentTier = $tierLadder[0];
        foreach ($tierLadder as $tier) {
            if ($rcoinBalance >= $tier['min']) {
                $currentTier = $tier;
            }
        }

        return [
            'user' => $user,
            'rcoinBalance' => $rcoinBalance,
            'currentTier' => $currentTier,
            'walletBalance' => $walletBalance,
            'walletCurrencyCode' => $walletCurrencyCode,
            'walletCurrencyCase' => $walletCurrencyCase,
            'walletSymbol' => $walletSymbol,
            'walletsPayload' => $walletsPayload,
            'recentTransactions' => $recentTransactions,
            'recentOrders' => $recentOrders,
            'popularProducts' => $popularProducts,
            // One rollup for both carousels rather than a query per card -
            // see Review::brandRatings().
            'brandRatings' => \App\Models\Review::brandRatings($popularProducts->pluck('brand_key')->all()),
            'initialWalletColor' => $colorFor($walletCurrencyCode),
        ];
    }
}; ?>

@php
    // Unified status badge tones - same recipe as the admin list pages and the
    // customer orders / transactions pages so every status in the app reads
    // identically. Light tint bg + bold text + 1px ring; dark-mode variants
    // included. Pairs with the rounded-[5px] wrapper at render time.
    $orderStatusUi = [
        'completed'           => ['Completed',  'bg-emerald-50 text-emerald-700 ring-emerald-200 dark:bg-emerald-500/15 dark:text-emerald-300 dark:ring-emerald-500/30'],
        'partially_completed' => ['Partial',    'bg-blue-50 text-blue-700 ring-blue-200 dark:bg-blue-600/15 dark:text-blue-300 dark:ring-blue-500/30'],
        'processing'          => ['Processing', 'bg-blue-50 text-blue-700 ring-blue-200 dark:bg-blue-600/15 dark:text-blue-300 dark:ring-blue-500/30'],
        'pending'             => ['Pending',    'bg-amber-50 text-amber-700 ring-amber-200 dark:bg-amber-500/15 dark:text-amber-300 dark:ring-amber-500/30'],
        'failed'              => ['Failed',     'bg-red-50 text-red-700 ring-red-200 dark:bg-red-500/15 dark:text-red-300 dark:ring-red-500/30'],
        'cancelled'           => ['Cancelled',  'bg-zinc-100 text-zinc-700 ring-zinc-200 dark:bg-white/5 dark:text-zinc-300 dark:ring-zinc-700/60'],
        'requires_attention'  => ['Review',     'bg-red-50 text-red-700 ring-red-200 dark:bg-red-500/15 dark:text-red-300 dark:ring-red-500/30'],
    ];
@endphp

{{-- Single root element (Livewire requirement). --}}
<div>

    {{-- ─────────────────────────────────────────────────────── --}}
    {{-- MOBILE VIEW (< lg)                                      --}}
    {{-- ─────────────────────────────────────────────────────── --}}
    <div class="flex flex-col gap-5 lg:hidden">

        {{-- Quick Actions - 4-col grid (2 rows of 4). --}}
        <div class="rounded-[15px] bg-[#eff6ff] p-5 dash-shimmer border border-zinc-200 shadow-md shadow-zinc-900/[0.06] transition-colors hover:border-green-200 dark:border-zinc-700 dark:hover:border-white dark:shadow-none">
            <div class="flex items-center justify-between">
                <h3 class="text-base font-bold text-zinc-900">Quick Actions</h3>
                <a href="{{ route('dashboard.shop.gift-cards') }}" wire:navigate aria-label="See more categories" class="inline-flex h-8 w-8 items-center justify-center rounded-full bg-[#eff6ff] text-blue-700 border border-zinc-200 transition-colors hover:border-green-200 dark:border-zinc-700 dark:hover:border-white">
                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15"/>
                    </svg>
                </a>
            </div>
            <div class="mt-4 grid grid-cols-4 gap-3">
                {{-- A hidden travel tile is REPLACED, not removed: the grid is
                     sized 2x4, and a 6-tile render leaves a visible hole. Fund
                     Wallet takes the Flights slot; Electronics steps in next to
                     Gaming (before More) when Stays is hidden. --}}
                @foreach (array_values(array_filter([
                    ['Gift Cards', 'gift cards.svg',   'bg-pink-500',    route('dashboard.shop.gift-cards')],
                    ['eSIMs',      'esim.svg',         'bg-sky-500',     route('dashboard.shop.esims')],
                    \App\Support\FeatureFlag::categoryVisible('flights')
                        ? ['Flights', 'flight 2.svg', 'bg-indigo-500', route('dashboard.shop.flights')]
                        : ['Fund Wallet', 'Wallet.svg', 'bg-indigo-500', route('dashboard.wallet')],
                    \App\Support\FeatureFlag::categoryVisible('stays')
                        ? ['Stays', 'stay 2.svg', 'bg-orange-500', route('dashboard.shop.stays')]
                        : null,
                    ['Topups',     'topup1.svg',       'bg-emerald-500', route('dashboard.shop.topups')],
                    ['Bills',      'bill payment.svg', 'bg-teal-500',    route('dashboard.shop.bills')],
                    ['Gaming',     'Gaming.svg',       'bg-fuchsia-500', route('dashboard.shop.gift-cards', ['subcategory' => 'gaming-entertainment'])],
                    \App\Support\FeatureFlag::categoryVisible('stays')
                        ? null
                        : ['Electronics', 'mobile.svg', 'bg-orange-500', route('dashboard.shop.gift-cards', ['subcategory' => 'electronics'])],
                    ['More',       'More.svg',         'bg-blue-500',    route('dashboard.shop.gift-cards')],
                ])) as [$label, $icon, $bg, $href])
                    <a href="{{ $href }}" wire:navigate class="group flex flex-col items-center gap-2 text-center">
                        <span class="flex h-12 w-12 items-center justify-center rounded-full {{ $bg }} transition-transform group-hover:scale-105 group-active:scale-95">
                            <img src="{{ asset('assets/' . rawurlencode($icon)) }}" alt="" class="h-6 w-6 brightness-0 invert" loading="lazy">
                        </span>
                        <span class="text-[11px] font-medium text-zinc-700">{{ $label }}</span>
                    </a>
                @endforeach
            </div>
        </div>

        {{-- Popular Gift Cards - same curated source as desktop. brand-row auto-scrolls on mobile.
             See-more "+" sends the user to the dashboard gift-cards page so they stay
             inside the dashboard chrome instead of bouncing out to the storefront. --}}
        @if ($popularProducts->isNotEmpty())
            <div class="rounded-[15px] bg-[#eff6ff] p-5 dash-shimmer border border-zinc-200 shadow-md shadow-zinc-900/[0.06] transition-colors hover:border-green-200 dark:border-zinc-700 dark:hover:border-white dark:shadow-none">
                <x-home.brand-row
                    title="Popular Gift Cards"
                    subtitle="Top-rated in your region"
                    :view-all-href="route('dashboard.shop.gift-cards')"
                    view-all-variant="plus"
                    :cols="5"
                    {{-- The storefront row, not the wrapping grid this used to
                         be - same control, same depth, same paging. --}}
                    :carousel="true"
                    :rows="2"
                    :mobile-rows="3"
                    {{-- The track would span 100vw and overrun this column. --}}
                    :bleed="false"
                >
                    @foreach ($popularProducts as $p)
                        @php
                            $logo = Product::brandLogoUrl($p->brand_key, $p->logo_url);
                            $label = Product::brandDisplayName($p->brand_key);
                            $brandColor = Product::brandColor($p->brand_key, $p->brand_color);
                        @endphp
                        <x-home.brand-card
                            :name="$label"
                            :price-range="$p->priceRangeLabel()"
                            :rating="$brandRatings[$p->brand_key] ?? null"
                            :href="route('dashboard.shop.brand', ['brandSlug' => Product::brandSlug($p->brand_key)])"
                            :card-class="$logo ? 'bg-[#ffffff]' : ($brandColor ? '' : 'bg-zinc-100')"
                            :style="! $logo && $brandColor ? 'background-color: ' . $brandColor . ';' : false"
                        >
                            @if ($logo)
                                <img src="{{ $logo }}" alt="{{ $label }} gift card" class="h-full w-full object-cover" loading="lazy">
                            @else
                                <span class="px-3 text-center text-xl font-black uppercase leading-tight tracking-tight {{ $brandColor ? 'text-white' : 'text-zinc-700' }}">{{ $label }}</span>
                            @endif
                        </x-home.brand-card>
                    @endforeach
                </x-home.brand-row>
            </div>
        @endif

        {{-- Give the Perfect Gift promo - placed here on mobile (desktop keeps its own copy in the right rail). --}}
        <div class="relative overflow-hidden rounded-[12px] bg-blue-950 p-5 text-white">
            <div class="relative z-10 max-w-[54%]">
                <h3 class="text-lg font-bold tracking-tight">Give the Perfect Gift</h3>
                <p class="mt-1 text-sm text-blue-100/80">Gift cards for every occasion and everyone.</p>
                <a href="{{ route('dashboard.shop.gift-cards') }}" wire:navigate class="mt-4 inline-flex items-center gap-2 rounded-[12px] bg-[#ffffff] px-4 py-2 text-sm font-semibold text-blue-950 transition-colors hover:bg-[#dbeafe]">
                    Shop Now
                    <img src="{{ asset('assets/' . rawurlencode('Shop.svg')) }}" alt="" class="h-4 w-4 no-dark-invert" loading="lazy">
                </a>
            </div>
            <img
                src="{{ asset('assets/' . rawurlencode('Pick a product first process.webp')) }}"
                alt=""
                class="pointer-events-none absolute -right-3 bottom-2 w-[46%] max-w-[260px] select-none object-contain object-bottom drop-shadow-2xl"
                loading="lazy"
            >
        </div>

        {{-- Recent Orders - mobile shows up to 5; sits directly above Recent
             Transactions so the latest spending activity reads as one block. --}}
        <div class="relative overflow-hidden rounded-[15px] bg-[#eff6ff] p-5 border border-zinc-200 shadow-md shadow-zinc-900/[0.06] transition-colors dark:border-zinc-700 dark:shadow-none">
            <x-ui.floating-confetti />
            <div class="relative z-10 flex items-center justify-between">
                <h3 class="text-base font-bold text-zinc-900">Recent Orders</h3>
                <a href="{{ route('dashboard.orders') }}" wire:navigate aria-label="View all orders" class="inline-flex h-8 w-8 items-center justify-center rounded-full bg-[#eff6ff] text-blue-700 border border-zinc-200 transition-colors hover:border-green-200 dark:border-zinc-700 dark:hover:border-white">
                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15"/>
                    </svg>
                </a>
            </div>

            <ul class="relative z-10 mt-4 space-y-3">
                @forelse ($recentOrders as $order)
                    @php
                        $groupedItems = $order->items->groupBy('product_variant_id');
                        $firstGroup = $groupedItems->first();
                        $firstItem = $firstGroup?->first();
                        $firstItemQty = $firstGroup ? $firstGroup->count() : 0;
                        $snap = (array) ($firstItem?->product_snapshot ?? []);
                        $brandKey = $snap['brand_key'] ?? null;
                        $itemName = $brandKey ? Product::brandDisplayName($brandKey) : ($snap['name'] ?? 'Order');
                        $extraVariants = max(0, $groupedItems->count() - 1);
                        $logo = Product::brandLogoUrl($brandKey, $snap['logo_url'] ?? null);
                        [$statusLabel, $statusTone] = $orderStatusUi[$order->order_status?->value] ?? ['Pending', 'bg-amber-50 text-amber-700 ring-amber-200 dark:bg-amber-500/15 dark:text-amber-300 dark:ring-amber-500/30'];
                    @endphp
                    <li>
                        <a href="{{ route('dashboard.orders') }}" wire:navigate class="flex items-center gap-3">
                            <span class="flex h-12 w-12 shrink-0 items-center justify-center overflow-hidden rounded-[12px] bg-white ring-1 ring-zinc-100">
                                @if ($logo)
                                    <img src="{{ $logo }}" alt="" class="h-8 w-8 object-contain" loading="lazy">
                                @else
                                    <span class="text-xs font-bold uppercase text-zinc-500">{{ \Illuminate\Support\Str::substr($itemName, 0, 2) }}</span>
                                @endif
                            </span>
                            <div class="min-w-0 flex-1">
                                <div class="flex items-center justify-between gap-2">
                                    <p class="truncate text-sm font-semibold text-zinc-900">
                                        @if ($firstItemQty > 1){{ $firstItemQty }}x @endif{{ $itemName }}@if ($extraVariants > 0) <span class="font-normal text-zinc-500">+{{ $extraVariants }}</span>@endif
                                    </p>
                                    <span class="inline-flex w-fit shrink-0 items-center whitespace-nowrap rounded-[5px] px-2.5 py-1 text-[10px] font-bold uppercase tracking-wide ring-1 {{ $statusTone }}">{{ $statusLabel }}</span>
                                </div>
                                <div class="mt-0.5 flex items-center justify-between gap-2">
                                    <p class="truncate text-xs text-zinc-600">@moneyCode((float) $order->total_amount, $order->settlement_currency)</p>
                                    <p class="shrink-0 text-[10px] text-zinc-600">{{ $order->created_at->diffForHumans() }}</p>
                                </div>
                            </div>
                        </a>
                    </li>
                @empty
                    <li class="flex flex-col items-center justify-center py-6 text-center">
                        <p class="text-sm font-semibold text-zinc-900">No orders yet</p>
                        <p class="mt-1 text-xs text-zinc-600">Your purchases will show up here.</p>
                    </li>
                @endforelse
            </ul>
        </div>

        {{-- Recent Transactions - mobile only; mirrors the desktop right-rail card.
             Shows top-ups (credits) and purchases (debits) interleaved since both
             write to wallet_transactions.

             Sliced to 8 here while the desktop card renders the whole pool: this
             card has no height to fill, so it grows with its content and the
             pool would run it off the bottom of the page. Full history is on
             /dashboard/transactions via the See more "+" button. --}}
        <div class="relative overflow-hidden rounded-[15px] bg-[#eff6ff] dash-shimmer p-5 border border-zinc-200 shadow-md shadow-zinc-900/[0.06] transition-colors dark:border-zinc-700 dark:shadow-none">
            <x-ui.floating-confetti />
            <div class="relative z-10 flex items-center justify-between">
                <h3 class="text-base font-bold text-zinc-900">Recent Transactions</h3>
                <a href="{{ route('dashboard.transactions') }}" wire:navigate aria-label="View all transactions" class="inline-flex h-8 w-8 items-center justify-center rounded-full bg-[#eff6ff] text-blue-700 border border-zinc-200 transition-colors hover:border-green-200 dark:border-zinc-700 dark:hover:border-white">
                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15"/>
                    </svg>
                </a>
            </div>

            <ul class="relative z-10 mt-4 space-y-3">
                @forelse ($recentTransactions->take(8) as $txn)
                    <li class="flex items-center gap-3">
                        <span class="flex h-11 w-11 shrink-0 items-center justify-center rounded-full bg-white shadow-sm shadow-zinc-900/5">
                            @if ($txn->is_credit)
                                <x-icons.txn-credit class="h-9 w-9" />
                            @else
                                <x-icons.txn-debit class="h-9 w-9" />
                            @endif
                        </span>
                        <div class="min-w-0 flex-1">
                            <p class="truncate text-sm font-semibold text-zinc-900">{{ $txn->title }}</p>
                            <p class="truncate text-[11px] text-zinc-600">{{ $txn->sub }}</p>
                        </div>
                        <div class="shrink-0 text-right">
                            <p class="text-sm font-bold {{ $txn->is_credit ? 'text-emerald-600' : 'text-zinc-900' }}">{{ $txn->is_credit ? '+' : '-' }}{{ $txn->symbol }}{{ number_format($txn->amount, 2) }}</p>
                            <p class="text-[10px] text-zinc-600">{{ $txn->date->format('d M, H:i') }}</p>
                        </div>
                    </li>
                @empty
                    <li class="flex flex-col items-center justify-center py-10 text-center">
                        <span class="flex h-12 w-12 items-center justify-center rounded-[12px] bg-blue-50 text-blue-600">
                            <svg class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5" aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M2.25 8.25h19.5M2.25 9h19.5m-16.5 5.25h6m-6 2.25h3m-3.75 3h15a2.25 2.25 0 002.25-2.25V6.75A2.25 2.25 0 0019.5 4.5h-15a2.25 2.25 0 00-2.25 2.25v10.5A2.25 2.25 0 004.5 19.5z"/>
                            </svg>
                        </span>
                        <p class="mt-3 text-sm font-semibold text-zinc-900">No transactions yet</p>
                        <p class="mt-1 text-xs text-zinc-600">Fund your wallet or shop to get started.</p>
                    </li>
                @endforelse
            </ul>

            @if ($recentTransactions->isNotEmpty())
                <a href="{{ route('dashboard.transactions') }}" wire:navigate
                    class="mt-4 flex w-full items-center justify-center rounded-[12px] bg-blue-50 px-4 py-2.5 text-sm font-semibold text-blue-700 transition-colors hover:bg-blue-100">
                    View more
                </a>
            @endif
        </div>
    </div>

    {{-- ─────────────────────────────────────────────────────── --}}
    {{-- DESKTOP VIEW (lg+)                                      --}}
    {{-- ─────────────────────────────────────────────────────── --}}
    <div class="hidden lg:block">

        {{-- 12-col grid: heading + content live in left (cols 1-8), right rail (cols 9-12) starts at the top --}}
        <div class="grid grid-cols-1 gap-6 lg:grid-cols-12">

            {{-- LEFT: heading row, wallet/QA/orders, trust, shop by cat, recommended --}}
            <div class="flex flex-col gap-6 lg:col-span-8">

                {{-- Page heading row (sits inline with RShop Points on the right) --}}
                <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                    <div>
                        @php $firstName = strtok(trim((string) $user->name), ' ') ?: $user->name; @endphp
                        <h1 class="flex items-center gap-2 text-lg font-bold tracking-tight text-zinc-900 dark:text-white sm:text-xl"
                            x-data="typewriterGreeting(@js(['Hi ' . $firstName, 'Welcome', 'Your shop is ready']))">
                            {{-- translateZ(0) puts the changing text + the pulsing cursor on
                                 their own GPU layer, so their constant repaints stay composited
                                 and don't force the glass/gradient cards below to re-render
                                 (which read as a flicker/blink). --}}
                            <span x-text="display" style="transform: translateZ(0);"></span><span class="animate-pulse font-normal text-blue-600" style="transform: translateZ(0);" aria-hidden="true">|</span>
                            <svg class="h-5 w-5 shrink-0 text-blue-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5" aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M10.05 4.575a1.575 1.575 0 1 0-3.15 0v3m3.15-3v-1.5a1.575 1.575 0 0 1 3.15 0v1.5m-3.15 0 .075 5.925m3.075.75V4.575m0 0a1.575 1.575 0 0 1 3.15 0V15M6.9 7.575a1.575 1.575 0 1 0-3.15 0v8.175a6.75 6.75 0 0 0 6.75 6.75h2.018a5.25 5.25 0 0 0 3.712-1.538l1.732-1.732a5.25 5.25 0 0 0 1.538-3.712l.003-2.024a.668.668 0 0 1 .198-.471 1.575 1.575 0 1 0-2.228-2.228 3.818 3.818 0 0 0-1.12 2.687M6.9 7.575V12m6.27 4.318A4.49 4.49 0 0 1 16.35 15"/>
                            </svg>
                        </h1>
                        <p class="mt-1 text-sm text-zinc-600">Here's what's happening with your account today.</p>
                    </div>

                    {{-- Customize button - theme picker dropdown (Light / Dark / System).
                         Hooks into window.setTheme() from partials/theme-engine.blade.php,
                         which writes localStorage['theme'] and toggles the .dark class on
                         <html> for instant before-first-paint application. The dropdown
                         re-syncs on theme-changed events (fired by the engine when the OS
                         changes while on System) so the active dot stays accurate.
                         Held as a self-contained Alpine subtree so it doesn't conflict
                         with the wallet card's x-data sibling. --}}
                    <div
                        x-data="{
                            open: false,
                            theme: window.themeChoice ? window.themeChoice() : (localStorage.getItem('theme') || 'system'),
                            choose(value) {
                                this.theme = value;
                                window.setTheme(value);
                                this.open = false;
                            },
                        }"
                        x-on:theme-changed.window="theme = localStorage.getItem('theme') || 'system'"
                        @click.outside="open = false"
                        @keydown.escape="open = false"
                        class="relative self-start"
                    >
                        <button
                            type="button"
                            @click="open = ! open"
                            :aria-expanded="open.toString()"
                            class="inline-flex items-center gap-2 rounded-[12px] border border-zinc-200 px-4 py-2 text-sm font-semibold text-zinc-700 transition-colors"
                        >
                            {{-- Theme indicator - swaps Light / Dark / Auto PNG icons
                                 (project-canonical, brightness-0 dark:invert so they
                                 adapt to the current surface). --}}
                            <span class="relative inline-flex h-4 w-4 items-center justify-center">
                                <img
                                    x-show="theme === 'light'"
                                    x-cloak
                                    x-transition:enter="transition ease-out duration-200"
                                    x-transition:enter-start="opacity-0 scale-75"
                                    x-transition:enter-end="opacity-100 scale-100"
                                    x-transition:leave="transition ease-in duration-150"
                                    x-transition:leave-start="opacity-100 scale-100"
                                    x-transition:leave-end="opacity-0 scale-75"
                                    src="{{ asset('assets/' . rawurlencode('Light mode respects theme.webp')) }}"
                                    alt=""
                                    class="absolute h-4 w-4 brightness-0 dark:invert"
                                    loading="lazy"
                                >
                                <img
                                    x-show="theme === 'dark'"
                                    x-cloak
                                    x-transition:enter="transition ease-out duration-200"
                                    x-transition:enter-start="opacity-0 scale-75"
                                    x-transition:enter-end="opacity-100 scale-100"
                                    x-transition:leave="transition ease-in duration-150"
                                    x-transition:leave-start="opacity-100 scale-100"
                                    x-transition:leave-end="opacity-0 scale-75"
                                    src="{{ asset('assets/' . rawurlencode('Dark mode respects light and dark mode.webp')) }}"
                                    alt=""
                                    class="absolute h-4 w-4 brightness-0 dark:invert"
                                    loading="lazy"
                                >
                                <img
                                    x-show="theme === 'system'"
                                    x-transition:enter="transition ease-out duration-200"
                                    x-transition:enter-start="opacity-0 scale-75"
                                    x-transition:enter-end="opacity-100 scale-100"
                                    x-transition:leave="transition ease-in duration-150"
                                    x-transition:leave-start="opacity-100 scale-100"
                                    x-transition:leave-end="opacity-0 scale-75"
                                    src="{{ asset('assets/' . rawurlencode('Auto Mode.webp')) }}"
                                    alt=""
                                    class="absolute h-4 w-4 brightness-0 dark:invert"
                                    loading="lazy"
                                >
                            </span>
                            Customize
                            <svg class="h-4 w-4 text-zinc-600 transition-transform duration-150" :class="open && 'rotate-180'" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7"/>
                            </svg>
                        </button>

                        {{-- Settings panel - theme picker + master toggles + link out. --}}
                        <div
                            x-show="open"
                            x-cloak
                            x-transition:enter="transition ease-out duration-150"
                            x-transition:enter-start="opacity-0 -translate-y-1"
                            x-transition:enter-end="opacity-100 translate-y-0"
                            x-transition:leave="transition ease-in duration-100"
                            x-transition:leave-start="opacity-100 translate-y-0"
                            x-transition:leave-end="opacity-0 -translate-y-1"
                            class="absolute right-0 top-full z-30 mt-1.5 w-64 overflow-hidden rounded-[15px] bg-[#eff6ff] p-1 shadow-xl shadow-zinc-900/15 border border-zinc-200 dark:border-zinc-700"
                            role="menu"
                        >
                            <p class="px-3 pt-2 pb-1 text-[10px] font-semibold uppercase tracking-wider text-zinc-500">Appearance</p>
                            @foreach ([
                                ['value' => 'light',  'label' => 'Light',  'icon' => 'icons.theme-light'],
                                ['value' => 'dark',   'label' => 'Dark',   'icon' => 'icons.theme-dark'],
                                ['value' => 'system', 'label' => 'System', 'icon' => 'icons.theme-auto'],
                            ] as $opt)
                                <button
                                    type="button"
                                    @click="choose('{{ $opt['value'] }}')"
                                    :class="theme === '{{ $opt['value'] }}' ? 'bg-blue-50 text-blue-700' : 'text-zinc-700 hover:bg-zinc-100'"
                                    class="flex w-full items-center justify-between gap-3 rounded-[12px] px-3 py-2 text-left text-sm font-medium transition-colors"
                                    role="menuitem"
                                >
                                    <span class="inline-flex items-center gap-2.5">
                                        <x-dynamic-component :component="$opt['icon']" class="h-4 w-4" />
                                        {{ $opt['label'] }}
                                    </span>
                                    <svg x-show="theme === '{{ $opt['value'] }}'" class="h-4 w-4 text-blue-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5" aria-hidden="true">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5"/>
                                    </svg>
                                </button>
                            @endforeach


                            {{-- Divider --}}
                            <div class="my-1 h-px bg-zinc-100"></div>

                            <p class="px-3 pt-1 pb-1 text-[10px] font-semibold uppercase tracking-wider text-zinc-500">Privacy & density</p>

                            {{-- Hide all balances - master switch that overrides every wallet
                                 card's eye-toggle so balances are masked dashboard-wide. --}}
                            <button
                                type="button"
                                @click="$store.dashPrefs.setHideBalance(! $store.dashPrefs.hideBalance)"
                                class="flex w-full items-center justify-between gap-3 rounded-[12px] px-3 py-2 text-left text-sm font-medium text-zinc-700 transition-colors hover:bg-zinc-100"
                                role="menuitemcheckbox"
                                :aria-checked="$store.dashPrefs.hideBalance.toString()"
                            >
                                <span class="inline-flex items-center gap-2.5">
                                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75" aria-hidden="true">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M3.98 8.223A10.477 10.477 0 001.934 12C3.226 16.338 7.244 19.5 12 19.5c.993 0 1.953-.138 2.863-.395M6.228 6.228A10.45 10.45 0 0112 4.5c4.756 0 8.773 3.162 10.065 7.498a10.523 10.523 0 01-4.293 5.774M6.228 6.228L3 3m3.228 3.228l3.65 3.65m7.244 7.244L19.5 19.5m-2.876-2.876L13.875 13.875M9.878 9.878a3 3 0 105.249 5.249"/>
                                    </svg>
                                    Hide all balances
                                </span>
                                <span
                                    :class="$store.dashPrefs.hideBalance ? 'bg-blue-600' : 'bg-zinc-200'"
                                    class="theme-static relative inline-flex h-5 w-9 shrink-0 cursor-pointer items-center rounded-[12px] transition-colors"
                                >
                                    <span
                                        :class="$store.dashPrefs.hideBalance ? 'translate-x-4' : 'translate-x-0.5'"
                                        class="inline-block h-4 w-4 transform rounded-[12px] bg-white shadow transition-transform"
                                    ></span>
                                </span>
                            </button>

                            {{-- Compact mode - adds the `.compact` class on <html>; tighter padding
                                 + smaller gaps on dashboard cards (rules in app.css). --}}
                            <button
                                type="button"
                                @click="$store.dashPrefs.setCompactMode(! $store.dashPrefs.compactMode)"
                                class="flex w-full items-center justify-between gap-3 rounded-[12px] px-3 py-2 text-left text-sm font-medium text-zinc-700 transition-colors hover:bg-zinc-100"
                                role="menuitemcheckbox"
                                :aria-checked="$store.dashPrefs.compactMode.toString()"
                            >
                                <span class="inline-flex items-center gap-2.5">
                                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75" aria-hidden="true">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M3.75 9h16.5m-16.5 6.75h16.5"/>
                                    </svg>
                                    Compact mode
                                </span>
                                <span
                                    :class="$store.dashPrefs.compactMode ? 'bg-blue-600' : 'bg-zinc-200'"
                                    class="theme-static relative inline-flex h-5 w-9 shrink-0 cursor-pointer items-center rounded-[12px] transition-colors"
                                >
                                    <span
                                        :class="$store.dashPrefs.compactMode ? 'translate-x-4' : 'translate-x-0.5'"
                                        class="inline-block h-4 w-4 transform rounded-[12px] bg-white shadow transition-transform"
                                    ></span>
                                </span>
                            </button>

                            {{-- Divider --}}
                            <div class="my-1 h-px bg-zinc-100"></div>

                            <a
                                href="{{ route('dashboard.appearance') }}"
                                wire:navigate
                                @click="open = false"
                                class="flex items-center justify-between gap-3 rounded-[12px] px-3 py-2 text-sm font-semibold text-blue-600 transition-colors hover:bg-blue-50"
                                role="menuitem"
                            >
                                All settings
                                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M13.5 4.5L21 12m0 0l-7.5 7.5M21 12H3"/>
                                </svg>
                            </a>
                        </div>
                    </div>
                </div>

                {{-- Wallet + Recent Order row --}}
                <div class="grid grid-cols-1 gap-6 xl:grid-cols-2">

                    {{-- Wallet balance card. Switchable per-currency. Card bg adopts the active wallet's brand color. --}}
                    <div
                        x-data="{
                            visible: true,
                            walletOpen: false,
                            wallets: @js($walletsPayload),
                            get current() { return this.wallets[this.$store.wallet.active] ?? { code: 'USD', symbol: '$', label: 'US Dollar', formatted: '$0.00', type: 'fiat', color: 'bg-blue-800', icon: null }; }
                        }"
                        class="wallet-glass flex flex-col justify-center gap-4 rounded-[15px] p-5 text-left text-blue-950 dark:text-white"
                        :class="walletOpen && 'relative z-40'"
                    >
                        <div class="flex items-start justify-between">
                            <div class="min-w-0">
                                <p class="text-xs font-semibold uppercase tracking-wider text-blue-700 dark:text-blue-100">Total Wallet Balance</p>
                                <div class="mt-1.5 flex items-center gap-2">
                                    {{-- Effective visibility = local eye toggle AND the global "Hide all
                                         balances" master from the Customize menu. Either one false → mask. --}}
                                    <p class="truncate text-3xl font-bold tracking-tight" x-text="(visible && ! $store.dashPrefs.hideBalance) ? current.formatted : (current.symbol + ' ••••')">{{ $walletSymbol }}{{ number_format($walletBalance, 2) }}</p>
                                    <button type="button" @click="visible = !visible" class="shrink-0 rounded-[12px] p-1 text-blue-700 transition-colors hover:bg-blue-900/10 hover:text-blue-900 dark:text-blue-200 dark:hover:bg-white/10 dark:hover:text-white" :aria-label="visible ? 'Hide balance' : 'Show balance'">
                                        <svg x-show="visible" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75" aria-hidden="true">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M3.98 8.223A10.477 10.477 0 001.934 12C3.226 16.338 7.244 19.5 12 19.5c.993 0 1.953-.138 2.863-.395M6.228 6.228A10.45 10.45 0 0112 4.5c4.756 0 8.773 3.162 10.065 7.498a10.523 10.523 0 01-4.293 5.774M6.228 6.228L3 3m3.228 3.228l3.65 3.65m7.244 7.244L19.5 19.5m-2.876-2.876L13.875 13.875M9.878 9.878a3 3 0 105.249 5.249"/>
                                        </svg>
                                        <svg x-show="!visible" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75" aria-hidden="true" style="display:none;">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M2.036 12.322a1.012 1.012 0 010-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.638 0-8.573-3.007-9.963-7.178z"/>
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/>
                                        </svg>
                                    </button>
                                </div>
                                <p class="mt-0.5 text-xs text-blue-700 dark:text-blue-100"><span x-text="current.code">{{ $walletCurrencyCode }}</span> · <span x-text="current.label">{{ $walletCurrencyCase?->label() ?? 'Wallet' }}</span></p>
                            </div>

                            <span class="flex h-12 w-12 shrink-0 items-center justify-center overflow-hidden rounded-full bg-blue-600/10 dark:bg-white/15">
                                {{-- Show active wallet's specific icon when available, otherwise the generic wallet glyph.
                                     The flag fills the circle (object-cover, clipped by the parent's overflow-hidden). --}}
                                <template x-if="current.icon">
                                    <img :src="current.icon" alt="" class="h-full w-full object-cover" loading="lazy">
                                </template>
                                <template x-if="!current.icon">
                                    <img src="{{ asset('assets/' . rawurlencode('Wallet.svg')) }}" alt="" class="h-6 w-6 brightness-0 dark:invert" loading="lazy">
                                </template>
                            </span>
                        </div>

                        {{-- Top Up pill (right-aligned) with a tiny live-rate ticker below.
                             Fund Wallet modal calls WalletFundingService → gateway. --}}
                        <div>
                            <div class="flex items-center justify-end">
                                <livewire:dashboard.fund-wallet :currency="$walletCurrencyCode" wire:key="fund-desktop" />
                            </div>
                        </div>

                        {{-- Currency switcher - collapsed by default to keep the card clean.
                             Click to open a list of all wallets (with balances), pick one,
                             panel closes. The trigger only ever shows the active wallet so
                             other balances don't leak at a glance. --}}
                        @if ($walletsPayload->count() > 1)
                            <div class="relative" @click.outside="walletOpen = false" @keydown.escape="walletOpen = false">
                                {{-- Trigger --}}
                                <button
                                    type="button"
                                    @click="walletOpen = ! walletOpen"
                                    :aria-expanded="walletOpen.toString()"
                                    class="flex w-full items-center justify-between gap-2 rounded-[12px] bg-blue-600/10 px-3 py-2.5 text-left text-blue-950 transition-colors hover:bg-blue-600/15 dark:bg-white/10 dark:text-white dark:hover:bg-white/15"
                                >
                                    <span class="inline-flex min-w-0 items-center gap-2">
                                        <span class="text-[10px] font-semibold uppercase tracking-wider text-blue-700 dark:text-blue-100">Switch wallet</span>
                                        <span class="text-blue-400 dark:text-zinc-200">&middot;</span>
                                        <img :src="current.icon" alt="" class="h-4 w-4 shrink-0 object-contain" x-show="current.icon" loading="lazy">
                                        <span class="text-xs font-bold uppercase tracking-wider" x-text="current.code"></span>
                                    </span>
                                    <svg class="h-4 w-4 shrink-0 text-blue-700 dark:text-blue-100 transition-transform duration-150" :class="walletOpen && 'rotate-180'" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5" aria-hidden="true">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7"/>
                                    </svg>
                                </button>

                                {{-- Panel - absolute below the trigger, light bg so it reads against the
                                     blue card behind. z-30 keeps it above the dashboard content but well
                                     below modals (which start at z-[70]). --}}
                                <div
                                    x-show="walletOpen"
                                    x-cloak
                                    x-transition:enter="transition ease-out duration-150"
                                    x-transition:enter-start="opacity-0 -translate-y-1"
                                    x-transition:enter-end="opacity-100 translate-y-0"
                                    x-transition:leave="transition ease-in duration-100"
                                    x-transition:leave-start="opacity-100 translate-y-0"
                                    x-transition:leave-end="opacity-0 -translate-y-1"
                                    class="absolute left-0 right-0 top-full z-30 mt-1.5 max-h-72 overflow-y-auto rounded-[15px] bg-[#eff6ff] p-1 shadow-xl shadow-zinc-900/25 border border-zinc-200 dark:border-zinc-700"
                                    role="listbox"
                                >
                                    <template x-for="(w, i) in wallets" :key="w.code">
                                        <button
                                            type="button"
                                            @click="$store.wallet.active = i; walletOpen = false"
                                            :class="$store.wallet.active === i ? 'bg-blue-50 text-blue-700' : 'text-zinc-700 hover:bg-zinc-100'"
                                            class="flex w-full items-center justify-between gap-3 rounded-[12px] px-3 py-2.5 text-left text-sm font-medium transition-colors"
                                            role="option"
                                        >
                                            <span class="flex min-w-0 items-center gap-2">
                                                <img :src="w.icon" alt="" class="h-5 w-5 shrink-0 object-contain" x-show="w.icon" loading="lazy">
                                                <span class="text-xs font-bold uppercase tracking-wider" x-text="w.code"></span>
                                                <span class="truncate text-xs text-zinc-500" x-text="w.label"></span>
                                            </span>
                                            <span class="flex shrink-0 items-center gap-2">
                                                <span class="text-sm font-bold tabular-nums" x-text="$store.dashPrefs.hideBalance ? (w.symbol + ' ••••') : w.formatted"></span>
                                                <svg x-show="$store.wallet.active === i" class="h-4 w-4 text-blue-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5" aria-hidden="true">
                                                    <path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5"/>
                                                </svg>
                                            </span>
                                        </button>
                                    </template>
                                </div>
                            </div>
                        @endif
                    </div>

                    {{-- Recent Order card. Capped at 3 on desktop's right-rail
                         even though the query fetches 5 (mobile renders all 5). --}}
                    <div class="relative overflow-hidden rounded-[15px] bg-[#eff6ff] p-5 border border-zinc-200 shadow-md shadow-zinc-900/[0.06] transition-colors dark:border-zinc-700 dark:shadow-none">
                        <x-ui.floating-confetti />
                        <div class="relative z-10 flex items-center justify-between">
                            <h3 class="text-base font-semibold text-zinc-900">Recent Orders</h3>
                            <a href="{{ route('dashboard.orders') }}" wire:navigate class="inline-flex items-center rounded-md bg-blue-50 px-1.5 py-0.5 text-[10px] font-semibold text-blue-600 ring-1 ring-inset ring-blue-200 transition-colors hover:bg-blue-100 dark:bg-blue-500/15 dark:text-blue-300 dark:ring-blue-500/30 dark:hover:bg-blue-500/25">View all</a>
                        </div>

                        <ul class="relative z-10 mt-4 space-y-3">
                            @forelse ($recentOrders->take(3) as $order)
                                @php
                                    $groupedItems = $order->items->groupBy('product_variant_id');
                                    $firstGroup = $groupedItems->first();
                                    $firstItem = $firstGroup?->first();
                                    $firstItemQty = $firstGroup ? $firstGroup->count() : 0;
                                    $snap = (array) ($firstItem?->product_snapshot ?? []);
                                    $brandKey = $snap['brand_key'] ?? null;
                                    $itemName = $brandKey ? Product::brandDisplayName($brandKey) : ($snap['name'] ?? 'Order');
                                    $extraVariants = max(0, $groupedItems->count() - 1);
                                    $logo = Product::brandLogoUrl($brandKey, $snap['logo_url'] ?? null);
                                    [$statusLabel, $statusTone] = $orderStatusUi[$order->order_status?->value] ?? ['Pending', 'bg-amber-50 text-amber-700 ring-amber-200 dark:bg-amber-500/15 dark:text-amber-300 dark:ring-amber-500/30'];
                                @endphp
                                <li>
                                    <a href="{{ route('dashboard.orders') }}" wire:navigate class="flex items-center gap-3">
                                        <span class="flex h-12 w-12 shrink-0 items-center justify-center overflow-hidden rounded-[12px] bg-white ring-1 ring-zinc-100">
                                            @if ($logo)
                                                <img src="{{ $logo }}" alt="" class="h-8 w-8 object-contain" loading="lazy">
                                            @else
                                                <span class="text-xs font-bold uppercase text-zinc-500">{{ \Illuminate\Support\Str::substr($itemName, 0, 2) }}</span>
                                            @endif
                                        </span>
                                        <div class="min-w-0 flex-1">
                                            <div class="flex items-center justify-between gap-2">
                                                <p class="truncate text-sm font-semibold text-zinc-900">
                                                    @if ($firstItemQty > 1){{ $firstItemQty }}x @endif{{ $itemName }}@if ($extraVariants > 0) <span class="font-normal text-zinc-500">+{{ $extraVariants }}</span>@endif
                                                </p>
                                                <span class="inline-flex w-fit shrink-0 items-center whitespace-nowrap rounded-[5px] px-2.5 py-1 text-[10px] font-bold uppercase tracking-wide ring-1 {{ $statusTone }}">{{ $statusLabel }}</span>
                                            </div>
                                            <div class="mt-0.5 flex items-center justify-between gap-2">
                                                <p class="truncate text-xs text-zinc-600">@moneyCode((float) $order->total_amount, $order->settlement_currency)</p>
                                                <p class="shrink-0 text-[10px] text-zinc-600">{{ $order->created_at->diffForHumans() }}</p>
                                            </div>
                                        </div>
                                    </a>
                                </li>
                            @empty
                                <li class="flex flex-col items-center justify-center py-8 text-center">
                                    <p class="text-sm font-semibold text-zinc-900">No orders yet</p>
                                    <p class="mt-1 text-xs text-zinc-600">Your purchases will show up here.</p>
                                </li>
                            @endforelse
                        </ul>
                    </div>
                </div>

                {{-- Trust strip - serious glass pill. The frost + highlights
                     + dark/light variants live on .trust-glow in resources/css/app.css
                     so the Tailwind utilities here stay structural only. --}}
                <div class="flex items-center gap-4 rounded-full bg-[#eff6ff] py-3 pl-3 pr-6 border border-zinc-200 shadow-md shadow-zinc-900/[0.06] dark:border-zinc-700 dark:shadow-none">
                    <span class="flex h-12 w-12 shrink-0 items-center justify-center">
                        <img src="{{ asset('assets/secure payments.svg') }}" alt="" class="h-9 w-9" loading="lazy">
                    </span>
                    <div class="min-w-0 flex-1">
                        <p class="text-sm font-semibold text-zinc-900">Secure. Fast. Reliable.</p>
                        <p class="mt-0.5 text-xs text-zinc-600">Your transactions are protected with bank-level security.</p>
                    </div>
                </div>

                {{-- Quick Actions + Recommended for you (combined card with divider) --}}
                <div class="rounded-[15px] bg-[#eff6ff] dash-shimmer border border-zinc-200 shadow-md shadow-zinc-900/[0.06] transition-colors hover:border-green-200 dark:border-zinc-700 dark:hover:border-white dark:shadow-none">

                    {{-- Quick Actions section --}}
                    <div class="p-5 sm:p-6">
                        <div class="flex items-center justify-between">
                            <h3 class="text-base font-semibold text-zinc-900">Quick Actions</h3>
                            <a href="{{ route('dashboard.shop.gift-cards') }}" wire:navigate class="inline-flex items-center rounded-md bg-blue-50 px-1.5 py-0.5 text-[10px] font-semibold text-blue-600 ring-1 ring-inset ring-blue-200 transition-colors hover:bg-blue-100 dark:bg-blue-500/15 dark:text-blue-300 dark:ring-blue-500/30 dark:hover:bg-blue-500/25">View all</a>
                        </div>

                        <div class="mt-4 grid grid-cols-4 gap-3 sm:grid-cols-8">
                            {{-- Same backfill rule as the mobile grid: Fund Wallet
                                 takes the Flights slot, Electronics slots in next
                                 to Gaming when Stays is hidden, so the single
                                 sm:grid-cols-8 row never shows a gap. --}}
                            @foreach (array_values(array_filter([
                                ['Gift Cards', 'gift cards.svg',   'bg-pink-500',     null,             route('dashboard.shop.gift-cards')],
                                ['eSIMs',      'esim.svg',         'bg-sky-500',      null,             route('dashboard.shop.esims')],
                                \App\Support\FeatureFlag::categoryVisible('flights')
                                    ? ['Flights', 'flight 2.svg', 'bg-indigo-500', 'flight.svg', route('dashboard.shop.flights')]
                                    : ['Fund Wallet', 'Wallet.svg', 'bg-indigo-500', null, route('dashboard.wallet')],
                                \App\Support\FeatureFlag::categoryVisible('stays')
                                    ? ['Stays', 'stay 2.svg', 'bg-orange-500', 'stay.svg', route('dashboard.shop.stays')]
                                    : null,
                                ['Topups',     'topup1.svg',       'bg-emerald-500',  null,             route('dashboard.shop.topups')],
                                ['Bills',      'bill payment.svg', 'bg-teal-500',     'Bills 2.svg',    route('dashboard.shop.bills')],
                                ['Gaming',     'Gaming.svg',       'bg-fuchsia-500',  'gaming two.svg', route('dashboard.shop.gift-cards', ['subcategory' => 'gaming-entertainment'])],
                                \App\Support\FeatureFlag::categoryVisible('stays')
                                    ? null
                                    : ['Electronics', 'mobile.svg', 'bg-orange-500', null, route('dashboard.shop.gift-cards', ['subcategory' => 'electronics'])],
                                ['More',       'More.svg',         'bg-blue-500',     'more two.svg',   route('dashboard.shop.gift-cards')],
                            ])) as [$label, $icon, $bg, $hoverIcon, $href])
                                <a href="{{ $href }}" wire:navigate class="group flex flex-col items-center gap-2 text-center">
                                    <span class="flex h-14 w-14 items-center justify-center rounded-full {{ $bg }} transition-transform group-hover:scale-105">
                                        @if ($hoverIcon)
                                            <img src="{{ asset('assets/' . rawurlencode($icon)) }}" alt="" class="h-6 w-6 brightness-0 invert group-hover:hidden" loading="lazy">
                                            <img src="{{ asset('assets/' . rawurlencode($hoverIcon)) }}" alt="" class="hidden h-6 w-6 brightness-0 invert group-hover:block" loading="lazy">
                                        @else
                                            <img src="{{ asset('assets/' . rawurlencode($icon)) }}" alt="" class="h-6 w-6 brightness-0 invert" loading="lazy">
                                        @endif
                                    </span>
                                    <span class="text-xs font-medium text-zinc-700 transition-colors group-hover:text-blue-700">{{ $label }}</span>
                                </a>
                            @endforeach
                        </div>
                    </div>

                    {{-- Divider --}}
                    <div class="border-t border-zinc-100"></div>

                    {{-- Popular Gift Cards - same curated source (config/popular_brands.php)
                        and card style as the storefront's "Popular Gift Cards" row. --}}
                    <div class="p-5 sm:p-6">
                        @if ($popularProducts->isNotEmpty())
                            <x-home.brand-row
                                title="Popular Gift Cards"
                                subtitle="Top-rated gift cards in your region"
                                :view-all-href="route('dashboard.shop.gift-cards')"
                                :cols="5"
                                :carousel="true"
                                :rows="2"
                                :mobile-rows="3"
                                {{-- Three across, two deep. Measured rather than
                                     fixed: this column is 8 of 12, so the
                                     storefront's 280px card would show two. --}}
                                :per-view="3"
                                {{-- Sits in the desktop left column, not the page. --}}
                                :bleed="false"
                            >
                                @foreach ($popularProducts as $p)
                                    @php
                                        $logo = Product::brandLogoUrl($p->brand_key, $p->logo_url);
                                        $label = Product::brandDisplayName($p->brand_key);
                                        $brandColor = Product::brandColor($p->brand_key, $p->brand_color);
                                    @endphp
                                    <x-home.brand-card
                                        :name="$label"
                                        :price-range="$p->priceRangeLabel()"
                                        :rating="$brandRatings[$p->brand_key] ?? null"
                                        :href="route('dashboard.shop.brand', ['brandSlug' => Product::brandSlug($p->brand_key)])"
                                        :card-class="$logo ? 'bg-[#ffffff]' : ($brandColor ? '' : 'bg-zinc-100')"
                                        :style="! $logo && $brandColor ? 'background-color: ' . $brandColor . ';' : false"
                                    >
                                        @if ($logo)
                                            <img src="{{ $logo }}" alt="{{ $label }} gift card" class="h-full w-full object-cover" loading="lazy">
                                        @else
                                            <span class="px-3 text-center text-xl font-black uppercase leading-tight tracking-tight sm:text-2xl {{ $brandColor ? 'text-white' : 'text-zinc-700' }}">{{ $label }}</span>
                                        @endif
                                    </x-home.brand-card>
                                @endforeach
                            </x-home.brand-row>
                        @else
                            <div class="rounded-[12px] bg-blue-50 px-4 py-8 text-center">
                                <p class="text-sm font-semibold text-zinc-900">No gift cards in your region yet</p>
                                <p class="mt-1 text-xs text-zinc-600">Check back soon as the catalogue grows.</p>
                            </div>
                        @endif
                    </div>
                </div>
            </div>

            {{-- RIGHT RAIL: points, gift promo, recent transactions --}}
            <div class="flex flex-col gap-6 lg:col-span-4">

                {{-- RShop Rcoin card (desktop). Live balance + tier from the
                     wallet ledger (RewardEngine credits cashback + referral here,
                     CheckoutService debits redemptions, etc.). --}}
                <div class="rounded-[15px] bg-[#eff6ff] p-5 dash-shimmer border border-zinc-200 shadow-md shadow-zinc-900/[0.06] transition-colors hover:border-green-200 dark:border-zinc-700 dark:hover:border-white dark:shadow-none">
                    <div class="flex items-start gap-3">
                        <span class="flex h-10 w-10 shrink-0 items-center justify-center overflow-hidden rounded-[12px] bg-blue-100">
                            <img src="{{ asset('assets/favicon.ico') }}" alt="" class="h-6 w-6 object-contain" loading="lazy">
                        </span>
                        <div class="min-w-0 flex-1">
                            <p class="text-sm font-semibold text-zinc-900">RShop Rcoin</p>
                            <div class="mt-1 flex items-center gap-2">
                                <span class="text-2xl font-bold tracking-tight text-zinc-900">{{ number_format($rcoinBalance) }}</span>
                                <span class="rounded-[5px] {{ $currentTier['color'] }} px-2 py-0.5 text-[10px] font-bold uppercase tracking-wide text-white">{{ $currentTier['name'] }}</span>
                            </div>
                        </div>
                    </div>
                    <p class="mt-4 text-xs text-zinc-600">Earn Rcoin on every order and referral, then spend it on gift cards.</p>

                    <a href="{{ route('dashboard.rewards') }}" wire:navigate class="mt-4 inline-flex w-full items-center justify-center gap-1.5 rounded-[12px] bg-blue-600 px-4 py-2.5 text-sm font-semibold text-white transition-colors hover:bg-blue-700">
                        {{-- Favicon is blue; force it white so it shows on the blue button. --}}
                        <img src="{{ asset('assets/favicon.ico') }}" alt="" class="h-4 w-4 object-contain" style="filter: brightness(0) invert(1);">
                        View coins
                    </a>
                </div>

                {{-- Give the Perfect Gift promo --}}
                <div class="relative overflow-hidden rounded-[12px] bg-blue-950 p-5 text-white">
                    <div class="relative z-10 max-w-[54%]">
                        <h3 class="text-lg font-bold tracking-tight">Give the Perfect Gift</h3>
                        <p class="mt-1 text-sm text-blue-100/80">Gift cards for every occasion and everyone.</p>
                        {{-- Literal-hex bg + no-dark-invert icon so this light button stays
                             light on the always-dark promo card in both themes. --}}
                        <a href="{{ route('dashboard.shop.gift-cards') }}" wire:navigate class="mt-4 inline-flex items-center gap-2 rounded-[12px] bg-[#ffffff] px-4 py-2 text-sm font-semibold text-blue-950 transition-colors hover:bg-[#dbeafe]">
                            Shop Now
                            <img src="{{ asset('assets/' . rawurlencode('Shop.svg')) }}" alt="" class="h-4 w-4 no-dark-invert" loading="lazy">
                        </a>
                    </div>

                    <img
                        src="{{ asset('assets/' . rawurlencode('Pick a product first process.webp')) }}"
                        alt=""
                        class="pointer-events-none absolute -right-3 bottom-2 w-[46%] max-w-[230px] select-none object-contain object-bottom drop-shadow-2xl"
                        loading="lazy"
                    >
                </div>

                {{-- Recent Transactions (stretches to match left column height) --}}
                <div class="relative flex flex-1 flex-col overflow-hidden rounded-[15px] bg-[#eff6ff] dash-shimmer p-5 border border-zinc-200 shadow-md shadow-zinc-900/[0.06] transition-colors dark:border-zinc-700 dark:shadow-none">
                    <x-ui.floating-confetti />
                    <div class="relative z-10 flex items-center justify-between">
                        <h3 class="text-base font-semibold text-zinc-900">Recent Transactions</h3>
                        <a href="{{ route('dashboard.transactions') }}" wire:navigate class="inline-flex items-center rounded-md bg-blue-50 px-1.5 py-0.5 text-[10px] font-semibold text-blue-600 ring-1 ring-inset ring-blue-200 transition-colors hover:bg-blue-100 dark:bg-blue-500/15 dark:text-blue-300 dark:ring-blue-500/30 dark:hover:bg-blue-500/25">View all</a>
                    </div>

                    {{-- min-h-0 is load-bearing: without it this flex child refuses
                         to shrink below its content, so a long list stretches the
                         card instead of scrolling inside it. The scrollbar is
                         hidden to match every other scroll surface here. --}}
                    <ul class="relative z-10 mt-4 min-h-0 flex-1 space-y-3 overflow-y-auto [scrollbar-width:none] [&::-webkit-scrollbar]:hidden">
                        @forelse ($recentTransactions as $txn)
                            <li class="flex items-center gap-3">
                                <span class="flex h-11 w-11 shrink-0 items-center justify-center rounded-full bg-white shadow-sm shadow-zinc-900/5">
                                    @if ($txn->is_credit)
                                        <x-icons.txn-credit class="h-9 w-9" />
                                    @else
                                        <x-icons.txn-debit class="h-9 w-9" />
                                    @endif
                                </span>
                                <div class="min-w-0 flex-1">
                                    <p class="truncate text-sm font-semibold text-zinc-900">{{ $txn->title }}</p>
                                    <p class="truncate text-[11px] text-zinc-600">{{ $txn->sub }}</p>
                                </div>
                                <div class="shrink-0 text-right">
                                    <p class="text-sm font-bold {{ $txn->is_credit ? 'text-emerald-600' : 'text-zinc-900' }}">{{ $txn->is_credit ? '+' : '-' }}{{ $txn->symbol }}{{ number_format($txn->amount, 2) }}</p>
                                    <p class="text-[10px] text-zinc-600">{{ $txn->date->format('d M, H:i') }}</p>
                                </div>
                            </li>
                        @empty
                            <li class="flex flex-1 flex-col items-center justify-center py-10 text-center">
                                <span class="flex h-12 w-12 items-center justify-center rounded-[12px] bg-blue-50 text-blue-600">
                                    <svg class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5" aria-hidden="true">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M2.25 8.25h19.5M2.25 9h19.5m-16.5 5.25h6m-6 2.25h3m-3.75 3h15a2.25 2.25 0 002.25-2.25V6.75A2.25 2.25 0 0019.5 4.5h-15a2.25 2.25 0 00-2.25 2.25v10.5A2.25 2.25 0 004.5 19.5z"/>
                                    </svg>
                                </span>
                                <p class="mt-3 text-sm font-semibold text-zinc-900">No transactions yet</p>
                                <p class="mt-1 text-xs text-zinc-600">Fund your wallet or shop directly to get started.</p>
                            </li>
                        @endforelse
                    </ul>
                </div>
            </div>
        </div>

    </div>
</div>

{{--
    Customer dashboard — /dashboard.

    Thin shell: this view owns the layout + the mobile blue-hero wallet carousel
    (a named layout slot, which a Livewire component cannot pass). The main content
    area is the lazy <livewire:dashboard.overview> component — it returns a skeleton
    placeholder instantly, then boots and swaps in the real cards. So the dashboard
    "loads with a skeleton" on both hard loads and wire:navigate transitions.

    Admin operator dashboard lives at /admin/dashboard with a separate guard.
--}}
@php
    use App\Domain\Shared\Enums\Currency;

    $user = auth()->user();

    // Wallet data for the mobile hero carousel. The desktop wallet card lives inside
    // the lazy overview component, which computes its own copy of this payload.
    // Rcoin is rewards points, not a spendable wallet - it lives on its own
    // rewards section and never renders as a wallet card. The default wallet
    // (funded; largest USD-equivalent when several are funded) leads the carousel.
    $defaultWallet = $user->defaultWallet();
    // The markets the store actually sells into lead the rest, so a customer
    // in Lagos, Douala or Accra meets their own wallet in the stack before a
    // currency they hold a few cents in. The default wallet still comes
    // first: this orders what follows it.
    $walletLead = ['NGN' => 0, 'XAF' => 1, 'GHS' => 2];

    $allWallets = $user->wallets()->where('is_active', true)->where('currency', '!=', 'RCOIN')->get()
        ->sortBy(fn ($w) => $walletLead[strtoupper((string) ($w->currency?->value ?? ''))] ?? 99)
        ->sortByDesc(fn ($w) => $defaultWallet && $w->id === $defaultWallet->id)
        ->values();

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

    $isCryptoCode = fn (string $code) => in_array(strtoupper($code), ['BTC','USDT','BUSD','SOL','BNB','LTC','ETH','USDC'], true);

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

    // Wallet card bg — every wallet uses the same colour as the USD card.
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
@endphp

<x-layouts.dashboard>

    {{-- ─────────────────────────────────────────────────────── --}}
    {{-- MOBILE HERO (sits inside the layout's blue panel)        --}}
    {{-- ─────────────────────────────────────────────────────── --}}
    <x-slot:mobileHero>
        @php
            // Total balance across the cash wallets, in USD - the same conversion
            // the nav chip uses (User::navWalletSummary): a dollar wallet counts
            // as-is, every other currency divides by its active rate_per_usd, and
            // a currency with no active rate is left out rather than passed
            // through 1:1. Each wallet's USD share drives the ring and the % view.
            $ratesPerUsd = \App\Models\CurrencyRate::query()->where('is_active', true)->pluck('rate_per_usd', 'code');
            $usdFor = function (string $code, float $balance) use ($ratesPerUsd): ?float {
                if (strtoupper($code) === 'USD') {
                    return $balance;
                }
                $rate = (float) ($ratesPerUsd[strtoupper($code)] ?? 0.0);

                return $rate > 0 ? $balance / $rate : null;
            };
            $ringPalette = ['#2563eb', '#10b981', '#f59e0b', '#8b5cf6', '#ec4899', '#14b8a6', '#f97316'];
            $walletRows = $walletsPayload->values()->map(function (array $w, int $i) use ($usdFor, $ringPalette) {
                $w['usd'] = $usdFor($w['code'], (float) $w['balance']);
                $w['ring'] = $ringPalette[$i % count($ringPalette)];

                return $w;
            });
            $walletTotalUsd = round((float) $walletRows->sum(fn (array $w) => (float) ($w['usd'] ?? 0)), 2);
            [$totalWhole, $totalCents] = explode('.', number_format($walletTotalUsd, 2));
            $walletShares = $walletRows->map(fn (array $w) => $walletTotalUsd > 0 ? (int) round(100 * max(0.0, (float) ($w['usd'] ?? 0)) / $walletTotalUsd) : 0);

            // The ring: one arc per funded wallet, in wallet order, r=48 on a
            // 120-box, a 2-unit gap between arcs. An empty total draws no arc
            // and the track alone shows.
            $ringLength = 2 * M_PI * 48;
            $ringOffset = 0.0;
            $ringSegments = [];
            foreach ($walletRows as $w) {
                $share = $walletTotalUsd > 0 ? max(0.0, (float) ($w['usd'] ?? 0)) / $walletTotalUsd : 0.0;
                if ($share <= 0) {
                    continue;
                }
                $ringSegments[] = ['color' => $w['ring'], 'len' => max(0.0, $share * $ringLength - 2), 'offset' => $ringOffset];
                $ringOffset += $share * $ringLength;
            }
        @endphp
        {{-- Wallets: a pill (the wallets' flags stacked, the word, a
             chevron) over the USD figure. Tapping the pill slides a sheet up from
             the bottom with the ring and every wallet's balance, each with its own
             Top Up. The per-wallet swipe carousel this replaces is gone: one
             number first, the detail one tap away. --}}
        <div
            x-data="{ open: false, visible: true, mode: 'amount' }"
            x-on:keydown.escape.window="open = false"
            x-effect="open ? window.rshopScrollLock?.lock() : window.rshopScrollLock?.unlock()"
            class="flex flex-col items-center text-center"
        >
            <button
                type="button"
                @click="open = true"
                :aria-expanded="open.toString()"
                aria-haspopup="dialog"
                aria-label="Show wallet balances"
                class="inline-flex items-center gap-2 rounded-full bg-[#ffffff] py-1.5 pl-1.5 pr-4 text-[12px] font-black uppercase tracking-[0.12em] text-zinc-900 shadow-md shadow-zinc-900/[0.06] transition-transform active:scale-95 dark:bg-[#1d3252] dark:text-zinc-200 dark:shadow-none"
            >
                <span class="flex -space-x-2.5">
                    {{-- Every wallet the customer holds, up to five. Three was a
                         figure with nothing behind it: an account with five wallets
                         showed three flags and a chevron, so the pill under-reported
                         the very thing it exists to summarise. Five is where the
                         stack stops reading as a stack on a phone. --}}
                    @foreach ($walletRows->take(5) as $w)
                        <img src="{{ $w['icon'] }}" alt="" class="h-7 w-7 rounded-full object-cover ring-2 ring-white dark:ring-[#1a1a1e]" loading="lazy">
                    @endforeach
                </span>
                {{-- It opens a sheet of wallets, and the figure it sits over is
                     already labelled. "Total balance" named the number twice and
                     the thing the pill actually does not at all. --}}
                <span>Wallets</span>
                <svg class="h-4 w-4 shrink-0 transition-transform" :class="open && 'rotate-180'" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M19.5 8.25l-7.5 7.5-7.5-7.5"/>
                </svg>
            </button>

            {{-- Effective visibility = the local eye AND the account menu's global
                 "Hide all balances" switch. Either one off masks the figure. --}}
            <div class="mt-4 flex items-center gap-2 text-white">
                <p class="text-[40px] font-bold leading-none tracking-tight tabular-nums">
                    <span x-show="visible && ! $store.dashPrefs.hideBalance">${{ $totalWhole }}<span class="text-2xl">.{{ $totalCents }}</span></span>
                    <span x-show="! (visible && ! $store.dashPrefs.hideBalance)" x-cloak>$ ••••</span>
                </p>
                <button type="button" @click="visible = ! visible" :aria-label="visible ? 'Hide balance' : 'Show balance'" class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-[#ffffff] text-zinc-500 shadow-sm shadow-zinc-900/[0.06] transition-colors active:bg-zinc-100 dark:bg-white/10 dark:text-zinc-300 dark:shadow-none">
                    <svg x-show="visible" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M3.98 8.223A10.477 10.477 0 001.934 12C3.226 16.338 7.244 19.5 12 19.5c.993 0 1.953-.138 2.863-.395M6.228 6.228A10.45 10.45 0 0112 4.5c4.756 0 8.773 3.162 10.065 7.498a10.523 10.523 0 01-4.293 5.774M6.228 6.228L3 3m3.228 3.228l3.65 3.65m7.894 7.894L21 21m-3.228-3.228l-3.65-3.65m0 0a3 3 0 10-4.243-4.243m4.242 4.242L9.88 9.88"/>
                    </svg>
                    <svg x-show="!visible" x-cloak class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M2.036 12.322a1.012 1.012 0 010-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.638 0-8.573-3.007-9.963-7.178z"/>
                        <path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/>
                    </svg>
                </button>
            </div>
            <p class="mt-2 text-xs text-white">{{ $walletRows->count() }} {{ \Illuminate\Support\Str::plural('wallet', $walletRows->count()) }} · USD equivalent</p>

            {{-- The sheet lives on <body>: the hero's own stacking context would
                 cap its z-index under the fixed header. --}}
            <template x-teleport="body">
                <div class="lg:hidden">
                    <div
                        x-show="open"
                        x-transition.opacity.duration.200ms
                        @click="open = false"
                        style="display:none;"
                        class="fixed inset-0 z-[80] bg-zinc-900/45"
                        aria-hidden="true"
                    ></div>
                    <div
                        x-show="open"
                        {{-- The handle at the top has always looked draggable. Now it is. --}}
                        x-init="window.rshopSheetSwipe?.($el, () => open = false)"
                        x-transition:enter="transition-transform duration-300 ease-[cubic-bezier(0.22,1,0.36,1)]"
                        x-transition:enter-start="translate-y-full"
                        x-transition:enter-end="translate-y-0"
                        x-transition:leave="transition-transform duration-200 ease-in"
                        x-transition:leave-start="translate-y-0"
                        x-transition:leave-end="translate-y-full"
                        style="display:none; padding-bottom: max(1.25rem, env(safe-area-inset-bottom));"
                        class="wallet-sheet fixed inset-x-0 bottom-0 z-[90] max-h-[88dvh] overflow-y-auto rounded-t-[24px] bg-[#ffffff] px-5 pt-3 text-left shadow-2xl shadow-zinc-900/30 dark:bg-[#1d3252]"
                        role="dialog"
                        aria-modal="true"
                        aria-label="Wallet balances"
                    >
                        <div class="flex justify-center">
                            <span class="h-1.5 w-12 rounded-full bg-zinc-300 dark:bg-white/25" aria-hidden="true"></span>
                        </div>

                        {{-- The ring --}}
                        <div class="relative mx-auto mt-5 h-52 w-52">
                            <svg viewBox="0 0 120 120" class="h-full w-full -rotate-90" aria-hidden="true">
                                <circle cx="60" cy="60" r="48" fill="none" stroke-width="12" class="stroke-zinc-100 dark:stroke-white/10"/>
                                @foreach ($ringSegments as $seg)
                                    <circle cx="60" cy="60" r="48" fill="none" stroke-width="12" stroke="{{ $seg['color'] }}" stroke-dasharray="{{ round($seg['len'], 3) }} {{ round($ringLength, 3) }}" stroke-dashoffset="{{ round(-$seg['offset'], 3) }}"/>
                                @endforeach
                            </svg>
                            <div class="absolute inset-0 flex flex-col items-center justify-center">
                                <p class="text-[11px] font-semibold uppercase tracking-[0.14em] text-zinc-500 dark:text-zinc-400">Total balance</p>
                                <p class="mt-1 text-2xl font-bold tabular-nums text-zinc-900 dark:text-white">
                                    <span x-show="visible && ! $store.dashPrefs.hideBalance">${{ number_format($walletTotalUsd, 2) }}</span>
                                    <span x-show="! (visible && ! $store.dashPrefs.hideBalance)" x-cloak>$ ••••</span>
                                </p>
                            </div>
                        </div>

                        {{-- Breakdown: amounts, or each wallet's share of the total --}}
                        <div class="mt-6 flex items-center justify-between gap-3">
                            <h3 class="text-lg font-bold text-zinc-900 dark:text-white">Balance breakdown</h3>
                            {{-- Segmented $ / % switch. One white thumb slides between the
                                 two equal cells (translate, not a re-coloured button), so
                                 the choice glides across instead of snapping. --}}
                            <div class="relative grid grid-cols-2 rounded-[10px] bg-[#f4f6f5] p-1 text-sm font-semibold dark:bg-white/5" role="group" aria-label="Show as">
                                <span aria-hidden="true" :class="mode === 'share' ? 'translate-x-full' : 'translate-x-0'" class="wallet-mode-thumb pointer-events-none absolute inset-y-1 left-1 w-[calc(50%-0.25rem)] rounded-[8px] bg-[#ffffff] shadow-sm transition-transform duration-300 ease-[cubic-bezier(0.22,1,0.36,1)] motion-reduce:transition-none dark:bg-white/15"></span>
                                <button type="button" @click="mode = 'amount'" :class="mode === 'amount' ? 'text-zinc-900 dark:text-white' : 'text-zinc-500'" :aria-pressed="(mode === 'amount').toString()" class="relative z-10 rounded-[8px] px-3 py-1 transition-colors" aria-label="Amounts">$</button>
                                <button type="button" @click="mode = 'share'" :class="mode === 'share' ? 'text-zinc-900 dark:text-white' : 'text-zinc-500'" :aria-pressed="(mode === 'share').toString()" class="relative z-10 rounded-[8px] px-3 py-1 transition-colors" aria-label="Shares">%</button>
                            </div>
                        </div>

                        <ul class="mt-3 flex flex-col gap-1">
                            @foreach ($walletRows as $i => $w)
                                <li class="flex items-center gap-3 rounded-[12px] py-2.5">
                                    <span class="flex h-11 w-11 shrink-0 items-center justify-center overflow-hidden rounded-[12px]" style="background-color: {{ $w['ring'] }}1a;">
                                        <img src="{{ $w['icon'] }}" alt="" class="h-7 w-7 rounded-full object-cover" loading="lazy">
                                    </span>
                                    <span class="min-w-0 flex-1">
                                        <span class="block truncate text-sm font-bold text-zinc-900 dark:text-white">{{ $w['label'] }}</span>
                                        <span class="block text-xs text-zinc-500 dark:text-zinc-400">{{ $w['code'] }}</span>
                                    </span>
                                    <span class="shrink-0 text-right">
                                        <span x-show="mode === 'amount'" class="block text-sm font-bold tabular-nums text-zinc-900 dark:text-white">
                                            <span x-show="visible && ! $store.dashPrefs.hideBalance">{{ $w['formatted'] }}</span>
                                            <span x-show="! (visible && ! $store.dashPrefs.hideBalance)" x-cloak>••••</span>
                                        </span>
                                        <span x-show="mode === 'share'" x-cloak class="block text-sm font-bold tabular-nums text-zinc-900 dark:text-white">{{ $walletShares[$i] }}%</span>
                                        @if (strtoupper($w['code']) !== 'USD' && $w['usd'] !== null)
                                            <span x-show="mode === 'amount' && visible && ! $store.dashPrefs.hideBalance" class="block text-[11px] text-zinc-500 dark:text-zinc-400">≈ ${{ number_format($w['usd'], 2) }}</span>
                                        @endif
                                    </span>
                                    {{-- Top Up for this wallet: the fund-wallet component, pre-set to its currency. --}}
                                    <livewire:dashboard.fund-wallet :currency="$w['code']" variant="sheet" wire:key="fund-sheet-{{ $w['code'] }}" />
                                </li>
                            @endforeach
                        </ul>
                    </div>
                </div>
            </template>
        </div>
    </x-slot>

    {{-- Main content — lazy component: renders the skeleton placeholder instantly,
         then boots and swaps in the real overview cards. --}}
    <livewire:dashboard.overview lazy />

</x-layouts.dashboard>

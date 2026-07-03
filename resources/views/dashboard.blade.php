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
    $allWallets = $user->wallets()->where('is_active', true)->where('currency', '!=', 'RCOIN')->get()
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
        {{-- Swipeable wallet carousel — one card per funded currency. Each card
             carries its own balance + in-card Top Up button; swipe between wallets. --}}
        {{-- active wallet index lives in $store.wallet so the desktop card and this
             mobile carousel always show the same wallet (synced live, no reload). --}}
        <div x-data="{
                visible: true,
                syncActive() { this.$store.wallet.active = Math.round(this.$refs.track.scrollLeft / Math.max(1, this.$refs.track.clientWidth)); },
                goTo(i) { this.$store.wallet.active = i; },
            }"
            x-effect="$store.wallet.active; $nextTick(() => { const t = $refs.track; if (! t || ! t.clientWidth) return; const target = $store.wallet.active * t.clientWidth; if (Math.abs(t.scrollLeft - target) > 4) t.scrollTo({ left: target, behavior: 'smooth' }); })"
            @resize.window.debounce.200ms="$nextTick(() => { const t = $refs.track; if (t && t.clientWidth) t.scrollTo({ left: $store.wallet.active * t.clientWidth }); })"
        >
            {{-- Swipe track — native scroll-snap, one card per wallet.
                 -mx-5 cancels the hero's px-5 so each slide is a FULL viewport width;
                 the slide's own px-5 keeps the card visually inset. One swipe = one full-width card. --}}
            <div
                x-ref="track"
                @scroll.debounce.60ms="syncActive()"
                class="-mx-5 flex snap-x snap-mandatory overflow-x-auto [scrollbar-width:none] [&::-webkit-scrollbar]:hidden"
            >
                @foreach ($walletsPayload as $w)
                    <div class="w-full shrink-0 snap-center px-5">
                        {{-- Card bg adopts this wallet's brand colour, so swiping reveals each wallet's own colour (matches the desktop card). --}}
                        <div class="rounded-2xl bg-blue-800 p-5 text-white ring-1 ring-white/10">
                            <div class="flex items-start justify-between gap-3">
                                <p class="text-sm font-medium text-blue-100">Wallet Balance</p>
                                <button type="button" @click="visible = ! visible" class="-mr-1 -mt-1 shrink-0 rounded-md p-1 text-blue-200 transition-colors hover:bg-white/10 hover:text-white" :aria-label="visible ? 'Hide balance' : 'Show balance'">
                                    <svg x-show="visible" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75" aria-hidden="true">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M3.98 8.223A10.477 10.477 0 001.934 12C3.226 16.338 7.244 19.5 12 19.5c.993 0 1.953-.138 2.863-.395M6.228 6.228A10.45 10.45 0 0112 4.5c4.756 0 8.773 3.162 10.065 7.498a10.523 10.523 0 01-4.293 5.774M6.228 6.228L3 3m3.228 3.228l3.65 3.65m7.244 7.244L19.5 19.5m-2.876-2.876L13.875 13.875M9.878 9.878a3 3 0 105.249 5.249"/>
                                    </svg>
                                    <svg x-show="!visible" x-cloak class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75" aria-hidden="true">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M2.036 12.322a1.012 1.012 0 010-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.638 0-8.573-3.007-9.963-7.178z"/>
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/>
                                    </svg>
                                </button>
                            </div>
                            <div class="mt-2 flex items-center justify-between gap-3">
                                <div class="min-w-0">
                                    <p class="truncate text-3xl font-bold tracking-tight sm:text-4xl">
                                        <span x-show="visible">{{ $w['formatted'] }}</span>
                                        <span x-show="!visible" x-cloak>{{ $w['symbol'] }} ••••</span>
                                    </p>
                                    <p class="mt-1 text-xs text-blue-100">{{ $w['code'] }} · {{ $w['label'] }}</p>
                                </div>
                                {{-- In-card Top Up — fund-wallet Volt component, pre-set to this wallet's currency. --}}
                                <livewire:dashboard.fund-wallet :currency="$w['code']" variant="compact" wire:key="fund-mobile-{{ $w['code'] }}" />
                            </div>
                        </div>
                    </div>
                @endforeach
            </div>

            {{-- Dots — swipe position indicator (multiple wallets only). --}}
            @if ($walletsPayload->count() > 1)
                <div class="mt-3 flex items-center justify-center gap-1.5">
                    @foreach ($walletsPayload as $i => $w)
                        <button
                            type="button"
                            @click="goTo({{ $i }})"
                            :class="$store.wallet.active === {{ $i }} ? 'w-5 bg-white' : 'w-1.5 bg-white/40'"
                            class="theme-static h-1.5 rounded-full transition-all duration-200"
                            aria-label="Wallet {{ $i + 1 }}"
                        ></button>
                    @endforeach
                </div>
            @endif
        </div>

        {{-- (Floating mini-chrome removed — the scroll-pinned wallet-balance pill
             + Top Up button were duplicated/overlapped the layout's floating
             hamburger/cart/notification bar. Balance + Top Up live on the hero
             wallet card; the layout's floating chrome handles cart/notifications.) --}}
    </x-slot>

    {{-- Main content — lazy component: renders the skeleton placeholder instantly,
         then boots and swaps in the real overview cards. --}}
    <livewire:dashboard.overview lazy />

</x-layouts.dashboard>

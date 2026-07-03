{{--
    My Wallets — /dashboard/wallet.
    Shows every wallet card the customer holds (one per currency), the count,
    and an in-card Top Up on each. Wallets are auto-created per currency, so
    this list grows as the customer funds new currencies.
--}}
@php
    use App\Domain\Shared\Enums\Currency;

    $user = auth()->user();
    // Rcoin is rewards points, not a spendable wallet - it lives on its own
    // rewards section and never renders as a wallet card here.
    $allWallets = $user->wallets()->where('is_active', true)->where('currency', '!=', 'RCOIN')->get();

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

    $walletsPayload = $allWallets->map(function ($w) use ($symbolFor, $iconFor) {
        $code = $w->currency?->value ?? 'USD';
        $iconFile = $iconFor($code);

        return [
            'code'      => $code,
            'label'     => Currency::tryFrom((string) $code)?->label() ?? $code,
            'formatted' => $symbolFor($code) . number_format((float) $w->balance, 2),
            'icon'      => $iconFile ? asset('assets/' . rawurlencode($iconFile)) : \App\Models\Product::flagUrl(match (strtoupper((string) $code)) { 'USD' => 'US', 'GBP' => 'GB', 'EUR' => 'EU', 'KES' => 'KE', 'ZAR' => 'ZA', 'UGX' => 'UG', 'TZS' => 'TZ', 'RWF' => 'RW', 'ZMW' => 'ZM', 'MWK' => 'MW', 'ETB' => 'ET', 'EGP' => 'EG', 'MAD' => 'MA', default => '' }),
        ];
    })->values();

    $walletCount = $walletsPayload->count();
@endphp

<x-layouts.dashboard>
    <div class="flex w-full flex-col gap-6">

        {{-- Heading (desktop only — mobile uses the layout's slim top bar) --}}
        <div class="hidden lg:block">
            <h1 class="text-xl font-bold tracking-tight text-zinc-900 sm:text-3xl">My Wallets</h1>
            <p class="mt-1 text-sm text-zinc-600">Your balances across every currency you hold.</p>
        </div>

        {{-- features.wallet_funding_enabled kill-switch banner. Existing
             balances stay spendable; only new top-ups are paused. --}}
        <x-paused-banner
            flag="wallet_funding"
            title="Wallet top-ups are temporarily paused"
            message="You can still spend your existing balance. New top-ups will be back online shortly."
        />

        {{-- Wallet-created success now pops as the global flash-toast pill
             (x-flash-toast in the dashboard layout) instead of an inline banner. --}}

        {{-- Summary strip - count chip + add-wallet CTA. Glass surface with
             a subtle ring + tinted shadow to lift it off the page bg without
             feeling heavy. --}}
        <div class="flex flex-wrap items-center justify-between gap-4 rounded-[12px] bg-[#eff6ff] p-5 border border-zinc-200 shadow-md shadow-zinc-900/[0.06] dark:border-zinc-700 dark:shadow-none">
            <div class="flex min-w-0 items-center gap-4">
                <span class="relative flex h-12 w-12 shrink-0 items-center justify-center overflow-hidden rounded-[12px] bg-gradient-to-br from-blue-500 to-blue-700 shadow-sm shadow-blue-600/30">
                    <img src="{{ asset('assets/' . rawurlencode('Wallet.svg')) }}" alt="" class="h-6 w-6 brightness-0 invert" loading="lazy">
                </span>
                <div class="min-w-0">
                    <p class="text-2xl font-bold tracking-tight text-zinc-900 dark:text-white">{{ $walletCount }}</p>
                    <p class="text-sm text-zinc-600 dark:text-zinc-300">{{ \Illuminate\Support\Str::plural('wallet card', $walletCount) }} active</p>
                </div>
            </div>
            @if ($walletCount > 0)
                <livewire:dashboard.create-wallet wire:key="create-wallet-header" />
            @endif
        </div>

        {{-- Wallet cards --}}
        @if ($walletCount > 0)
            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3">
                @foreach ($walletsPayload as $w)
                    {{-- Flat solid blue card: currency chip, large balance, then a
                         hairline divider before the compact Top Up component.
                         rounded-[12px] keeps it consistent with the rest of the
                         dashboard. --}}
                    <div
                        class="wallet-glass group relative flex flex-col gap-4 overflow-hidden rounded-2xl p-5 text-blue-950 transition-transform duration-200 hover:-translate-y-0.5 dark:text-white"
                    >
                        <div class="relative flex items-start justify-between gap-3">
                            <div class="min-w-0">
                                <div class="inline-flex items-center gap-1.5 rounded-full bg-blue-600/10 px-2.5 py-1 text-[10px] font-bold uppercase tracking-wider text-blue-700 ring-1 ring-blue-600/15 dark:bg-white/10 dark:text-blue-100 dark:ring-white/15">
                                    <span class="h-1.5 w-1.5 rounded-full bg-emerald-500 shadow-[0_0_6px_rgba(52,211,153,0.9)] dark:bg-emerald-400"></span>
                                    Wallet Balance
                                </div>
                                <p class="mt-3 truncate text-3xl font-extrabold tracking-tight tabular-nums">{{ $w['formatted'] }}</p>
                                <p class="mt-1 text-xs font-medium text-blue-700/80 dark:text-blue-100/90">{{ $w['code'] }} &middot; {{ $w['label'] }}</p>
                            </div>
                            <span class="flex h-12 w-12 shrink-0 items-center justify-center overflow-hidden rounded-[12px] bg-blue-600/10 ring-1 ring-blue-600/15 dark:bg-white/15 dark:ring-white/20">
                                @if ($w['icon'])
                                    <img src="{{ $w['icon'] }}" alt="" class="h-7 w-7 object-contain" loading="lazy">
                                @else
                                    <img src="{{ asset('assets/' . rawurlencode('Wallet.svg')) }}" alt="" class="h-6 w-6 brightness-0 dark:invert" loading="lazy">
                                @endif
                            </span>
                        </div>

                        {{-- Hairline divider so the Top Up button feels like its
                             own action surface inside the card. --}}
                        <div class="relative h-px w-full bg-gradient-to-r from-transparent via-blue-900/10 to-transparent dark:via-white/15" aria-hidden="true"></div>

                        {{-- In-card Top Up - fund-wallet Volt component, pre-set to this currency. --}}
                        <div class="relative">
                            <livewire:dashboard.fund-wallet :currency="$w['code']" variant="compact" wire:key="wallet-fund-{{ $w['code'] }}" />
                        </div>
                    </div>
                @endforeach
            </div>
        @else
            {{-- Empty state - modern centered card with a soft tinted icon halo. --}}
            <div class="relative overflow-hidden rounded-[12px] bg-[#eff6ff] px-6 py-16 text-center border border-zinc-200 shadow-md shadow-zinc-900/[0.06] dark:border-zinc-700 dark:shadow-none">
                {{-- Soft background glow behind the icon. --}}
                <div class="pointer-events-none absolute left-1/2 top-8 h-32 w-32 -translate-x-1/2 rounded-full bg-blue-100/60 blur-2xl dark:bg-blue-500/10" aria-hidden="true"></div>
                <span class="relative mx-auto flex h-16 w-16 items-center justify-center rounded-[12px] bg-gradient-to-br from-blue-500 to-blue-700 shadow-lg shadow-blue-600/30 ring-4 ring-white dark:ring-[#0c1a36]">
                    <img src="{{ asset('assets/' . rawurlencode('Wallet.svg')) }}" alt="" class="h-7 w-7 brightness-0 invert" loading="lazy">
                </span>
                <p class="relative mt-5 text-lg font-bold text-zinc-900 dark:text-white">No wallet yet</p>
                <p class="relative mx-auto mt-1.5 max-w-sm text-sm leading-relaxed text-zinc-600 dark:text-zinc-300">Your wallet is normally set up automatically. If it did not, create one here to get started.</p>
                <div class="relative mt-6 flex justify-center">
                    <livewire:dashboard.create-wallet wire:key="create-wallet-empty" />
                </div>
            </div>
        @endif

    </div>
</x-layouts.dashboard>

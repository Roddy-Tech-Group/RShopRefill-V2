{{--
    Customer Rewards page - /dashboard/rewards. The loyalty currency is "Rcoin".

    Live state:
      - Balance + history → wallet_transactions where currency = RCOIN
      - Cashback + referral credits → RewardEngine (dispatched by ProcessOrderRewardsJob)
      - Convert-to-wallet POST → RcoinConvertController::toWallet
      - Withdraw-to-cash POST → RcoinWithdrawalController::store
      - All thresholds, percentages, caps → Settings table, editable at /admin/content/rewards
--}}
@php
    use App\Models\Product;
    use App\Models\Setting;
    use App\Domain\Wallet\Services\WalletService;
    use App\Domain\Rewards\Services\RewardEngine;
    use App\Domain\Shared\Enums\Currency;
    
    $user = auth()->user();
    
    $walletService = app(WalletService::class);
    $rewardEngine = app(RewardEngine::class);
    
    $rcoinWallet = $walletService->getOrCreateWallet($user, Currency::RCOIN);
    $rcoinBalance = (int) $rcoinWallet->balance;
    $rcoinEarned = (int) $user->walletTransactions()
        ->where('currency', Currency::RCOIN->value)
        ->whereIn('type', [\App\Domain\Shared\Enums\WalletTransactionType::Credit->value])
        ->sum('amount');
        
    $rcoinPerUsd = 1 / Setting::rcoinUsdRate();
    $convertMinUsd = (float) Setting::get('wallet_conversion_min_usd', 2.00);
    $convertEnabled = (bool) Setting::get('wallet_conversion_enabled', true);
    // Minimum Rcoin needed to clear the USD floor - used to gate the form.
    $convertThreshold = $rcoinPerUsd > 0 ? (int) ceil($convertMinUsd * $rcoinPerUsd) : PHP_INT_MAX;
    $withdrawThreshold = (int) Setting::get('withdrawal_min_rcoin', 2000);

    // Per-user earnings multiplier - admin can give influencers / power users
    // a higher number from the admin customer page. 1.00 = standard.
    $rcoinMultiplier = (float) ($user->rcoin_multiplier ?? 1.00);
    $hasMultiplier = abs($rcoinMultiplier - 1.0) > 0.005;
    
    $cashValue = $rewardEngine->rcoinToUsd($rcoinBalance);
    $coin = asset('assets/favicon.ico');

    // ── Loyalty tier ladder ──
    $tierLadder = [
        ['name' => 'Bronze',   'min' => 0,    'requires' => null],
        ['name' => 'Silver',   'min' => 1000, 'requires' => null],
        ['name' => 'Gold',     'min' => 1500, 'requires' => null],
        ['name' => 'Platinum', 'min' => 3000, 'requires' => 'Email verified'],
        ['name' => 'Diamond',  'min' => 6000, 'requires' => 'ID verified'],
    ];
    $currentTier = $tierLadder[0];
    $nextTier    = null;
    foreach ($tierLadder as $idx => $tier) {
        if ($rcoinBalance >= $tier['min']) {
            $currentTier = $tier;
            $nextTier    = $tierLadder[$idx + 1] ?? null;
        }
    }
    $rcoinToNext  = $nextTier ? max(0, $nextTier['min'] - $rcoinBalance) : 0;
    $tierProgress = $nextTier
        ? min(100, round((($rcoinBalance - $currentTier['min']) / max(1, $nextTier['min'] - $currentTier['min'])) * 100, 1))
        : 100;

    // ── Convert / withdraw availability ──
    // KYC hard-gate is opt-in via the compliance setting; when ON, even a
    // sufficient balance can't unlock the withdraw button until the customer
    // is KYC-verified. The dashboard surfaces the reason inline (see below).
    $requireKycForWithdrawal = (bool) Setting::get('require_kyc_for_withdrawal', false);
    $kycVerified = strtolower((string) ($user->kyc_status ?? '')) === 'verified';
    $kycBlocksWithdrawal = $requireKycForWithdrawal && ! $kycVerified;
    $canConvert  = $convertEnabled && $rcoinBalance >= $convertThreshold;
    $canWithdraw = $rcoinBalance >= $withdrawThreshold && ! $kycBlocksWithdrawal;
    $convertProgress  = min(100, round(($rcoinBalance / max(1, $convertThreshold)) * 100, 1));
    $withdrawProgress = min(100, round(($rcoinBalance / max(1, $withdrawThreshold)) * 100, 1));
    // Convertible amount in USD given the current balance - what the user
    // would receive in their wallet if they converted the maximum allowed.
    $maxConvertibleUsd = $rcoinPerUsd > 0 ? round($rcoinBalance / $rcoinPerUsd, 2) : 0.0;

    // ── Rcoin history ──
    $transactions = $user->walletTransactions()
        ->where('currency', Currency::RCOIN->value)
        ->latest()
        ->limit(20)
        ->get();
        
    $rcoinHistory = $transactions->map(function ($txn) {
        $isCredit = $txn->type === \App\Domain\Shared\Enums\WalletTransactionType::Credit;
        return [
            'label' => $txn->description ?? 'RCOIN Transaction',
            'date' => $txn->created_at,
            'rcoin' => $isCredit ? (int) $txn->amount : -((int) $txn->amount),
            'isCredit' => $isCredit,
        ];
    })->toArray();
@endphp

<x-layouts.dashboard>
    <div class="flex w-full flex-col gap-8">

        {{-- features.wallet_withdraw_enabled kill-switch banner. Customers
             can still earn / spend Rcoin; only cash-out withdrawals are paused. --}}
        <x-paused-banner
            flag="wallet_withdraw"
            title="Withdrawals are temporarily paused"
            message="You can still earn and spend Rcoin on the storefront. Cash-out withdrawals will be back online shortly."
        />

        {{-- ─── Rcoin balance card ─── --}}
        <section>
            <div class="hidden items-center gap-3 lg:flex">
                <h1 class="text-xl font-bold tracking-tight text-black sm:text-3xl">Your Rcoin</h1>
                @if ($hasMultiplier)
                    {{-- Power-user multiplier badge. Only renders when admin
                         has bumped the user above (or dropped below) 1×. --}}
                    <span class="inline-flex items-center gap-1.5 rounded-[12px] bg-gradient-to-r from-amber-400 to-orange-500 px-3 py-1 text-xs font-bold uppercase tracking-wider text-white shadow-sm shadow-orange-500/30">
                        <svg class="h-3 w-3" fill="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                            <path d="M12 2l2.39 7.36H22l-6.18 4.49L18.18 21 12 16.51 5.82 21l2.36-7.15L2 9.36h7.61z"/>
                        </svg>
                        {{ number_format($rcoinMultiplier, $rcoinMultiplier == (int) $rcoinMultiplier ? 0 : 2) }}× earner
                    </span>
                @endif
            </div>

            <div class="mt-4 rounded-[12px] bg-[#eff6ff] p-5 dash-shimmer border border-zinc-200 shadow-md shadow-zinc-900/[0.06] transition-colors hover:border-green-200 dark:border-zinc-700 dark:hover:border-white dark:shadow-none sm:p-6">
                <div class="flex items-start gap-4">
                    <span class="flex h-12 w-12 shrink-0 items-center justify-center rounded-[12px] bg-blue-50 shadow-sm shadow-zinc-900/10">
                        <img src="{{ $coin }}" alt="Rcoin" class="h-7 w-7 object-contain">
                    </span>

                    <div class="min-w-0 flex-1">
                        <p class="text-base font-bold text-black">RShop Rcoin</p>
                        <div class="mt-1 flex flex-wrap items-center gap-2">
                            <p class="text-3xl font-extrabold tracking-tight text-black">{{ number_format($rcoinBalance) }}</p>
                            <span class="inline-flex items-center rounded-[5px] bg-amber-500 px-2 py-0.5 text-[10px] font-bold uppercase tracking-wide text-white">{{ strtoupper($currentTier['name']) }} MEMBER</span>
                        </div>
                        <p class="mt-0.5 text-sm text-zinc-600">Worth about ${{ number_format($cashValue, 2) }}</p>
                    </div>
                </div>

                @if ($nextTier)
                    <p class="mt-5 text-sm text-zinc-600">You're {{ number_format($rcoinToNext) }} Rcoin away from {{ $nextTier['name'] }} level</p>
                    <div class="mt-2 h-2 w-full overflow-hidden rounded-full bg-zinc-200">
                        <div class="h-full rounded-full bg-blue-600 transition-all duration-500" style="width: {{ $tierProgress }}%;"></div>
                    </div>
                    <p class="mt-2 text-right text-xs text-zinc-600">{{ number_format($rcoinBalance) }} / {{ number_format($nextTier['min']) }}</p>
                @else
                    <p class="mt-5 text-sm text-zinc-600">You've reached the highest tier. Thank you for being a loyal member.</p>
                    <div class="mt-2 h-2 w-full overflow-hidden rounded-full bg-zinc-200">
                        <div class="h-full rounded-full bg-blue-600" style="width: 100%;"></div>
                    </div>
                @endif
            </div>

            <p class="mt-3 text-sm text-zinc-600">Rcoin is earned on every purchase and becomes available 48 hours after the order completes.</p>
        </section>

        {{-- ─── Earn more Rcoin: referral link ─── --}}
        <section>
            <h2 class="text-sm font-bold text-black">Earn more Rcoin</h2>

            <div class="mt-3 rounded-[12px] bg-[#eff6ff] p-5 dash-shimmer border border-zinc-200 shadow-md shadow-zinc-900/[0.06] transition-colors hover:border-green-200 dark:border-zinc-700 dark:hover:border-white dark:shadow-none sm:p-6">
                <div class="flex items-start gap-4">
                    <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-[12px] bg-blue-50 dark:bg-blue-500/15">
                        <img src="{{ asset('assets/referals.webp') }}" alt="" class="no-dark-invert h-5 w-5 object-contain" loading="lazy">
                    </span>
                    <div class="min-w-0">
                        <p class="text-base font-bold text-black">Refer friends, earn Rcoin</p>
                        <p class="mt-1 text-sm text-zinc-600">Share your link. You earn Rcoin when a friend signs up, and again every time they place an order.</p>
                    </div>
                </div>

                <div class="mt-4">
                    <livewire:referral-code />
                </div>

                {{-- Two ways referrals pay out Rcoin. --}}
                <div class="mt-3 grid grid-cols-2 gap-3 text-sm">
                    <div class="rounded-[12px] bg-blue-50 px-3 py-2.5 ring-1 ring-blue-200 dark:bg-blue-500/10 dark:ring-blue-500/20">
                        <p class="font-semibold text-zinc-900">On sign-up</p>
                        <p class="text-xs text-zinc-600">Rcoin lands when your referral creates their account.</p>
                    </div>
                    <div class="rounded-[12px] bg-emerald-50 px-3 py-2.5 ring-1 ring-emerald-200 dark:bg-emerald-500/10 dark:ring-emerald-500/20">
                        <p class="font-semibold text-zinc-900">On every order</p>
                        <p class="text-xs text-zinc-600">Keep earning Rcoin each time they buy.</p>
                    </div>
                </div>
            </div>
        </section>

        {{-- ─── Spend your Rcoin: convert to gift card / withdraw to cash ─── --}}
        <section>
            <h2 class="text-sm font-bold text-black">Spend your Rcoin</h2>
            <div class="mt-3 grid grid-cols-1 gap-4 sm:grid-cols-2">

                {{-- Convert to wallet - instant Rcoin → USD wallet swap.
                     Posts to RcoinConvertController. Min USD floor lives in
                     `wallet_conversion_min_usd` setting (default $2). --}}
                <div class="flex flex-col rounded-[12px] bg-[#eff6ff] p-5 dash-shimmer border border-zinc-200 shadow-md shadow-zinc-900/[0.06] transition-colors hover:border-green-200 dark:border-zinc-700 dark:hover:border-white dark:shadow-none">
                    <span class="flex h-10 w-10 items-center justify-center rounded-[12px] bg-blue-50 dark:bg-blue-500/15">
                        <img src="{{ asset('assets/' . rawurlencode('Wallet.svg')) }}" alt="" class="no-dark-invert h-5 w-5 dark:invert dark:brightness-200" loading="lazy">
                    </span>
                    <p class="mt-3 text-base font-bold text-black">Convert to wallet balance</p>
                    <p class="mt-1 text-sm text-zinc-600">Swap your Rcoin for instant USD wallet credit. Spend it on any product - gift cards, eSIMs, top-ups, flights.</p>

                    <div class="mt-4 rounded-[12px] bg-blue-50 px-3 py-2.5 text-sm ring-1 ring-blue-200 dark:bg-blue-500/10 dark:ring-blue-500/20">
                        <div class="flex items-center justify-between">
                            <span class="text-zinc-600">Available</span>
                            <span class="inline-flex items-center gap-1 font-bold text-zinc-900">
                                <img src="{{ $coin }}" alt="" class="h-4 w-4">{{ number_format($rcoinBalance) }}
                            </span>
                        </div>
                        <div class="mt-1 flex items-center justify-between">
                            <span class="text-zinc-600">Convertible value</span>
                            <span class="font-bold text-zinc-900">${{ number_format($maxConvertibleUsd, 2) }}</span>
                        </div>
                    </div>

                    {{-- Status flash pops as the global flash-toast pill (dashboard layout). --}}
                    @error('convert_amount')
                        <p class="mt-3 rounded-[12px] bg-red-50 px-3 py-2 text-xs font-semibold text-red-700">{{ $message }}</p>
                    @enderror

                    @if ($canConvert)
                        <form method="POST" action="{{ route('dashboard.rewards.convert-to-wallet') }}" class="mt-4 flex flex-col gap-2.5">
                            @csrf
                            <input
                                type="number"
                                name="convert_amount"
                                min="{{ $convertThreshold }}"
                                max="{{ $rcoinBalance }}"
                                step="1"
                                value="{{ $convertThreshold }}"
                                placeholder="Rcoin to convert"
                                class="w-full rounded-[12px] border border-zinc-200 bg-white px-3 py-2.5 text-sm text-zinc-900 outline-none transition-colors focus:border-blue-500 focus:ring-2 focus:ring-blue-500/15"
                            >
                            <p class="text-[11px] text-zinc-500">Minimum {{ number_format($convertThreshold) }} Rcoin (≈ ${{ number_format($convertMinUsd, 2) }}). Credit lands in your USD wallet instantly.</p>
                            <button type="submit" class="mt-1 w-full rounded-[12px] bg-blue-600 px-4 py-2.5 text-sm font-semibold text-white transition-colors hover:bg-blue-700">
                                Convert to USD wallet
                            </button>
                        </form>
                    @else
                        <div class="mt-auto pt-4">
                            <button type="button" disabled class="w-full cursor-not-allowed rounded-[12px] bg-zinc-200 px-4 py-2.5 text-sm font-semibold text-zinc-500">
                                {{ number_format(max(0, $convertThreshold - $rcoinBalance)) }} more Rcoin to unlock (${{ number_format($convertMinUsd, 2) }} minimum)
                            </button>
                            <div class="mt-2 h-1.5 w-full overflow-hidden rounded-full bg-zinc-200">
                                <div class="h-full rounded-full bg-blue-600" style="width: {{ $convertProgress }}%;"></div>
                            </div>
                        </div>
                    @endif
                </div>

                {{-- Withdraw to cash --}}
                <div
                    x-data="{ amount: '', method: 'wallet' }"
                    class="flex flex-col rounded-[12px] bg-[#eff6ff] p-5 dash-shimmer border border-zinc-200 shadow-md shadow-zinc-900/[0.06] transition-colors hover:border-green-200 dark:border-zinc-700 dark:hover:border-white dark:shadow-none"
                >
                    <span class="flex h-10 w-10 items-center justify-center rounded-[12px] bg-emerald-50 dark:bg-emerald-500/15">
                        <img src="{{ asset('assets/' . rawurlencode('wallet 2.svg')) }}" alt="" class="no-dark-invert h-5 w-5 dark:invert dark:brightness-200" loading="lazy">
                    </span>
                    <p class="mt-3 text-base font-bold text-black">Withdraw to cash</p>
                    <p class="mt-1 text-sm text-zinc-600">Cash out your Rcoin balance once you reach the minimum.</p>

                    <div class="mt-4 rounded-[12px] bg-emerald-50 px-3 py-2.5 text-sm ring-1 ring-emerald-200 dark:bg-emerald-500/10 dark:ring-emerald-500/20">
                        <div class="flex items-center justify-between">
                            <span class="text-zinc-600">Available</span>
                            <span class="inline-flex items-center gap-1 font-bold text-zinc-900">
                                <img src="{{ $coin }}" alt="" class="h-4 w-4">{{ number_format($rcoinBalance) }}
                            </span>
                        </div>
                        <div class="mt-1 flex items-center justify-between">
                            <span class="text-zinc-600">Cash value</span>
                            <span class="font-bold text-zinc-900">${{ number_format($cashValue, 2) }}</span>
                        </div>
                    </div>

                    @if ($canWithdraw)
                        {{-- Withdrawal request form. Posts to RcoinWithdrawalController
                             which validates settings, debits the user's Rcoin wallet, and
                             creates a `pending` RcoinWithdrawal row for admin review. --}}
                        {{-- Status flash pops as the global flash-toast pill (dashboard layout). --}}
                        @error('withdraw_amount')
                            <p class="mt-3 rounded-[12px] bg-red-50 px-3 py-2 text-xs font-semibold text-red-700">{{ $message }}</p>
                        @enderror
                        <form method="POST" action="{{ route('dashboard.rewards.withdraw') }}" class="mt-4 flex flex-col gap-2.5">
                            @csrf
                            <input
                                type="number"
                                name="withdraw_amount"
                                x-model="amount"
                                min="{{ $withdrawThreshold }}"
                                max="{{ $rcoinBalance }}"
                                placeholder="Rcoin to withdraw"
                                class="w-full rounded-[12px] border border-zinc-200 bg-white px-3 py-2.5 text-sm text-zinc-900 outline-none transition-colors focus:border-blue-500 focus:ring-2 focus:ring-blue-500/15"
                            >
                            {{-- Brand dropdown (custom Alpine listbox, not the
                                 native select): same recipe as the admin and
                                 storefront pickers. A hidden input carries the
                                 chosen value into the POST. --}}
                            <div
                                x-data="{ openMethod: false, methodLabels: { wallet: 'RShop wallet', bank: 'Bank transfer', mobile_money: 'Mobile money' } }"
                                @click.outside="openMethod = false"
                                @keydown.escape.window="openMethod = false"
                                class="relative"
                            >
                                <input type="hidden" name="withdraw_method" :value="method">
                                <button
                                    type="button"
                                    @click="openMethod = ! openMethod"
                                    :aria-expanded="openMethod.toString()"
                                    aria-haspopup="listbox"
                                    :class="openMethod ? 'border-blue-500 ring-2 ring-blue-500/15' : 'border-zinc-200 hover:border-zinc-400'"
                                    class="flex w-full items-center justify-between gap-2 rounded-[12px] border bg-white py-2.5 pl-3 pr-3 text-sm font-medium text-zinc-900 outline-none transition-colors"
                                >
                                    <span x-text="methodLabels[method] ?? 'Choose method'">RShop wallet</span>
                                    <svg class="h-4 w-4 shrink-0 text-zinc-500 transition-transform duration-150" :class="openMethod && 'rotate-180'" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7"/>
                                    </svg>
                                </button>
                                <div
                                    x-show="openMethod"
                                    x-transition:enter="transition ease-out duration-150"
                                    x-transition:enter-start="opacity-0 -translate-y-1"
                                    x-transition:enter-end="opacity-100 translate-y-0"
                                    x-transition:leave="transition ease-in duration-100"
                                    x-transition:leave-start="opacity-100 translate-y-0"
                                    x-transition:leave-end="opacity-0 -translate-y-1"
                                    style="display:none;"
                                    class="absolute left-0 right-0 top-full z-20 mt-2 overflow-hidden rounded-[12px] bg-[#eff6ff] p-1 shadow-xl shadow-zinc-900/10 ring-1 ring-zinc-200"
                                    role="listbox"
                                >
                                    <template x-for="(label, value) in methodLabels" :key="value">
                                        <button
                                            type="button"
                                            role="option"
                                            :aria-selected="(method === value).toString()"
                                            @click="method = value; openMethod = false"
                                            :class="method === value ? 'bg-blue-50 text-blue-700' : 'text-zinc-700 hover:bg-zinc-50'"
                                            class="flex w-full items-center justify-between rounded-[12px] px-3 py-2 text-left text-sm font-medium transition-colors"
                                        >
                                            <span x-text="label"></span>
                                            <svg x-show="method === value" class="h-4 w-4 shrink-0 text-blue-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5" aria-hidden="true">
                                                <path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5"/>
                                            </svg>
                                        </button>
                                    </template>
                                </div>
                            </div>
                            <button type="submit" class="mt-1 w-full rounded-[12px] bg-emerald-600 px-4 py-2.5 text-sm font-semibold text-white transition-colors hover:bg-emerald-700">
                                Request withdrawal
                            </button>
                        </form>
                    @else
                        <div class="mt-auto pt-4">
                            @if ($kycBlocksWithdrawal)
                                <a href="{{ route('dashboard.kyc') }}" wire:navigate class="block w-full rounded-[12px] bg-amber-50 px-4 py-2.5 text-center text-sm font-semibold text-amber-700 ring-1 ring-amber-200 transition-colors hover:bg-amber-100">
                                    Verify your identity to unlock withdrawals
                                </a>
                                <p class="mt-2 text-center text-[11px] text-zinc-500">KYC is required for withdrawals.</p>
                            @else
                                <button type="button" disabled class="w-full cursor-not-allowed rounded-[12px] bg-zinc-200 px-4 py-2.5 text-sm font-semibold text-zinc-500">
                                    {{ number_format($withdrawThreshold - $rcoinBalance) }} more Rcoin to unlock
                                </button>
                                <div class="mt-2 h-1.5 w-full overflow-hidden rounded-full bg-zinc-200">
                                    <div class="h-full rounded-full bg-emerald-600" style="width: {{ $withdrawProgress }}%;"></div>
                                </div>
                            @endif
                        </div>
                    @endif
                </div>
            </div>
        </section>

        {{-- ─── Tier ladder ─── --}}
        <section>
            <h2 class="mb-3 text-sm font-bold text-black">Membership tiers</h2>
            @php
                // Solid-colour medal palettes per tier (no gradients - project rule).
                // rim = outer disc, face = inner disc, ribbon = hanging tails, star = emblem.
                $medals = [
                    'Bronze'   => ['rim' => '#8a5a2b', 'face' => '#c47f3e', 'ribbon' => '#a3672f', 'star' => '#fdf1e0'],
                    'Silver'   => ['rim' => '#7f8794', 'face' => '#c3c9d3', 'ribbon' => '#99a0ad', 'star' => '#ffffff'],
                    'Gold'     => ['rim' => '#b07d12', 'face' => '#e6b03a', 'ribbon' => '#c99a26', 'star' => '#fffaef'],
                    'Platinum' => ['rim' => '#5f6b7e', 'face' => '#9fadc4', 'ribbon' => '#8390a6', 'star' => '#ffffff'],
                    'Diamond'  => ['rim' => '#0e7490', 'face' => '#3bbdf4', 'ribbon' => '#0a90b6', 'star' => '#ffffff'],
                ];
            @endphp
            <div class="grid grid-cols-2 gap-2 sm:grid-cols-5">
                @foreach ($tierLadder as $tier)
                    @php
                        $reached = $rcoinBalance >= $tier['min'];
                        $isCurrent = $tier['name'] === $currentTier['name'];
                        $m = $medals[$tier['name']] ?? $medals['Bronze'];
                    @endphp
                    <div @class([
                        'rounded-full p-2 text-center ring-1 transition-colors hover:ring-blue-300 dark:hover:ring-blue-400/50',
                        'bg-blue-600 text-white ring-blue-600' => $isCurrent,
                        'bg-white text-zinc-900 ring-zinc-100' => ! $isCurrent && $reached,
                        'bg-white text-zinc-400 ring-zinc-100' => ! $reached,
                    ])>
                        {{-- Tier medal - reduced to a compact 36×32 medallion so
                             the whole tile reads as a chip instead of a card. --}}
                        <svg viewBox="0 0 48 56" class="mx-auto mb-1 h-7 w-6 {{ $reached ? '' : 'opacity-40' }}" aria-hidden="true">
                            <path d="M15 24 L9 54 L19 46 Z" fill="{{ $m['ribbon'] }}"/>
                            <path d="M33 24 L39 54 L29 46 Z" fill="{{ $m['ribbon'] }}"/>
                            <circle cx="24" cy="20" r="19" fill="{{ $m['rim'] }}"/>
                            <circle cx="24" cy="20" r="13.5" fill="{{ $m['face'] }}"/>
                            <g transform="translate(15.5 11.5) scale(0.7)">
                                <path d="M12 .587l3.668 7.568L24 9.423l-6 5.951L19.336 24 12 19.897 4.664 24 6 15.374 0 9.423l8.332-1.268z" fill="{{ $m['star'] }}"/>
                            </g>
                        </svg>
                        <p class="text-xs font-bold">{{ $tier['name'] }}</p>
                        <p @class(['mt-0.5 text-[10px]', 'text-white/80' => $isCurrent, 'text-zinc-500' => ! $isCurrent])>{{ number_format($tier['min']) }} Rcoin</p>
                        @if ($tier['requires'])
                            @php
                                // eSIM-category-style pill: tinted border + fill + text.
                                // Blue for email verification, green for ID verification.
                                $reqPill = $tier['requires'] === 'Email verified'
                                    ? 'border-blue-600 bg-blue-600/10 text-blue-700 dark:border-blue-400/60 dark:bg-blue-500/20 dark:text-blue-200'
                                    : 'border-emerald-600 bg-emerald-600/10 text-emerald-700 dark:border-emerald-400/60 dark:bg-emerald-500/20 dark:text-emerald-200';
                            @endphp
                            <span class="mt-1.5 inline-flex items-center gap-1 rounded-full border px-2 py-0.5 text-[10px] font-semibold {{ $reqPill }}">
                                <svg class="h-2.5 w-2.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5" aria-hidden="true">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M16.5 10.5V6.75a4.5 4.5 0 10-9 0v3.75M3.75 21.75h16.5a.75.75 0 00.75-.75v-9a.75.75 0 00-.75-.75H3.75a.75.75 0 00-.75.75v9c0 .414.336.75.75.75z"/>
                                </svg>
                                {{ $tier['requires'] }}
                            </span>
                        @endif
                    </div>
                @endforeach
            </div>
        </section>

        {{-- ─── Rcoin history ─── --}}
        <section>
            <h2 class="mb-3 text-sm font-bold text-black">Rcoin history</h2>
            <div class="divide-y divide-zinc-100 rounded-[12px] bg-[#eff6ff] dash-shimmer border border-zinc-200 shadow-md shadow-zinc-900/[0.06] transition-colors hover:border-green-200 dark:border-zinc-700 dark:hover:border-white dark:shadow-none">
                @forelse ($rcoinHistory as $entry)
                    <div class="flex items-center justify-between gap-4 px-5 py-3">
                        <div class="min-w-0">
                            <p class="truncate text-sm font-medium text-zinc-700">{{ $entry['label'] }}</p>
                            <p class="mt-0.5 text-xs text-zinc-600">{{ \Illuminate\Support\Carbon::parse($entry['date'])->format('d/m/Y') }}</p>
                        </div>
                        <div class="inline-flex shrink-0 items-center gap-1.5 text-sm font-semibold">
                            <svg class="h-3.5 w-3.5 text-emerald-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5" aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15"/>
                            </svg>
                            <img src="{{ $coin }}" alt="" class="h-4 w-4">
                            <span class="text-black">{{ number_format($entry['rcoin']) }} Rcoin</span>
                        </div>
                    </div>
                @empty
                    <div class="px-5 py-10 text-center">
                        <p class="text-sm font-semibold text-zinc-900">No Rcoin activity yet</p>
                        <p class="mt-1 text-xs text-zinc-600">Earn Rcoin on every order and referral, then track it here.</p>
                    </div>
                @endforelse
            </div>
        </section>

    </div>
</x-layouts.dashboard>

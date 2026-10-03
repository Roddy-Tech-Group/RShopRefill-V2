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
    {{-- For Partners. Three options along the top: Refer & Earn, Rcoin Earnings
         and the Partnership program (New). The Rcoin page that used to be the
         whole of this screen now sits, unchanged, under Rcoin Earnings, which is
         also the default tab. The open tab is kept in ?tab= so each one can be
         linked to directly and survives a reload. --}}
    @php
        $partnerTabs = [
            'refer' => ['label' => 'Refer & Earn', 'new' => false],
            'rcoin' => ['label' => 'Rcoin Earnings', 'new' => false],
            'partners' => ['label' => 'Partnership program', 'new' => true],
        ];
        // Refer & Earn opens the page: it is the one tab a partner comes here to
        // act on. Rcoin Earnings is where they check a balance, which is a second
        // question, and ?tab= still links straight to either.
        $partnerTab = array_key_exists(request()->queryString('tab'), $partnerTabs) ? request()->queryString('tab') : 'refer';

        // Refer & Earn figures. Every number is live: the reward rules come
        // from the admin rewards settings, the counts from this customer's own
        // referral records (total_rewards_generated is Rcoin, net of reversals).
        $referralEnabled = (bool) \App\Models\Setting::get('referral_enabled', true);
        $referralPct = (float) \App\Models\Setting::get('referral_reward_percentage', 0.5);
        $referralPctLabel = rtrim(rtrim(number_format($referralPct, 2), '0'), '.');
        $referralRecurring = (bool) \App\Models\Setting::get('recurring_referral_rewards_enabled', true);
        $referralCap = (int) \App\Models\Setting::get('max_referral_rewards_per_user', 0);
        $referralEarnUpTo = (int) config('dashboard.earn_up_to_usd', 0);
        $referredCount = \App\Models\Referral::where('referrer_id', $user->id)->count();
        $referralRcoin = (int) \App\Models\Referral::where('referrer_id', $user->id)->sum('total_rewards_generated');
        // Its cash value at the admin's Rcoin rate (Setting rcoin_usd_rate).
        $referralRcoinUsd = $referralRcoin * (float) \App\Models\Setting::rcoinUsdRate();

        // Both sides of a referral, in Rcoin, on a $1 order. The buyer's side is
        // cashback and the referrer's is the referral bonus: two separate admin
        // settings, so they are read and converted separately rather than assumed
        // equal. Running each through RewardEngine::usdToRcoin (and, for the
        // referrer, their own multiplier, which is what awardReferralBonus does)
        // means the figures shown are exactly what would be credited. A rate that
        // rounds down to nothing simply hides its line.
        $referralCashbackPct = (bool) \App\Models\Setting::get('rcoin_enabled', true)
            ? (float) \App\Models\Setting::get('cashback_percentage', 0.5)
            : 0.0;
        $referralCashbackPctLabel = rtrim(rtrim(number_format($referralCashbackPct, 2), '0'), '.');
        $rcoinPerDollarBuyer = $referralCashbackPct > 0 ? $rewardEngine->usdToRcoin($referralCashbackPct / 100) : 0;
        $rcoinPerDollarReferrer = $referralPct > 0 ? $rewardEngine->usdToRcoin($referralPct / 100) : 0;
        if ($rcoinPerDollarReferrer > 0 && $rcoinMultiplier > 0 && $hasMultiplier) {
            $rcoinPerDollarReferrer = (int) floor($rcoinPerDollarReferrer * $rcoinMultiplier);
        }

        // What a friend's order can be: the shop services that are switched on
        // right now, so a category the admin turned off is never advertised here.
        $referralServices = collect([
            'Gift Cards' => 'gift_cards',
            'eSIMs' => 'esims',
            'Topups' => 'topups',
            'Bills' => 'bills',
        ])->filter(fn (string $flag) => \App\Support\FeatureFlag::on($flag))->keys()->all();

        // Platform totals for the hero's stats strip: every referral in the
        // system, all the Rcoin the platform has ever minted (every RCOIN credit
        // on record, so cashback and bonuses count too, not just referrals), and
        // who the biggest referrers are. Only ids and counts are cached, for five
        // minutes: the referrers themselves are loaded fresh on every request so
        // a profile picture changed a minute ago shows up straight away.
        [$platformReferrals, $platformRcoinMinted, $topReferrerIds, $platformReferrerCount] = \Illuminate\Support\Facades\Cache::remember(
            'partners.referral-totals',
            now()->addMinutes(5),
            function () {
                $ranked = \App\Models\Referral::query()
                    ->selectRaw('referrer_id, COUNT(*) as referral_count')
                    ->groupBy('referrer_id')
                    ->orderByDesc('referral_count')
                    ->pluck('referrer_id');

                return [
                    (int) \App\Models\Referral::count(),
                    (int) \App\Models\WalletTransaction::query()
                        ->where('currency', Currency::RCOIN->value)
                        ->where('type', \App\Domain\Shared\Enums\WalletTransactionType::Credit->value)
                        ->sum('amount'),
                    $ranked->take(5)->values()->all(),
                    $ranked->count(),
                ];
            }
        );
        // Back into ranked order: whereIn returns them however the database likes.
        $topReferrers = \App\Models\User::query()
            ->whereIn('id', $topReferrerIds)
            ->get()
            ->sortBy(fn ($referrer) => array_search($referrer->id, $topReferrerIds, true))
            ->values();

        // Referral Hero tiles: the featured gift card brands (Apple, Amazon,
        // Sephora, Discord by default; config/dashboard.php partner_tile_brands),
        // in order, around the RShopRefill mark. Logos come from the local brand
        // assets, else from an active product of the brand.
        $tileFor = function (string $brandKey): ?array {
            $logo = \App\Models\Product::brandLogoUrl(
                $brandKey,
                \App\Models\Product::query()
                    ->where('brand_key', $brandKey)
                    ->where('is_active', true)
                    ->whereNotNull('logo_url')
                    ->value('logo_url')
            );

            return filled($logo)
                ? ['brand' => $brandKey, 'name' => \App\Models\Product::brandDisplayName($brandKey), 'logo' => $logo]
                : null;
        };
        $referralTiles = collect((array) config('dashboard.partner_tile_brands', []))
            ->map($tileFor)
            ->filter()
            ->unique('brand')
            ->take(4)
            ->values();

        // Never an empty tile: a featured brand with no logo gives its place to
        // a high-margin brand, then to one of the region's popular brands.
        if ($referralTiles->count() < 4) {
            $referralRegion = strtoupper((string) (request()->attributes->get('region') ?: 'US'));
            $referralTiles = $referralTiles
                ->concat(\App\Support\HighMarginBrands::top(4))
                ->concat(
                    \App\Models\Product::popularForRegion($referralRegion)->map(fn ($product) => [
                        'brand' => (string) $product->brand_key,
                        'name' => \App\Models\Product::brandDisplayName($product->brand_key),
                        'logo' => \App\Models\Product::brandLogoUrl($product->brand_key, $product->logo_url ?? null),
                    ])
                )
                ->filter(fn ($tile) => filled($tile['logo'] ?? null))
                ->unique('brand')
                ->take(4)
                ->values();
        }

    @endphp
    <div
        x-data="{
            tab: @js($partnerTab),
            show(key) {
                this.tab = key;
                try {
                    const url = new URL(window.location.href);
                    url.searchParams.set('tab', key);
                    window.history.replaceState(window.history.state, '', url);
                } catch (e) {}
            },
        }"
        class="flex w-full flex-col gap-6"
    >
        <div>
            <h1 class="hidden text-xl font-bold tracking-tight text-zinc-900 sm:text-3xl lg:block dark:text-white">For Partners</h1>

            {{-- Underlined tabs across a hairline, like the reference. The Refer &
                 Earn tab shows the customer's real friends-joined count on the
                 right of the row (no bonus programme is claimed: none exists). --}}
            <div class="mt-5 flex flex-wrap items-end justify-between gap-x-6 gap-y-2 border-b border-[#dfe3e1] dark:border-white/10">
                <div class="-mb-px flex flex-wrap gap-x-7" role="tablist" aria-label="For Partners">
                    @foreach ($partnerTabs as $key => $partnerOption)
                        <button
                            type="button"
                            role="tab"
                            id="partners-tab-{{ $key }}"
                            aria-controls="partners-panel-{{ $key }}"
                            @click="show(@js($key))"
                            {{-- The underline follows aria-selected alone. It used to be
                                 state classes in both class and :class, but Alpine never
                                 strips classes the server rendered, so the first tab kept
                                 its underline after switching. --}}
                            aria-selected="{{ $partnerTab === $key ? 'true' : 'false' }}"
                            :aria-selected="(tab === @js($key)).toString()"
                            class="inline-flex items-center gap-2 border-b-2 border-transparent pb-3 text-sm font-semibold text-zinc-500 transition-colors hover:text-zinc-900 aria-selected:border-[#0c1337] aria-selected:text-[#0c1337] dark:text-zinc-400 dark:hover:text-white dark:aria-selected:border-white dark:aria-selected:text-white"
                        >
                            {{ $partnerOption['label'] }}
                            @if ($partnerOption['new'])
                                <span class="inline-flex items-center rounded-[5px] bg-[#26358c] px-1.5 py-0.5 text-[10px] font-bold uppercase tracking-wide text-white">New</span>
                            @endif
                        </button>
                    @endforeach
                </div>

                <p x-show="tab === 'refer'" @if ($partnerTab !== 'refer') style="display: none;" @endif class="flex items-center gap-2 pb-3 text-sm text-zinc-600 dark:text-zinc-300">
                    <svg class="h-4 w-4 text-[#0c1337] dark:text-zinc-200" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M15 19.128a9.38 9.38 0 002.625.372 9.337 9.337 0 004.121-.952 4.125 4.125 0 00-7.533-2.493M15 19.128v-.003c0-1.113-.285-2.16-.786-3.07M15 19.128v.106A12.318 12.318 0 018.624 21c-2.331 0-4.512-.645-6.374-1.766l-.001-.109a6.375 6.375 0 0111.964-3.07M12 6.375a3.375 3.375 0 11-6.75 0 3.375 3.375 0 016.75 0zm8.25 2.25a2.625 2.625 0 11-5.25 0 2.625 2.625 0 015.25 0z"/></svg>
                    <span><span class="font-semibold text-zinc-900 dark:text-white">{{ number_format($referredCount) }}</span> {{ \Illuminate\Support\Str::plural('friend', $referredCount) }} joined</span>
                </p>
            </div>
        </div>

        {{-- ─── Refer & Earn ─── --}}
        <div
            id="partners-panel-refer"
            role="tabpanel"
            aria-labelledby="partners-tab-refer"
            x-show="tab === 'refer'"
            @if ($partnerTab !== 'refer') style="display: none;" @endif
        >
            {{-- Referral Hero, built from the design file (brand colour #0c1337).
                 An always-dark card: a solid dome with a soft glow under the
                 accent bar, floating tiles around the RShopRefill mark, the big
                 "Invite others and earn up to" heading, the link field, how the
                 reward pays, and a stats bar.

                 Kept to the project's rules: no gradients at all. The design's
                 gradient dome and radial glow were drawn as blurred solid shapes
                 for a while and are now gone entirely, at the user's request, so
                 the card is one flat surface. No invented offers or numbers. The design's
                 "Refer 3 people, get $40 extra", countdown, "They save 20%",
                 plan footnote and platform totals become the customer's real
                 reward terms and their own referral stats. Every colour is a
                 literal hex so the dashboard's dark-mode surface remaps leave the
                 card as designed in both themes. --}}
            <style>
                @keyframes partner-bob { 0%, 100% { transform: translateY(0); } 50% { transform: translateY(-8px); } }
                .partner-bob { animation: partner-bob var(--bob-duration, 5.6s) ease-in-out var(--bob-delay, 0s) infinite; }
                @media (prefers-reduced-motion: reduce) { .partner-bob { animation: none; } }
                /* The whole card is DM Sans 399, the platform face and weight: one
                   rule on every descendant so nothing inside can drift to a bolder
                   cut. Emphasis in here is carried by colour, not weight. */
                .partner-hero, .partner-hero * { font-family: 'DM Sans', sans-serif; font-weight: 399; }
                /* The same grain the dark surfaces wear, so the card reads as
                   part of the theme rather than a flat slab. It needs saying
                   here because theme-static keeps this card out of the dark
                   remaps, which is where every other surface picks the grain up.
                   The card is always dark, so it carries it in both themes. */
                .partner-hero > div { background-image: var(--grain); background-size: 220px 220px; }
            </style>
            {{-- One dark layer: the design's outer #08080a frame was removed at the
                 user's request, so the card sits straight on the page. --}}
            <div class="partner-hero theme-static w-full shadow-[0_18px_44px_rgba(0,0,0,0.28)] rounded-[22px]">
                <div class="relative overflow-hidden rounded-[22px] bg-[#17171a]">

                    {{-- The dome, in one flat fill of the brand blue, the same
                         #0c1337 as the bar above it, so the two read as one shape
                         flowing down the card. It used to carry a blurred blue
                         glow inside it, which is the gradient the brand does not
                         use; there is no filter, no clip and no second colour
                         here now. --}}
                    <svg viewBox="0 0 1300 360" preserveAspectRatio="none" class="pointer-events-none absolute inset-x-0 top-0 block h-[360px] w-full" aria-hidden="true">
                        <path d="M0 0H1300V46C1180 46 1062 74 992 132C912 198 828 268 650 268C472 268 388 198 308 132C238 74 120 46 0 46Z" fill="#0c1337"/>
                    </svg>

                    {{-- Accent bar --}}
                    <div class="relative z-[3] flex items-center justify-center gap-2.5 bg-[#0c1337] px-5 py-3.5 text-center">
                        <span class="text-white"><svg class="h-[17px] w-[17px]" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><rect x="3" y="8" width="18" height="13" rx="2"/><path d="M3 12h18M12 8v13"/><path d="M12 8C12 8 10.5 3 7.5 3S4.5 8 8 8zM12 8c0 0 1.5-5 4.5-5S20 8 16 8z"/></svg></span>
                        <span class="text-[14.5px] leading-tight tracking-[-0.01em] text-white">
                            @if (! $referralEnabled)
                                Referral rewards are paused right now
                            @elseif ($referralRecurring)
                                Earn Rcoin every time a friend's order completes
                            @else
                                Earn Rcoin when a friend's first order completes
                            @endif
                        </span>
                    </div>

                    {{-- Floating tiles: real brands around the RShopRefill mark. --}}
                    @php
                        $tileAt = fn (int $i) => $referralTiles[$i] ?? null;
                        $sideTile = 'partner-bob grid place-items-center overflow-hidden border border-white/[0.12] bg-[#0e0e12]';
                    @endphp
                    <div class="relative z-[2] flex h-[170px] items-start justify-center gap-4 pb-2.5 pt-[46px] sm:gap-[30px]">
                        @foreach ([[0, 'mt-2 h-[62px] w-[62px] rounded-[17px]', '5.6s', '0s', 'hidden sm:grid'], [1, 'mt-[52px] h-[58px] w-[58px] rounded-[16px]', '6.6s', '.6s', 'grid']] as [$i, $size, $duration, $delay, $display])
                            <div class="{{ $sideTile }} {{ $size }} {{ $display }}" style="--bob-duration: {{ $duration }}; --bob-delay: {{ $delay }};">
                                @if ($tile = $tileAt($i))
                                    <img src="{{ $tile['logo'] }}" alt="{{ $tile['name'] }}" class="no-dark-invert h-full w-full object-cover" loading="lazy">
                                @endif
                            </div>
                        @endforeach

                        <div class="partner-bob grid h-[72px] w-[72px] place-items-center rounded-[26px] border-2 border-white/85 bg-[#fdfdfd] shadow-[0_16px_34px_rgba(8,8,12,0.55)]" style="--bob-duration: 5s; --bob-delay: .3s;">
                            <img src="{{ asset('assets/PWAicon.webp') }}" alt="{{ $siteName }}" class="no-dark-invert h-10 w-10 object-contain">
                        </div>

                        @foreach ([[2, 'mt-[52px] h-[58px] w-[58px] rounded-[16px]', '7s', '.9s', 'grid'], [3, 'mt-2 h-[62px] w-[62px] rounded-[17px]', '6.1s', '.45s', 'hidden sm:grid']] as [$i, $size, $duration, $delay, $display])
                            <div class="{{ $sideTile }} {{ $size }} {{ $display }}" style="--bob-duration: {{ $duration }}; --bob-delay: {{ $delay }};">
                                @if ($tile = $tileAt($i))
                                    <img src="{{ $tile['logo'] }}" alt="{{ $tile['name'] }}" class="no-dark-invert h-full w-full object-cover" loading="lazy">
                                @endif
                            </div>
                        @endforeach
                    </div>

                    <div class="relative z-[2] flex flex-col items-center px-5 pb-9 sm:px-10">
                        {{-- The design's countdown pill shows only when an offer runs; none
                             does, so it carries how the reward is paid instead. --}}
                        <div class="flex items-center gap-1.5 rounded-full bg-[#252f85] px-3 py-1.5 shadow-[0_6px_20px_rgba(8,8,12,0.35)]">
                            <img src="{{ asset('assets/favicon.ico') }}" alt="" class="no-dark-invert h-3 w-3 object-contain brightness-0 invert">
                            <span class="text-[12px] leading-none tracking-[-0.005em] text-white">Paid in Rcoin</span>
                        </div>

                        <h2 class="mt-6 text-center text-[32px] leading-[1.12] tracking-[-0.03em] text-white [text-wrap:balance] sm:text-[44px]">
                            @if ($referralEarnUpTo > 0)
                                Invite others and earn up to ${{ number_format($referralEarnUpTo) }}
                            @else
                                Invite others and earn Rcoin on {{ $referralRecurring ? 'every order' : 'their first order' }}
                            @endif
                        </h2>

                        {{-- What their order can be. Built from the shop categories that
                             are switched on, so it never lists a service the customer
                             cannot actually buy. --}}
                        @if (filled($referralServices))
                            <p class="mb-7 mt-2.5 text-center text-[14px] leading-[1.35] text-[#9696a3]">{{ implode(', ', $referralServices) }}</p>
                        @else
                            <div class="mb-7"></div>
                        @endif

                        <div class="w-full max-w-[780px]">
                            <livewire:referral-code variant="dark" />
                        </div>

                        @if ($referralEnabled && $referralPct > 0)
                            {{-- Two rates, two settings: the buyer's cashback and the
                                 referrer's bonus. The line under them restates the same
                                 reward in Rcoin on a $1 order, which is what the customer
                                 actually sees credited. Each line hides itself when its
                                 rate is off or rounds down to nothing, so nothing here
                                 can promise a reward the engine would not pay. --}}
                            <p class="mt-6 text-center text-[15px] leading-none text-[#c9c9d4]">
                                @if ($referralCashbackPct > 0)
                                    They earn <span class="text-white">{{ $referralCashbackPctLabel }}%</span> {{ $referralRecurring ? 'per order' : 'on their first order' }}, you earn <span class="text-white">{{ $referralPctLabel }}%</span> too
                                @else
                                    You earn <span class="text-white">{{ $referralPctLabel }}%</span> of {{ $referralRecurring ? 'every order' : 'the first order' }} they complete, in Rcoin
                                @endif
                            </p>
                            @if ($rcoinPerDollarBuyer > 0 && $rcoinPerDollarReferrer > 0)
                                <p class="mt-2.5 text-center text-[14px] leading-none text-[#c9c9d4]">
                                    @if ($rcoinPerDollarBuyer === $rcoinPerDollarReferrer)
                                        That is <span class="text-white">{{ number_format($rcoinPerDollarBuyer) }} {{ \Illuminate\Support\Str::plural('Rcoin', $rcoinPerDollarBuyer) }}</span> each per $1 they spend
                                    @else
                                        That is <span class="text-white">{{ number_format($rcoinPerDollarBuyer) }} Rcoin</span> for them and <span class="text-white">{{ number_format($rcoinPerDollarReferrer) }} Rcoin</span> for you per $1 they spend
                                    @endif
                                </p>
                            @endif
                            <div class="mt-2.5 flex items-center justify-center gap-[7px]">
                                <p class="text-center text-[13px] leading-none text-[#87878f]">Added to your Rcoin balance when their order completes{{ $referralCap > 0 ? ', on up to '.number_format($referralCap).' '.\Illuminate\Support\Str::plural('order', $referralCap).' per friend' : '' }}.</p>
                                {{-- Earn more: the creator tiers. The multiplier is real
                                     (users.rcoin_multiplier, applied by RewardEngine to
                                     every reward); the two tier figures come from
                                     config/dashboard.php so the note and the programme
                                     move together, and the customer's own multiplier is
                                     named only when it is not the standard 1x. --}}
                                @php
                                    $mulLabel = fn (float $m) => rtrim(rtrim(number_format($m, 2), '0'), '.').'x';
                                    $influencerMultiplier = (float) config('dashboard.influencer_multiplier', 2);
                                    $ambassadorMultiplier = (float) config('dashboard.ambassador_multiplier', 3);
                                @endphp
                                <span
                                    class="relative inline-flex"
                                    x-data="{ tip: false }"
                                    @mouseenter="tip = true"
                                    @mouseleave="tip = false"
                                    @click.outside="tip = false"
                                    @keydown.escape.window="tip = false"
                                >
                                    <button
                                        type="button"
                                        @click="tip = ! tip"
                                        :aria-expanded="tip.toString()"
                                        aria-label="How to earn more per order"
                                        class="text-[#8a8a97] transition-colors hover:text-white focus:outline-none focus-visible:text-white"
                                    >
                                        <svg class="h-[15px] w-[15px]" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M12 11v5.5M12 7.8v.4"/></svg>
                                    </button>
                                    <span
                                        x-show="tip"
                                        x-cloak
                                        x-transition:enter="transition ease-out duration-150"
                                        x-transition:enter-start="opacity-0 translate-y-1"
                                        x-transition:enter-end="opacity-100 translate-y-0"
                                        x-transition:leave="transition ease-in duration-100"
                                        x-transition:leave-start="opacity-100 translate-y-0"
                                        x-transition:leave-end="opacity-0 translate-y-1"
                                        role="tooltip"
                                        class="absolute bottom-full left-1/2 z-30 mb-3 w-[320px] max-w-[80vw] -translate-x-1/2 rounded-[12px] bg-[#fdfdfd] p-4 text-left text-[13px] leading-[1.55] text-zinc-700 shadow-[0_18px_40px_rgba(8,8,12,0.45)]"
                                    >
                                        You can earn more per order. Every account starts at 1x. Contact us to become a {{ $siteName }} influencer, which pays {{ $mulLabel($influencerMultiplier) }} on each reward, or an ambassador, which pays {{ $mulLabel($ambassadorMultiplier) }}.@if ($hasMultiplier) You are on {{ $mulLabel($rcoinMultiplier) }} right now.@endif
                                        {{-- The inquiry form lives on the storefront and has
                                             no dashboard mirror, so it opens in its own tab
                                             rather than navigating the dashboard away. --}}
                                        <a href="{{ route('shop.affiliates') }}" target="_blank" rel="noopener" class="mt-2 block text-zinc-900 underline underline-offset-2">Apply to become one</a>
                                        <span class="absolute left-1/2 top-full h-3 w-3 -translate-x-1/2 -translate-y-1/2 rotate-45 rounded-[2px] bg-[#fdfdfd]"></span>
                                    </span>
                                </span>
                            </div>
                        @else
                            <p class="mt-6 text-center text-[15px] text-[#c9c9d4]">Your link still works, and new referrals are recorded.</p>
                        @endif
                    </div>

                    {{-- Stats strip: the reference's shape (avatar stack and a count on
                         the left, one figure on the right) carrying this platform's own
                         numbers. The faces are the five biggest referrers, live from
                         their profiles, so a new picture shows here too; the "+N" counts
                         every other account that has referred someone. The right side is
                         all the Rcoin the platform has minted, not a cash figure. --}}
                    @php
                        $platformReferralsLabel = $platformReferrals >= 1000
                            ? \App\Support\CatalogFigures::abbreviate($platformReferrals)
                            : number_format($platformReferrals);
                        $platformMintedLabel = $platformRcoinMinted >= 1000
                            ? \App\Support\CatalogFigures::abbreviate($platformRcoinMinted)
                            : number_format($platformRcoinMinted);
                        $moreReferrers = max(0, $platformReferrerCount - $topReferrers->count());
                    @endphp
                    <div class="relative z-[2] mx-3 mb-3 flex flex-wrap items-center justify-between gap-x-5 gap-y-1.5 rounded-[14px] bg-[#0e0e12] px-4 py-2.5">
                        <span class="flex items-center gap-3">
                            @if ($topReferrers->isNotEmpty())
                                <span class="flex">
                                    @foreach ($topReferrers as $referrer)
                                        <img src="{{ $referrer->avatar_url ?: $referrer->initialsAvatar(56) }}" alt="" class="no-dark-invert block h-[28px] w-[28px] rounded-full border-2 border-[#0e0e12] object-cover {{ $loop->first ? '' : '-ml-[10px]' }}" loading="lazy">
                                    @endforeach
                                    @if ($moreReferrers > 0)
                                        <span class="-ml-[10px] grid h-[28px] min-w-[28px] place-items-center rounded-full border-2 border-[#0e0e12] bg-[#252f85] px-1.5 text-[11px] leading-none text-white">+{{ \App\Support\CatalogFigures::abbreviate($moreReferrers) }}</span>
                                    @endif
                                </span>
                            @endif
                            <span class="text-[13px] leading-[1.35] text-white">{{ $platformReferralsLabel }} {{ \Illuminate\Support\Str::plural('person', $platformReferrals) }} referred</span>
                        </span>
                        <span class="text-[13px] leading-[1.35] text-[#9696a3]"><span class="text-white">{{ $platformMintedLabel }} Rcoin</span> minted so far</span>
                    </div>
                </div>
            </div>

            {{-- Refer a product: the same referral link pointed at one product's
                 public page. CaptureReferralCookie reads ?ref= on every web request,
                 not just the home page, so a product link attributes a sign-up
                 exactly like the plain link does. The code is read here rather than
                 in the block at the top of the page because the referral-code
                 component above saves one for customers who have none, and it has
                 mounted by now.

                 The cards ARE the storefront's own: eSIMs render through
                 <x-esim.regional-card variant="tile"> off EsimCardPresenter, the
                 same component and data the home row and /esims use, and the gift
                 card tile repeats that component's documented box (16/10, 15px,
                 zinc ring, keep-light) so the two read as one system. On top of
                 each sits the only thing this page adds: the referral reward that
                 product's largest denomination could pay, and a Copy button. --}}
            @php
                $shareCode = (string) (auth()->user()->referral_code ?? '');
                $referProducts = \App\Support\TopMarginProducts::top(18);
                // The branded eSIM cards, keyed by the same public URL our own
                // rows carry, so the two sets line up without matching on names.
                $esimCards = \App\Support\EsimCardPresenter::regions()
                    ->keyBy(fn ($card) => (string) parse_url((string) $card['destinationUrl'], PHP_URL_PATH));

                // The best referral reward each card can pay: its biggest
                // denomination through the live rate, the customer's multiplier
                // included exactly as awardReferralBonus applies it.
                $referRewardFor = function (float $maxUsd) use ($rewardEngine, $referralPct, $rcoinMultiplier, $hasMultiplier): int {
                    if ($maxUsd <= 0 || $referralPct <= 0) {
                        return 0;
                    }
                    $rcoin = $rewardEngine->usdToRcoin($maxUsd * ($referralPct / 100));
                    return $hasMultiplier && $rcoinMultiplier > 0 ? (int) floor($rcoin * $rcoinMultiplier) : $rcoin;
                };

                // Highest paid orders by that same figure, so the pill sorts by what
                // the customer would actually earn, not by our margin.
                $referByReward = collect($referProducts)
                    ->sortByDesc(fn ($card) => $referRewardFor((float) $card['maxUsd']))
                    ->pluck('url')
                    ->values()
                    ->flip();

                // How many cards each filter would show. All and New are always
                // offered, New especially: it is where a product added this month
                // turns up, and a pill that appears only once something qualifies
                // is a pill nobody knows to look for. Highest paid and Popular
                // appear only when the data behind them exists at all.
                $referCounts = [
                    'all' => count($referProducts),
                    'paid' => collect($referProducts)->filter(fn ($c) => $referRewardFor((float) $c['maxUsd']) > 0)->count(),
                    'popular' => collect($referProducts)->filter(fn ($c) => $c['popular'])->count(),
                    'new' => collect($referProducts)->filter(fn ($c) => $c['isNew'])->count(),
                ];
                $referFilters = array_values(array_filter([
                    ['key' => 'all', 'label' => 'All'],
                    $referCounts['paid'] > 0 ? ['key' => 'paid', 'label' => 'Highest paid'] : null,
                    $referCounts['popular'] > 0 ? ['key' => 'popular', 'label' => 'Popular'] : null,
                    ['key' => 'new', 'label' => 'New'],
                ]));
                $referEmpty = [
                    'all' => 'No products to refer yet.',
                    'paid' => 'No product pays a referral reward at the moment.',
                    'popular' => 'Nothing on the popular list right now.',
                    'new' => 'Nothing added in the last 30 days. New products show up here as soon as they are added.',
                ];
            @endphp
            @if ($shareCode !== '' && $referProducts !== [])
                <div class="mt-8" x-data="{ filter: 'all' }">
                    <h3 class="text-[20px] font-semibold tracking-[-0.01em] text-[#0c1337] dark:text-white">Know what they need? Refer a product</h3>
                    <p class="mt-1.5 text-[13px] font-semibold text-[#0c1337] dark:text-zinc-200">Copy the link on any card. It carries your code, so a sign-up through it still counts as your referral.</p>

                    {{-- The selected pill is styled here rather than with dark:
                         variants: stacked variants leave the winner to the order
                         Tailwind emits them in, and the single-variant white text
                         was landing on the white dark-mode pill. Two explicit
                         rules, the second more specific, cannot tie. --}}
                    <style>
                        .refer-pill[aria-pressed="true"] { background-color: #0c1337; border-color: transparent; color: #ffffff; }
                        html.dark .refer-pill[aria-pressed="true"] { background-color: #ffffff; border-color: transparent; color: #0c1337; }
                        /* Hover lives here for the same reason as the selected state:
                           a one-variant hover: rule and a two-variant dark:hover: rule
                           tie on specificity, so which one wins is down to the order
                           Tailwind happens to emit them in. In dark mode that was
                           painting the near-white light-mode hover over dark surfaces. */
                        .refer-pill:hover { background-color: #f4f6f5; border-color: #c9ccd4; color: #0c1337; }
                        html.dark .refer-pill:hover { background-color: rgba(255, 255, 255, 0.08); border-color: rgba(255, 255, 255, 0.35); color: #ffffff; }
                        /* The selected pill keeps its own colours on hover, both themes. */
                        .refer-pill[aria-pressed="true"]:hover { border-color: transparent; color: #ffffff; }
                        html.dark .refer-pill[aria-pressed="true"]:hover { border-color: transparent; color: #0c1337; }
                        .refer-action:hover { background-color: #f4f6f5; border-color: #c9ccd4; }
                        html.dark .refer-action:hover { background-color: rgba(255, 255, 255, 0.08); border-color: rgba(255, 255, 255, 0.3); }
                        /* The card answers the pointer with light only, never movement.
                           It used to lift, but the branded eSIM tile inside lifts on its
                           own hover too: the two together carried the card out from under
                           the cursor, which dropped the hover, which dropped the lift, and
                           the card sat there flickering. Shadow cannot do that. */
                        .refer-card { transition: box-shadow 180ms ease; }
                        .refer-card:hover { box-shadow: 0 14px 30px rgba(12, 19, 55, 0.14); }
                        html.dark .refer-card:hover { box-shadow: 0 14px 30px rgba(0, 0, 0, 0.55); }
                        .refer-tile { transition: box-shadow 180ms ease, outline-color 180ms ease; }
                        .refer-card:hover .refer-tile { box-shadow: 0 10px 24px rgba(12, 19, 55, 0.2); }
                        @media (prefers-reduced-motion: reduce) {
                            .refer-card, .refer-tile { transition: none; }
                        }
                    </style>
                    @if (count($referFilters) > 1)
                        <div class="mt-4 flex flex-wrap gap-2" role="group" aria-label="Filter products">
                            @foreach ($referFilters as $tab)
                                <button
                                    type="button"
                                    @click="filter = @js($tab['key'])"
                                    aria-pressed="{{ $loop->first ? 'true' : 'false' }}"
                                    :aria-pressed="(filter === @js($tab['key'])).toString()"
                                    class="refer-pill inline-flex items-center gap-1.5 rounded-full border border-zinc-200 px-4 py-2 text-[13px] text-[#0c1337] transition-colors dark:border-white/15 dark:text-zinc-300"
                                >
                                    <svg x-show="filter === @js($tab['key'])" x-cloak class="h-[13px] w-[13px]" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4.5 12.75l6 6 9-13.5"/></svg>
                                    {{ $tab['label'] }}
                                </button>
                            @endforeach
                        </div>
                    @endif

                    <p
                        x-show="@js($referCounts)[filter] === 0"
                        x-cloak
                        x-text="@js($referEmpty)[filter]"
                        class="mt-5 text-[13px] text-[#0c1337]/90 dark:text-zinc-400"
                    ></p>

                    <div class="mt-5 grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-3">
                        @foreach ($referProducts as $index => $product)
                            @php
                                // Two different links on purpose. The one the
                                // customer COPIES is the storefront's, because whoever
                                // they send it to is not signed in; the one the card
                                // itself opens is the dashboard mirror, so browsing
                                // from in here never drops out of the dashboard.
                                $shareUrl = url($product['url']).'?ref='.$shareCode;
                                $browseUrl = \Illuminate\Support\Facades\Route::has('dashboard.'.$product['routeName'])
                                    ? route('dashboard.'.$product['routeName'], $product['routeParams'])
                                    : url($product['url']);
                                $reward = $referRewardFor((float) $product['maxUsd']);
                                $esimCard = $esimCards[$product['url']] ?? null;
                            @endphp
                            <div
                                x-data="{ copied: false }"
                                x-show="filter === 'all'
                                    || (filter === 'paid' && {{ $reward > 0 ? 'true' : 'false' }})
                                    || (filter === 'popular' && {{ $product['popular'] ? 'true' : 'false' }})
                                    || (filter === 'new' && {{ $product['isNew'] ? 'true' : 'false' }})"
                                :style="filter === 'paid' ? 'order: {{ $referByReward[$product['url']] ?? $index }}' : 'order: {{ $index }}'"
                                class="refer-card relative flex flex-col rounded-[16px] bg-[#ffffff] p-3 shadow-md shadow-zinc-900/[0.06] dark:shadow-none"
                            >
                                @if ($esimCard)
                                    {{-- The storefront's own destination card, same
                                         component and data as /esims and the home row. --}}
                                    <x-esim.regional-card
                                        variant="tile"
                                        :name="$esimCard['name']"
                                        :type="$esimCard['type']"
                                        :country-count="$esimCard['countryCount']"
                                        :description="$esimCard['description']"
                                        :accent-color="$esimCard['accentColor']"
                                        :flag-urls="$esimCard['flagUrls']"
                                        :flag-display="$esimCard['flagDisplay']"
                                        :background-pattern="$esimCard['backgroundPattern']"
                                        :hotspot-supported="$esimCard['hotspotSupported']"
                                        :network-types="$esimCard['networkTypes']"
                                        :availability="$esimCard['availability']"
                                        :destination-url="$browseUrl"
                                        :from-price="$esimCard['fromPrice']"
                                    />
                                @else
                                    {{-- The gift tile: the box x-esim.regional-card calls
                                         "exactly the gift tile's box", with the caption
                                         x-home.brand-card puts under it. --}}
                                    <a href="{{ $browseUrl }}" wire:navigate.hover class="group block focus:outline-none">
                                        <div class="refer-tile keep-light relative flex aspect-[16/10] items-center justify-center overflow-hidden rounded-[15px] ring-1 ring-zinc-200 shadow-sm group-focus-visible:ring-2 group-focus-visible:ring-blue-500/40 {{ $product['logo'] ? 'bg-[#ffffff]' : ($product['color'] ? '' : 'bg-zinc-100') }}" @if (! $product['logo'] && $product['color']) style="background-color: {{ $product['color'] }};" @endif>
                                            @if ($product['logo'])
                                                <img
                                                    src="{{ $product['logo'] }}"
                                                    alt="{{ $product['name'] }}"
                                                    class="h-full w-full object-cover"
                                                    loading="lazy"
                                                    x-data
                                                    {{-- x-on:, not the @ shorthand: Blade reads @error as its own directive. --}}
                                                    x-on:error="$el.parentElement.querySelector('[data-logo-fallback]')?.removeAttribute('hidden'); $el.remove()"
                                                >
                                            @endif
                                            <span
                                                data-logo-fallback
                                                @if ($product['logo']) hidden @endif
                                                class="px-3 text-center text-xl font-black uppercase leading-tight tracking-tight {{ $product['color'] ? 'text-white' : 'text-[#0c1337]' }}"
                                            >{{ $product['name'] }}</span>
                                        </div>
                                        <span class="mt-2 flex items-center gap-2">
                                            <p translate="no" class="notranslate min-w-0 flex-1 truncate text-[16px] font-bold leading-tight text-[#0c1337] dark:text-white">{{ $product['name'] }}</p>
                                            @if ($product['isNew'] || $product['popular'])
                                                <span class="inline-flex shrink-0 items-center rounded-[5px] bg-[#26358c] px-1.5 py-0.5 text-[10px] uppercase tracking-wide text-white">{{ $product['isNew'] ? 'New' : 'Popular' }}</span>
                                            @endif
                                        </span>
                                    </a>
                                @endif

                                @if ($esimCard && ($product['isNew'] || $product['popular']))
                                    <span class="mt-2 inline-flex w-fit items-center rounded-[5px] bg-[#26358c] px-1.5 py-0.5 text-[10px] uppercase tracking-wide text-white">{{ $product['isNew'] ? 'New' : 'Popular' }}</span>
                                @endif

                                {{-- What it solves, under the product card rather than
                                     printed on it: our own line from config/dashboard.php,
                                     falling back to the supplier's description. A product
                                     with neither shows no line at all. --}}
                                @if ($product['description'])
                                    <p class="mb-4 mt-2 text-[13px] leading-[1.45] text-[#0c1337]/90 dark:text-zinc-400">{{ \Illuminate\Support\Str::limit(strip_tags($product['description']), 130) }}</p>
                                @endif

                                @if ($reward > 0)
                                    <span class="pointer-events-none absolute right-3 top-3 z-[2] rounded-bl-[12px] rounded-tr-[12px] bg-[#0c7a4b] px-2.5 py-1.5 text-[11px] leading-none text-white">Earn up to {{ number_format($reward) }} Rcoin</span>
                                @endif

                                <button
                                    type="button"
                                    @click="navigator.clipboard.writeText(@js($shareUrl)).then(() => { copied = true; setTimeout(() => copied = false, 1800) })"
                                    class="refer-action mt-auto inline-flex w-full items-center justify-center gap-2 rounded-[8px] border border-zinc-200 px-3 py-2.5 text-[13px] leading-none text-[#0c1337] transition-colors dark:border-white/15 dark:text-white"
                                >
                                    <span x-show="!copied"><svg class="h-[14px] w-[14px]" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="9" y="9" width="11" height="11" rx="2.5"/><path d="M15 5.5A2.5 2.5 0 0 0 12.5 3h-7A2.5 2.5 0 0 0 3 5.5v7A2.5 2.5 0 0 0 5.5 15"/></svg></span>
                                    <span x-show="copied" x-cloak><svg class="h-[14px] w-[14px]" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4.5 12.75l6 6 9-13.5"/></svg></span>
                                    <span x-text="copied ? 'Link copied' : 'Copy referral link'">Copy referral link</span>
                                </button>
                            </div>
                        @endforeach
                    </div>
                </div>
            @endif

            {{-- How referrals work: three steps, each one a rule the engine
                 actually enforces. Step two follows the recurring setting, step
                 three names the real withdrawal minimum and only mentions
                 identity checks when compliance requires them. Nothing here
                 names a payout provider. --}}
            @php
                $referSteps = [
                    [
                        'title' => 'You send an invite',
                        'body' => 'Share your link, or copy the link on any product above. Both carry your code.',
                    ],
                    [
                        'title' => 'They complete an order',
                        'body' => $referralRecurring
                            ? 'Signing up alone earns nothing. The reward lands when an order they place completes, and again on every order after that.'
                            : 'Signing up alone earns nothing. The reward lands when their first order completes.',
                    ],
                    [
                        'title' => 'You get paid in Rcoin',
                        'body' => 'Spend it at checkout, convert it to your wallet, or withdraw it once you reach '
                            .number_format((int) \App\Models\Setting::get('withdrawal_min_rcoin', 2000)).' Rcoin'
                            .((bool) \App\Models\Setting::get('require_kyc_for_withdrawal', false) ? ' and your identity is verified' : '').'.',
                    ],
                ];
            @endphp
            <div class="mt-8">
                <h3 class="text-[20px] font-semibold tracking-[-0.01em] text-[#0c1337] dark:text-white">How do referrals work?</h3>

                <div class="mt-4 grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-3">
                    @foreach ($referSteps as $i => $step)
                        <div class="rounded-[16px] bg-[#ffffff] p-5 shadow-md shadow-zinc-900/[0.06] dark:shadow-none">
                            <span class="grid h-9 w-9 place-items-center rounded-full bg-[#0c1337]/10 text-[15px] font-semibold text-[#0c1337] dark:bg-white/10 dark:text-white">{{ $i + 1 }}</span>
                            <p class="mt-4 text-[15px] font-semibold text-[#0c1337] dark:text-white">{{ $step['title'] }}</p>
                            <p class="mt-2 text-[13px] leading-[1.5] text-[#0c1337]/90 dark:text-zinc-400">{{ $step['body'] }}</p>
                        </div>
                    @endforeach
                </div>
            </div>
        </div>

        {{-- ─── Rcoin Earnings: the Rcoin page, unchanged ─── --}}
        <div
            id="partners-panel-rcoin"
            role="tabpanel"
            aria-labelledby="partners-tab-rcoin"
            x-show="tab === 'rcoin'"
            @if ($partnerTab !== 'rcoin') style="display: none;" @endif
        >
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

            <div class="mt-4 rounded-[12px] bg-[#ffffff] p-5 dash-shimmer shadow-md shadow-zinc-900/[0.06] dark:shadow-none sm:p-6">
                <div class="flex items-start gap-4">
                    <span class="flex h-12 w-12 shrink-0 items-center justify-center rounded-[12px] bg-[#f4f6f5] shadow-sm shadow-zinc-900/10">
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

            {{-- Follows what OrderService actually does: the reward job runs as the
                 order completes, unless the admin fraud hold delays it by whole days.
                 The old line promised a 48-hour wait the code never had. --}}
            @php
                $rcoinHoldDays = \App\Models\Setting::get('fraud_hold_enabled', false)
                    ? max(0, (int) \App\Models\Setting::get('fraud_hold_days', 0))
                    : 0;
            @endphp
            <p class="mt-3 text-sm text-zinc-600">
                @if ($rcoinHoldDays > 0)
                    Rcoin is earned on every purchase and becomes available {{ $rcoinHoldDays }} {{ \Illuminate\Support\Str::plural('day', $rcoinHoldDays) }} after the order completes.
                @else
                    Rcoin is earned on every purchase and becomes available immediately after the order completes.
                @endif
            </p>
        </section>

        {{-- Rcoin Earnings is for tracking earnings only: the referral link and
             the referral programme live under the Refer & Earn tab. --}}

        {{-- ─── Spend your Rcoin: convert to gift card / withdraw to cash ─── --}}
        <section>
            <h2 class="text-sm font-bold text-black">Spend your Rcoin</h2>
            <div class="mt-3 grid grid-cols-1 gap-4 sm:grid-cols-2">

                {{-- Convert to wallet - instant Rcoin → USD wallet swap.
                     Posts to RcoinConvertController. Min USD floor lives in
                     `wallet_conversion_min_usd` setting (default $2). --}}
                <div class="flex flex-col rounded-[12px] bg-[#ffffff] p-5 dash-shimmer shadow-md shadow-zinc-900/[0.06] dark:shadow-none">
                    <span class="flex h-10 w-10 items-center justify-center rounded-[12px] bg-[#f4f6f5] dark:bg-blue-500/15">
                        <img src="{{ asset('assets/' . rawurlencode('Wallet.svg')) }}" alt="" class="no-dark-invert h-5 w-5 dark:invert dark:brightness-200" loading="lazy">
                    </span>
                    <p class="mt-3 text-base font-bold text-black">Convert to wallet balance</p>
                    <p class="mt-1 text-sm text-zinc-600">Swap your Rcoin for instant USD wallet credit. Spend it on any product - gift cards, eSIMs, top-ups, flights.</p>

                    <div class="mt-4 rounded-[12px] bg-blue-50 px-3 py-2.5 text-sm dark:bg-blue-500/10 dark:ring-1 dark:ring-blue-500/20">
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
                                inputmode="numeric"
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
                    class="flex flex-col rounded-[12px] bg-[#ffffff] p-5 dash-shimmer shadow-md shadow-zinc-900/[0.06] dark:shadow-none"
                >
                    <span class="flex h-10 w-10 items-center justify-center rounded-[12px] bg-emerald-50 dark:bg-emerald-500/15">
                        <img src="{{ asset('assets/' . rawurlencode('wallet 2.svg')) }}" alt="" class="no-dark-invert h-5 w-5 dark:invert dark:brightness-200" loading="lazy">
                    </span>
                    <p class="mt-3 text-base font-bold text-black">Withdraw</p>
                    <p class="mt-1 text-sm text-zinc-600">Cash out your Rcoin balance once you reach the minimum and are verified and eligible.</p>

                    <div class="mt-4 rounded-[12px] bg-emerald-50 px-3 py-2.5 text-sm dark:bg-emerald-500/10 dark:ring-1 dark:ring-emerald-500/20">
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
                                inputmode="numeric"
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
                                    class="absolute left-0 right-0 top-full z-20 mt-2 overflow-hidden rounded-[12px] bg-[#ffffff] p-1 shadow-xl shadow-zinc-900/10"
                                    role="listbox"
                                >
                                    <template x-for="(label, value) in methodLabels" :key="value">
                                        <button
                                            type="button"
                                            role="option"
                                            :aria-selected="(method === value).toString()"
                                            @click="method = value; openMethod = false"
                                            :class="method === value ? 'bg-blue-50 text-blue-700' : 'text-zinc-700 hover:bg-[#f4f6f5]'"
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
            <h2 class="mb-3 text-sm font-bold text-black">Rcoin Reward Tiers</h2>
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
                        'rounded-[15px] p-2 text-center shadow-md shadow-zinc-900/[0.06]',
                        'bg-blue-600 text-white' => $isCurrent,
                        'bg-[#ffffff] text-zinc-900' => ! $isCurrent && $reached,
                        'bg-[#ffffff] text-zinc-400' => ! $reached,
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
            <div class="divide-y divide-zinc-100 rounded-[12px] bg-[#ffffff] dash-shimmer shadow-md shadow-zinc-900/[0.06] dark:shadow-none">
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
                        <p class="mt-1 text-xs text-zinc-600">Earn Rcoin on your orders, then track it here.</p>
                    </div>
                @endforelse
            </div>
        </section>

    </div>
        </div>

        {{-- ─── Partnership program (New) ─── --}}
        <div
            id="partners-panel-partners"
            role="tabpanel"
            aria-labelledby="partners-tab-partners"
            x-show="tab === 'partners'"
            @if ($partnerTab !== 'partners') style="display: none;" @endif
        >
            {{-- Promises no rates, commissions or response times: those are
                 business terms nobody has set yet (same rule as the public
                 Influencers and Affiliates page this links to). --}}
            <section class="rounded-[15px] bg-[#ffffff] p-5 shadow-md shadow-zinc-900/[0.06] sm:p-6 dark:shadow-none">
                <div class="flex items-start gap-4">
                    <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-[12px] bg-[#f4f6f5] text-[#0c1337] dark:text-zinc-200">
                        <x-ui.anim-icon file="for-partners.svg" tone="inherit" class="h-5 w-5" />
                    </span>
                    <div class="min-w-0">
                        <div class="flex items-center gap-2">
                            <h2 class="text-base font-bold text-zinc-900 dark:text-white">Partnership program</h2>
                            <span class="inline-flex items-center rounded-[5px] bg-[#26358c] px-1.5 py-0.5 text-[10px] font-bold uppercase tracking-wide text-white">New</span>
                        </div>
                        <p class="mt-1 text-sm text-zinc-600 dark:text-zinc-300">Share {{ $siteName }} with your audience. Tell us where you publish and how you would like to work together, and our team will review it and get back to you.</p>
                    </div>
                </div>

                {{-- The application form itself, not a button to it. This was a
                     link that sent a signed-in customer out to the storefront
                     page to start over; the form is the thing they came for, so
                     the same body the storefront renders is included here. --}}
                <div class="mt-5">
                    @include('shop._inquiry-form-body', [
                        'kind' => 'affiliate',
                        'title' => 'Partnership program',
                        'icon' => 'for-partners.svg',
                        'formTitle' => 'Join us today',
                        'submitLabel' => 'Submit application',
                        'intro' => 'Tell us about your channel, who follows you and the kind of collaboration you have in mind, and our team will review it and get back to you.',
                        'postRoute' => 'affiliates.send',
                        'steps' => [
                            'Send us your details',
                            'We review and get back to you',
                        ],
                        'benefits' => [
                            'No commitment required',
                            'Free to join',
                            'Quick and simple process',
                        ],
                        'categories' => ['Instagram', 'TikTok', 'YouTube', 'X (Twitter)', 'Facebook', 'Snapchat', 'WhatsApp or Telegram channel', 'Blog or website', 'Other'],
                        'emailLabel' => 'Email',
                        'emailPlaceholder' => 'you@example.com',
                        'companyLabel' => 'Brand, channel or handle',
                        'companyPlaceholder' => '@yourhandle',
                        'urlLabel' => 'Profile or website link',
                        'roleLabel' => 'Audience size',
                        'rolePlaceholder' => 'e.g. 25,000 followers',
                        'categoryLabel' => 'Main platform',
                        'messagePlaceholder' => 'Tell us about your audience and how you would like to collaborate',
                    ])
                </div>
            </section>
        </div>
    </div>
</x-layouts.dashboard>

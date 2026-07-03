<div>
@php
    use App\Domain\Admin\Queries\DashboardMetricsQuery;

    /** @var DashboardMetricsQuery $dashboardQuery */
    $dashboardQuery = app(DashboardMetricsQuery::class);

    // KPI cards - filtered by the top-right date range picker. Reads from
    // the Livewire component's public properties (hydrated from query
    // string on mount). Filter clicks call wire:click handlers on the
    // component so subsequent updates skip the lazy placeholder.
    $rangePreset = $this->rangePreset;
    $rangeStart  = $this->rangeStart;
    $rangeEnd    = $this->rangeEnd;

    $metrics = $dashboardQuery->getOverviewMetrics($rangePreset, $rangeStart, $rangeEnd);
    $totalUsers        = (int)   $metrics['total_users'];
    $totalOrders       = (int)   $metrics['total_orders'];
    $totalRevenue      = (float) $metrics['total_revenue'];
    $totalTransactions = (int)   $metrics['transactions_count'];
    $successRate       = (float) $metrics['success_rate'];
    $walletBalanceTotal = (float) $metrics['wallet_balance_total'];
    $donuts            = (array) ($metrics['donuts'] ?? []);

    // ── Tables ─────────────────────────────────────────────────────────
    $latestUsers        = $dashboardQuery->getLatestUsers(5)->items();
    $latestTransactions = $dashboardQuery->getLatestTransactions(5)->items();

    // ── Trends chart series — `?revenue_days=N` (1-365) ──────────────
    // Sales / Cost timeseries rendered by ApexCharts in the browser. The
    // period dropdown at the bottom of the chart picks a preset and SPA
    // navigates with a fresh `?revenue_days=`, so the server re-aggregates.
    // "This Month" / "This Year" are dynamic (today's day-of-month / -year);
    // raw day counts not matching a preset fall back to "Last N Days".
    $revenueDays = $this->revenueDays;
    $salesCostSeries = $dashboardQuery->getSalesCostTimeseries($revenueDays);
    $pointCount = count($salesCostSeries);

    $trendsPeriods = [
        ['label' => 'This Week',    'days' => 7],
        ['label' => 'This Month',   'days' => max(1, now()->day)],
        ['label' => 'Last 30 Days', 'days' => 30],
        ['label' => 'Last 90 Days', 'days' => 90],
        ['label' => 'This Year',    'days' => max(1, now()->dayOfYear)],
    ];
    $currentTrendsLabel = (collect($trendsPeriods)->firstWhere('days', $revenueDays))['label']
        ?? "Last {$revenueDays} Days";

    // ── Best Selling Countries map ──────────────────────────────────
    // `?country_days=` drives the rolling window; `?country_cat=` narrows to
    // a product category (esim | gift | topup); the Country / Region toggle
    // is client-side (re-shades the same world map). The map widget pulls
    // ISO → continent from config/continents.php.
    $countryDays = $this->countryDays;
    $countryCategory = $this->countryCategory;
    $countriesByCode = $dashboardQuery->getBestSellingCountries($countryDays, $countryCategory);

    $codeToContinent = (array) config('continents.codes', []);
    $salesByRegion = [];
    foreach ($countriesByCode as $cc => $total) {
        $region = $codeToContinent[$cc] ?? null;
        if ($region === null) {
            continue;
        }
        $salesByRegion[$region] = ($salesByRegion[$region] ?? 0) + (float) $total;
    }

    $countryPeriods = [
        ['label' => 'Today',        'days' => 1],
        ['label' => 'This Week',    'days' => 7],
        ['label' => 'Last 30 Days', 'days' => 30],
        ['label' => 'This Year',    'days' => max(1, now()->dayOfYear)],
    ];
    $currentCountryPeriod = (collect($countryPeriods)->firstWhere('days', $countryDays))['label']
        ?? "Last {$countryDays} Days";

    $countryCategories = [
        ['label' => 'All Products',  'key' => 'all'],
        ['label' => 'eSIMs',         'key' => 'esim'],
        ['label' => 'Gift Cards',    'key' => 'gift'],
        ['label' => 'Mobile Top-up', 'key' => 'topup'],
    ];
    $currentCountryCategory = (collect($countryCategories)->firstWhere('key', $countryCategory))['label']
        ?? 'All Products';

    // KPI card definitions live in the SAME PHP block as the data above.
    // Splitting into a second top-level data block, or mentioning the literal
    // at-directive token (e.g. at-php) inside a Blade comment, makes Blade's
    // raw-text scanner mis-pair tokens and silently swallow the surrounding
    // markup along with the next directive in the lazy-render output.
    //
    // The donut target tier is computed inline via collect()->first() (a
    // self-invoking closure with a foreach inside used to live here but
    // confused Blade's brace counting during the lazy hydration POST).
    $userTiers = [100, 250, 500, 1000, 2500, 5000, 10000, 25000, 50000, 100000, 250000, 500000, 1000000];
    $userTarget = collect($userTiers)->first(fn ($t) => $totalUsers < $t) ?? end($userTiers);

    $kpiCards = [
        [
            'label' => 'Total Users',
            'value' => number_format($totalUsers),
            'sub' => 'All-time registered',
            'tone' => 'bg-blue-200',
            'icon' => 'trusted by millions.svg',
            'span' => false,
            'donut' => [
                'pct'     => min(100.0, ($totalUsers / $userTarget) * 100),
                'color'   => '#0044FF',
                'caption' => 'of '.number_format($userTarget),
            ],
        ],
        [
            'label' => 'Total Orders',
            'value' => number_format($totalOrders),
            'sub' => 'Across all time',
            'tone' => 'bg-orange-200',
            'icon' => 'total orders.svg',
            'span' => false,
            'donut' => ['pct' => (float) ($donuts['completed_orders_pct'] ?? 0), 'color' => '#f97316', 'caption' => 'Completed'],
        ],
        [
            'label' => 'Total Revenue',
            'value' => '$'.number_format($totalRevenue, 2),
            'sub' => 'Completed orders only',
            'tone' => 'bg-emerald-200',
            'icon' => 'total revenue.svg',
            'span' => false,
            'donut' => ['pct' => (float) ($donuts['markup_share_pct'] ?? 0), 'color' => '#10b981', 'caption' => 'Markup'],
        ],
        [
            'label' => 'Total Transactions',
            'value' => number_format($totalTransactions),
            'sub' => 'Payments + wallet activity',
            'tone' => 'bg-amber-200',
            'icon' => 'total transactions.svg',
            'span' => false,
            'donut' => ['pct' => (float) ($donuts['transactions_success_pct'] ?? 0), 'color' => '#f59e0b', 'caption' => 'Success'],
        ],
        [
            'label' => 'Success Rate',
            'value' => number_format($successRate, 2).'%',
            'sub' => 'Completed / total payments',
            'tone' => 'bg-pink-200',
            'icon' => 'Success rate.svg',
            'span' => true,
            'donut' => ['pct' => (float) ($donuts['success_rate_pct'] ?? 0), 'color' => '#ec4899', 'caption' => 'Rate'],
        ],
    ];
@endphp

{{-- Body of the lazy-loaded admin dashboard. The heavy data block above
     only runs when this component lazy-boots, so the initial page response
     is fast and the skeleton placeholder paints instantly. The shell view
     (admin/dashboard.blade.php) owns the layout wrapper + page-level styles.
     IMPORTANT: do NOT mention directive names like the at-php / at-include /
     at-endphp keywords inside Blade comments - Blade scans the raw template
     text for those tokens and will treat a comment mention as a real
     directive open, eating every line between it and the next matching
     close tag. --}}
<div class="flex flex-1 flex-col gap-4 sm:gap-6">

        {{-- ─── KPI cards (real data) ────────────────────────────────── --}}
        <div>
            {{-- Previously had a livewire:navigate skeleton overlay here. Removed:
                 admin pages run as full page loads (per 2026-05-16 sidebar fix), so
                 `livewire:navigated` didn't reliably fire to hide the overlay —
                 left grey skeletons sitting on top of the already-rendered text. --}}

        {{-- KPI grid. 2-col on mobile (tighter, denser), 5-col on desktop.
             The 5th card (Success Rate) spans both columns on mobile so the trailing card
             doesn't sit alone in a half-row. --}}
        <div class="grid grid-cols-1 gap-3 sm:grid-cols-2 sm:gap-4 md:grid-cols-3 lg:grid-cols-5">

            {{-- $kpiCards is computed in the top-level data block above. --}}

            @foreach ($kpiCards as $kpi)
                @php $isSuccessRate = $kpi['label'] === 'Success Rate'; @endphp
                <div class="kpi-card relative flex flex-col overflow-hidden rounded-[20px] bg-white p-4 shadow-sm shadow-zinc-900/5 ring-1 ring-zinc-100 sm:p-5 {{ $kpi['span'] ? 'sm:col-span-2 md:col-span-3 lg:col-span-1' : '' }}">
                    {{-- Success Rate keeps its floating illustration (no donut). --}}
                    @if ($isSuccessRate)
                        <img
                            src="{{ asset('assets/' . rawurlencode('success rates admin.svg')) }}"
                            alt=""
                            aria-hidden="true"
                            loading="lazy"
                            class="no-dark-invert pointer-events-none absolute right-8 top-1/2 h-28 w-28 -translate-y-1/2 select-none object-contain opacity-90 animate-float sm:right-10 sm:h-32 sm:w-32 lg:right-6 lg:h-28 lg:w-28"
                        >
                    @endif

                    <div class="relative z-10 flex flex-1 flex-col {{ $isSuccessRate ? 'pr-24 sm:pr-32' : '' }}">
                        {{-- Top row: tile icon (left) + animated mini-donut (right).
                             Donut is suppressed on Success Rate — that card keeps
                             its decorative illustration instead. --}}
                        <div class="flex items-start justify-between gap-2">
                            <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-[12px] {{ $kpi['tone'] }} sm:h-11 sm:w-11">
                                {{-- no-dark-invert keeps the small emoji-style icon in its
                                     original dark artwork (against the pastel tile) instead
                                     of being flipped white by the blanket dark-mode SVG
                                     invert in app.css. --}}
                                <img src="{{ asset('assets/' . rawurlencode($kpi['icon'])) }}" alt="" class="no-dark-invert h-5 w-5 sm:h-6 sm:w-6" loading="lazy">
                            </span>
                            @unless ($isSuccessRate)
                                <x-mini-donut
                                    :percent="$kpi['donut']['pct']"
                                    :color="$kpi['donut']['color']"
                                    :size="48"
                                    :stroke="6"
                                />
                            @endunless
                        </div>
                        {{-- `.kpi-label` / `.kpi-sub` get their dark-mode colour from the
                             inline <style> block at the top of this view — bypasses the
                             Tailwind dark variant which couldn't beat app.css's broader
                             `.dark .text-zinc-600` remap. --}}
                        <p class="kpi-label mt-3 text-xs font-medium text-zinc-600 sm:text-sm">{{ $kpi['label'] }}</p>
                        <p class="mt-0.5 text-xl font-bold tracking-tight text-zinc-900 sm:text-2xl">{{ $kpi['value'] }}</p>
                        <p class="kpi-sub mt-auto pt-3 text-[11px] text-zinc-600 sm:text-xs">
                            {{ $kpi['sub'] }}@unless ($isSuccessRate) · <span class="font-semibold">{{ number_format($kpi['donut']['pct'], 1) }}% {{ $kpi['donut']['caption'] }}</span>@endunless
                        </p>
                    </div>
                </div>
            @endforeach

        </div>
        </div> {{-- /skeleton-wrap KPIs --}}

        {{-- ─── Charts row (placeholder UI — no aggregation endpoints yet) ─ --}}
        <div class="grid grid-cols-1 gap-4 xl:grid-cols-2">

            {{-- Best Selling Countries — jsvectormap world map shaded by sales.
                 Footer carries the Period + Product filters (SPA-navigate the
                 server for fresh aggregates); the Country / Region toggle is
                 a client-side repaint via the Alpine factory. --}}
            <div
                x-data="bestSellingCountriesMap({
                    countries: @js($countriesByCode),
                    regions: @js($salesByRegion),
                    codeToContinent: @js($codeToContinent),
                })"
                x-on:map-data-updated.window="updateData($event.detail)"
                class="flex min-w-0 flex-col overflow-hidden rounded-[20px] bg-white p-5 shadow-sm shadow-zinc-900/5 ring-1 ring-zinc-100 dark:bg-[#1d3252] dark:ring-zinc-700/60"
            >
                {{-- Header: title (left), continent scope dropdown (right).
                     Picking a continent filters which countries get shaded on
                     the map — client-side via the Alpine factory's setContinent(). --}}
                <div class="flex items-start justify-between gap-3">
                    <h2 class="text-base font-semibold text-zinc-900 dark:text-white">Best Selling Countries</h2>
                    <div x-data="{ open: false }" @click.outside="open = false" @keydown.escape.window="open = false" class="relative">
                        <button
                            type="button"
                            @click="open = ! open"
                            :aria-expanded="open.toString()"
                            class="flex items-center gap-1.5 rounded-[12px] border border-zinc-200 px-2.5 py-1 text-xs font-medium text-zinc-600 transition-colors hover:bg-zinc-50 dark:border-zinc-700/60 dark:text-zinc-300 dark:hover:bg-[#26416b]"
                        >
                            <span x-text="continent === 'all' ? 'Global' : continent"></span>
                            <svg class="h-3 w-3 transition-transform" :class="open && 'rotate-180'" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5" aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7"/>
                            </svg>
                        </button>
                        <div
                            x-show="open"
                            x-transition:enter="transition ease-out duration-150" x-transition:enter-start="opacity-0 -translate-y-1" x-transition:enter-end="opacity-100 translate-y-0"
                            style="display:none;"
                            class="absolute right-0 top-full z-30 mt-2 w-44 overflow-hidden rounded-[12px] bg-white p-1.5 shadow-xl shadow-zinc-900/10 ring-1 ring-zinc-200 dark:bg-[#1d3252] dark:ring-zinc-700/60"
                            role="menu"
                        >
                            @php
                                $continentOptions = [
                                    ['key' => 'all',           'label' => 'Global'],
                                    ['key' => 'Africa',        'label' => 'Africa'],
                                    ['key' => 'Asia',          'label' => 'Asia'],
                                    ['key' => 'Europe',        'label' => 'Europe'],
                                    ['key' => 'North America', 'label' => 'North America'],
                                    ['key' => 'South America', 'label' => 'South America'],
                                    ['key' => 'Oceania',       'label' => 'Oceania'],
                                ];
                            @endphp
                            @foreach ($continentOptions as $opt)
                                <button
                                    type="button"
                                    @click="setContinent(@js($opt['key'])); open = false"
                                    :class="continent === @js($opt['key']) ? 'bg-blue-50 text-blue-700 dark:bg-blue-600/15 dark:text-blue-300' : 'text-zinc-700 hover:bg-zinc-50 dark:text-zinc-200 dark:hover:bg-[#26416b]'"
                                    class="flex w-full items-center justify-between rounded-[12px] px-3 py-1.5 text-left text-xs font-medium transition-colors"
                                >
                                    <span>{{ $opt['label'] }}</span>
                                    <svg x-show="continent === @js($opt['key'])" class="h-3.5 w-3.5 text-blue-600 dark:text-blue-300" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5" aria-hidden="true">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5"/>
                                    </svg>
                                </button>
                            @endforeach
                        </div>
                    </div>
                </div>

                {{-- Map canvas. jsvectormap injects raw SVG inside x-ref="map"
                     once init() resolves. Livewire would otherwise wipe that
                     SVG on every component re-render (filter clicks), so the
                     whole block is wire:ignore and stays mounted permanently -
                     the Alpine factory re-shades it from the data pushed via
                     the map-data-updated browser event, and the empty state is
                     a client-side overlay (a server-rendered swap here used to
                     destroy the SVG the moment a filter hit an empty window,
                     leaving a dead canvas until a full reload). --}}
                <div wire:ignore class="relative mt-2 w-full max-w-full flex-1 overflow-hidden" style="min-height: 320px;">
                    <div x-ref="map" class="h-full w-full" style="min-height: 320px;"></div>
                    <div
                        x-show="isEmpty()"
                        x-cloak
                        class="absolute inset-0 z-10 flex items-center justify-center rounded-[12px] bg-zinc-50/95 text-sm text-zinc-600 dark:bg-[#26416b]/95 dark:text-zinc-400"
                    >
                        No completed orders in this period yet.
                    </div>
                </div>

                {{-- Footer: Period dropdown + Product dropdown (server-side),
                     Country / Region toggle (client-side repaint). --}}
                <div class="mt-3 flex flex-wrap items-center justify-between gap-2">
                    <div class="flex items-center gap-2">
                        {{-- Period --}}
                        <div x-data="{ open: false }" @click.outside="open = false" @keydown.escape.window="open = false" class="relative">
                            <button
                                type="button"
                                @click="open = ! open"
                                :aria-expanded="open.toString()"
                                class="flex items-center gap-2 rounded-[12px] px-2.5 py-1.5 text-xs font-medium text-zinc-600 transition-colors hover:bg-zinc-50 dark:text-zinc-300 dark:hover:bg-[#26416b]"
                            >
                                <span>{{ $currentCountryPeriod }}</span>
                                <svg class="h-3 w-3 transition-transform" :class="open && 'rotate-180'" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5" aria-hidden="true">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7"/>
                                </svg>
                            </button>
                            <div
                                x-show="open"
                                x-transition:enter="transition ease-out duration-150" x-transition:enter-start="opacity-0 -translate-y-1" x-transition:enter-end="opacity-100 translate-y-0"
                                style="display:none;"
                                class="absolute left-0 bottom-full z-30 mb-2 w-40 overflow-hidden rounded-[12px] bg-white p-1.5 shadow-xl shadow-zinc-900/10 ring-1 ring-zinc-200 dark:bg-[#1d3252] dark:ring-zinc-700/60"
                                role="menu"
                            >
                                @foreach ($countryPeriods as $p)
                                    <button
                                        type="button"
                                        wire:click="setCountryDays({{ $p['days'] }})"
                                        @click="open = false"
                                        @class([
                                            'flex w-full items-center justify-between rounded-[12px] px-3 py-1.5 text-left text-xs font-medium transition-colors',
                                            'bg-blue-50 text-blue-700 dark:bg-blue-600/15 dark:text-blue-300' => $currentCountryPeriod === $p['label'],
                                            'text-zinc-700 hover:bg-zinc-50 dark:text-zinc-200 dark:hover:bg-[#26416b]' => $currentCountryPeriod !== $p['label'],
                                        ])
                                    >
                                        {{ $p['label'] }}
                                        @if ($currentCountryPeriod === $p['label'])
                                            <svg class="h-3.5 w-3.5 text-blue-600 dark:text-blue-300" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5" aria-hidden="true">
                                                <path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5"/>
                                            </svg>
                                        @endif
                                    </button>
                                @endforeach
                            </div>
                        </div>

                        {{-- Product category --}}
                        <div x-data="{ open: false }" @click.outside="open = false" @keydown.escape.window="open = false" class="relative">
                            <button
                                type="button"
                                @click="open = ! open"
                                :aria-expanded="open.toString()"
                                class="flex items-center gap-2 rounded-[12px] px-2.5 py-1.5 text-xs font-medium text-zinc-600 transition-colors hover:bg-zinc-50 dark:text-zinc-300 dark:hover:bg-[#26416b]"
                            >
                                <span>{{ $currentCountryCategory }}</span>
                                <svg class="h-3 w-3 transition-transform" :class="open && 'rotate-180'" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5" aria-hidden="true">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7"/>
                                </svg>
                            </button>
                            <div
                                x-show="open"
                                x-transition:enter="transition ease-out duration-150" x-transition:enter-start="opacity-0 -translate-y-1" x-transition:enter-end="opacity-100 translate-y-0"
                                style="display:none;"
                                class="absolute left-0 bottom-full z-30 mb-2 w-44 overflow-hidden rounded-[12px] bg-white p-1.5 shadow-xl shadow-zinc-900/10 ring-1 ring-zinc-200 dark:bg-[#1d3252] dark:ring-zinc-700/60"
                                role="menu"
                            >
                                @foreach ($countryCategories as $cat)
                                    <button
                                        type="button"
                                        wire:click="setCountryCategory({{ '\''.$cat['key'].'\'' }})"
                                        @click="open = false"
                                        @class([
                                            'flex w-full items-center justify-between rounded-[12px] px-3 py-1.5 text-left text-xs font-medium transition-colors',
                                            'bg-blue-50 text-blue-700 dark:bg-blue-600/15 dark:text-blue-300' => $currentCountryCategory === $cat['label'],
                                            'text-zinc-700 hover:bg-zinc-50 dark:text-zinc-200 dark:hover:bg-[#26416b]' => $currentCountryCategory !== $cat['label'],
                                        ])
                                    >
                                        {{ $cat['label'] }}
                                        @if ($currentCountryCategory === $cat['label'])
                                            <svg class="h-3.5 w-3.5 text-blue-600 dark:text-blue-300" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5" aria-hidden="true">
                                                <path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5"/>
                                            </svg>
                                        @endif
                                    </button>
                                @endforeach
                            </div>
                        </div>
                    </div>

                    {{-- Country / Region toggle — client-side, repaints the map. --}}
                    <div class="flex items-center gap-1 rounded-[12px] bg-zinc-100 p-1 dark:bg-[#26416b]">
                        <button type="button" @click="setView('country')" :class="view === 'country' ? 'bg-blue-600 text-white' : 'text-zinc-700 dark:text-zinc-200'" class="rounded-[12px] px-3 py-1 text-xs font-semibold transition-colors">Country</button>
                        <button type="button" @click="setView('region')"  :class="view === 'region'  ? 'bg-blue-600 text-white' : 'text-zinc-700 dark:text-zinc-200'" class="rounded-[12px] px-3 py-1 text-xs font-semibold transition-colors">Region</button>
                    </div>
                </div>
            </div>

            {{-- Trends chart — smooth sales/cost area chart via ApexCharts.
                 Alpine factory `salesCostChart` (resources/js/app.js) lazy-
                 imports ApexCharts on init so it doesn't ship to pages that
                 don't render a chart. Don't add x-init="init()" — Alpine
                 auto-runs init() and explicitly calling it again duplicates
                 the chart. --}}
            <div
                x-data="salesCostChart(@js($salesCostSeries))"
                x-on:trends-data-updated.window="updateData($event.detail)"
                class="min-w-0 overflow-hidden rounded-[20px] bg-white p-5 shadow-sm shadow-zinc-900/5 ring-1 ring-zinc-100 dark:bg-[#1d3252] dark:ring-zinc-700/60"
            >
                {{-- Header: title + squiggle legend (left), Sales/Cost dropdown (right) --}}
                <div class="flex items-start justify-between gap-3">
                    <div class="flex flex-wrap items-center gap-x-4 gap-y-1">
                        <h2 class="text-base font-semibold text-zinc-900 dark:text-white">Trends</h2>
                        <div class="flex items-center gap-3 text-xs font-medium text-zinc-600 dark:text-zinc-300">
                            <span class="flex items-center gap-1.5">
                                <svg viewBox="0 0 24 8" class="h-2 w-6 text-emerald-400" fill="none" aria-hidden="true">
                                    <path d="M1 4 Q 4 0, 7 4 T 13 4 T 19 4 T 23 4" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/>
                                </svg>
                                Sales
                            </span>
                            <span class="flex items-center gap-1.5">
                                <svg viewBox="0 0 24 8" class="h-2 w-6 text-blue-400" fill="none" aria-hidden="true">
                                    <path d="M1 4 Q 4 0, 7 4 T 13 4 T 19 4 T 23 4" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/>
                                </svg>
                                Cost
                            </span>
                        </div>
                    </div>

                    {{-- Sales / Cost selector — client-side series toggle. Drives the
                         chart instance via salesCostChart.setMode(); no server round-trip. --}}
                    <div x-data="{ open: false }" @click.outside="open = false" @keydown.escape.window="open = false" class="relative shrink-0">
                        <button
                            type="button"
                            @click="open = ! open"
                            :aria-expanded="open.toString()"
                            class="flex items-center gap-2 rounded-[12px] border border-zinc-200 bg-white px-3 py-1.5 text-xs font-medium text-zinc-700 transition-colors hover:bg-zinc-50 dark:border-zinc-700/60 dark:bg-[#26416b] dark:text-white dark:hover:bg-[#34507a]"
                        >
                            <span x-text="modeLabel()">Sales / Cost</span>
                            <svg class="h-3 w-3 transition-transform" :class="open && 'rotate-180'" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5" aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7"/>
                            </svg>
                        </button>

                        <div
                            x-show="open"
                            x-transition:enter="transition ease-out duration-150" x-transition:enter-start="opacity-0 -translate-y-1" x-transition:enter-end="opacity-100 translate-y-0"
                            x-transition:leave="transition ease-in duration-100"   x-transition:leave-start="opacity-100 translate-y-0"  x-transition:leave-end="opacity-0 -translate-y-1"
                            style="display:none;"
                            class="absolute right-0 top-full z-30 mt-2 w-44 overflow-hidden rounded-[12px] bg-white p-1.5 shadow-xl shadow-zinc-900/10 ring-1 ring-zinc-200 dark:bg-[#1d3252] dark:ring-zinc-700/60"
                            role="menu"
                        >
                            @foreach ([['both', 'Sales / Cost'], ['sales', 'Sales'], ['cost', 'Cost']] as [$key, $label])
                                <button
                                    type="button"
                                    @click="setMode('{{ $key }}'); open = false"
                                    :class="mode === '{{ $key }}' ? 'bg-blue-50 text-blue-700 dark:bg-blue-600/15 dark:text-blue-300' : 'text-zinc-700 hover:bg-zinc-50 dark:text-zinc-200 dark:hover:bg-[#26416b]'"
                                    class="flex w-full items-center justify-between rounded-[12px] px-3 py-1.5 text-left text-xs font-medium transition-colors"
                                >
                                    {{ $label }}
                                    <svg x-show="mode === '{{ $key }}'" class="h-3.5 w-3.5 text-blue-600 dark:text-blue-300" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5" aria-hidden="true">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5"/>
                                    </svg>
                                </button>
                            @endforeach
                        </div>
                    </div>
                </div>

                {{-- Chart canvas. ApexCharts renders into x-ref="canvas" once
                     init() resolves (lazy-imports the lib on demand). The block
                     is wire:ignore so Livewire's morph never wipes the Apex SVG
                     when the period filter re-renders the component - fresh
                     series arrive via the trends-data-updated browser event and
                     the empty state is a client-side overlay. --}}
                <div wire:ignore class="relative">
                    <div x-ref="canvas" class="mt-2 min-h-[320px]"></div>
                    <div
                        x-show="isEmpty()"
                        x-cloak
                        class="absolute inset-0 z-10 mt-2 flex items-center justify-center rounded-[12px] bg-zinc-50/95 text-sm text-zinc-600 dark:bg-[#26416b]/95 dark:text-zinc-400"
                    >
                        No completed orders in this period yet.
                    </div>
                </div>

                {{-- Footer: period dropdown — SPA-navigates with ?revenue_days=N so
                     the server re-aggregates the window. Currently selected period
                     mirrors the URL (the PHP block above computes $currentTrendsLabel). --}}
                <div class="mt-3 flex items-center">
                    <div x-data="{ open: false }" @click.outside="open = false" @keydown.escape.window="open = false" class="relative">
                        <button
                            type="button"
                            @click="open = ! open"
                            :aria-expanded="open.toString()"
                            class="flex items-center gap-2 rounded-[12px] px-2.5 py-1.5 text-xs font-medium text-zinc-600 transition-colors hover:bg-zinc-50 dark:text-zinc-300 dark:hover:bg-[#26416b]"
                        >
                            <span>{{ $currentTrendsLabel }}</span>
                            <svg class="h-3 w-3 transition-transform" :class="open && 'rotate-180'" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5" aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7"/>
                            </svg>
                        </button>

                        <div
                            x-show="open"
                            x-transition:enter="transition ease-out duration-150" x-transition:enter-start="opacity-0 -translate-y-1" x-transition:enter-end="opacity-100 translate-y-0"
                            x-transition:leave="transition ease-in duration-100"   x-transition:leave-start="opacity-100 translate-y-0"  x-transition:leave-end="opacity-0 -translate-y-1"
                            style="display:none;"
                            class="absolute left-0 bottom-full z-30 mb-2 w-40 overflow-hidden rounded-[12px] bg-white p-1.5 shadow-xl shadow-zinc-900/10 ring-1 ring-zinc-200 dark:bg-[#1d3252] dark:ring-zinc-700/60"
                            role="menu"
                        >
                            @foreach ($trendsPeriods as $p)
                                <button
                                    type="button"
                                    wire:click="setRevenueDays({{ $p['days'] }})"
                                    @click="open = false"
                                    @class([
                                        'flex w-full items-center justify-between rounded-[12px] px-3 py-1.5 text-left text-xs font-medium transition-colors',
                                        'bg-blue-50 text-blue-700 dark:bg-blue-600/15 dark:text-blue-300' => $currentTrendsLabel === $p['label'],
                                        'text-zinc-700 hover:bg-zinc-50 dark:text-zinc-200 dark:hover:bg-[#26416b]' => $currentTrendsLabel !== $p['label'],
                                    ])
                                >
                                    {{ $p['label'] }}
                                    @if ($currentTrendsLabel === $p['label'])
                                        <svg class="h-3.5 w-3.5 text-blue-600 dark:text-blue-300" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5" aria-hidden="true">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5"/>
                                        </svg>
                                    @endif
                                </button>
                            @endforeach
                        </div>
                    </div>
                </div>
            </div>

        </div>

        {{-- ─── Tables row (real data) ──────────────────────────────── --}}
        <div>
            {{-- Skeleton overlay removed — same reason as the KPI overlay above.
                 The tables render with the page; no transitional placeholder needed. --}}
        <div class="grid grid-cols-1 gap-4 xl:grid-cols-2">

            {{-- Latest Users --}}
            <div class="min-w-0 overflow-hidden rounded-[20px] bg-white shadow-sm shadow-zinc-900/5 ring-1 ring-zinc-100">
                <div class="flex items-center justify-between border-b border-zinc-200 p-5">
                    <div class="flex items-center gap-2">
                        <img src="{{ asset('assets/' . rawurlencode('user.svg')) }}" alt="" class="h-5 w-5" loading="lazy">
                        <h2 class="text-base font-semibold text-zinc-900">Latest Users</h2>
                    </div>
                    <a href="{{ route('admin.customers') }}" class="rounded-[12px] border border-zinc-200 bg-white px-3 py-1 text-xs font-medium text-zinc-600 transition-colors hover:bg-zinc-50">View All</a>
                </div>

                <div class="overflow-x-auto">
                    <table class="inset-divide w-full text-left text-[11px]">
                        <thead class="bg-zinc-50 text-[10px] uppercase tracking-wider text-zinc-600">
                            <tr>
                                <th class="px-5 py-3 font-semibold">User</th>
                                <th class="px-5 py-3 font-semibold">Status</th>
                                <th class="px-5 py-3 font-semibold">Registered</th>
                                <th class="px-5 py-3 text-right font-semibold">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($latestUsers as $user)
                                <tr>
                                    <td class="px-5 py-3">
                                        <div class="flex items-center gap-3">
                                            @php
                                                $rowAvatar = $user->avatar_url ?: $user->initialsAvatar();
                                            @endphp
                                            <img src="{{ $rowAvatar }}" alt="" class="h-9 w-9 shrink-0 rounded-[12px] object-cover ring-1 ring-blue-100">
                                            <div class="leading-tight">
                                                <p class="text-[11px] font-semibold text-zinc-900">{{ $user->name }}</p>
                                                <p class="text-[10px] text-zinc-600">{{ $user->email }}</p>
                                            </div>
                                        </div>
                                    </td>
                                    <td class="px-5 py-3">
                                        @php
                                            // Same badge logic as the Customers list page so the dashboard
                                            // and list view never disagree on a user's status.
                                            $userStatus = match (true) {
                                                $user->banned_at !== null => ['label' => 'Banned', 'tone' => 'red'],
                                                $user->suspended_at !== null => ['label' => 'Suspended', 'tone' => 'amber'],
                                                $user->email_verified_at === null => ['label' => 'Pending', 'tone' => 'amber'],
                                                default => ['label' => 'Active', 'tone' => 'emerald'],
                                            };
                                        @endphp
                                        <x-admin.badge :tone="$userStatus['tone']">{{ $userStatus['label'] }}</x-admin.badge>
                                    </td>
                                    <td class="px-5 py-3 text-[11px] text-zinc-600">{{ $user->created_at->format('M j, Y') }}</td>
                                    <td class="px-5 py-3 text-right">
                                        <a href="{{ route('admin.customers') }}" class="inline-flex items-center rounded-[12px] border border-zinc-200 bg-white px-3 py-1 text-xs font-medium text-zinc-600 transition-colors hover:bg-zinc-50">View</a>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="4" class="px-5 py-12 text-center text-sm text-zinc-600">No users yet.</td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>

            {{-- Latest Transactions --}}
            <div class="min-w-0 overflow-hidden rounded-[20px] bg-white shadow-sm shadow-zinc-900/5 ring-1 ring-zinc-100 dark:bg-[#1d3252] dark:ring-zinc-700/60">
                <div class="flex items-center justify-between border-b border-zinc-200 p-5 dark:border-zinc-700/60">
                    <div class="flex items-center gap-2">
                        <img src="{{ asset('assets/' . rawurlencode('Latest transactions.webp')) }}" alt="" class="h-5 w-5 dark:invert dark:brightness-200" loading="lazy">
                        <h2 class="text-base font-semibold text-zinc-900 dark:text-white">Latest Transactions</h2>
                    </div>
                    <a href="{{ route('admin.transactions') }}" class="rounded-[12px] border border-zinc-200 bg-white px-3 py-1 text-xs font-medium text-zinc-600 transition-colors hover:bg-zinc-50 dark:border-zinc-700/60 dark:bg-[#26416b] dark:text-zinc-200 dark:hover:bg-[#34507a]">View All</a>
                </div>

                <div class="overflow-x-auto">
                    <table class="inset-divide w-full text-left text-[11px]">
                        <thead class="bg-zinc-50 text-[10px] uppercase tracking-wider text-zinc-600">
                            <tr>
                                <th class="px-5 py-3 font-semibold">Reference</th>
                                <th class="px-5 py-3 font-semibold">Customer</th>
                                <th class="px-5 py-3 font-semibold">Type</th>
                                <th class="px-5 py-3 font-semibold">Amount</th>
                                <th class="px-5 py-3 font-semibold">Status</th>
                                <th class="px-5 py-3 font-semibold">Date</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($latestTransactions as $tx)
                                @php
                                    // Same badge palette as the Transactions list page so the
                                    // dashboard preview matches the full table 1:1.
                                    $statusValue = $tx->status ?? 'pending';
                                    $txStatus = match ($statusValue) {
                                        'paid', 'completed' => ['label' => 'Paid', 'class' => 'bg-emerald-50 text-emerald-700 ring-emerald-200 dark:bg-emerald-500/15 dark:text-emerald-300 dark:ring-emerald-500/30'],
                                        'failed', 'expired', 'cancelled' => ['label' => 'Failed', 'class' => 'bg-red-50 text-red-700 ring-red-200 dark:bg-red-500/15 dark:text-red-300 dark:ring-red-500/30'],
                                        'refunded', 'partially_refunded' => ['label' => 'Refunded', 'class' => 'bg-zinc-100 text-zinc-700 ring-zinc-200 dark:bg-white/5 dark:text-zinc-300 dark:ring-zinc-700/60'],
                                        'processing', 'reserved' => ['label' => 'Processing', 'class' => 'bg-blue-50 text-blue-700 ring-blue-200 dark:bg-blue-600/15 dark:text-blue-300 dark:ring-blue-500/30'],
                                        default => ['label' => 'Pending', 'class' => 'bg-amber-50 text-amber-700 ring-amber-200 dark:bg-amber-500/15 dark:text-amber-300 dark:ring-amber-500/30'],
                                    };
                                    // Type badge — same ring style as status, with a category
                                    // tone (blue for payment, purple for wallet) so the two
                                    // pill columns don't fight visually.
                                    $isWalletTx = $tx->source === 'wallet_transaction';
                                    $typeLabel = $isWalletTx
                                        ? 'Wallet · ' . ucfirst(str_replace('_', ' ', (string) $tx->type))
                                        : 'Payment · ' . ucfirst((string) ($tx->gateway ?? 'gateway'));
                                    $typePillClass = $isWalletTx
                                        ? 'bg-purple-50 text-purple-700 ring-purple-200 dark:bg-purple-500/15 dark:text-purple-300 dark:ring-purple-500/30'
                                        : 'bg-blue-50 text-blue-700 ring-blue-200 dark:bg-blue-600/15 dark:text-blue-300 dark:ring-blue-500/30';
                                    $reference = $tx->reference ?: '#' . $tx->id;
                                @endphp
                                <tr>
                                    <td class="px-5 py-3 text-[11px] font-mono text-zinc-600">{{ $reference }}</td>
                                    <td class="px-5 py-3 text-[11px] font-medium text-zinc-900">{{ $tx->customer_name ?? '—' }}</td>
                                    <td class="px-5 py-3">
                                        <span class="inline-flex w-fit items-center whitespace-nowrap rounded-[5px] px-2.5 py-1 text-[10px] font-bold uppercase tracking-wide ring-1 {{ $typePillClass }}">
                                            {{ $typeLabel }}
                                        </span>
                                    </td>
                                    <td class="px-5 py-3 text-[11px] font-semibold text-zinc-900">{{ \App\Models\Product::currencySymbol($tx->currency ?? 'USD') }}{{ number_format((float) $tx->amount, 2) }}</td>
                                    <td class="px-5 py-3">
                                        <span class="inline-flex w-fit items-center whitespace-nowrap rounded-[5px] px-2.5 py-1 text-[10px] font-bold uppercase tracking-wide ring-1 {{ $txStatus['class'] }}">
                                            {{ $txStatus['label'] }}
                                        </span>
                                    </td>
                                    <td class="px-5 py-3 text-[11px] text-zinc-600">{{ \Illuminate\Support\Carbon::parse($tx->date)->format('M j, Y') }}</td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="6" class="px-5 py-12 text-center text-sm text-zinc-600">No transactions yet.</td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>

        </div>
        </div> {{-- /skeleton-wrap tables --}}

</div>
</div>

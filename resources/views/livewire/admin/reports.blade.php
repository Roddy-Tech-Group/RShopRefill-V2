<?php

use App\Domain\Admin\Queries\DashboardMetricsQuery;
use App\Models\Category;
use Illuminate\Support\Carbon;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;

new
#[Layout('components.layouts.admin')]
#[Title('Reports')]
class extends Component {
    // URL-bound filter state so a report can be shared via link / refreshed.
    #[Url(history: true)] public string $preset = 'week';        // today | week | month | quarter | year | custom
    #[Url(history: true)] public ?string $start = null;           // ISO date, used when preset = custom
    #[Url(history: true)] public ?string $end = null;
    #[Url(history: true)] public string $granularity = 'daily';   // daily | weekly | monthly
    #[Url(history: true)] public ?int $categoryId = null;         // null = all products
    #[Url(history: true)] public string $chartType = 'line';      // line | bar

    public function setPreset(string $preset): void
    {
        $this->preset = in_array($preset, ['today', 'week', 'month', 'quarter', 'year', 'custom'], true) ? $preset : 'week';
        if ($this->preset !== 'custom') {
            $this->start = null;
            $this->end = null;
        }
    }

    public function setGranularity(string $g): void
    {
        $this->granularity = in_array($g, ['daily', 'weekly', 'monthly'], true) ? $g : 'daily';
    }

    public function setCategory(?int $id): void
    {
        $this->categoryId = $id;
    }

    public function setChartType(string $t): void
    {
        $this->chartType = $t === 'bar' ? 'bar' : 'line';
    }

    /**
     * Resolve the active filter window. Custom dates fall back to "this week"
     * if either bound is missing or unparseable so the page never blows up on
     * bad query-string input.
     *
     * @return array{0: Carbon, 1: Carbon, 2: string}
     */
    public function resolvedRange(): array
    {
        if ($this->preset === 'custom' && $this->start && $this->end) {
            try {
                return [
                    Carbon::parse($this->start)->startOfDay(),
                    Carbon::parse($this->end)->endOfDay(),
                    'Custom range',
                ];
            } catch (\Throwable $e) {
                // fall through to week
            }
        }

        return match ($this->preset) {
            'today' => [now()->startOfDay(), now()->endOfDay(), 'Today'],
            'month' => [now()->startOfMonth(), now()->endOfDay(), 'This Month'],
            'quarter' => [now()->startOfQuarter(), now()->endOfDay(), 'This Quarter'],
            'year' => [now()->startOfYear(), now()->endOfDay(), 'This Year'],
            default => [now()->startOfWeek(), now()->endOfDay(), 'This Week'],
        };
    }

    #[Computed]
    public function categories()
    {
        return Category::orderBy('name')->get(['id', 'name', 'slug']);
    }

    #[Computed]
    public function series(): array
    {
        [$start, $end] = $this->resolvedRange();

        return app(DashboardMetricsQuery::class)
            ->getReportSeries($start, $end, $this->granularity, $this->categoryId);
    }

    /** Aggregate totals for the KPI strip. */
    #[Computed]
    public function totals(): array
    {
        $series = $this->series;
        $transactions = array_sum(array_column($series, 'transactions'));
        $sales = array_sum(array_column($series, 'sales_usd'));
        $cost = array_sum(array_column($series, 'cost_usd'));
        $profit = round($sales - $cost, 4);

        return [
            'transactions' => (int) $transactions,
            'sales' => round((float) $sales, 2),
            'cost' => round((float) $cost, 2),
            'profit' => round((float) $profit, 2),
            'profit_margin' => $sales > 0 ? round(($profit / $sales) * 100, 2) : 0.0,
            'avg_per_tx' => $transactions > 0 ? round($sales / $transactions, 2) : 0.0,
        ];
    }

    public function exportUrl(): string
    {
        return route('admin.reports.export', [
            'preset' => $this->preset,
            'start' => $this->start,
            'end' => $this->end,
            'granularity' => $this->granularity,
            'categoryId' => $this->categoryId,
        ]);
    }
}; ?>

<div class="w-full">

    @php
        [$rangeStart, $rangeEnd, $rangeLabel] = $this->resolvedRange();
        $presets = [
            'today' => 'Today',
            'week' => 'This Week',
            'month' => 'This Month',
            'quarter' => 'This Quarter',
            'year' => 'This Year',
        ];
        $granularities = ['daily' => 'Daily', 'weekly' => 'Weekly', 'monthly' => 'Monthly'];
        $activeCategoryLabel = $this->categoryId
            ? optional($this->categories->firstWhere('id', $this->categoryId))->name ?? 'All Products'
            : 'All Products';
    @endphp

    {{-- ── Filter card ─────────────────────────────────────────── --}}
    <section class="rounded-[12px] border-[1.5px] border-white bg-white p-5 shadow-sm shadow-zinc-900/[0.04] dark:border-white dark:bg-[#1d3252]">
        <header class="mb-4 flex items-center justify-between gap-3">
            <h2 class="text-sm font-bold text-zinc-900 dark:text-white">Sales Overview by Date</h2>
            <a href="{{ $this->exportUrl() }}" class="inline-flex items-center gap-1.5 rounded-[12px] bg-blue-50 px-3 py-1.5 text-[11px] font-semibold text-blue-700 ring-1 ring-blue-200 transition-colors hover:bg-blue-100 dark:bg-blue-600/15 dark:text-blue-300 dark:ring-blue-500/30 dark:hover:bg-blue-600/25">
                <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M3 16.5v2.25A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75V16.5M16.5 12L12 16.5m0 0L7.5 12m4.5 4.5V3"/>
                </svg>
                Export CSV
            </a>
        </header>

        {{-- Preset chips + custom range + granularity + category --}}
        {{-- Single segmented-control pill wrapping every filter: date presets,
             granularity, and product category. Each segment shares the same
             tone; only the active preset is filled (blue). The two dropdowns
             still pop out as floating menus from their slot inside the pill. --}}
        @php
            $segmentBase = 'inline-flex items-center gap-1.5 rounded-[8px] px-3 py-1 text-[12px] font-semibold transition-colors';
            $segmentInactive = 'text-zinc-600 hover:text-zinc-900 dark:text-zinc-300 dark:hover:text-white';
            $segmentActive = 'bg-blue-600 text-white shadow-sm';
        @endphp
        <div class="flex flex-wrap items-center gap-2">
            <div class="inline-flex flex-wrap items-center gap-0.5 rounded-[12px] bg-zinc-100 p-0.5 dark:bg-[#26416b]">
                @foreach ($presets as $key => $label)
                    @php $active = $preset === $key; @endphp
                    <button
                        type="button"
                        wire:click="setPreset('{{ $key }}')"
                        class="{{ $segmentBase }} {{ $active ? $segmentActive : $segmentInactive }}"
                    >{{ $label }}</button>
                @endforeach

                {{-- Granularity segment — dropdown trigger styled as an inactive chip. --}}
                <div x-data="{ open: false }" @click.outside="open = false" class="relative">
                    <button type="button" @click="open = ! open" class="{{ $segmentBase }} {{ $segmentInactive }}">
                        {{ $granularities[$granularity] ?? 'Daily' }}
                        <svg class="h-3 w-3 transition-transform" :class="open && 'rotate-180'" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7"/></svg>
                    </button>
                    <div x-show="open" x-transition style="display:none;" class="absolute left-0 z-30 mt-1.5 w-36 overflow-hidden rounded-[12px] bg-white p-1.5 shadow-xl shadow-zinc-900/10 ring-1 ring-zinc-200 dark:bg-[#1d3252] dark:ring-zinc-700/60">
                        @foreach ($granularities as $key => $label)
                            <button type="button" wire:click="setGranularity('{{ $key }}')" @click="open = false" @class([
                                'flex w-full items-center rounded-[12px] px-3 py-1.5 text-left text-[12px] font-medium transition-colors',
                                'bg-blue-50 text-blue-700 dark:bg-blue-600/15 dark:text-blue-300' => $granularity === $key,
                                'text-zinc-700 hover:bg-zinc-50 dark:text-zinc-200 dark:hover:bg-[#26416b]' => $granularity !== $key,
                            ])>{{ $label }}</button>
                        @endforeach
                    </div>
                </div>

                {{-- Category segment. --}}
                <div x-data="{ open: false }" @click.outside="open = false" class="relative">
                    <button type="button" @click="open = ! open" class="{{ $segmentBase }} {{ $segmentInactive }}">
                        {{ $activeCategoryLabel }}
                        <svg class="h-3 w-3 transition-transform" :class="open && 'rotate-180'" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7"/></svg>
                    </button>
                    <div x-show="open" x-transition style="display:none;" class="absolute left-0 z-30 mt-1.5 w-44 overflow-hidden rounded-[12px] bg-white p-1.5 shadow-xl shadow-zinc-900/10 ring-1 ring-zinc-200 dark:bg-[#1d3252] dark:ring-zinc-700/60">
                        <button type="button" wire:click="setCategory(null)" @click="open = false" @class([
                            'flex w-full items-center rounded-[12px] px-3 py-1.5 text-left text-[12px] font-medium transition-colors',
                            'bg-blue-50 text-blue-700 dark:bg-blue-600/15 dark:text-blue-300' => $categoryId === null,
                            'text-zinc-700 hover:bg-zinc-50 dark:text-zinc-200 dark:hover:bg-[#26416b]' => $categoryId !== null,
                        ])>All Products</button>
                        @foreach ($this->categories as $cat)
                            <button type="button" wire:click="setCategory({{ $cat->id }})" @click="open = false" @class([
                                'flex w-full items-center rounded-[12px] px-3 py-1.5 text-left text-[12px] font-medium transition-colors',
                                'bg-blue-50 text-blue-700 dark:bg-blue-600/15 dark:text-blue-300' => $categoryId === $cat->id,
                                'text-zinc-700 hover:bg-zinc-50 dark:text-zinc-200 dark:hover:bg-[#26416b]' => $categoryId !== $cat->id,
                            ])>{{ $cat->name }}</button>
                        @endforeach
                    </div>
                </div>
            </div>

            {{-- Custom date range — sits outside the pill since it's two
                 inputs that only appear when preset = custom. --}}
            @if ($preset === 'custom')
                <div class="flex items-center gap-1.5">
                    <input type="date" wire:model.live="start" class="rounded-[12px] border border-zinc-200 bg-white px-2 py-1.5 text-[12px] text-zinc-900 outline-none focus:border-blue-500 focus:ring-2 focus:ring-blue-500/15 dark:border-zinc-700/60 dark:bg-[#26416b] dark:text-white">
                    <span class="text-zinc-500">→</span>
                    <input type="date" wire:model.live="end" class="rounded-[12px] border border-zinc-200 bg-white px-2 py-1.5 text-[12px] text-zinc-900 outline-none focus:border-blue-500 focus:ring-2 focus:ring-blue-500/15 dark:border-zinc-700/60 dark:bg-[#26416b] dark:text-white">
                </div>
            @endif
        </div>

        {{-- KPI strip. Icons are PNGs in public/assets — picked by the project
             owner; we render them at their natural colour without recolouring.
             Label sits inline with the icon, value sits on the row below. --}}
        @php
            $kpis = [
                ['label' => 'Transactions',  'value' => number_format($this->totals['transactions']), 'icon' => 'transactions.webp'],
                ['label' => 'Total sales',   'value' => '@money', 'money' => $this->totals['sales'],   'icon' => 'total sales.webp'],
                ['label' => 'Profit',        'value' => '@money', 'money' => $this->totals['profit'],  'icon' => 'profit.webp'],
                ['label' => 'Profit Margin', 'value' => number_format($this->totals['profit_margin'], 2) . '%', 'icon' => 'Profit margin.webp'],
                ['label' => 'Avg $ per Tx',  'value' => '@money', 'money' => $this->totals['avg_per_tx'], 'icon' => 'avg $ per tx.webp'],
            ];
        @endphp
        <div class="mt-5 flex flex-wrap items-start gap-x-10 gap-y-4">
            @foreach ($kpis as $kpi)
                <div class="flex flex-col gap-1">
                    <div class="flex items-center gap-1.5">
                        <img src="{{ asset('assets/' . rawurlencode($kpi['icon'])) }}" alt="" class="h-6 w-6 shrink-0 object-contain" loading="lazy">
                        <p class="text-[14px] font-medium text-zinc-500 dark:text-zinc-400">{{ $kpi['label'] }}</p>
                    </div>
                    <p class="text-[12px] font-bold tabular-nums text-zinc-900 dark:text-white">
                        @if (($kpi['value'] ?? null) === '@money')
                            @moneyCode($kpi['money'], 'USD')
                        @else
                            {{ $kpi['value'] }}
                        @endif
                    </p>
                </div>
            @endforeach
        </div>

        {{-- Chart toggle + chart. Segmented control: a single outer chip
             contains both switchers; only the active one is filled.
             Active state uses the project's primary blue. --}}
        <div class="mt-5">
            <div class="flex items-center justify-end">
                <div class="inline-flex items-center gap-0.5 rounded-[8px] bg-zinc-100 p-0.5 dark:bg-[#26416b]">
                    @foreach (['bar' => 'Bar Chart', 'line' => 'Line Chart'] as $type => $label)
                        @php $active = $chartType === $type; @endphp
                        <button type="button" wire:click="setChartType('{{ $type }}')" @class([
                            'rounded-[8px] px-4 py-0.5 text-[12px] font-semibold transition-colors',
                            'bg-blue-600 text-white shadow-sm' => $active,
                            'text-zinc-600 hover:text-zinc-900 dark:text-zinc-300 dark:hover:text-white' => ! $active,
                        ])>{{ $label }}</button>
                    @endforeach
                </div>
            </div>
            <div
                wire:key="report-chart-{{ $chartType }}-{{ $granularity }}-{{ $categoryId ?? 'all' }}-{{ $preset }}"
                x-data="reportChart(@js($this->series), @js($chartType))"
                class="mt-3"
            >
                @if (count($this->series) === 0 || $this->totals['transactions'] === 0)
                    <div class="flex h-72 items-center justify-center rounded-[12px] bg-zinc-50 text-sm text-zinc-600 dark:bg-[#26416b] dark:text-zinc-400">
                        No completed orders in {{ strtolower($rangeLabel) }} yet.
                    </div>
                @else
                    <div x-ref="canvas" class="min-h-[320px]"></div>
                @endif
            </div>

            {{-- Series legend — squiggle marks match the chart line/bar colors.
                 Each squiggle's path is 4 wavelengths long (extends well past the
                 viewBox) so the `animateTransform` can slide it by exactly one
                 wavelength (12 units) on loop, making the wave appear to flow
                 forever without a visible reset. Sales + Cost use slightly
                 different durations so they don't pulse in lockstep. --}}
            @if (count($this->series) > 0 && $this->totals['transactions'] > 0)
                <div class="mt-2 flex items-center gap-4 text-[11px] font-medium text-zinc-600 dark:text-zinc-300">
                    <span class="flex items-center gap-1.5">
                        <svg viewBox="0 0 24 8" class="h-2 w-6 text-emerald-400" fill="none" aria-hidden="true">
                            <g>
                                <animateTransform attributeName="transform" type="translate" from="0 0" to="-12 0" dur="2s" repeatCount="indefinite"/>
                                <path d="M-12 4 Q -9 0, -6 4 T 0 4 T 6 4 T 12 4 T 18 4 T 24 4 T 30 4 T 36 4" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/>
                            </g>
                        </svg>
                        Sales
                    </span>
                    <span class="flex items-center gap-1.5">
                        <svg viewBox="0 0 24 8" class="h-2 w-6 text-blue-400" fill="none" aria-hidden="true">
                            <g>
                                <animateTransform attributeName="transform" type="translate" from="0 0" to="-12 0" dur="2.4s" repeatCount="indefinite"/>
                                <path d="M-12 4 Q -9 0, -6 4 T 0 4 T 6 4 T 12 4 T 18 4 T 24 4 T 30 4 T 36 4" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/>
                            </g>
                        </svg>
                        Cost
                    </span>
                </div>
            @endif
        </div>
    </section>

    {{-- ── Data table ──────────────────────────────────────────── --}}
    <section class="mt-4 overflow-hidden rounded-[12px] border-[1.5px] border-white bg-white shadow-sm shadow-zinc-900/[0.04] dark:border-white dark:bg-[#1d3252]">
        <div class="overflow-x-auto p-3">
            <table class="admin-table w-full text-left text-[12px]">
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>Transactions</th>
                        <th>Cost</th>
                        <th>Total Sales</th>
                        <th>Profit</th>
                        <th>Profit Margin</th>
                        <th>Avg $ per Tx</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($this->series as $row)
                        @php
                            $rowDate = \Illuminate\Support\Carbon::parse($row['date']);
                            $label = match ($granularity) {
                                'monthly' => $rowDate->format('M Y'),
                                'weekly' => 'Week of ' . $rowDate->format('M j'),
                                default => $rowDate->format('M j, D'),
                            };
                        @endphp
                        <tr>
                            <td class="font-medium text-zinc-900 dark:text-white">{{ $label }}</td>
                            <td class="tabular-nums">{{ number_format($row['transactions']) }}</td>
                            <td class="tabular-nums">@moneyCode((float) $row['cost_usd'], 'USD')</td>
                            <td class="tabular-nums">@moneyCode((float) $row['sales_usd'], 'USD')</td>
                            <td class="tabular-nums">@moneyCode((float) $row['profit_usd'], 'USD')</td>
                            <td class="tabular-nums">{{ number_format($row['profit_margin'], 2) }}%</td>
                            <td class="tabular-nums">@moneyCode((float) $row['avg_per_tx_usd'], 'USD')</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7" class="px-5 py-10 text-center text-sm text-zinc-600 dark:text-zinc-400">No data for the selected window.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </section>
</div>

<?php

use App\Domain\Admin\Queries\FinanceReportQuery;
use Illuminate\Support\Carbon;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;

new
#[Layout('components.layouts.admin')]
#[Title('Finances')]
class extends Component {
    /** Selected month as Y-m, URL-bound so a month view can be shared. */
    #[Url(history: true)] public string $month = '';

    public function mount(): void
    {
        if (! $this->validMonth($this->month)) {
            $this->month = now()->format('Y-m');
        }
    }

    public function setMonth(string $month): void
    {
        if ($this->validMonth($month)) {
            $this->month = $month;
            unset($this->breakdown);
        }
    }

    public function stepMonth(int $direction): void
    {
        $this->setMonth(
            $this->selectedMonth()->addMonthsNoOverflow($direction >= 0 ? 1 : -1)->format('Y-m'),
        );
    }

    public function selectedMonth(): Carbon
    {
        return $this->validMonth($this->month)
            ? $this->parseMonth($this->month)
            : now()->startOfMonth();
    }

    public function launchMonth(): Carbon
    {
        return $this->parseMonth(FinanceReportQuery::LAUNCH_MONTH);
    }

    /**
     * Selectable months from launch (June 2026) to the current month, grouped
     * by year for the picker, newest year and month first.
     *
     * @return array<string, array<string, string>> year => [Y-m => short month name]
     */
    public function monthOptions(): array
    {
        $options = [];
        $cursor = now()->startOfMonth();
        $launch = $this->launchMonth();

        while ($cursor->gte($launch)) {
            $options[$cursor->format('Y')][$cursor->format('Y-m')] = $cursor->format('M');
            $cursor = $cursor->subMonthNoOverflow();
        }

        return $options;
    }

    #[Computed]
    public function breakdown(): array
    {
        return app(FinanceReportQuery::class)->monthlyBreakdown($this->selectedMonth());
    }

    /** A month is valid when well-formed AND between launch and today. */
    private function validMonth(string $month): bool
    {
        if (! preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month)) {
            return false;
        }

        return $this->parseMonth($month)->between($this->launchMonth(), now()->startOfMonth());
    }

    /**
     * Parse a Y-m string to the first day of that month. The day MUST be
     * pinned explicitly: createFromFormat('Y-m', ...) borrows the current day
     * of month, so parsing "2026-06" on July 31st overflows to July 1st and
     * short months silently vanish from the picker.
     */
    private function parseMonth(string $month): Carbon
    {
        return Carbon::createFromFormat('Y-m-d', $month.'-01')->startOfMonth();
    }
}; ?>

<x-slot:heading>Finances</x-slot:heading>

@php
    $card = 'rounded-[12px] ring-1 ring-zinc-200 bg-white p-5 shadow-sm shadow-zinc-900/[0.04] dark:ring-white/10 dark:bg-[#1d3252]';
    $sectionTitle = 'text-sm font-bold text-zinc-900 dark:text-white';
    $th = 'px-3 py-2 text-left text-[11px] font-semibold uppercase tracking-wide text-zinc-500 dark:text-zinc-400';
    $td = 'px-3 py-2.5 text-[13px] tabular-nums text-zinc-700 dark:text-zinc-200';

    $data = $this->breakdown;
    $totals = $data['totals'];
    $previous = $data['previous_totals'];
    $isCurrentMonth = $data['month'] === now()->format('Y-m');

    // Δ% vs previous month; null when there is no prior baseline.
    $delta = function (float $current, float $prior): ?float {
        if ($prior == 0.0) {
            return null;
        }

        return round((($current - $prior) / abs($prior)) * 100, 1);
    };

    $deltaBadge = function (?float $pct, bool $downIsGood = false) {
        if ($pct === null) {
            return '';
        }
        $good = $downIsGood ? $pct <= 0 : $pct >= 0;
        $tone = $good
            ? 'bg-emerald-50 text-emerald-700 ring-emerald-200 dark:bg-emerald-500/10 dark:text-emerald-300 dark:ring-emerald-500/30'
            : 'bg-red-50 text-red-700 ring-red-200 dark:bg-red-500/10 dark:text-red-300 dark:ring-red-500/30';

        return '<span class="ml-2 inline-flex items-center rounded-full px-2 py-0.5 text-[10px] font-semibold ring-1 '.$tone.'">'
            .($pct >= 0 ? '+' : '').number_format($pct, 1).'% vs prev</span>';
    };
@endphp

<div class="space-y-6 max-w-7xl mx-auto -mt-2">
    {{-- ── Month picker ──────────────────────────────────────────── --}}
    <div class="flex flex-wrap items-center justify-between gap-3">
        <p class="text-sm text-zinc-600 dark:text-zinc-400">Monthly revenue, supplier cost and gross profit, by provider and category.</p>
        @php $isLaunchMonth = $this->selectedMonth()->equalTo($this->launchMonth()); @endphp
        <div class="flex items-center gap-2">
            <button type="button" wire:click="stepMonth(-1)" @disabled($isLaunchMonth) class="rounded-[10px] p-2 text-zinc-500 ring-1 ring-zinc-200 transition-colors hover:bg-zinc-100 hover:text-zinc-900 disabled:cursor-not-allowed disabled:opacity-40 dark:ring-white/10 dark:hover:bg-white/10 dark:hover:text-white" aria-label="Previous month">
                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M15 19l-7-7 7-7"/></svg>
            </button>

            {{-- Month picker: months since launch (Jun 2026), grouped by year. --}}
            <div x-data="{ open: false }" @click.outside="open = false" class="relative">
                <button type="button" @click="open = ! open" :aria-expanded="open.toString()" class="inline-flex min-w-40 items-center justify-between gap-2 rounded-[10px] bg-white px-3.5 py-2 text-sm font-semibold text-zinc-900 ring-1 ring-zinc-200 transition-colors hover:bg-zinc-50 dark:bg-[#1d3252] dark:text-white dark:ring-white/10 dark:hover:bg-white/5">
                    <span class="inline-flex items-center gap-2">
                        <svg class="h-4 w-4 text-zinc-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75"><path stroke-linecap="round" stroke-linejoin="round" d="M6.75 3v2.25M17.25 3v2.25M3 18.75V7.5a2.25 2.25 0 0 1 2.25-2.25h13.5A2.25 2.25 0 0 1 21 7.5v11.25m-18 0A2.25 2.25 0 0 0 5.25 21h13.5A2.25 2.25 0 0 0 21 18.75m-18 0v-7.5A2.25 2.25 0 0 1 5.25 9h13.5A2.25 2.25 0 0 1 21 11.25v7.5"/></svg>
                        {{ $this->selectedMonth()->format('F Y') }}
                    </span>
                    <svg :class="open && 'rotate-180'" class="h-3.5 w-3.5 text-zinc-400 transition-transform" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7"/></svg>
                </button>
                <div x-show="open" x-transition style="display:none;" class="absolute right-0 z-30 mt-1.5 w-64 overflow-hidden rounded-[12px] bg-white p-3 shadow-xl shadow-zinc-900/10 ring-1 ring-zinc-200 dark:bg-[#1d3252] dark:ring-zinc-700/60">
                    @foreach ($this->monthOptions() as $year => $months)
                        <p class="px-1 pb-1.5 {{ $loop->first ? '' : 'pt-3' }} text-[10px] font-bold uppercase tracking-wider text-zinc-400">{{ $year }}</p>
                        <div class="grid grid-cols-4 gap-1">
                            @foreach ($months as $optionValue => $shortMonth)
                                <button
                                    type="button"
                                    wire:click="setMonth('{{ $optionValue }}')"
                                    @click="open = false"
                                    @class([
                                        'rounded-[8px] px-2 py-1.5 text-[12px] font-semibold transition-colors',
                                        'bg-blue-600 text-white shadow-sm' => $optionValue === $month,
                                        'text-zinc-600 hover:bg-zinc-100 hover:text-zinc-900 dark:text-zinc-300 dark:hover:bg-white/10 dark:hover:text-white' => $optionValue !== $month,
                                    ])
                                >{{ $shortMonth }}</button>
                            @endforeach
                        </div>
                    @endforeach
                    <p class="mt-3 border-t border-zinc-100 px-1 pt-2 text-[10px] text-zinc-400 dark:border-white/10">Since launch · June 2026</p>
                </div>
            </div>

            <button type="button" wire:click="stepMonth(1)" @disabled($isCurrentMonth) class="rounded-[10px] p-2 text-zinc-500 ring-1 ring-zinc-200 transition-colors hover:bg-zinc-100 hover:text-zinc-900 disabled:cursor-not-allowed disabled:opacity-40 dark:ring-white/10 dark:hover:bg-white/10 dark:hover:text-white" aria-label="Next month">
                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7"/></svg>
            </button>
        </div>
    </div>

    {{-- ── Headline totals ───────────────────────────────────────── --}}
    <section class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-6">
        @php
            $headlines = [
                ['label' => 'Revenue', 'money' => $totals['revenue_usd'], 'delta' => $delta($totals['revenue_usd'], $previous['revenue_usd'])],
                // Up is green here like every other headline: supplier cost
                // rises with sales, so a bigger number is more business, not
                // a problem. Marking it "down is good" painted growth red.
                ['label' => 'Supplier cost', 'money' => $totals['cost_usd'], 'delta' => $delta($totals['cost_usd'], $previous['cost_usd'])],
                ['label' => 'Gross profit', 'money' => $totals['profit_usd'], 'delta' => $delta($totals['profit_usd'], $previous['profit_usd'])],
                ['label' => 'Gross margin', 'value' => number_format($totals['margin_pct'], 2).'%', 'delta' => $delta($totals['margin_pct'], $previous['margin_pct'])],
                ['label' => 'Completed orders', 'value' => number_format($totals['orders']), 'delta' => $delta((float) $totals['orders'], (float) $previous['orders'])],
                ['label' => 'Refunds issued', 'money' => $data['refunds']['total_usd'], 'sub' => number_format($data['refunds']['count']).' refunds', 'downGood' => true],
            ];
        @endphp
        @foreach ($headlines as $headline)
            <div class="{{ $card }} !p-4">
                <p class="text-[12px] font-medium text-zinc-500 dark:text-zinc-400">{{ $headline['label'] }}</p>
                <p class="mt-1 text-lg font-bold tracking-tight text-zinc-900 dark:text-white tabular-nums">
                    @isset($headline['money']) @moneyCode($headline['money'], 'USD') @else {{ $headline['value'] }} @endisset
                </p>
                <p class="mt-1 text-[11px] text-zinc-400">
                    {{ $headline['sub'] ?? '' }}{!! $deltaBadge($headline['delta'] ?? null, $headline['downGood'] ?? false) !!}
                </p>
            </div>
        @endforeach
    </section>

    {{-- ── Per-provider P&L ──────────────────────────────────────── --}}
    <section class="{{ $card }} !p-0 overflow-hidden">
        <header class="flex items-center justify-between px-5 pt-5 pb-3">
            <h2 class="{{ $sectionTitle }}">By providers</h2>
        </header>
        <div class="overflow-x-auto">
            <table class="w-full">
                <thead class="border-y border-zinc-100 bg-zinc-50/60 dark:border-white/10 dark:bg-white/5">
                    <tr>
                        <th class="{{ $th }} pl-5">Provider</th>
                        <th class="{{ $th }} text-right">Orders</th>
                        <th class="{{ $th }} text-right">Units</th>
                        <th class="{{ $th }} text-right">Revenue</th>
                        <th class="{{ $th }} text-right">Cost price</th>
                        <th class="{{ $th }} text-right">Gross profit</th>
                        <th class="{{ $th }} pr-5 text-right">Margin</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-zinc-100 dark:divide-white/10">
                    @forelse ($data['providers'] as $row)
                        <tr>
                            <td class="{{ $td }} pl-5 font-semibold text-zinc-900 dark:text-white">{{ $row['label'] }}</td>
                            <td class="{{ $td }} text-right">{{ number_format($row['orders']) }}</td>
                            <td class="{{ $td }} text-right">{{ number_format($row['units']) }}</td>
                            <td class="{{ $td }} text-right">@moneyCode($row['revenue_usd'], 'USD')</td>
                            <td class="{{ $td }} text-right">@moneyCode($row['cost_usd'], 'USD')</td>
                            <td class="{{ $td }} text-right font-semibold {{ $row['profit_usd'] < 0 ? 'text-red-600 dark:text-red-400' : 'text-emerald-700 dark:text-emerald-300' }}">@moneyCode($row['profit_usd'], 'USD')</td>
                            <td class="{{ $td }} pr-5 text-right">{{ number_format($row['margin_pct'], 2) }}%</td>
                        </tr>
                    @empty
                        <tr><td colspan="7" class="{{ $td }} pl-5 py-8 text-center text-zinc-400">No completed orders in this month.</td></tr>
                    @endforelse
                </tbody>
                @if (count($data['providers']) > 1)
                    <tfoot class="border-t border-zinc-200 bg-zinc-50/60 dark:border-white/10 dark:bg-white/5">
                        <tr>
                            <td class="{{ $td }} pl-5 font-bold text-zinc-900 dark:text-white">Company total</td>
                            <td class="{{ $td }} text-right font-bold">{{ number_format($totals['orders']) }}</td>
                            <td class="{{ $td }} text-right font-bold">{{ number_format($totals['units']) }}</td>
                            <td class="{{ $td }} text-right font-bold">@moneyCode($totals['revenue_usd'], 'USD')</td>
                            <td class="{{ $td }} text-right font-bold">@moneyCode($totals['cost_usd'], 'USD')</td>
                            <td class="{{ $td }} text-right font-bold {{ $totals['profit_usd'] < 0 ? 'text-red-600 dark:text-red-400' : 'text-emerald-700 dark:text-emerald-300' }}">@moneyCode($totals['profit_usd'], 'USD')</td>
                            <td class="{{ $td }} pr-5 text-right font-bold">{{ number_format($totals['margin_pct'], 2) }}%</td>
                        </tr>
                    </tfoot>
                @endif
            </table>
        </div>
    </section>

    {{-- ── Per-category P&L ──────────────────────────────────────── --}}
    <section class="{{ $card }} !p-0 overflow-hidden">
        <header class="flex items-center justify-between px-5 pt-5 pb-3">
            <h2 class="{{ $sectionTitle }}">By product category</h2>
        </header>
        <div class="overflow-x-auto">
            <table class="w-full">
                <thead class="border-y border-zinc-100 bg-zinc-50/60 dark:border-white/10 dark:bg-white/5">
                    <tr>
                        <th class="{{ $th }} pl-5">Category</th>
                        <th class="{{ $th }} text-right">Orders</th>
                        <th class="{{ $th }} text-right">Units</th>
                        <th class="{{ $th }} text-right">Revenue</th>
                        <th class="{{ $th }} text-right">Cost price</th>
                        <th class="{{ $th }} text-right">Gross profit</th>
                        <th class="{{ $th }} pr-5 text-right">Margin</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-zinc-100 dark:divide-white/10">
                    @forelse ($data['categories'] as $row)
                        <tr>
                            <td class="{{ $td }} pl-5 font-semibold text-zinc-900 dark:text-white">{{ $row['category'] }}</td>
                            <td class="{{ $td }} text-right">{{ number_format($row['orders']) }}</td>
                            <td class="{{ $td }} text-right">{{ number_format($row['units']) }}</td>
                            <td class="{{ $td }} text-right">@moneyCode($row['revenue_usd'], 'USD')</td>
                            <td class="{{ $td }} text-right">@moneyCode($row['cost_usd'], 'USD')</td>
                            <td class="{{ $td }} text-right font-semibold {{ $row['profit_usd'] < 0 ? 'text-red-600 dark:text-red-400' : 'text-emerald-700 dark:text-emerald-300' }}">@moneyCode($row['profit_usd'], 'USD')</td>
                            <td class="{{ $td }} pr-5 text-right">{{ number_format($row['margin_pct'], 2) }}%</td>
                        </tr>
                    @empty
                        <tr><td colspan="7" class="{{ $td }} pl-5 py-8 text-center text-zinc-400">No completed orders in this month.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </section>

</div>

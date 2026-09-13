<?php

use App\Domain\PaymentInsights\Services\PaymentInsightsMetrics;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new
#[Layout('components.layouts.admin')]
class extends Component {
    public $timeframe = '7_days'; // 24_hours, 7_days, 30_days, all_time

    public function with(): array
    {
        $since = match ($this->timeframe) {
            '24_hours' => now()->subDay(),
            '7_days' => now()->subDays(7),
            '30_days' => now()->subDays(30),
            default => null,
        };

        $metrics = app(PaymentInsightsMetrics::class);

        return [
            ...$metrics->outcomes($since),
            'recovery' => $metrics->recovery($since),
            'frontend' => $metrics->frontend($since),
            'stages' => $metrics->funnelStages($since),
            'trend' => $metrics->dailyTrend($this->timeframe === '24_hours' ? 7 : 14),
            'methods' => $metrics->byPaymentMethod($since),
        ];
    }
}; ?>

<x-slot:heading>Payment Insights</x-slot:heading>

@php
    $card = 'rounded-[12px] ring-1 ring-zinc-200 bg-white p-5 shadow-sm shadow-zinc-900/[0.04] dark:ring-white/10 dark:bg-[#1d3252]';
    $sectionTitle = 'text-sm font-bold text-zinc-900 dark:text-white';
    $subtle = 'text-[12px] text-zinc-500 dark:text-zinc-400';
    $label = 'truncate text-[12px] font-medium text-zinc-500 dark:text-zinc-400';
    $stat = 'mt-1 text-2xl font-bold tracking-tight tabular-nums';
    $foot = 'mt-1 text-[11px] text-zinc-400 dark:text-zinc-500';
    $th = 'px-3 py-2 text-left text-[11px] font-semibold uppercase tracking-wide text-zinc-500 dark:text-zinc-400';
    $td = 'px-3 py-2.5 text-[13px] tabular-nums text-zinc-700 dark:text-zinc-200';
    $row = 'flex items-center justify-between text-[13px]';

    // Rate colouring uses payment-industry bands, not arbitrary thirds:
    // healthy authorization sits at 85%+, below 70% is an incident.
    $rateTone = fn (?float $rate) => $rate === null
        ? 'text-zinc-400'
        : ($rate >= 85 ? 'text-emerald-600 dark:text-emerald-400' : ($rate >= 70 ? 'text-amber-600 dark:text-amber-400' : 'text-red-600 dark:text-red-400'));

    $timeframes = [
        '24_hours' => 'Last 24 Hours',
        '7_days' => 'Last 7 Days',
        '30_days' => 'Last 30 Days',
        'all_time' => 'All Time',
    ];
@endphp

<div class="space-y-5 max-w-7xl mx-auto -mt-2">
    {{-- Period selector. The shared admin dropdown, not a native <select>: the
         OS paints native option panels, which is why they went light on dark
         admin pages. --}}
    <div class="flex flex-wrap items-center justify-between gap-3">
        <p class="text-sm text-zinc-600 dark:text-zinc-400">How payments succeed, fail and recover, and where lost ones went.</p>
        <x-admin.select wire:model.live="timeframe" :options="$timeframes" wrapperClass="w-44 !mt-0" />
    </div>

    {{-- ── Headline rates ─────────────────────────────────────────── --}}
    <section class="grid grid-cols-2 gap-4 lg:grid-cols-5">
        <div class="{{ $card }}">
            <p class="{{ $label }}">Checkouts Started</p>
            <p class="{{ $stat }} text-zinc-900 dark:text-white">{{ number_format($totalAttempts) }}</p>
            <p class="{{ $foot }}">{{ number_format($submitted) }} reached a gateway</p>
        </div>
        <div class="{{ $card }}">
            <p class="{{ $label }}">Payment Success</p>
            <p class="{{ $stat }} {{ $rateTone($authorizationRate) }}">{{ $authorizationRate }}%</p>
            <p class="{{ $foot }}">{{ number_format($successful) }} of {{ number_format($submitted) }} submitted</p>
        </div>
        <div class="{{ $card }}">
            <p class="{{ $label }}">Checkout Conversion</p>
            <p class="{{ $stat }} text-blue-600 dark:text-blue-400">{{ $checkoutConversionRate }}%</p>
            <p class="{{ $foot }}">{{ number_format($successful) }} of {{ number_format($totalAttempts) }} started</p>
        </div>
        <div class="{{ $card }}">
            <p class="{{ $label }}">Declined</p>
            <p class="{{ $stat }} text-red-600 dark:text-red-400">{{ $failureRate }}%</p>
            <p class="{{ $foot }}">{{ number_format($failed) }} rejected at a gateway</p>
        </div>
        <div class="{{ $card }}">
            <p class="{{ $label }}">Walked Away</p>
            <p class="{{ $stat }} text-amber-600 dark:text-amber-400">{{ $abandonRate }}%</p>
            <p class="{{ $foot }}">{{ number_format($abandoned) }} expired or cancelled</p>
        </div>
    </section>

    {{-- ── Daily trend ────────────────────────────────────────────── --}}
    {{-- Three lines via ApexCharts (paymentTrendChart in resources/js/app.js,
         lazy-imported). The success rate lives in the tooltip, not a second
         axis. wire:key on the timeframe replaces the card on a period change
         so Alpine mounts a fresh chart with the new series. Don't add
         x-init="init()": Alpine runs init() itself. --}}
    <section class="{{ $card }} min-w-0 overflow-hidden" wire:key="payment-trend-{{ $timeframe }}" x-data="paymentTrendChart(@js($trend))">
        <header class="mb-2 flex flex-wrap items-center justify-between gap-2">
            <h2 class="{{ $sectionTitle }}">Daily Trend</h2>
            <div class="flex items-center gap-3 text-[11px] font-medium text-zinc-500 dark:text-zinc-400">
                <span class="flex items-center gap-1.5">
                    <svg viewBox="0 0 24 8" class="h-2 w-6 text-emerald-400" fill="none" aria-hidden="true"><path d="M1 4 Q 4 0, 7 4 T 13 4 T 19 4 T 23 4" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>
                    Paid
                </span>
                <span class="flex items-center gap-1.5">
                    <svg viewBox="0 0 24 8" class="h-2 w-6 text-red-400" fill="none" aria-hidden="true"><path d="M1 4 Q 4 0, 7 4 T 13 4 T 19 4 T 23 4" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>
                    Declined
                </span>
                <span class="flex items-center gap-1.5">
                    <svg viewBox="0 0 24 8" class="h-2 w-6 text-amber-400" fill="none" aria-hidden="true"><path d="M1 4 Q 4 0, 7 4 T 13 4 T 19 4 T 23 4" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>
                    Walked away
                </span>
            </div>
        </header>
        <div class="relative">
            <div x-ref="canvas" class="min-h-[280px]"></div>
            <div
                x-show="isEmpty()"
                x-cloak
                class="absolute inset-0 z-10 flex items-center justify-center rounded-[12px] bg-zinc-50/95 text-sm text-zinc-500 dark:bg-[#26416b]/95 dark:text-zinc-400"
            >
                No payments in this period yet.
            </div>
        </div>
    </section>

    {{-- ── Where payments die + why ───────────────────────────────── --}}
    <div class="grid grid-cols-1 gap-4 lg:grid-cols-2">
        <section class="{{ $card }}">
            <h2 class="{{ $sectionTitle }} mb-4">Where Payments Die</h2>
            <div class="space-y-3">
                @foreach ($stages as $stage)
                    <div>
                        <div class="{{ $row }}">
                            <span class="font-medium text-zinc-700 dark:text-zinc-200">{{ $stage['label'] }}</span>
                            <span class="text-zinc-500 dark:text-zinc-400 tabular-nums">{{ number_format($stage['count']) }} ({{ $stage['share'] }}%)</span>
                        </div>
                        <div class="mt-1.5 h-2 w-full overflow-hidden rounded-full bg-zinc-100 dark:bg-white/10">
                            <div class="h-2 rounded-full bg-blue-500" style="width: {{ $stage['share'] }}%"></div>
                        </div>
                    </div>
                @endforeach
            </div>
        </section>

        <section class="{{ $card }}">
            <h2 class="{{ $sectionTitle }} mb-4">Failure Categories</h2>
            <div class="space-y-3">
                @php $bucketTotal = max(1, array_sum($buckets)); @endphp
                @foreach ($buckets as $category => $count)
                    @php $pct = round(($count / $bucketTotal) * 100, 1); @endphp
                    <div>
                        <div class="{{ $row }}">
                            <span class="font-medium text-zinc-700 dark:text-zinc-200">{{ $category }}</span>
                            <span class="text-zinc-500 dark:text-zinc-400 tabular-nums">{{ number_format($count) }} ({{ $pct }}%)</span>
                        </div>
                        <div class="mt-1.5 h-2 w-full overflow-hidden rounded-full bg-zinc-100 dark:bg-white/10">
                            <div class="h-2 rounded-full
                                @if($category == 'Customer') bg-amber-500
                                @elseif($category == 'Provider') bg-blue-500
                                @elseif($category == 'System') bg-red-500
                                @elseif($category == 'Frontend') bg-purple-500
                                @else bg-zinc-400 @endif"
                                style="width: {{ $pct }}%"></div>
                        </div>
                    </div>
                @endforeach
            </div>
        </section>
    </div>

    {{-- ── Reasons + methods ──────────────────────────────────────── --}}
    <div class="grid grid-cols-1 gap-4 lg:grid-cols-2">
        <section class="{{ $card }} !p-0 overflow-hidden">
            <header class="px-5 pt-5 pb-3">
                <h2 class="{{ $sectionTitle }}">Top Failure Reasons</h2>
            </header>
            <div class="overflow-x-auto">
                <table class="w-full">
                    <thead class="border-y border-zinc-100 bg-zinc-50/60 dark:border-white/10 dark:bg-white/5">
                        <tr>
                            <th class="{{ $th }} pl-5">Reason</th>
                            <th class="{{ $th }} text-right">Share</th>
                            <th class="{{ $th }} pr-5 text-right">Count</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-zinc-100 dark:divide-white/10">
                        @forelse($topReasons as $reason)
                            <tr>
                                <td class="{{ $td }} pl-5">
                                    <span class="font-semibold text-zinc-900 dark:text-white">{{ $reason->label }}</span>
                                    @if ($reason->retryable)
                                        <span class="ml-1.5 inline-flex items-center rounded-full bg-emerald-50 px-1.5 py-0.5 text-[10px] font-semibold text-emerald-700 ring-1 ring-emerald-200 dark:bg-emerald-500/10 dark:text-emerald-300 dark:ring-emerald-500/30">retryable</span>
                                    @endif
                                    <span class="mt-0.5 block text-[11px] text-zinc-400 dark:text-zinc-500">{{ $reason->failure_reason_code ?: 'unknown' }}</span>
                                </td>
                                <td class="{{ $td }} text-right">
                                    {{ $reason->share }}%
                                    <span class="mt-1 ml-auto block h-1.5 w-16 overflow-hidden rounded-full bg-zinc-100 dark:bg-white/10">
                                        <span class="block h-1.5 rounded-full bg-zinc-400" style="width: {{ min(100, $reason->share) }}%"></span>
                                    </span>
                                </td>
                                <td class="{{ $td }} pr-5 text-right font-semibold">{{ number_format($reason->count) }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="3" class="{{ $td }} py-8 text-center text-zinc-400">No failures in this timeframe.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </section>

        <section class="{{ $card }} !p-0 overflow-hidden">
            <header class="px-5 pt-5 pb-3">
                <h2 class="{{ $sectionTitle }}">By Payment Method</h2>
            </header>
            <div class="overflow-x-auto">
                <table class="w-full">
                    <thead class="border-y border-zinc-100 bg-zinc-50/60 dark:border-white/10 dark:bg-white/5">
                        <tr>
                            <th class="{{ $th }} pl-5">Method</th>
                            <th class="{{ $th }} text-right">Started</th>
                            <th class="{{ $th }} text-right">Walked</th>
                            <th class="{{ $th }} pr-5 text-right">Success</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-zinc-100 dark:divide-white/10">
                        @forelse($methods as $method)
                            <tr>
                                <td class="{{ $td }} pl-5 font-semibold capitalize text-zinc-900 dark:text-white">{{ str_replace('_', ' ', $method->payment_method) }}</td>
                                <td class="{{ $td }} text-right">{{ number_format($method->started) }}</td>
                                <td class="{{ $td }} text-right">{{ $method->abandon_rate }}%</td>
                                <td class="{{ $td }} pr-5 text-right font-bold {{ $rateTone($method->authorization_rate) }}">
                                    {{ $method->authorization_rate === null ? '—' : $method->authorization_rate.'%' }}
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="4" class="{{ $td }} py-8 text-center text-zinc-400">No method data yet.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </section>
    </div>

    {{-- ── Recent lost payments ───────────────────────────────────── --}}
    @php $visibleFailures = 6; @endphp
    <section x-data="{ expanded: false }" class="{{ $card }} !p-0 overflow-hidden">
        <header class="px-5 pt-5 pb-3">
            <h2 class="{{ $sectionTitle }}">Recent Lost Payments</h2>
        </header>
        <div class="overflow-x-auto">
            <table class="w-full">
                <thead class="border-y border-zinc-100 bg-zinc-50/60 dark:border-white/10 dark:bg-white/5">
                    <tr>
                        <th class="{{ $th }} pl-5">When</th>
                        <th class="{{ $th }}">Outcome</th>
                        <th class="{{ $th }}">Stage</th>
                        <th class="{{ $th }}">Provider</th>
                        <th class="{{ $th }}">Verdict</th>
                        <th class="{{ $th }} pr-5">Gateway said</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-zinc-100 dark:divide-white/10">
                    @forelse($recentFailures as $failure)
                        @php
                            $tone = match ($failure['outcome']) {
                                'failed' => 'bg-red-50 text-red-700 ring-red-200 dark:bg-red-500/10 dark:text-red-300 dark:ring-red-500/30',
                                'expired' => 'bg-amber-50 text-amber-700 ring-amber-200 dark:bg-amber-500/10 dark:text-amber-300 dark:ring-amber-500/30',
                                default => 'bg-zinc-100 text-zinc-600 ring-zinc-200 dark:bg-white/10 dark:text-zinc-300 dark:ring-white/10',
                            };
                        @endphp
                        <tr @if ($loop->index >= $visibleFailures) x-show="expanded" x-cloak @endif>
                            <td class="{{ $td }} pl-5 whitespace-nowrap text-zinc-500 dark:text-zinc-400">{{ \Illuminate\Support\Carbon::parse($failure['at'])->diffForHumans(short: true) }}</td>
                            <td class="{{ $td }} whitespace-nowrap">
                                <span class="inline-flex items-center rounded-full px-2 py-0.5 text-[11px] font-semibold ring-1 {{ $tone }}">
                                    {{ $failure['outcome'] === 'failed' ? 'declined' : $failure['outcome'] }}
                                </span>
                            </td>
                            <td class="{{ $td }} whitespace-nowrap capitalize text-zinc-500 dark:text-zinc-400">{{ $failure['stage'] ?? '—' }}</td>
                            <td class="{{ $td }} whitespace-nowrap">{{ $failure['provider'] ?: 'unknown' }}{{ $failure['payment_method'] ? ' · '.$failure['payment_method'] : '' }}</td>
                            <td class="{{ $td }} whitespace-nowrap">
                                <span class="font-semibold text-zinc-900 dark:text-white">{{ $failure['label'] }}</span>
                                @if ($failure['retryable'])
                                    <span class="ml-1.5 inline-flex items-center rounded-full bg-emerald-50 px-1.5 py-0.5 text-[10px] font-semibold text-emerald-700 ring-1 ring-emerald-200 dark:bg-emerald-500/10 dark:text-emerald-300 dark:ring-emerald-500/30">retryable</span>
                                @endif
                            </td>
                            <td class="{{ $td }} pr-5 text-zinc-500 dark:text-zinc-400">
                                {{ $failure['provider_message'] ?? $failure['raw_reason'] ?? 'No diagnostic captured' }}
                                @if ($failure['provider_code'] && $failure['provider_code'] !== $failure['provider_message'])
                                    <span class="block text-[11px] text-zinc-400 dark:text-zinc-500">code: {{ $failure['provider_code'] }}</span>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="{{ $td }} py-8 text-center text-zinc-400">No lost payments in this timeframe.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @if (count($recentFailures) > $visibleFailures)
            <button type="button" @click="expanded = ! expanded" class="flex w-full items-center justify-center gap-1 border-t border-zinc-100 px-5 py-2.5 text-[11px] font-semibold text-zinc-400 transition-colors hover:bg-zinc-50 hover:text-zinc-600 dark:border-white/10 dark:hover:bg-white/5 dark:hover:text-zinc-200">
                <span x-show="! expanded">Show {{ count($recentFailures) - $visibleFailures }} more</span>
                <span x-show="expanded" x-cloak>Show less</span>
                <svg :class="expanded && 'rotate-180'" class="h-3 w-3 transition-transform" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7"/></svg>
            </button>
        @endif
    </section>

    {{-- ── Transaction recovery ───────────────────────────────────── --}}
    <section class="{{ $card }}">
        <header class="mb-4">
            <h2 class="{{ $sectionTitle }}">Transaction Recovery</h2>
        </header>
        <div class="grid grid-cols-2 gap-4 lg:grid-cols-5">
            <div>
                <p class="{{ $label }}">Recoveries Sent</p>
                <p class="{{ $stat }} text-zinc-900 dark:text-white">{{ number_format($recovery['sent']) }}</p>
                <p class="{{ $foot }}">{{ number_format($recovery['cancelled']) }} superseded, {{ number_format($recovery['abandoned']) }} lapsed</p>
            </div>
            <div>
                <p class="{{ $label }}">Click Rate</p>
                <p class="{{ $stat }} text-blue-600 dark:text-blue-400">{{ $recovery['clickRate'] }}%</p>
                <p class="{{ $foot }}">{{ number_format($recovery['clicked']) }} opened their link</p>
            </div>
            <div>
                <p class="{{ $label }}">Recovery Rate</p>
                <p class="{{ $stat }} text-emerald-600 dark:text-emerald-400">{{ $recovery['conversionRate'] }}%</p>
                <p class="{{ $foot }}">{{ number_format($recovery['recovered']) }} actually paid</p>
            </div>
            <div>
                <p class="{{ $label }}">Reminders</p>
                <p class="{{ $stat }} text-zinc-900 dark:text-white">{{ number_format($recovery['reminded']) }}</p>
                <p class="{{ $foot }}">{{ number_format($recovery['recoveredAfterReminder']) }} paid after the nudge</p>
            </div>
            <div>
                <p class="{{ $label }}">Recovered Value</p>
                <p class="mt-1 text-lg font-bold tracking-tight tabular-nums text-zinc-900 dark:text-white">
                    @forelse($recovery['recoveredValue'] as $currency => $amount)
                        <span class="block">{{ $currency }} {{ number_format((float) $amount, 2) }}</span>
                    @empty
                        <span class="text-zinc-400">&mdash;</span>
                    @endforelse
                </p>
                @if ($recovery['retrying'] > 0)
                    <p class="{{ $foot }}">{{ number_format($recovery['retrying']) }} mid-retry</p>
                @endif
            </div>
        </div>
    </section>

    {{-- ── Frontend failures ──────────────────────────────────────── --}}
    {{-- Browser-side losses are rare by nature, so a quiet period collapses
         the panel to one line rather than four empty columns. It expands
         itself the moment there is anything to show. --}}
    @php $frontendQuiet = ($frontend['total'] ?? 0) === 0 && $frontend['signals']->isEmpty(); @endphp
    @if ($frontendQuiet)
    <section class="{{ $card }} flex flex-wrap items-center justify-between gap-3 !py-4">
        <h2 class="{{ $sectionTitle }}">Frontend Failures</h2>
        <p class="{{ $subtle }}">None in this period. Browser-side losses and environment signals appear here when they happen.</p>
    </section>
    @else
    <section class="{{ $card }}">
        <header class="mb-4 flex items-start justify-between gap-4">
            <h2 class="{{ $sectionTitle }}">Frontend Failures</h2>
            <div class="shrink-0 text-right">
                <p class="text-2xl font-bold tracking-tight text-purple-600 dark:text-purple-400">{{ $frontend['shareOfFailures'] }}%</p>
                <p class="{{ $foot }}">of all failures ({{ number_format($frontend['total']) }})</p>
            </div>
        </header>
        <div class="grid grid-cols-1 gap-6 sm:grid-cols-2 lg:grid-cols-4">
            @foreach ([
                ['By Reason', $frontend['byCode'], 'No frontend failures in this timeframe.'],
                ['Environment Signals', $frontend['signals'], 'No signals reported.'],
                ['By Provider', $frontend['byProvider'], '—'],
                ['By Browser', $frontend['browsers'], '—'],
            ] as [$heading, $set, $emptyText])
                <div>
                    <h3 class="mb-3 text-[11px] font-semibold uppercase tracking-wide text-zinc-500 dark:text-zinc-400">{{ $heading }}</h3>
                    <div class="space-y-2">
                        @forelse($set as $key => $count)
                            <div class="{{ $row }}">
                                <span class="font-medium capitalize text-zinc-700 dark:text-zinc-200">{{ str_replace('_', ' ', (string) $key) }}</span>
                                <span class="tabular-nums text-zinc-500 dark:text-zinc-400">{{ number_format($count) }}</span>
                            </div>
                        @empty
                            <p class="py-3 text-[12px] text-zinc-400 dark:text-zinc-500">{{ $emptyText }}</p>
                        @endforelse
                    </div>
                </div>
            @endforeach
        </div>
    </section>
    @endif

    {{-- ── Providers, skips, feedback ─────────────────────────────── --}}
    <div class="grid grid-cols-1 gap-4 lg:grid-cols-3">
        <section class="{{ $card }} !p-0 overflow-hidden">
            <header class="px-5 pt-5 pb-3">
                <h2 class="{{ $sectionTitle }}">Provider Success Rate</h2>
            </header>
            <div class="overflow-x-auto">
                <table class="w-full">
                    <thead class="border-y border-zinc-100 bg-zinc-50/60 dark:border-white/10 dark:bg-white/5">
                        <tr>
                            <th class="{{ $th }} pl-5">Provider</th>
                            <th class="{{ $th }} text-right">Outcomes</th>
                            <th class="{{ $th }} pr-5 text-right">Success</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-zinc-100 dark:divide-white/10">
                        @forelse($providers as $provider)
                            <tr>
                                <td class="{{ $td }} pl-5 font-semibold capitalize text-zinc-900 dark:text-white">{{ $provider->provider }}</td>
                                <td class="{{ $td }} text-right">{{ number_format($provider->total) }}</td>
                                <td class="{{ $td }} pr-5 text-right font-bold {{ $rateTone((float) $provider->success_rate) }}">{{ $provider->success_rate }}%</td>
                            </tr>
                        @empty
                            <tr><td colspan="3" class="{{ $td }} py-8 text-center text-zinc-400">No provider outcomes in this timeframe.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </section>

        <section class="{{ $card }}">
            <h2 class="{{ $sectionTitle }} mb-4">Recovery Skips</h2>
            <div class="space-y-2.5">
                @forelse($recovery['skipReasons'] as $reason => $count)
                    <div class="{{ $row }}">
                        <span class="font-medium capitalize text-zinc-700 dark:text-zinc-200">{{ str_replace('_', ' ', (string) $reason) }}</span>
                        <span class="tabular-nums text-zinc-500 dark:text-zinc-400">{{ number_format($count) }}</span>
                    </div>
                @empty
                    <p class="py-6 text-center text-[12px] text-zinc-400 dark:text-zinc-500">No skipped recoveries in this timeframe.</p>
                @endforelse
            </div>
        </section>

        <section class="{{ $card }}">
            <h2 class="{{ $sectionTitle }}">Customer Feedback</h2>
            <div class="space-y-2.5">
                @forelse($recovery['feedbackBreakdown'] as $reason => $count)
                    <div class="{{ $row }}">
                        <span class="font-medium capitalize text-zinc-700 dark:text-zinc-200">{{ str_replace('_', ' ', (string) $reason) }}</span>
                        <span class="tabular-nums text-zinc-500 dark:text-zinc-400">{{ number_format($count) }}</span>
                    </div>
                @empty
                    <p class="py-6 text-center text-[12px] text-zinc-400 dark:text-zinc-500">No feedback received in this timeframe.</p>
                @endforelse
            </div>
        </section>
    </div>
</div>

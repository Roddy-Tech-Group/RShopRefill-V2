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

    $trendMax = max(1, max(array_map(fn ($d) => $d['submitted'] + $d['abandoned'], $trend)));
@endphp

<div class="space-y-5 max-w-7xl mx-auto -mt-2">
    <div class="flex flex-wrap items-center justify-between gap-3">
        <p class="{{ $subtle }}">
            <span class="font-semibold text-zinc-700 dark:text-zinc-200">Payment success</span> counts only payments that reached a gateway, so it measures whether payments work.
            <span class="font-semibold text-zinc-700 dark:text-zinc-200">Checkout conversion</span> counts everyone who started, so it measures whether checkout works.
        </p>
        <select wire:model.live="timeframe" class="rounded-[10px] border-zinc-300 text-sm font-semibold focus:border-blue-500 focus:ring-blue-500 dark:border-white/10 dark:bg-[#1d3252] dark:text-white">
            <option value="24_hours">Last 24 Hours</option>
            <option value="7_days">Last 7 Days</option>
            <option value="30_days">Last 30 Days</option>
            <option value="all_time">All Time</option>
        </select>
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
    <section class="{{ $card }}">
        <header class="mb-4 flex flex-wrap items-center justify-between gap-2">
            <div>
                <h2 class="{{ $sectionTitle }}">Daily Trend</h2>
                <p class="{{ $subtle }}">Degradation is a slope, not a snapshot. Bar height is volume; the number under each is that day's payment success rate.</p>
            </div>
            <div class="flex items-center gap-3 text-[11px] font-medium text-zinc-500 dark:text-zinc-400">
                <span class="inline-flex items-center gap-1.5"><span class="h-2 w-2 rounded-full bg-emerald-500"></span>Paid</span>
                <span class="inline-flex items-center gap-1.5"><span class="h-2 w-2 rounded-full bg-red-500"></span>Declined</span>
                <span class="inline-flex items-center gap-1.5"><span class="h-2 w-2 rounded-full bg-amber-400"></span>Walked away</span>
            </div>
        </header>
        <div class="flex items-end gap-1.5 overflow-x-auto pb-1">
            @foreach ($trend as $day)
                @php
                    $total = $day['submitted'] + $day['abandoned'];
                    $heightPct = round(($total / $trendMax) * 100);
                @endphp
                <div class="flex min-w-8 flex-1 flex-col items-center gap-1.5">
                    <div class="flex h-28 w-full flex-col justify-end" title="{{ $day['date'] }}: {{ $day['confirmed'] }} paid, {{ $day['failed'] }} declined, {{ $day['abandoned'] }} walked away">
                        @if ($total === 0)
                            <div class="h-1 w-full rounded-sm bg-zinc-100 dark:bg-white/10"></div>
                        @else
                            <div class="flex w-full flex-col overflow-hidden rounded-[4px]" style="height: {{ max(4, $heightPct) }}%">
                                @if ($day['abandoned'])
                                    <div class="w-full bg-amber-400" style="flex-grow: {{ $day['abandoned'] }}"></div>
                                @endif
                                @if ($day['failed'])
                                    <div class="w-full bg-red-500" style="flex-grow: {{ $day['failed'] }}"></div>
                                @endif
                                @if ($day['confirmed'])
                                    <div class="w-full bg-emerald-500" style="flex-grow: {{ $day['confirmed'] }}"></div>
                                @endif
                            </div>
                        @endif
                    </div>
                    <span class="text-[10px] font-semibold tabular-nums {{ $rateTone($day['authorizationRate']) }}">
                        {{ $day['authorizationRate'] === null ? '—' : round($day['authorizationRate']).'%' }}
                    </span>
                    <span class="text-[10px] text-zinc-400 dark:text-zinc-500">{{ \Illuminate\Support\Carbon::parse($day['date'])->format('j M') }}</span>
                </div>
            @endforeach
        </div>
    </section>

    {{-- ── Where payments die + why ───────────────────────────────── --}}
    <div class="grid grid-cols-1 gap-4 lg:grid-cols-2">
        <section class="{{ $card }}">
            <h2 class="{{ $sectionTitle }}">Where Payments Die</h2>
            <p class="{{ $subtle }} mb-4">The checkout step each lost payment reached before it stopped.</p>
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
            <h2 class="{{ $sectionTitle }}">Failure Categories</h2>
            <p class="{{ $subtle }} mb-4">Who or what is responsible for the loss.</p>
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
            <div class="mt-4 space-y-1 border-t border-zinc-100 pt-3 text-[11px] text-zinc-400 dark:border-white/10 dark:text-zinc-500">
                <p><span class="font-semibold">Customer:</span> declines, wrong PIN, walked away. <span class="font-semibold">Provider:</span> gateway errors and timeouts.</p>
                <p><span class="font-semibold">Frontend:</span> browser blocked the payment. <span class="font-semibold">System:</span> our own bugs.</p>
            </div>
        </section>
    </div>

    {{-- ── Reasons + methods ──────────────────────────────────────── --}}
    <div class="grid grid-cols-1 gap-4 lg:grid-cols-2">
        <section class="{{ $card }} !p-0 overflow-hidden">
            <header class="px-5 pt-5 pb-3">
                <h2 class="{{ $sectionTitle }}">Top Failure Reasons</h2>
                <p class="{{ $subtle }}">The gateway's actual verdicts. "Retryable" means asking again could work; those are recovery-email candidates.</p>
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
                <p class="{{ $subtle }}">Which rails work. A weak method is a checkout decision, not just a statistic.</p>
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
                            <tr><td colspan="4" class="{{ $td }} py-8 text-center text-zinc-400">No method data yet. Newly captured on each payment attempt, so this fills in from here.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </section>
    </div>

    {{-- ── Recent lost payments ───────────────────────────────────── --}}
    <section class="{{ $card }} !p-0 overflow-hidden">
        <header class="px-5 pt-5 pb-3">
            <h2 class="{{ $sectionTitle }}">Recent Lost Payments</h2>
            <p class="{{ $subtle }}">
                Every payment that did not complete, declines and walk-aways alike, with the gateway's own words.
                The first place to look when a customer asks why their payment failed. Never shown to customers.
            </p>
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
                        <tr>
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
    </section>

    {{-- ── Transaction recovery ───────────────────────────────────── --}}
    <section class="{{ $card }}">
        <header class="mb-4">
            <h2 class="{{ $sectionTitle }}">Transaction Recovery</h2>
            <p class="{{ $subtle }}">Recovery emails sent for failed payments, and what customers did with them. "Recovered" counts payments that actually confirmed, never clicks.</p>
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
    <section class="{{ $card }}">
        <header class="mb-4 flex items-start justify-between gap-4">
            <div>
                <h2 class="{{ $sectionTitle }}">Frontend Failures</h2>
                <p class="{{ $subtle }}">Payments lost inside the customer's browser: blocked popups, SDK loads, script errors, plus non-terminal environment signals.</p>
            </div>
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
            <h2 class="{{ $sectionTitle }}">Recovery Skips</h2>
            <p class="{{ $subtle }} mb-4">Failed payments where no recovery email was sent, and why.</p>
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
            <p class="{{ $subtle }} mb-4">Reasons customers gave on the recovery page for not completing payment.</p>
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

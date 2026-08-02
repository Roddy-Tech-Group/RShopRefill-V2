<?php

use Livewire\Volt\Component;
use App\Domain\PaymentInsights\Services\PaymentInsightsMetrics;

use Livewire\Attributes\Layout;

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
        ];
    }
}; ?>

    <x-slot:heading>Payment Insights</x-slot:heading>

<div class="space-y-4 max-w-7xl mx-auto -mt-4">
    <div class="flex items-center justify-end">
        <select wire:model.live="timeframe" class="rounded-[12px] border-zinc-300 text-sm focus:border-blue-500 focus:ring-blue-500">
            <option value="24_hours">Last 24 Hours</option>
            <option value="7_days">Last 7 Days</option>
            <option value="30_days">Last 30 Days</option>
            <option value="all_time">All Time</option>
        </select>
    </div>

    <!-- Overview Cards -->
    <div class="grid grid-cols-1 gap-5 sm:grid-cols-4">
        <div class="overflow-hidden rounded-[16px] bg-white p-5 shadow-sm border border-zinc-100">
            <dt class="truncate text-sm font-medium text-zinc-500">Total Attempts</dt>
            <dd class="mt-1 text-3xl font-semibold tracking-tight text-zinc-900">{{ number_format($totalAttempts) }}</dd>
        </div>
        <div class="overflow-hidden rounded-[16px] bg-white p-5 shadow-sm border border-zinc-100">
            <dt class="truncate text-sm font-medium text-zinc-500">Success Rate</dt>
            <dd class="mt-1 text-3xl font-semibold tracking-tight text-emerald-600">{{ $successRate }}%</dd>
            <p class="text-xs text-zinc-400 mt-1">{{ number_format($successful) }} successful</p>
        </div>
        <div class="overflow-hidden rounded-[16px] bg-white p-5 shadow-sm border border-zinc-100">
            <dt class="truncate text-sm font-medium text-zinc-500">Failure Rate</dt>
            <dd class="mt-1 text-3xl font-semibold tracking-tight text-red-600">{{ $failureRate }}%</dd>
            <p class="text-xs text-zinc-400 mt-1">{{ number_format($failed) }} failed</p>
        </div>
        <div class="overflow-hidden rounded-[16px] bg-white p-5 shadow-sm border border-zinc-100">
            <dt class="truncate text-sm font-medium text-zinc-500">Abandon Rate (Expired/Cancelled)</dt>
            <dd class="mt-1 text-3xl font-semibold tracking-tight text-amber-600">{{ $abandonRate }}%</dd>
            <p class="text-xs text-zinc-400 mt-1">{{ number_format($abandoned) }} abandoned</p>
        </div>
    </div>

    <div class="grid grid-cols-1 gap-6 lg:grid-cols-2">
        <!-- Failure Buckets -->
        <div class="rounded-[16px] bg-white p-6 shadow-sm border border-zinc-100">
            <h2 class="text-lg font-bold text-zinc-900 mb-4">Failure Categories</h2>
            <div class="space-y-4">
                @foreach($buckets as $category => $count)
                    @php 
                        $totalFails = array_sum($buckets);
                        $pct = $totalFails > 0 ? round(($count / $totalFails) * 100) : 0;
                    @endphp
                    <div>
                        <div class="flex items-center justify-between text-sm">
                            <span class="font-medium text-zinc-700">{{ $category }}</span>
                            <span class="text-zinc-500">{{ number_format($count) }} ({{ $pct }}%)</span>
                        </div>
                        <div class="mt-2 h-2 w-full overflow-hidden rounded-full bg-zinc-100">
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
            
            <div class="mt-6 text-xs text-zinc-500 space-y-2">
                <p><strong>Customer:</strong> Insufficient funds, wrong PIN, user abandoned.</p>
                <p><strong>Provider:</strong> Gateway timeout, API errors, provider declined.</p>
                <p><strong>Frontend:</strong> JS errors, UI bugs blocking payment.</p>
                <p><strong>System:</strong> RShopRefills backend errors, database issues.</p>
            </div>
        </div>

        <!-- Top Failure Reasons -->
        <div class="rounded-[16px] bg-white p-6 shadow-sm border border-zinc-100">
            <h2 class="text-lg font-bold text-zinc-900 mb-1">Top Failure Reasons</h2>
            <p class="text-xs text-zinc-500 mb-4">The gateway's actual verdicts, graded. "Retryable" means asking the customer to try again could work; those are recovery-email candidates.</p>
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-zinc-200">
                    <thead>
                        <tr>
                            <th class="py-3 text-left text-xs font-medium text-zinc-500 uppercase tracking-wider">Reason</th>
                            <th class="py-3 text-right text-xs font-medium text-zinc-500 uppercase tracking-wider">Share</th>
                            <th class="py-3 text-right text-xs font-medium text-zinc-500 uppercase tracking-wider">Count</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-zinc-100">
                        @forelse($topReasons as $reason)
                            <tr>
                                <td class="py-3 pr-3 text-sm text-zinc-900">
                                    <span class="font-medium">{{ $reason->label }}</span>
                                    @if ($reason->retryable)
                                        <span class="ml-1.5 inline-flex items-center rounded-full bg-emerald-50 px-1.5 py-0.5 text-[10px] font-semibold text-emerald-700 ring-1 ring-emerald-200">retryable</span>
                                    @endif
                                    <span class="mt-0.5 block text-[11px] text-zinc-400">{{ $reason->failure_reason_code ?: 'unknown' }}</span>
                                </td>
                                <td class="py-3 text-sm text-zinc-500 text-right whitespace-nowrap">
                                    {{ $reason->share }}%
                                    <div class="mt-1 ml-auto h-1.5 w-16 overflow-hidden rounded-full bg-zinc-100">
                                        <div class="h-1.5 rounded-full bg-zinc-400" style="width: {{ min(100, $reason->share) }}%"></div>
                                    </div>
                                </td>
                                <td class="py-3 text-sm text-zinc-500 text-right">{{ number_format($reason->count) }}</td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="3" class="py-8 text-center text-sm text-zinc-500">No failures recorded in this timeframe.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- Recent failures: per-payment drill-down with the gateway's verbatim verdict -->
    <div class="rounded-[16px] bg-white p-6 shadow-sm border border-zinc-100">
        <h2 class="text-lg font-bold text-zinc-900 mb-1">Recent Failures</h2>
        <p class="text-xs text-zinc-500 mb-4">Latest failed payments with the gateway's own words; the first place to look when a customer asks "why did my payment fail?". Never shown to customers.</p>
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-zinc-200">
                <thead>
                    <tr>
                        <th class="py-3 pr-3 text-left text-xs font-medium text-zinc-500 uppercase tracking-wider">When</th>
                        <th class="py-3 pr-3 text-left text-xs font-medium text-zinc-500 uppercase tracking-wider">Provider · Method</th>
                        <th class="py-3 pr-3 text-left text-xs font-medium text-zinc-500 uppercase tracking-wider">Verdict</th>
                        <th class="py-3 text-left text-xs font-medium text-zinc-500 uppercase tracking-wider">Gateway said</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-zinc-100">
                    @forelse($recentFailures as $failure)
                        <tr>
                            <td class="py-3 pr-3 text-sm text-zinc-500 whitespace-nowrap">{{ \Illuminate\Support\Carbon::parse($failure['at'])->diffForHumans(short: true) }}</td>
                            <td class="py-3 pr-3 text-sm text-zinc-700 whitespace-nowrap">{{ $failure['provider'] ?: 'unknown' }}{{ $failure['payment_method'] ? ' · '.$failure['payment_method'] : '' }}</td>
                            <td class="py-3 pr-3 text-sm whitespace-nowrap">
                                <span class="font-medium text-zinc-900">{{ $failure['label'] }}</span>
                                @if ($failure['retryable'])
                                    <span class="ml-1.5 inline-flex items-center rounded-full bg-emerald-50 px-1.5 py-0.5 text-[10px] font-semibold text-emerald-700 ring-1 ring-emerald-200">retryable</span>
                                @endif
                            </td>
                            <td class="py-3 text-sm text-zinc-500">
                                {{ $failure['provider_message'] ?? $failure['raw_reason'] ?? 'No diagnostic captured' }}
                                @if ($failure['provider_code'] && $failure['provider_code'] !== $failure['provider_message'])
                                    <span class="block text-[11px] text-zinc-400">code: {{ $failure['provider_code'] }}</span>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="4" class="py-8 text-center text-sm text-zinc-500">No failed payments in this timeframe.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <!-- Frontend Failures -->
    <div class="rounded-[16px] bg-white p-6 shadow-sm border border-zinc-100">
        <div class="flex items-start justify-between mb-4">
            <div>
                <h2 class="text-lg font-bold text-zinc-900 mb-1">Frontend Failures</h2>
                <p class="text-xs text-zinc-500">Payment failures originating in the customer's browser — blocked popups, SDK loads, JS errors — plus non-terminal environment signals.</p>
            </div>
            <div class="text-right shrink-0 ml-4">
                <dd class="text-3xl font-semibold tracking-tight text-purple-600">{{ $frontend['shareOfFailures'] }}%</dd>
                <dt class="text-xs text-zinc-400">of all failures ({{ number_format($frontend['total']) }})</dt>
            </div>
        </div>
        <div class="grid grid-cols-1 gap-6 sm:grid-cols-2 lg:grid-cols-4">
            <div>
                <h3 class="text-xs font-medium text-zinc-500 uppercase tracking-wider mb-3">By Reason</h3>
                <div class="space-y-2">
                    @forelse($frontend['byCode'] as $code => $count)
                        <div class="flex items-center justify-between text-sm">
                            <span class="font-medium text-zinc-700">{{ str_replace('_', ' ', ucfirst((string) $code)) }}</span>
                            <span class="text-zinc-500">{{ number_format($count) }}</span>
                        </div>
                    @empty
                        <p class="py-4 text-sm text-zinc-500">No frontend failures in this timeframe.</p>
                    @endforelse
                </div>
            </div>
            <div>
                <h3 class="text-xs font-medium text-zinc-500 uppercase tracking-wider mb-3">Environment Signals</h3>
                <div class="space-y-2">
                    @forelse($frontend['signals'] as $code => $count)
                        <div class="flex items-center justify-between text-sm">
                            <span class="font-medium text-zinc-700">{{ str_replace('_', ' ', ucfirst((string) $code)) }}</span>
                            <span class="text-zinc-500">{{ number_format($count) }}</span>
                        </div>
                    @empty
                        <p class="py-4 text-sm text-zinc-500">No signals reported.</p>
                    @endforelse
                </div>
            </div>
            <div>
                <h3 class="text-xs font-medium text-zinc-500 uppercase tracking-wider mb-3">By Provider</h3>
                <div class="space-y-2">
                    @forelse($frontend['byProvider'] as $provider => $count)
                        <div class="flex items-center justify-between text-sm">
                            <span class="font-medium text-zinc-700">{{ ucfirst((string) $provider) }}</span>
                            <span class="text-zinc-500">{{ number_format($count) }}</span>
                        </div>
                    @empty
                        <p class="py-4 text-sm text-zinc-500">&mdash;</p>
                    @endforelse
                </div>
            </div>
            <div>
                <h3 class="text-xs font-medium text-zinc-500 uppercase tracking-wider mb-3">By Browser</h3>
                <div class="space-y-2">
                    @forelse($frontend['browsers'] as $browser => $count)
                        <div class="flex items-center justify-between text-sm">
                            <span class="font-medium text-zinc-700">{{ $browser }}</span>
                            <span class="text-zinc-500">{{ number_format($count) }}</span>
                        </div>
                    @empty
                        <p class="py-4 text-sm text-zinc-500">&mdash;</p>
                    @endforelse
                </div>
            </div>
        </div>
    </div>

    <!-- Recovery Funnel -->
    <div class="rounded-[16px] bg-white p-6 shadow-sm border border-zinc-100">
        <h2 class="text-lg font-bold text-zinc-900 mb-1">Transaction Recovery</h2>
        <p class="text-xs text-zinc-500 mb-4">Recovery emails sent for failed payments, and what customers did with them.</p>
        <div class="grid grid-cols-2 gap-5 sm:grid-cols-4">
            <div>
                <dt class="truncate text-sm font-medium text-zinc-500">Recoveries Sent</dt>
                <dd class="mt-1 text-3xl font-semibold tracking-tight text-zinc-900">{{ number_format($recovery['sent']) }}</dd>
                <p class="text-xs text-zinc-400 mt-1">{{ number_format($recovery['cancelled']) }} auto-cancelled, {{ number_format($recovery['abandoned']) }} lapsed</p>
            </div>
            <div>
                <dt class="truncate text-sm font-medium text-zinc-500">Click Rate</dt>
                <dd class="mt-1 text-3xl font-semibold tracking-tight text-blue-600">{{ $recovery['clickRate'] }}%</dd>
                <p class="text-xs text-zinc-400 mt-1">{{ number_format($recovery['clicked']) }} opened their link</p>
            </div>
            <div>
                <dt class="truncate text-sm font-medium text-zinc-500">Recovery Rate</dt>
                <dd class="mt-1 text-3xl font-semibold tracking-tight text-emerald-600">{{ $recovery['conversionRate'] }}%</dd>
                <p class="text-xs text-zinc-400 mt-1">{{ number_format($recovery['recovered']) }} resumed payment</p>
            </div>
            <div>
                <dt class="truncate text-sm font-medium text-zinc-500">Recovered Value</dt>
                <dd class="mt-1 text-lg font-semibold tracking-tight text-zinc-900">
                    @forelse($recovery['recoveredValue'] as $currency => $amount)
                        <div>{{ $currency }} {{ number_format((float) $amount, 2) }}</div>
                    @empty
                        <div class="text-zinc-400">&mdash;</div>
                    @endforelse
                </dd>
            </div>
        </div>
    </div>

    <div class="grid grid-cols-1 gap-6 lg:grid-cols-3">
        <!-- Provider Success Rates -->
        <div class="rounded-[16px] bg-white p-6 shadow-sm border border-zinc-100">
            <h2 class="text-lg font-bold text-zinc-900 mb-4">Provider Success Rate</h2>
            <table class="min-w-full divide-y divide-zinc-200">
                <thead>
                    <tr>
                        <th class="py-3 text-left text-xs font-medium text-zinc-500 uppercase tracking-wider">Provider</th>
                        <th class="py-3 text-right text-xs font-medium text-zinc-500 uppercase tracking-wider">Attempts</th>
                        <th class="py-3 text-right text-xs font-medium text-zinc-500 uppercase tracking-wider">Success</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-zinc-100">
                    @forelse($providers as $provider)
                        <tr>
                            <td class="py-3 text-sm text-zinc-900">{{ $provider->provider }}</td>
                            <td class="py-3 text-sm text-zinc-500 text-right">{{ number_format($provider->total) }}</td>
                            <td class="py-3 text-sm text-right font-medium {{ $provider->success_rate >= 80 ? 'text-emerald-600' : ($provider->success_rate >= 50 ? 'text-amber-600' : 'text-red-600') }}">{{ $provider->success_rate }}%</td>
                        </tr>
                    @empty
                        <tr><td colspan="3" class="py-8 text-center text-sm text-zinc-500">No provider outcomes in this timeframe.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <!-- Recovery Skip Reasons -->
        <div class="rounded-[16px] bg-white p-6 shadow-sm border border-zinc-100">
            <h2 class="text-lg font-bold text-zinc-900 mb-4">Recovery Skips</h2>
            <p class="text-xs text-zinc-500 mb-4">Failed payments where no recovery email was sent, and why.</p>
            <div class="space-y-3">
                @forelse($recovery['skipReasons'] as $reason => $count)
                    <div class="flex items-center justify-between text-sm">
                        <span class="font-medium text-zinc-700">{{ str_replace('_', ' ', ucfirst((string) $reason)) }}</span>
                        <span class="text-zinc-500">{{ number_format($count) }}</span>
                    </div>
                @empty
                    <p class="py-6 text-center text-sm text-zinc-500">No skipped recoveries in this timeframe.</p>
                @endforelse
            </div>
        </div>

        <!-- Feedback Breakdown -->
        <div class="rounded-[16px] bg-white p-6 shadow-sm border border-zinc-100">
            <h2 class="text-lg font-bold text-zinc-900 mb-4">Customer Feedback</h2>
            <p class="text-xs text-zinc-500 mb-4">Reasons customers gave on the recovery page for not completing payment.</p>
            <div class="space-y-3">
                @forelse($recovery['feedbackBreakdown'] as $reason => $count)
                    <div class="flex items-center justify-between text-sm">
                        <span class="font-medium text-zinc-700">{{ str_replace('_', ' ', ucfirst((string) $reason)) }}</span>
                        <span class="text-zinc-500">{{ number_format($count) }}</span>
                    </div>
                @empty
                    <p class="py-6 text-center text-sm text-zinc-500">No feedback received in this timeframe.</p>
                @endforelse
            </div>
        </div>
    </div>
</div>

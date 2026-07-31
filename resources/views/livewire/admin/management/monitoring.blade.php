<?php

use App\Domain\Admin\Services\MonitoringSnapshotService;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;

new
#[Layout('components.layouts.admin')]
#[Title('Monitoring')]
class extends Component {
    public function with(): array
    {
        return [
            'snapshot' => app(MonitoringSnapshotService::class)->snapshot(),
        ];
    }
}; ?>

<x-slot:heading>Monitoring</x-slot:heading>

@php
    $card = 'rounded-[12px] ring-1 ring-zinc-200 bg-white p-5 shadow-sm shadow-zinc-900/[0.04] dark:ring-white/10 dark:bg-[#1d3252]';
    $sectionTitle = 'text-sm font-bold text-zinc-900 dark:text-white';
    $label = 'text-[13px] font-medium text-zinc-500 dark:text-zinc-400';
    $value = 'mt-1 text-xl font-semibold tracking-tight text-zinc-900 dark:text-white tabular-nums';
    $ok = 'inline-flex items-center gap-1.5 rounded-full bg-emerald-50 px-2.5 py-0.5 text-[11px] font-semibold text-emerald-700 ring-1 ring-emerald-200 dark:bg-emerald-500/10 dark:text-emerald-300 dark:ring-emerald-500/30';
    $bad = 'inline-flex items-center gap-1.5 rounded-full bg-red-50 px-2.5 py-0.5 text-[11px] font-semibold text-red-700 ring-1 ring-red-200 dark:bg-red-500/10 dark:text-red-300 dark:ring-red-500/30';
    $warn = 'inline-flex items-center gap-1.5 rounded-full bg-amber-50 px-2.5 py-0.5 text-[11px] font-semibold text-amber-700 ring-1 ring-amber-200 dark:bg-amber-500/10 dark:text-amber-300 dark:ring-amber-500/30';
    $muted = 'inline-flex items-center gap-1.5 rounded-full bg-zinc-100 px-2.5 py-0.5 text-[11px] font-semibold text-zinc-500 ring-1 ring-zinc-200 dark:bg-white/5 dark:text-zinc-400 dark:ring-white/10';

    $health = $snapshot['health'];
    $backup = $snapshot['backup'];
    $ledger = $snapshot['ledger'];
    $recon = $snapshot['reconciliation'];
    $jobs = $snapshot['jobs'];
    $orders = $snapshot['orders'];
    $fx = $snapshot['fx'];
    $providers = $snapshot['providers'];
    $disk = $snapshot['disk'];

    // Provider float balances, keyed to match $providers. Red at or below the
    // low-water mark: a provider with an empty float fails orders exactly like
    // an outage, but throws no errors anywhere.
    $providerBalances = [
        'zendit' => $snapshot['zendit_balance'],
        'airalo' => $snapshot['airalo_balance'],
    ];
    $balanceFloor = 250;

    $componentLabels = [
        'database' => 'Database',
        'cache' => 'Cache',
        'redis' => 'Redis',
        'queue' => 'Horizon',
        'scheduler' => 'Scheduler',
        'alerting' => 'Alerting',
    ];

    $fxStale = $fx['newest_age_hours'] !== null && $fx['newest_age_hours'] > 26;
@endphp

<div class="space-y-6 max-w-7xl mx-auto -mt-2" wire:poll.60s>
    <div class="flex items-center justify-between gap-3">
        <p class="text-[12px] text-zinc-500 dark:text-zinc-400">
            Providers &amp; platform health. Payment gateways live in
            <a href="{{ route('admin.payment-insights') }}" wire:navigate class="font-semibold text-blue-600 hover:text-blue-500 dark:text-blue-400">Payment Insights</a>.
        </p>
        <p class="text-[12px] tabular-nums text-zinc-400 dark:text-zinc-500">
            Updated {{ \Illuminate\Support\Carbon::parse($snapshot['generated_at'])->diffForHumans() }} · auto-refreshes every minute
        </p>
    </div>

    {{-- ── System components ─────────────────────────────────────── --}}
    <section class="{{ $card }}">
        <header class="mb-4 flex items-center justify-between">
            <h2 class="{{ $sectionTitle }}">System</h2>
            <span class="{{ ($health['status'] ?? 'fail') === 'ok' ? $ok : $bad }}">
                {{ ($health['status'] ?? 'fail') === 'ok' ? 'All systems go' : 'Component failing' }}
            </span>
        </header>
        <div class="grid grid-cols-2 gap-3 sm:grid-cols-4 lg:grid-cols-8">
            @foreach ($componentLabels as $key => $componentLabel)
                @php $component = $health['components'][$key] ?? null; @endphp
                <div class="rounded-[10px] bg-zinc-50 p-3 ring-1 ring-zinc-100 dark:bg-white/5 dark:ring-white/10">
                    <p class="{{ $label }}">{{ $componentLabel }}</p>
                    <p class="mt-1.5">
                        @if ($component === null)
                            <span class="{{ $muted }}">n/a</span>
                        @elseif (($component['status'] ?? 'fail') === 'ok')
                            <span class="{{ $ok }}">OK</span>
                        @else
                            <span class="{{ $bad }}" title="{{ $component['message'] ?? '' }}">FAIL</span>
                        @endif
                    </p>
                </div>
            @endforeach

            {{-- Backups --}}
            @php $backupHealthy = (bool) ($backup['healthy'] ?? false); @endphp
            <div class="rounded-[10px] bg-zinc-50 p-3 ring-1 ring-zinc-100 dark:bg-white/5 dark:ring-white/10">
                <p class="{{ $label }}">Backups</p>
                <p class="mt-1.5">
                    <span class="{{ $backupHealthy ? $ok : $bad }}">{{ $backupHealthy ? 'OK' : 'CHECK' }}</span>
                </p>
            </div>

            {{-- Disk space: logs, backups and MySQL share this partition; a
                 full disk stops all three at once, usually silently. --}}
            <div class="rounded-[10px] bg-zinc-50 p-3 ring-1 ring-zinc-100 dark:bg-white/5 dark:ring-white/10">
                <p class="{{ $label }}">Disk</p>
                <p class="mt-1.5">
                    @if ($disk === null)
                        <span class="{{ $muted }}">n/a</span>
                    @else
                        <span class="{{ $disk['used_pct'] >= 90 ? $bad : ($disk['used_pct'] >= 75 ? $warn : $ok) }}" title="{{ $disk['free_gb'] }} GB free of {{ $disk['total_gb'] }} GB">
                            {{ number_format($disk['used_pct'], 0) }}% used
                        </span>
                    @endif
                </p>
            </div>
        </div>
        @if ($disk !== null && $disk['used_pct'] >= 75)
            <p class="mt-3 text-[12px] text-amber-700 dark:text-amber-300">
                Only {{ $disk['free_gb'] }} GB free of {{ $disk['total_gb'] }} GB. Clear old logs in <code class="rounded bg-zinc-100 px-1 py-0.5 text-[11px] dark:bg-white/10">storage/logs</code> and confirm backup rotation is pruning; a full disk stops MySQL, backups and logging at once.
            </p>
        @endif
        @unless ($backupHealthy)
            <p class="mt-3 text-[12px] text-amber-700 dark:text-amber-300">
                Backup health is not green — run <code class="rounded bg-zinc-100 px-1 py-0.5 text-[11px] dark:bg-white/10">php artisan backup:health</code> for the reason, then the
                <a href="{{ route('admin.management.runbooks', 'database-restore') }}" wire:navigate class="font-semibold underline">backup runbook</a>.
            </p>
        @endunless
    </section>

    {{-- ── Money integrity ───────────────────────────────────────── --}}
    <section class="{{ $card }}">
        <header class="mb-4 flex items-center justify-between">
            <h2 class="{{ $sectionTitle }}">Money integrity</h2>
        </header>
        <div class="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-4">
            <div class="rounded-[10px] bg-zinc-50 p-4 ring-1 ring-zinc-100 dark:bg-white/5 dark:ring-white/10">
                <p class="{{ $label }}">Ledger integrity</p>
                <p class="mt-1.5">
                    @if (($ledger['status'] ?? '') === 'passed')
                        <span class="{{ $ok }}">Passed · {{ count($ledger['checks'] ?? []) }} checks</span>
                    @else
                        <span class="{{ $bad }}">FAILED · {{ count($ledger['violations'] ?? []) }} violations</span>
                    @endif
                </p>
                <p class="mt-2 text-[11px] text-zinc-400">Nightly <code>ledger:verify</code> · recomputed live every 5 min</p>
            </div>

            <div class="rounded-[10px] bg-zinc-50 p-4 ring-1 ring-zinc-100 dark:bg-white/5 dark:ring-white/10">
                <p class="{{ $label }}">Wallet reconciliation</p>
                <p class="mt-1.5">
                    @if ($recon === null)
                        <span class="{{ $muted }}">No run recorded yet</span>
                    @elseif ($recon['status'] === 'clean')
                        <span class="{{ $ok }}">Clean</span>
                    @else
                        <span class="{{ $bad }}">{{ $recon['anomalies'] }} anomalies</span>
                    @endif
                </p>
                <p class="mt-2 text-[11px] text-zinc-400">
                    {{ $recon && $recon['completed_at'] ? 'Last run '.\Illuminate\Support\Carbon::parse($recon['completed_at'])->diffForHumans() : 'Runs nightly at 02:00' }}
                </p>
            </div>

            <div class="rounded-[10px] bg-zinc-50 p-4 ring-1 ring-zinc-100 dark:bg-white/5 dark:ring-white/10">
                <p class="{{ $label }}">Stuck paid orders</p>
                <p class="{{ $value }}">{{ number_format($orders['stuck']) }}</p>
                <p class="mt-1 text-[11px] text-zinc-400">Paid &gt; 15 min, fulfilment never started; each one is a missing dispatch</p>
            </div>

            <div class="rounded-[10px] bg-zinc-50 p-4 ring-1 ring-zinc-100 dark:bg-white/5 dark:ring-white/10">
                <p class="{{ $label }}">Requires attention</p>
                <p class="{{ $value }}">{{ number_format($orders['requires_attention']) }}</p>
                <p class="mt-1 text-[11px] text-zinc-400">Orders flagged for a human decision</p>
            </div>
        </div>
    </section>

    {{-- ── Queues & jobs ─────────────────────────────────────────── --}}
    <section class="{{ $card }}">
        <header class="mb-4 flex items-center justify-between">
            <h2 class="{{ $sectionTitle }}">Queues &amp; jobs</h2>
            <span class="{{ $jobs['failed_24h'] === 0 ? $ok : ($jobs['failed_24h'] > 10 ? $bad : $warn) }}">
                {{ number_format($jobs['failed_24h']) }} failed in 24h
            </span>
        </header>
        @if ($jobs['failed_24h'] === 0)
            <p class="text-[13px] text-zinc-500 dark:text-zinc-400">No failed jobs in the last 24 hours.</p>
        @else
            <div class="flex flex-wrap gap-2">
                @foreach ($jobs['by_queue'] as $queue => $failures)
                    <span class="{{ $warn }}">{{ $queue }} · {{ $failures }}</span>
                @endforeach
            </div>
            <p class="mt-3 text-[12px] text-zinc-500 dark:text-zinc-400">
                Inspect and retry from Horizon (<code class="rounded bg-zinc-100 px-1 py-0.5 text-[11px] dark:bg-white/10">/horizon</code>); the failed list expires after 7 days.
            </p>
        @endif

        @php
            $worstWait = $snapshot['queues']['worst'];
            $sweeper = $snapshot['sweeper'];
        @endphp
        <div class="mt-4 grid grid-cols-1 gap-3 border-t border-zinc-100 pt-4 sm:grid-cols-2 dark:border-white/10">
            {{-- Failed jobs are work that died; a long wait is work that is
                 quietly late, with zero errors anywhere. --}}
            <div class="rounded-[10px] bg-zinc-50 p-4 ring-1 ring-zinc-100 dark:bg-white/5 dark:ring-white/10">
                <p class="{{ $label }}">Worst queue wait</p>
                <p class="mt-1.5">
                    @if ($worstWait === null)
                        <span class="{{ $muted }}">No pending jobs</span>
                    @else
                        <span class="{{ $worstWait['seconds'] >= 300 ? $bad : ($worstWait['seconds'] >= 60 ? $warn : $ok) }}">
                            {{ $worstWait['queue'] }} · {{ $worstWait['seconds'] >= 120 ? number_format($worstWait['seconds'] / 60, 1).' min' : number_format($worstWait['seconds'], 0).'s' }}
                        </span>
                    @endif
                </p>
                <p class="mt-2 text-[11px] text-zinc-400">How long the oldest waiting job has stood in line; long waits mean customers are waiting too</p>
            </div>

            <div class="rounded-[10px] bg-zinc-50 p-4 ring-1 ring-zinc-100 dark:bg-white/5 dark:ring-white/10">
                <p class="{{ $label }}">Sweeper pickups (24h)</p>
                <p class="mt-1.5">
                    <span class="{{ $sweeper['pickups_24h'] === 0 ? $ok : ($sweeper['pickups_24h'] >= 3 ? $bad : $warn) }}">
                        {{ number_format($sweeper['pickups_24h']) }} rescued
                        @if ($sweeper['pickups_1h'] > 0)
                            · {{ number_format($sweeper['pickups_1h']) }} in the last hour
                        @endif
                    </span>
                </p>
                <p class="mt-2 text-[11px] text-zinc-400">Paid orders the safety net had to rescue; every one is a provider webhook that went missing, so this should be zero</p>
            </div>
        </div>
    </section>

    {{-- ── Fulfilment providers ──────────────────────────────────── --}}
    <section class="grid grid-cols-1 gap-4 lg:grid-cols-2">
        @foreach ($providers as $providerKey => $provider)
            @php
                $day = $provider['24h'];
                $week = $provider['7d'];
                $rate = $day['success_rate'];
                $ratePill = $rate === null ? $muted : ($rate >= 98 ? $ok : ($rate >= 90 ? $warn : $bad));
            @endphp
            <div class="{{ $card }}">
                <header class="mb-4 flex items-center justify-between">
                    <h2 class="{{ $sectionTitle }} capitalize">{{ $providerKey }}</h2>
                    <div class="flex items-center gap-2">
                        @if ($provider['circuit_breaker_open'] === true)
                            <span class="{{ $bad }}">Circuit breaker OPEN</span>
                        @endif
                        <span class="{{ $ratePill }}">
                            {{ $rate === null ? 'No decided items (24h)' : number_format($rate, 1).'% success (24h)' }}
                        </span>
                    </div>
                </header>
                <div class="grid grid-cols-3 gap-3 sm:grid-cols-6">
                    <div><p class="{{ $label }}">Attempts 24h</p><p class="{{ $value }}">{{ number_format($day['attempts']) }}</p></div>
                    <div><p class="{{ $label }}">Errors 24h</p><p class="{{ $value }} {{ $day['errors'] > 0 ? 'text-red-600 dark:text-red-400' : '' }}">{{ number_format($day['errors']) }}</p></div>
                    <div><p class="{{ $label }}">Fulfilled 24h</p><p class="{{ $value }}">{{ number_format($day['fulfilled']) }}</p></div>
                    <div><p class="{{ $label }}">Failed 24h</p><p class="{{ $value }} {{ $day['failed'] > 0 ? 'text-red-600 dark:text-red-400' : '' }}">{{ number_format($day['failed']) }}</p></div>
                    <div><p class="{{ $label }}">In flight</p><p class="{{ $value }}">{{ number_format($day['in_flight']) }}</p></div>
                    <div>
                        <p class="{{ $label }}">7-day rate</p>
                        <p class="{{ $value }}">{{ $week['success_rate'] === null ? '—' : number_format($week['success_rate'], 1).'%' }}</p>
                    </div>
                </div>
                <div class="mt-4 flex flex-wrap items-center justify-between gap-2 border-t border-zinc-100 pt-3 dark:border-white/10">
                    <p class="text-[12px] text-zinc-500 dark:text-zinc-400">
                        Last activity:
                        <span class="font-semibold text-zinc-700 dark:text-zinc-200">
                            {{ $provider['last_activity_at'] ? \Illuminate\Support\Carbon::parse($provider['last_activity_at'])->diffForHumans() : 'never' }}
                        </span>
                    </p>
                    @php $providerBalance = $providerBalances[$providerKey] ?? null; @endphp
                    <p class="text-[12px] text-zinc-500 dark:text-zinc-400">
                        Balance:
                        @if ($providerBalance === null)
                            <span class="font-semibold text-zinc-400">unavailable</span>
                        @else
                            <span class="font-semibold {{ $providerBalance['balance'] <= $balanceFloor ? 'text-red-600 dark:text-red-400' : 'text-zinc-700 dark:text-zinc-200' }}">
                                @moneyCode($providerBalance['balance'], $providerBalance['currency'])
                            </span>
                        @endif
                    </p>
                </div>
            </div>
        @endforeach
    </section>

    {{-- ── FX freshness ──────────────────────────────────────────── --}}
    <section class="{{ $card }}">
        <header class="mb-4 flex items-center justify-between">
            <h2 class="{{ $sectionTitle }}">Exchange rates</h2>
            <span class="{{ $fx['newest_age_hours'] === null ? $muted : ($fxStale ? $bad : $ok) }}">
                @if ($fx['newest_age_hours'] === null)
                    No active rates
                @elseif ($fxStale)
                    Stale · {{ number_format($fx['newest_age_hours'], 1) }}h old
                @else
                    Fresh · {{ number_format($fx['newest_age_hours'], 1) }}h old
                @endif
            </span>
        </header>
        <p class="text-[13px] text-zinc-500 dark:text-zinc-400">
            {{ number_format($fx['active_rates']) }} active currency rates. Rates older than 26h misprice the entire catalog;
            if stale, run <code class="rounded bg-zinc-100 px-1 py-0.5 text-[11px] dark:bg-white/10">php artisan rates:fetch</code> and see the
            <a href="{{ route('admin.management.runbooks', 'fx-failure') }}" wire:navigate class="font-semibold text-blue-600 hover:text-blue-500 dark:text-blue-400">FX runbook</a>.
        </p>
    </section>
</div>

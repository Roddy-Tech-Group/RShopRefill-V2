@php
    $m = $metrics;
    $enf = $m['enforcement'];
    $windows = [7 => '7 days', 30 => '30 days', 90 => '90 days'];

    // Full class strings (not interpolated) so Tailwind's JIT keeps them.
    $bandBar = [
        'LOW' => 'bg-emerald-500',
        'MEDIUM' => 'bg-blue-500',
        'HIGH' => 'bg-amber-500',
        'CRITICAL' => 'bg-red-500',
    ];
    $bandTotal = max(1, array_sum($m['bands']));
@endphp

<x-layouts.admin>
    <x-slot:heading>Risk Engine</x-slot:heading>
    <x-slot:subheading>
        Fraud &amp; risk decisions over the last {{ $windowDays }} days.
    </x-slot:subheading>

    @if (session('status'))
        <div class="mb-4 rounded-[12px] bg-emerald-50 px-4 py-3 text-sm font-medium text-emerald-700 ring-1 ring-emerald-200 dark:bg-emerald-500/10 dark:text-emerald-300 dark:ring-emerald-500/30">
            {{ session('status') }}
        </div>
    @endif

    {{-- Enforcement status + window selector --}}
    <div class="mb-6 flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
        <div class="flex flex-wrap items-center gap-2">
            @if ($enf['scoring_on'])
                <x-admin.badge tone="emerald">Scoring ON</x-admin.badge>
            @else
                <x-admin.badge tone="zinc">Scoring OFF</x-admin.badge>
            @endif
            @if ($enf['enforcing'])
                <x-admin.badge tone="red">Enforcing</x-admin.badge>
            @else
                <x-admin.badge tone="amber">Shadow mode</x-admin.badge>
            @endif
            <a href="{{ route('admin.risk.reviews') }}" class="ml-2 inline-flex items-center gap-1 rounded-[10px] bg-blue-600 px-3 py-1.5 text-sm font-semibold text-white hover:bg-blue-700">
                Review queue
                @if ($m['reviews']['pending'] > 0)
                    <span class="rounded-full bg-white/25 px-1.5 text-xs">{{ $m['reviews']['pending'] }}</span>
                @endif
            </a>
        </div>
        <div class="inline-flex overflow-x-auto rounded-[12px] bg-zinc-100 p-1 text-sm dark:bg-zinc-800/50">
            @foreach ($windows as $days => $label)
                <a href="{{ route('admin.risk.dashboard', ['window' => $days]) }}"
                   class="whitespace-nowrap rounded-[12px] px-3 py-1.5 {{ $windowDays === $days ? 'bg-white text-zinc-900 shadow-sm dark:bg-[#1d3252] dark:text-white' : 'text-zinc-600 dark:text-zinc-400' }}">
                    {{ $label }}
                </a>
            @endforeach
        </div>
    </div>

    @if (! $enf['enforcing'])
        <div class="mb-6 rounded-[12px] bg-amber-50 px-4 py-3 text-sm text-amber-800 ring-1 ring-amber-200 dark:bg-amber-500/10 dark:text-amber-300 dark:ring-amber-500/30">
            <strong>Shadow mode.</strong> The engine is scoring and recording decisions but not acting on them.
            It <em>would</em> have acted on <strong>{{ number_format($m['shadow_would_act']) }}</strong> of
            {{ number_format($m['total_assessments']) }} assessments in this window. Review these before enabling enforcement.
        </div>
    @endif

    {{-- KPI cards --}}
    <div class="grid grid-cols-2 gap-4 lg:grid-cols-4">
        @foreach ([
            ['label' => 'Assessments', 'value' => number_format($m['total_assessments']), 'sub' => 'scored'],
            ['label' => 'Review rate', 'value' => $m['rates']['review_rate'].'%', 'sub' => number_format($m['outcomes']['review']).' held'],
            ['label' => 'Decline rate', 'value' => $m['rates']['decline_rate'].'%', 'sub' => number_format($m['outcomes']['deny']).' denied'],
            ['label' => 'Step-up rate', 'value' => $m['rates']['step_up_rate'].'%', 'sub' => number_format($m['outcomes']['step_up']).' verified'],
        ] as $card)
            <div class="rounded-[12px] bg-white p-5 shadow-sm ring-1 ring-zinc-200 dark:bg-[#1d3252] dark:ring-white/10">
                <div class="text-xs font-medium uppercase tracking-wide text-zinc-500 dark:text-zinc-400">{{ $card['label'] }}</div>
                <div class="mt-1 text-2xl font-bold text-zinc-900 dark:text-white">{{ $card['value'] }}</div>
                <div class="text-xs text-zinc-500 dark:text-zinc-400">{{ $card['sub'] }}</div>
            </div>
        @endforeach
    </div>

    <div class="mt-6 grid gap-6 lg:grid-cols-3">
        {{-- Band distribution --}}
        <div class="rounded-[12px] bg-white p-6 shadow-sm ring-1 ring-zinc-200 dark:bg-[#1d3252] dark:ring-white/10 lg:col-span-2">
            <h3 class="text-sm font-semibold text-zinc-900 dark:text-white">Band distribution</h3>
            <div class="mt-4 space-y-3">
                @foreach ($m['bands'] as $band => $count)
                    <div>
                        <div class="flex justify-between text-xs text-zinc-500 dark:text-zinc-400">
                            <span>{{ $band }}</span>
                            <span>{{ number_format($count) }}</span>
                        </div>
                        <div class="mt-1 h-2 overflow-hidden rounded-full bg-zinc-100 dark:bg-white/10">
                            <div class="h-full rounded-full {{ $bandBar[$band] ?? 'bg-zinc-400' }}" style="width: {{ round($count / $bandTotal * 100) }}%"></div>
                        </div>
                    </div>
                @endforeach
            </div>
        </div>

        {{-- Queue health --}}
        <div class="rounded-[12px] bg-white p-6 shadow-sm ring-1 ring-zinc-200 dark:bg-[#1d3252] dark:ring-white/10">
            <h3 class="text-sm font-semibold text-zinc-900 dark:text-white">Review queue</h3>
            <dl class="mt-4 space-y-2 text-sm">
                <div class="flex justify-between"><dt class="text-zinc-500 dark:text-zinc-400">Pending</dt><dd class="font-semibold text-zinc-900 dark:text-white">{{ $m['reviews']['pending'] }}</dd></div>
                <div class="flex justify-between"><dt class="text-zinc-500 dark:text-zinc-400">Escalated</dt><dd class="font-semibold text-zinc-900 dark:text-white">{{ $m['reviews']['escalated'] }}</dd></div>
                <div class="flex justify-between"><dt class="text-zinc-500 dark:text-zinc-400">SLA breached</dt><dd class="font-semibold {{ $m['reviews']['sla_breached'] > 0 ? 'text-red-600 dark:text-red-400' : 'text-zinc-900 dark:text-white' }}">{{ $m['reviews']['sla_breached'] }}</dd></div>
                <div class="flex justify-between border-t border-zinc-100 pt-2 dark:border-white/10"><dt class="text-zinc-500 dark:text-zinc-400">Approved</dt><dd class="text-zinc-700 dark:text-zinc-300">{{ $m['reviews']['approved'] }}</dd></div>
                <div class="flex justify-between"><dt class="text-zinc-500 dark:text-zinc-400">Rejected</dt><dd class="text-zinc-700 dark:text-zinc-300">{{ $m['reviews']['rejected'] }}</dd></div>
            </dl>
        </div>
    </div>

    <div class="mt-6 grid gap-6 lg:grid-cols-3">
        {{-- Chargeback rate --}}
        <div class="rounded-[12px] bg-white p-6 shadow-sm ring-1 ring-zinc-200 dark:bg-[#1d3252] dark:ring-white/10">
            <h3 class="text-sm font-semibold text-zinc-900 dark:text-white">Chargeback rate</h3>
            @php $cb = $m['chargeback']; $over = $cb['rate'] > $cb['ceiling']; @endphp
            <div class="mt-3 text-3xl font-bold {{ $over ? 'text-red-600 dark:text-red-400' : 'text-emerald-600 dark:text-emerald-400' }}">{{ $cb['rate'] }}%</div>
            <p class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">
                {{ number_format($cb['chargebacks']) }} chargebacks / {{ number_format($cb['paid_orders']) }} paid orders.
                Ceiling {{ $cb['ceiling'] }}%.
            </p>
        </div>

        {{-- Top signals --}}
        <div class="rounded-[12px] bg-white p-6 shadow-sm ring-1 ring-zinc-200 dark:bg-[#1d3252] dark:ring-white/10 lg:col-span-2">
            <h3 class="text-sm font-semibold text-zinc-900 dark:text-white">Top firing signals</h3>
            <div class="mt-3 overflow-x-auto">
                <table class="w-full text-left text-sm">
                    <tbody class="divide-y divide-zinc-100 dark:divide-white/5">
                        @forelse ($m['top_signals'] as $signal)
                            <tr>
                                <td class="py-2 font-mono text-xs font-semibold text-zinc-900 dark:text-white">{{ $signal['code'] }}</td>
                                <td class="py-2 text-right text-zinc-500 dark:text-zinc-400">{{ number_format($signal['hits']) }}</td>
                            </tr>
                        @empty
                            <tr><td class="py-3 text-sm text-zinc-500 dark:text-zinc-400">No signals fired yet.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    {{-- Manual label (feedback loop) --}}
    <div class="mt-6 rounded-[12px] bg-white p-6 shadow-sm ring-1 ring-zinc-200 dark:bg-[#1d3252] dark:ring-white/10">
        <h3 class="text-sm font-semibold text-zinc-900 dark:text-white">Record a label</h3>
        <p class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">Feed the engine ground truth: a chargeback, confirmed fraud, a false positive, or a good actor.</p>
        <form method="POST" action="{{ route('admin.risk.label') }}" class="mt-4 flex flex-col gap-3 sm:flex-row sm:items-end">
            @csrf
            <div>
                <label class="block text-xs text-zinc-500 dark:text-zinc-400">Customer ID</label>
                <input type="number" name="user_id" required class="mt-1 w-40 rounded-[8px] border-zinc-300 text-sm dark:border-white/10 dark:bg-white/5">
            </div>
            <div>
                <label class="block text-xs text-zinc-500 dark:text-zinc-400">Label</label>
                <select name="label" class="mt-1 rounded-[8px] border-zinc-300 text-sm dark:border-white/10 dark:bg-white/5">
                    <option value="chargeback">Chargeback</option>
                    <option value="confirmed_fraud">Confirmed fraud</option>
                    <option value="false_positive">False positive</option>
                    <option value="good">Good</option>
                </select>
            </div>
            <div class="flex-1">
                <label class="block text-xs text-zinc-500 dark:text-zinc-400">Note</label>
                <input type="text" name="note" class="mt-1 w-full rounded-[8px] border-zinc-300 text-sm dark:border-white/10 dark:bg-white/5">
            </div>
            <button type="submit" class="rounded-[10px] bg-zinc-900 px-4 py-2 text-sm font-semibold text-white hover:bg-zinc-800 dark:bg-white dark:text-zinc-900">Record</button>
        </form>
    </div>
</x-layouts.admin>

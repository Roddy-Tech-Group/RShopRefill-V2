<?php

use App\Domain\Shared\Enums\Currency;
use App\Domain\Shared\Enums\TransactionCategory;
use App\Models\Referral;
use App\Models\WalletTransaction;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;

new
#[Layout('components.layouts.admin')]
#[Title('Rcoin Analytics')]
class extends Component {
    /**
     * Headline supply metrics - sum every Rcoin transaction in the ledger by
     * category and derive circulating supply (minted − redeemed − reversed).
     * Same source as the storefront API at /admin/api/rewards/analytics/metrics.
     */
    #[Computed]
    public function metrics(): array
    {
        $rcoin = Currency::RCOIN->value;

        $minted = (int) WalletTransaction::where('currency', $rcoin)
            ->whereIn('transaction_category', [
                TransactionCategory::RewardCashback->value,
                TransactionCategory::RewardReferral->value,
            ])
            ->sum('amount');

        $redeemed = (int) WalletTransaction::where('currency', $rcoin)
            ->where('transaction_category', TransactionCategory::RewardRedemption->value)
            ->sum('amount');

        $reversed = (int) WalletTransaction::where('currency', $rcoin)
            ->where('transaction_category', TransactionCategory::RewardReversal->value)
            ->sum('amount');

        return [
            'minted' => $minted,
            'redeemed' => $redeemed,
            'reversed' => $reversed,
            'circulating' => max(0, $minted - $redeemed - $reversed),
        ];
    }

    /**
     * Top 10 referrers by lifetime Rcoin generated. Powers the leaderboard.
     */
    #[Computed]
    public function topReferrers()
    {
        return Referral::query()
            ->select('referrer_id',
                DB::raw('COUNT(*) as total_referrals'),
                DB::raw('SUM(total_rewards_generated) as total_earned'),
                DB::raw('SUM(total_orders_completed) as total_orders'),
            )
            ->groupBy('referrer_id')
            ->orderByDesc('total_earned')
            ->with('referrer:id,name,email')
            ->limit(10)
            ->get();
    }

    /**
     * Last 7 days of award + redemption activity for a tiny sparkline.
     * Returns [date => [minted, redeemed]] for the 7-day window.
     */
    #[Computed]
    public function activitySeries(): array
    {
        $rcoin = Currency::RCOIN->value;
        $start = now()->subDays(6)->startOfDay();

        $rows = WalletTransaction::query()
            ->where('currency', $rcoin)
            ->where('created_at', '>=', $start)
            ->select(
                DB::raw("DATE_FORMAT(created_at, '%Y-%m-%d') as date"),
                DB::raw('transaction_category as category'),
                DB::raw('SUM(amount) as total'),
            )
            ->groupBy('date', 'category')
            ->get();

        $series = [];
        for ($i = 6; $i >= 0; $i--) {
            $d = now()->subDays($i)->format('Y-m-d');
            $series[$d] = ['minted' => 0, 'redeemed' => 0];
        }
        foreach ($rows as $r) {
            if (! isset($series[$r->date])) {
                continue;
            }
            $bucket = match ($r->category) {
                TransactionCategory::RewardCashback->value,
                TransactionCategory::RewardReferral->value => 'minted',
                TransactionCategory::RewardRedemption->value => 'redeemed',
                default => null,
            };
            if ($bucket) {
                $series[$r->date][$bucket] += (int) $r->total;
            }
        }

        return $series;
    }
}; ?>

<div class="w-full px-4 py-8 sm:px-6 lg:px-8">

    <header class="mb-6 flex flex-wrap items-end justify-between gap-3">
        <div>
            <h1 class="text-2xl font-bold text-zinc-900 dark:text-white">Rcoin Analytics</h1>
            <p class="mt-1 text-sm text-zinc-600 dark:text-zinc-400">Live supply, redemption and referral metrics from the Rcoin ledger.</p>
        </div>
        <a href="{{ route('admin.content.rewards') }}" wire:navigate class="inline-flex items-center gap-2 rounded-[12px] border border-zinc-200 bg-white px-3 py-1.5 text-xs font-semibold text-zinc-700 hover:bg-zinc-50 dark:border-zinc-700/60 dark:bg-[#1d3252] dark:text-zinc-200 dark:hover:bg-[#26416b]">
            Tune settings
            <svg class="h-3 w-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" d="M14 5l7 7m0 0l-7 7m7-7H3"/>
            </svg>
        </a>
    </header>

    {{-- KPI cards. Circulating leads (most-asked number), then the three
         component metrics that make it up. --}}
    @php
        $kpiCards = [
            ['label' => 'Circulating supply', 'value' => $this->metrics['circulating'], 'tone' => 'bg-blue-50 text-blue-700 ring-blue-200 dark:bg-blue-500/15 dark:text-blue-300 dark:ring-blue-500/30',          'sub' => 'Rcoin in customer wallets right now'],
            ['label' => 'Total minted',       'value' => $this->metrics['minted'],      'tone' => 'bg-emerald-50 text-emerald-700 ring-emerald-200 dark:bg-emerald-500/15 dark:text-emerald-300 dark:ring-emerald-500/30', 'sub' => 'Cashback + referral awarded all-time'],
            ['label' => 'Redeemed',           'value' => $this->metrics['redeemed'],    'tone' => 'bg-amber-50 text-amber-700 ring-amber-200 dark:bg-amber-500/15 dark:text-amber-300 dark:ring-amber-500/30',       'sub' => 'Spent at checkout by customers'],
            ['label' => 'Reversed',           'value' => $this->metrics['reversed'],    'tone' => 'bg-red-50 text-red-700 ring-red-200 dark:bg-red-500/15 dark:text-red-300 dark:ring-red-500/30',                 'sub' => 'Clawed back on refunds / chargebacks'],
        ];
    @endphp

    <div class="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-4">
        @foreach ($kpiCards as $card)
            <div class="flex flex-col gap-3 rounded-[12px] border border-zinc-100 bg-white p-5 shadow-sm shadow-zinc-900/[0.03] dark:border-zinc-700/60 dark:bg-[#1d3252]">
                <span class="inline-flex w-fit items-center whitespace-nowrap rounded-[5px] px-2 py-0.5 text-[10px] font-bold uppercase tracking-wider ring-1 {{ $card['tone'] }}">
                    {{ $card['label'] }}
                </span>
                <p class="text-3xl font-black tabular-nums tracking-tight text-zinc-900 dark:text-white">{{ number_format($card['value']) }}</p>
                <p class="text-xs text-zinc-600 dark:text-zinc-400">{{ $card['sub'] }}</p>
            </div>
        @endforeach
    </div>

    {{-- 7-day activity bars. Pure SVG - no chart lib for this small surface. --}}
    @php
        $series = $this->activitySeries;
        $maxMinted   = max(1, max(array_column($series, 'minted')));
        $maxRedeemed = max(1, max(array_column($series, 'redeemed')));
        $maxAny      = max($maxMinted, $maxRedeemed);
    @endphp
    <section class="mt-5 rounded-[12px] border-[1.5px] border-white bg-white p-5 shadow-sm shadow-zinc-900/[0.04] dark:border-white dark:bg-[#1d3252]">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <h2 class="text-sm font-bold text-zinc-900 dark:text-white">Last 7 days</h2>
            <div class="flex items-center gap-4 text-[11px] text-zinc-600 dark:text-zinc-400">
                <span class="inline-flex items-center gap-1.5"><span class="h-2 w-2 rounded-[2px] bg-emerald-500"></span> Minted</span>
                <span class="inline-flex items-center gap-1.5"><span class="h-2 w-2 rounded-[2px] bg-amber-500"></span> Redeemed</span>
            </div>
        </div>

        <div class="mt-4 grid grid-cols-7 items-end gap-2" style="height: 160px;">
            @foreach ($series as $date => $bucket)
                <div class="flex h-full flex-col items-center justify-end gap-1">
                    <div class="flex h-full w-full items-end gap-0.5">
                        <span class="flex-1 rounded-t-[3px] bg-emerald-500/80 transition-[height]" style="height: {{ $maxAny > 0 ? round($bucket['minted'] / $maxAny * 100, 1) : 0 }}%;"></span>
                        <span class="flex-1 rounded-t-[3px] bg-amber-500/80 transition-[height]" style="height: {{ $maxAny > 0 ? round($bucket['redeemed'] / $maxAny * 100, 1) : 0 }}%;"></span>
                    </div>
                    <span class="text-[9px] font-semibold uppercase tracking-wider text-zinc-500 dark:text-zinc-400">{{ \Illuminate\Support\Carbon::parse($date)->format('D') }}</span>
                </div>
            @endforeach
        </div>
    </section>

    {{-- Top referrers leaderboard. --}}
    <section class="mt-5 overflow-hidden rounded-[12px] border-[1.5px] border-white bg-white shadow-sm shadow-zinc-900/[0.04] dark:border-white dark:bg-[#1d3252]">
        {{-- Header pill --}}
        <header class="mx-3 my-3 rounded-[12px] bg-blue-50 px-6 py-3 ring-2 ring-blue-500 dark:bg-blue-600/15 dark:ring-blue-400">
            <h2 class="text-[11px] font-bold uppercase tracking-wider text-blue-700 dark:text-blue-300">Top referrers</h2>
            <p class="mt-0.5 text-xs text-blue-700/70 dark:text-blue-300/70">Customers who've earned the most Rcoin through referrals.</p>
        </header>
        @if ($this->topReferrers->isEmpty())
            <div class="px-5 py-10 text-center text-sm text-zinc-500 dark:text-zinc-400">No referral activity yet.</div>
        @else
            <ul class="divide-inset">
                @foreach ($this->topReferrers as $i => $row)
                    <li class="group relative mx-3 flex items-center gap-4 px-5 py-3 transition-all hover:bg-blue-50 hover:rounded-[12px] dark:hover:bg-blue-600/15 dark:hover:ring-blue-400" wire:key="ref-{{ $row->referrer_id }}">
                        <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-[12px] bg-blue-50 text-xs font-black text-blue-700 dark:bg-blue-500/15 dark:text-blue-300">
                            {{ $i + 1 }}
                        </span>
                        <div class="min-w-0 flex-1">
                            <p class="truncate text-sm font-semibold text-zinc-900 dark:text-white">{{ $row->referrer?->name ?? 'Unknown' }}</p>
                            <p class="truncate text-[11px] text-zinc-500 dark:text-zinc-400">{{ $row->referrer?->email }}</p>
                        </div>
                        <div class="hidden text-right text-[11px] text-zinc-500 dark:text-zinc-400 sm:block">
                            <p>{{ number_format($row->total_referrals) }} {{ \Illuminate\Support\Str::plural('referee', (int) $row->total_referrals) }}</p>
                            <p>{{ number_format($row->total_orders) }} orders</p>
                        </div>
                        <span class="inline-flex shrink-0 items-center gap-1 rounded-[5px] bg-emerald-50 px-2 py-1 text-[11px] font-bold tabular-nums text-emerald-700 ring-1 ring-emerald-200 dark:bg-emerald-500/15 dark:text-emerald-300 dark:ring-emerald-500/30">
                            {{ number_format((int) $row->total_earned) }} Rcoin
                        </span>
                    </li>
                @endforeach
            </ul>
        @endif
    </section>
</div>

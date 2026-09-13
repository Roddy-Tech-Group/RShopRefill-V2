<?php

use App\Domain\Admin\Queries\LeaderboardQuery;
use Illuminate\Support\Carbon;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;

new
#[Layout('components.layouts.admin')]
#[Title('Leaderboards')]
class extends Component {
    /** Activity window: today | week | month | quarter | year. */
    #[Url(history: true)] public string $period = 'month';

    public function setPeriod(string $period): void
    {
        $this->period = array_key_exists($period, $this->periods()) ? $period : 'month';
        unset($this->boards);
    }

    /** @return array<string, string> */
    public function periods(): array
    {
        return [
            'today' => 'Today',
            'week' => 'This Week',
            'month' => 'This Month',
            'quarter' => 'This Quarter',
            'year' => 'This Year',
        ];
    }

    private function since(): ?Carbon
    {
        return match ($this->period) {
            'today' => now()->startOfDay(),
            'week' => now()->startOfWeek(),
            'quarter' => now()->startOfQuarter(),
            'year' => now()->startOfYear(),
            default => now()->startOfMonth(),
        };
    }

    #[Computed]
    public function boards(): array
    {
        // Cached briefly so flipping between periods doesn't rerun a dozen
        // aggregate queries on every poll or navigation.
        return cache()->remember(
            'management:leaderboards:'.$this->period,
            60,
            fn () => app(LeaderboardQuery::class)->boards($this->since()),
        );
    }
}; ?>

<x-slot:heading>Leaderboards</x-slot:heading>

@php
    $card = 'rounded-[12px] ring-1 ring-zinc-200 bg-white shadow-sm shadow-zinc-900/[0.04] dark:ring-white/10 dark:bg-[#1d3252]';
    $usd = fn ($amount) => \App\Domain\Shared\Services\Money::codeAmount((float) $amount, 'USD');
    $when = fn (?string $ts) => $ts ? \Illuminate\Support\Carbon::parse($ts)->diffForHumans() : '—';

    // Board metadata: title, hint, primary metric, secondary line. `windowed`
    // marks boards the period filter actually applies to.
    $config = [
        'top_customers' => ['title' => 'Top Customers', 'hint' => 'Most completed orders', 'windowed' => true,
            'primary' => fn ($r) => number_format($r['orders']).' orders', 'secondary' => fn ($r) => $usd($r['spend_usd']).' spent'],
        'highest_margin' => ['title' => 'Highest Margin', 'hint' => 'Gross profit each customer generates', 'windowed' => true,
            'primary' => fn ($r) => $usd($r['margin_usd']).' profit', 'secondary' => fn ($r) => $usd($r['revenue_usd']).' revenue'],
        'newest_users' => ['title' => 'Newest Users', 'hint' => 'Latest registrations', 'windowed' => false,
            'primary' => fn ($r) => $when($r['registered_at']), 'secondary' => fn ($r) => $r['country'] ? 'From '.$r['country'] : ''],
        'returning_users' => ['title' => 'Returning Users', 'hint' => 'Repeat buyers, most recently back', 'windowed' => true,
            'primary' => fn ($r) => number_format($r['orders']).' orders', 'secondary' => fn ($r) => 'Last order '.$when($r['last_order_at'])],
        'dormant_users' => ['title' => 'Dormant Users', 'hint' => 'Bought before, quiet for 60+ days; your win-back list', 'windowed' => false,
            'primary' => fn ($r) => $usd($r['spend_usd']).' lifetime', 'secondary' => fn ($r) => 'Last order '.$when($r['last_order_at'])],
        'most_failed_transactions' => ['title' => 'Most Failed Transactions', 'hint' => 'Support radar and card-testing tripwire', 'windowed' => true,
            'primary' => fn ($r) => number_format($r['failures']).' failures', 'secondary' => fn ($r) => 'Last '.$when($r['last_failed_at']), 'tone' => 'red'],
        'highest_wallet_balance' => ['title' => 'Highest Wallet Balance', 'hint' => 'Current balances (customer float)', 'windowed' => false,
            'primary' => fn ($r) => $usd($r['balance_usd']), 'secondary' => fn () => ''],
        'most_referrals' => ['title' => 'Most Referrals', 'hint' => 'Users bringing in other users', 'windowed' => true,
            'primary' => fn ($r) => number_format($r['referrals']).' referred', 'secondary' => fn ($r) => number_format($r['referred_orders']).' orders by referees'],
        'most_gift_cards' => ['title' => 'Most Gift Cards', 'hint' => 'Gift card units purchased', 'windowed' => true,
            'primary' => fn ($r) => number_format($r['units']).' cards', 'secondary' => fn ($r) => $usd($r['revenue_usd'])],
        'most_airtime' => ['title' => 'Most Airtime', 'hint' => 'Top-up purchases', 'windowed' => true,
            'primary' => fn ($r) => number_format($r['units']).' top-ups', 'secondary' => fn ($r) => $usd($r['revenue_usd'])],
        'most_esims' => ['title' => 'Most eSIMs', 'hint' => 'eSIM purchases', 'windowed' => true,
            'primary' => fn ($r) => number_format($r['units']).' eSIMs', 'secondary' => fn ($r) => $usd($r['revenue_usd'])],
    ];

    $segmentBase = 'inline-flex items-center gap-1.5 rounded-[8px] px-3 py-1 text-[12px] font-semibold transition-colors';
@endphp

<div class="space-y-6 max-w-7xl mx-auto -mt-2">
    <div class="flex flex-wrap items-center justify-end gap-3">
        <div class="inline-flex flex-wrap items-center gap-0.5 rounded-[12px] bg-zinc-100 p-0.5 dark:bg-[#26416b]">
            @foreach ($this->periods() as $key => $periodLabel)
                <button
                    type="button"
                    wire:click="setPeriod('{{ $key }}')"
                    class="{{ $segmentBase }} {{ $period === $key ? 'bg-blue-600 text-white shadow-sm' : 'text-zinc-600 hover:text-zinc-900 dark:text-zinc-300 dark:hover:text-white' }}"
                >{{ $periodLabel }}</button>
            @endforeach
        </div>
    </div>

    <div class="grid grid-cols-1 gap-4 md:grid-cols-2 xl:grid-cols-3">
        @foreach ($config as $boardKey => $meta)
            @php $rows = $this->boards[$boardKey] ?? []; @endphp
            <section x-data="{ expanded: false }" class="{{ $card }} flex flex-col overflow-hidden">
                <header class="flex items-start justify-between gap-2 px-5 pt-4 pb-3">
                    <div>
                        <h2 class="text-sm font-bold text-zinc-900 dark:text-white">{{ $meta['title'] }}</h2>
                        <p class="mt-0.5 text-[11px] text-zinc-400 dark:text-zinc-500">{{ $meta['hint'] }}</p>
                    </div>
                    @unless ($meta['windowed'])
                        <span class="mt-0.5 shrink-0 rounded-full bg-zinc-100 px-2 py-0.5 text-[10px] font-semibold text-zinc-500 dark:bg-white/10 dark:text-zinc-400">current</span>
                    @endunless
                </header>
                <div class="flex-1 divide-y divide-zinc-100 dark:divide-white/10">
                    @forelse ($rows as $index => $row)
                        <a
                            href="{{ route('admin.customer', $row['user']['id']) }}"
                            wire:navigate
                            @if ($index >= 5) x-show="expanded" x-cloak @endif
                            class="flex items-center gap-3 px-5 py-2.5 transition-colors hover:bg-zinc-50 dark:hover:bg-white/5"
                        >
                            <span class="w-5 shrink-0 text-center text-[12px] font-bold tabular-nums {{ $index < 3 ? 'text-blue-600 dark:text-blue-400' : 'text-zinc-400' }}">{{ $index + 1 }}</span>
                            <img src="{{ $row['user']['avatar'] }}" alt="" class="h-7 w-7 shrink-0 rounded-full object-cover ring-1 ring-zinc-200 dark:ring-white/10" loading="lazy">
                            <span class="min-w-0 flex-1">
                                <span class="block truncate text-[13px] font-semibold text-zinc-900 dark:text-white">{{ $row['user']['name'] }}</span>
                                <span class="block truncate text-[11px] text-zinc-400">{{ $meta['secondary']($row) }}</span>
                            </span>
                            <span class="shrink-0 text-right text-[12px] font-bold tabular-nums {{ ($meta['tone'] ?? null) === 'red' ? 'text-red-600 dark:text-red-400' : 'text-zinc-700 dark:text-zinc-200' }}">
                                {{ $meta['primary']($row) }}
                            </span>
                        </a>
                    @empty
                        <p class="px-5 py-6 text-center text-[12px] text-zinc-400">Nothing in this window yet.</p>
                    @endforelse
                </div>
                @if (count($rows) > 5)
                    <button type="button" @click="expanded = ! expanded" class="flex w-full items-center justify-center gap-1 border-t border-zinc-100 px-5 py-2 text-[11px] font-semibold text-zinc-400 transition-colors hover:bg-zinc-50 hover:text-zinc-600 dark:border-white/10 dark:hover:bg-white/5 dark:hover:text-zinc-200">
                        <span x-show="! expanded">Show more</span>
                        <span x-show="expanded" x-cloak>Show less</span>
                        <svg :class="expanded && 'rotate-180'" class="h-3 w-3 transition-transform" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7"/></svg>
                    </button>
                @endif
            </section>
        @endforeach
    </div>
</div>

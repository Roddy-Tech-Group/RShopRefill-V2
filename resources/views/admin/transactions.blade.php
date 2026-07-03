@php
    use App\Models\PaymentAttempt;
    use App\Models\WalletTransaction;

    // URL-driven status filter. Chip labels map to the real PaymentStatus
    // enum values: "Completed" = paid, "Pending" = pending+processing+
    // reserved (in-flight), "Cancelled" = expired (timed-out checkout),
    // "Failed" = failed. "Refunded" is special — see below.
    $statusFilter = strtolower((string) request()->query('status', 'all'));
    $allowedStatuses = ['all', 'completed', 'pending', 'cancelled', 'failed', 'refunded'];
    if (! in_array($statusFilter, $allowedStatuses, true)) {
        $statusFilter = 'all';
    }

    $statusMap = [
        'completed' => ['paid'],
        'pending'   => ['pending', 'processing', 'reserved', 'unpaid'],
        'cancelled' => ['expired'],
        'failed'    => ['failed'],
    ];

    // Status pill tone — maps a transaction status onto the canonical
    // x-admin.badge tone palette.
    $statusPillFor = function (?string $status): string {
        return match ($status) {
            'paid' => 'emerald',
            'failed', 'expired' => 'red',
            'refunded', 'partially_refunded', 'refund' => 'zinc',
            'processing', 'reserved' => 'blue',
            default => 'amber',
        };
    };

    // The "Refunded" view reads the actual wallet refund credits (auto-refunds for
    // failed / undelivered items, plus manual reversals) from wallet_transactions —
    // those never land in payment_attempts, and gateway-paid orders are refunded
    // straight to the customer's wallet, so they were previously invisible here.
    // Every other filter reads payment_attempts (the gateway/checkout attempts).
    // Both sources are normalised into one $rows shape the table renders.
    if ($statusFilter === 'refunded') {
        $rows = WalletTransaction::with('wallet.user')
            ->whereIn('transaction_category', ['refund', 'reversal'])
            ->latest()
            ->limit(50)
            ->get()
            ->map(fn ($txn) => [
                'customer'     => $txn->wallet?->user?->name ?? 'Unknown customer',
                'order_number' => null,
                'meta_label'   => $txn->transaction_category?->value === 'reversal' ? 'Wallet reversal' : 'Wallet refund',
                'reference'    => $txn->reference ?: ($txn->idempotency_key ?: 'TXN-'.$txn->id),
                'gateway'      => 'Wallet',
                'amount'       => (float) $txn->amount,
                'currency'     => $txn->currency?->value ?? 'USD',
                'date'         => $txn->created_at,
                'status_label' => 'Refund',
                'status_tone'  => 'zinc',
            ]);
    } else {
        // The pre-merge `payments` table was dropped and replaced by `payment_attempts`
        // (polymorphic — an attempt belongs to an Order or a WalletFunding).
        $paymentsQuery = PaymentAttempt::with(['user', 'order'])->latest();
        if ($statusFilter !== 'all') {
            $paymentsQuery->whereIn('payment_status', $statusMap[$statusFilter]);
        }
        $rows = $paymentsQuery->limit(50)->get()->map(function ($payment) use ($statusPillFor) {
            $statusValue = $payment->payment_status?->value ?? 'pending';

            return [
                'customer'     => $payment->user?->name ?? 'Unknown customer',
                'order_number' => $payment->order?->order_number,
                'meta_label'   => $payment->order ? null : 'Wallet funding',
                'reference'    => $payment->gateway_reference ?: $payment->idempotency_key,
                'gateway'      => $payment->gateway,
                'amount'       => (float) $payment->amount,
                'currency'     => $payment->currency,
                'date'         => $payment->created_at,
                'status_label' => $payment->payment_status?->label() ?? 'Pending',
                'status_tone'  => $statusPillFor($statusValue),
            ];
        });
    }

    $totalPayments = PaymentAttempt::count();

    // KPI counts — full table totals so the strip stays stable as the
    // admin scrubs the filter chips. Same status buckets as the chips.
    $kpis = PaymentAttempt::query()
        ->selectRaw("
            SUM(CASE WHEN payment_status = 'paid' THEN 1 ELSE 0 END) AS completed,
            SUM(CASE WHEN payment_status IN ('pending', 'processing', 'reserved', 'unpaid') THEN 1 ELSE 0 END) AS pending,
            SUM(CASE WHEN payment_status = 'expired' THEN 1 ELSE 0 END) AS cancelled,
            SUM(CASE WHEN payment_status = 'failed' THEN 1 ELSE 0 END) AS failed
        ")
        ->first();
    $refundCount = WalletTransaction::whereIn('transaction_category', ['refund', 'reversal'])->count();

    // Preserve other query params when toggling the status filter chips.
    $filterUrl = fn (string $status) => route('admin.transactions', array_filter([
        'status' => $status === 'all' ? null : $status,
    ]));
@endphp

<x-layouts.admin>
    <x-slot:heading>
        <div class="flex items-end justify-between gap-3">
            <span>Transactions</span>
            {{-- CSV export. Forwards any active filter query params so an admin
                 can scope the file to whatever they were just looking at. --}}
            <a
                href="{{ route('admin.transactions.export', request()->query()) }}"
                class="inline-flex items-center gap-1.5 rounded-[12px] border border-zinc-200 bg-white px-3 py-2 text-xs font-semibold text-zinc-700 transition-colors hover:bg-zinc-50 dark:border-zinc-700/60 dark:bg-[#1d3252] dark:text-zinc-300 dark:hover:bg-[#26416b]"
            >
                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M3 16.5v2.25A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75V16.5M16.5 12L12 16.5m0 0L7.5 12m4.5 4.5V3"/>
                </svg>
                Export CSV
            </a>
        </div>
    </x-slot:heading>
    <x-slot:subheading>{{ number_format($totalPayments) }} payment attempts total. Showing the latest 50 matching the filter.</x-slot:subheading>

    {{-- KPI strip — same look as every other admin page. --}}
    <div class="mb-4 grid grid-cols-2 gap-3 sm:grid-cols-5">
        @foreach ([
            ['label' => 'Completed', 'value' => (int) $kpis->completed, 'dot' => 'bg-emerald-500'],
            ['label' => 'Pending',   'value' => (int) $kpis->pending,   'dot' => 'bg-amber-500'],
            ['label' => 'Cancelled', 'value' => (int) $kpis->cancelled, 'dot' => 'bg-zinc-500'],
            ['label' => 'Failed',    'value' => (int) $kpis->failed,    'dot' => 'bg-red-500'],
            ['label' => 'Refunded',  'value' => (int) $refundCount,     'dot' => 'bg-blue-500'],
        ] as $stat)
            <div class="rounded-[12px] border-[1.5px] border-white bg-white p-4 shadow-sm shadow-zinc-900/[0.04] dark:border-white dark:bg-[#1d3252]">
                <p class="flex items-center gap-1.5 text-[11px] font-semibold uppercase tracking-wider text-zinc-600 dark:text-zinc-300">
                    <span class="inline-block h-1.5 w-1.5 rounded-full {{ $stat['dot'] }}"></span>
                    {{ $stat['label'] }}
                </p>
                <p class="mt-2 text-3xl font-bold tracking-tight text-zinc-900 dark:text-white">{{ number_format($stat['value']) }}</p>
            </div>
        @endforeach
    </div>

    {{-- Status filter chips. Pressing a chip reloads the page with the
         filter applied via query string so admins can share / bookmark
         the URL ("send me /admin/transactions?status=failed"). --}}
    <div class="mb-4 flex flex-wrap items-center gap-1.5">
        <span class="text-[11px] font-semibold uppercase tracking-wider text-zinc-500 dark:text-zinc-400">Status</span>
        @foreach ([
            'all' => 'All', 'completed' => 'Completed', 'pending' => 'Pending', 'cancelled' => 'Cancelled', 'failed' => 'Failed', 'refunded' => 'Refunded',
        ] as $value => $label)
            <a
                href="{{ $filterUrl($value) }}"
                wire:navigate
                @class([
                    'rounded-[12px] px-3 py-1.5 text-xs font-semibold transition-colors',
                    'bg-blue-600 text-white' => $statusFilter === $value,
                    'bg-white text-zinc-700 ring-1 ring-zinc-200 hover:bg-zinc-50 dark:bg-[#1d3252] dark:text-zinc-300 dark:ring-zinc-700/60 dark:hover:bg-[#26416b]' => $statusFilter !== $value,
                ])
            >{{ $label }}</a>
        @endforeach
    </div>

    {{-- Grid template shared between the header pill and every data row so
         columns line up exactly. Same pattern as the Products page's
         .variant-row — single source of truth for column widths. --}}
    <style>
        .txn-row {
            display: grid;
            grid-template-columns:
                minmax(180px, 1.6fr)   /* Customer + reference (stacked) */
                minmax(110px, 0.9fr)   /* Gateway */
                minmax(120px, 1fr)     /* Amount */
                minmax(140px, 1fr)     /* Date */
                minmax(90px,  0.7fr);  /* Status pill */
            gap: 1.25rem;
            align-items: center;
            min-width: 820px;
        }
        .txn-body:not(:last-of-type)::after {
            content: '';
            position: absolute;
            left: 1.5rem;
            right: 1.5rem;
            bottom: 0;
            height: 1px;
            background-color: rgb(244 244 245);
            pointer-events: none;
        }
        html.dark .txn-body:not(:last-of-type)::after {
            background-color: rgb(255 255 255 / 0.08);
        }
        .txn-body:hover::after { display: none; }
        .txn-body:hover { border-radius: 10px; }
    </style>

    <div class="overflow-hidden rounded-[12px] border-[1.5px] border-white bg-white shadow-sm shadow-zinc-900/[0.04] dark:border-white dark:bg-[#1d3252]">
        <div class="overflow-x-auto [scrollbar-width:thin] [&::-webkit-scrollbar]:h-1.5 [&::-webkit-scrollbar-thumb]:rounded-full [&::-webkit-scrollbar-thumb]:bg-zinc-300 dark:[&::-webkit-scrollbar-thumb]:bg-zinc-600">

        {{-- Header pill --}}
        <div class="txn-row grid mx-3 my-3 rounded-[12px] bg-blue-50 px-6 py-3 text-[10px] font-bold uppercase tracking-wider text-blue-700 ring-2 ring-blue-500 dark:bg-blue-600/15 dark:text-blue-300 dark:ring-blue-400">
            <span class="col-customer">Customer</span>
            <span>Gateway</span>
            <span>Amount</span>
            <span>Date</span>
            <span class="col-status">Status</span>
        </div>

        @forelse ($rows as $row)
            <article class="txn-row txn-body group relative mx-3 cursor-pointer bg-white px-6 py-3 transition-all hover:bg-blue-50 dark:bg-[#1d3252] dark:hover:bg-blue-600/10 dark:hover:ring-blue-400">

                {{-- Customer — name + reference / order link stacked. The
                     reference line is a click-to-copy chip; clicking it puts
                     the full transaction id on the clipboard and flashes a
                     checkmark for ~1.5s as confirmation. --}}
                <div class="col-customer min-w-0">
                    <div class="flex flex-wrap items-baseline gap-x-2 gap-y-0.5">
                        <p class="truncate text-[13px] font-semibold text-zinc-900 dark:text-white">{{ $row['customer'] }}</p>
                        @if ($row['order_number'])
                            {{-- Order number — click-to-copy chip, same pattern as
                                 the reference line below. --}}
                            <button
                                type="button"
                                x-data="{ copied: false }"
                                @click.stop="navigator.clipboard.writeText(@js('#'.$row['order_number'])); copied = true; setTimeout(() => copied = false, 1500)"
                                :aria-label="copied ? 'Copied' : 'Copy order id'"
                                class="group/copy inline-flex max-w-full items-center gap-1 rounded-[12px] px-1.5 py-0.5 font-mono text-[11px] text-zinc-500 transition-colors hover:bg-blue-100 hover:text-blue-700 dark:text-zinc-400 dark:hover:bg-blue-500/15 dark:hover:text-blue-300"
                            >
                                <span class="truncate">#{{ $row['order_number'] }}</span>
                                <svg x-show="! copied" class="h-3 w-3 shrink-0 opacity-0 transition-opacity group-hover/copy:opacity-100" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M8 5H6a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2v-1M8 5a2 2 0 002 2h2a2 2 0 002-2M8 5a2 2 0 012-2h2a2 2 0 012 2m0 0h2a2 2 0 012 2v3"/>
                                </svg>
                                <svg x-show="copied" x-cloak class="h-3 w-3 shrink-0 text-emerald-600 dark:text-emerald-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="3" aria-hidden="true">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/>
                                </svg>
                            </button>
                        @elseif ($row['meta_label'])
                            <p class="truncate font-mono text-[11px] text-zinc-500 dark:text-zinc-400">{{ $row['meta_label'] }}</p>
                        @endif
                    </div>
                    <button
                        type="button"
                        x-data="{ copied: false }"
                        @click.stop="navigator.clipboard.writeText(@js($row['reference'])); copied = true; setTimeout(() => copied = false, 1500)"
                        :aria-label="copied ? 'Copied' : 'Copy transaction id'"
                        class="mt-0.5 group/copy inline-flex max-w-full items-center gap-1 rounded-[12px] px-1.5 py-0.5 font-mono text-[10px] text-zinc-500 transition-colors hover:bg-blue-100 hover:text-blue-700 dark:text-zinc-500 dark:hover:bg-blue-500/15 dark:hover:text-blue-300"
                    >
                        <span class="truncate">{{ $row['reference'] }}</span>
                        {{-- Copy / check icon swap on copy. --}}
                        <svg x-show="! copied" class="h-3 w-3 shrink-0 opacity-0 transition-opacity group-hover/copy:opacity-100" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M8 5H6a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2v-1M8 5a2 2 0 002 2h2a2 2 0 002-2M8 5a2 2 0 012-2h2a2 2 0 012 2m0 0h2a2 2 0 012 2v3"/>
                        </svg>
                        <svg x-show="copied" x-cloak class="h-3 w-3 shrink-0 text-emerald-600 dark:text-emerald-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="3" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/>
                        </svg>
                    </button>
                </div>

                {{-- Gateway --}}
                <span class="truncate text-[13px] font-medium capitalize text-zinc-700 dark:text-zinc-200">{{ $row['gateway'] }}</span>

                {{-- Amount --}}
                <span class="col-amount truncate text-[13px] font-bold tabular-nums text-zinc-900 dark:text-white">@moneyCode((float) $row['amount'], $row['currency'])</span>

                {{-- Date --}}
                <span class="truncate text-[12px] text-zinc-600 dark:text-zinc-400">{{ $row['date']->format('M j, Y · g:i A') }}</span>

                {{-- Status pill --}}
                <span class="col-status">
                    <x-admin.badge :tone="$row['status_tone']">{{ $row['status_label'] }}</x-admin.badge>
                </span>
            </article>
        @empty
            <div class="px-5 py-12 text-center">
                <p class="text-base font-semibold text-zinc-900 dark:text-white">
                    @if ($statusFilter === 'all')
                        No transactions yet
                    @else
                        No {{ $statusFilter }} transactions
                    @endif
                </p>
                @if ($statusFilter !== 'all')
                    <a href="{{ $filterUrl('all') }}" wire:navigate class="mt-2 inline-flex items-center gap-1.5 text-sm font-semibold text-blue-600 hover:text-blue-700 dark:text-blue-300 dark:hover:text-blue-200">
                        Show all transactions
                    </a>
                @endif
            </div>
        @endforelse

        </div>
    </div>
</x-layouts.admin>

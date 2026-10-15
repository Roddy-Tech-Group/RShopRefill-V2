@php
    $statusTone = fn (string $status): string => match ($status) {
        'won' => 'emerald',
        'lost' => 'red',
        default => 'amber',
    };

    $tabs = [
        'open' => 'Open',
        'won' => 'Won',
        'lost' => 'Lost',
        'all' => 'All',
    ];
@endphp

<x-layouts.admin>
    <x-slot:heading>Disputes</x-slot:heading>
    <x-slot:subheading>
        Chargebacks the gateways raised against our payments. Answer them in the gateway's dashboard before the deadline; the reports below are the evidence.
    </x-slot:subheading>

    <div class="mb-6 inline-flex overflow-x-auto rounded-[12px] bg-zinc-100 p-1 font-medium text-sm text-zinc-600 dark:bg-zinc-800/50 dark:text-zinc-400">
        @foreach ($tabs as $key => $label)
            <a href="{{ route('admin.risk.disputes', ['status' => $key]) }}"
               class="whitespace-nowrap rounded-[12px] px-4 py-2 text-sm transition-all {{ $status === $key ? 'bg-white text-zinc-900 shadow-sm ring-1 ring-zinc-900/5 dark:bg-[#1d3252] dark:text-white dark:ring-white/10' : 'hover:text-zinc-900 hover:bg-zinc-200/50 dark:hover:text-white dark:hover:bg-zinc-700/50' }}">
                {{ $label }}
                @if (($counts[$key] ?? 0) > 0)
                    <span class="ml-1 text-xs opacity-70">{{ $counts[$key] }}</span>
                @endif
            </a>
        @endforeach
    </div>

    <div class="overflow-hidden rounded-[12px] bg-white shadow-sm shadow-zinc-900/[0.04] dark:bg-[#1d3252]">
        <div class="overflow-x-auto p-3">
            <table class="w-full min-w-[960px] text-left text-sm admin-table">
                <thead>
                    <tr>
                        <th>Deadline</th>
                        <th>Gateway</th>
                        <th>Customer</th>
                        <th>Paid for</th>
                        <th>Amount</th>
                        <th>Reason</th>
                        <th>Status</th>
                        <th>Evidence</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($disputes as $dispute)
                        @php
                            $urgent = $dispute->isOpen() && $dispute->due_at && $dispute->due_at->lte(now()->addHours(6));
                        @endphp
                        <tr class="hover:bg-blue-50" data-dispute="{{ $dispute->gateway_dispute_id }}">
                            <td class="px-6 py-4 text-xs">
                                @if ($dispute->due_at)
                                    <div class="font-semibold {{ $urgent ? 'text-red-600 dark:text-red-400' : 'text-zinc-900 dark:text-white' }}">{{ $dispute->due_at->format('j M Y, H:i') }}</div>
                                    @if ($dispute->isOpen())
                                        <div class="{{ $urgent ? 'text-red-600 dark:text-red-400' : 'text-zinc-500 dark:text-zinc-400' }}">{{ $dispute->due_at->diffForHumans() }}</div>
                                    @endif
                                @else
                                    <span class="text-zinc-500 dark:text-zinc-400">Not given</span>
                                @endif
                            </td>
                            <td class="px-6 py-4">
                                <div class="font-medium text-zinc-900 dark:text-white">{{ $dispute->gatewayLabel() }}</div>
                                <div class="font-mono text-[11px] text-zinc-500 dark:text-zinc-400">{{ $dispute->gateway_dispute_id }}</div>
                            </td>
                            <td class="px-6 py-4">
                                @if ($dispute->user)
                                    <a href="{{ route('admin.customer', $dispute->user) }}" class="font-medium text-blue-600 hover:text-blue-700 dark:text-blue-400">{{ $dispute->user->name }}</a>
                                    <div class="text-xs text-zinc-500 dark:text-zinc-400">{{ $dispute->user->email }}</div>
                                @else
                                    <span class="text-xs font-medium text-amber-700 dark:text-amber-300">Not matched</span>
                                    <div class="font-mono text-[11px] text-zinc-500 dark:text-zinc-400">{{ $dispute->gateway_payment_reference ?? 'no reference given' }}</div>
                                @endif
                            </td>
                            <td class="px-6 py-4 text-zinc-700 dark:text-zinc-300">
                                @if ($dispute->order)
                                    {{ $dispute->order->order_number }}
                                @elseif ($dispute->paymentAttempt)
                                    Wallet funding
                                @else
                                    -
                                @endif
                            </td>
                            <td class="px-6 py-4 font-semibold text-zinc-900 dark:text-white">{{ $dispute->amountLabel() }}</td>
                            <td class="px-6 py-4 text-xs text-zinc-600 dark:text-zinc-300">{{ $dispute->reason ?? '-' }}</td>
                            <td class="px-6 py-4">
                                <x-admin.badge :tone="$statusTone($dispute->status->value)">{{ $dispute->status->label() }}</x-admin.badge>
                                <div class="mt-1 text-[11px] text-zinc-500 dark:text-zinc-400">{{ $dispute->gateway_status }}</div>
                            </td>
                            <td class="px-6 py-4 text-xs">
                                <div class="flex flex-col gap-1">
                                    @if ($dispute->order)
                                        <a href="{{ route('admin.order.report', $dispute->order) }}" class="font-semibold text-blue-600 hover:text-blue-700 dark:text-blue-400">Order report</a>
                                    @endif
                                    @if ($dispute->paymentAttempt)
                                        <a href="{{ route('admin.transaction.report', $dispute->paymentAttempt) }}" class="font-semibold text-blue-600 hover:text-blue-700 dark:text-blue-400">Transaction report</a>
                                    @endif
                                    @if (! $dispute->order && ! $dispute->paymentAttempt)
                                        <span class="text-zinc-500 dark:text-zinc-400">Trace by hand</span>
                                    @endif
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="8" class="px-6 py-12 text-center text-sm text-zinc-500 dark:text-zinc-400">No disputes in this view.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <div class="mt-4">
        {{ $disputes->links() }}
    </div>
</x-layouts.admin>

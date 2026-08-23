@php
    // Status pill tone — same palette as the transactions list.
    $statusValue = $attempt->payment_status?->value ?? 'pending';
    $statusTone = match ($statusValue) {
        'paid' => 'emerald',
        'failed', 'expired' => 'red',
        'refunded', 'partially_refunded' => 'zinc',
        'processing', 'reserved' => 'blue',
        default => 'amber',
    };
    $fmtDate = fn ($d) => $d ? $d->format('M j, Y · g:i:s A') : '—';
@endphp

<x-layouts.admin>
    <x-slot:heading>
        <div class="flex items-end justify-between gap-3">
            <span>Transaction #{{ $attempt->id }}</span>
            <a
                href="{{ route('admin.transaction.report', $attempt) }}"
                wire:navigate
                class="inline-flex items-center gap-1.5 rounded-[12px] bg-blue-600 px-3 py-2 text-xs font-semibold text-white transition-colors hover:bg-blue-700"
            >
                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M19.5 14.25v-2.625a3.375 3.375 0 00-3.375-3.375h-1.5A1.125 1.125 0 0113.5 7.125v-1.5a3.375 3.375 0 00-3.375-3.375H8.25m2.25 0H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 00-9-9z"/>
                </svg>
                Generate Report
            </a>
        </div>
    </x-slot:heading>
    <x-slot:subheading>
        <a href="{{ route('admin.transactions') }}" wire:navigate class="font-semibold text-blue-600 hover:text-blue-700 dark:text-blue-300">← Back to transactions</a>
    </x-slot:subheading>

    <div class="flex flex-1 flex-col gap-4">

        {{-- Amount hero --}}
        <div class="rounded-[12px] bg-white p-5 shadow-sm shadow-zinc-900/5 ring-1 ring-zinc-100 dark:bg-[#1d3252] dark:ring-white/10">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <p class="text-[11px] font-semibold uppercase tracking-wider text-zinc-500 dark:text-zinc-400">Amount</p>
                    <p class="mt-1 text-3xl font-bold tracking-tight text-zinc-900 dark:text-white">@moneyCode((float) $attempt->amount, $attempt->currency)</p>
                    <p class="mt-1 text-xs text-zinc-500 dark:text-zinc-400 capitalize">{{ $attempt->gateway }}{{ $attempt->payment_method ? ' · '.str_replace('_', ' ', $attempt->payment_method) : '' }} · {{ $fmtDate($attempt->created_at) }}</p>
                </div>
                <x-admin.badge :tone="$statusTone">{{ $attempt->payment_status?->label() ?? 'Pending' }}</x-admin.badge>
            </div>
        </div>

        <div class="grid gap-4 lg:grid-cols-2">
            {{-- Payment details --}}
            <div class="rounded-[12px] bg-white shadow-sm shadow-zinc-900/5 ring-1 ring-zinc-100 dark:bg-[#1d3252] dark:ring-white/10">
                <div class="border-b border-zinc-100 px-5 py-4 dark:border-white/10">
                    <h3 class="text-sm font-bold text-zinc-900 dark:text-white">Payment details</h3>
                </div>
                <dl class="px-5 py-4 text-xs">
                    @foreach ([
                        'Gateway reference' => $attempt->gateway_reference,
                        'Idempotency key' => $attempt->idempotency_key,
                        'Correlation ID' => $attempt->correlation_id,
                        'Exchange rate snapshot' => $attempt->exchange_rate_snapshot,
                        'Gateway fee' => $attempt->gateway_fee,
                        'Settlement delta' => $attempt->settlement_delta,
                        'Session' => $attempt->paymentSession ? ('#'.$attempt->paymentSession->id.' ('.($attempt->paymentSession->status->value ?? $attempt->paymentSession->status).')') : null,
                        'Confirmed at' => $attempt->confirmed_at ? $fmtDate($attempt->confirmed_at) : null,
                        'Failed at' => $attempt->failed_at ? $fmtDate($attempt->failed_at) : null,
                        'Expires at' => $attempt->expires_at ? $fmtDate($attempt->expires_at) : null,
                    ] as $label => $value)
                        @if ($value !== null && $value !== '')
                            <div class="flex items-start justify-between gap-4 border-b border-zinc-50 py-2 last:border-0 dark:border-white/5">
                                <dt class="shrink-0 text-zinc-500 dark:text-zinc-400">{{ $label }}</dt>
                                <dd class="break-all text-right font-mono font-medium text-zinc-800 dark:text-zinc-200">{{ $value }}</dd>
                            </div>
                        @endif
                    @endforeach
                </dl>
            </div>

            {{-- Customer + context --}}
            <div class="flex flex-col gap-4">
                <div class="rounded-[12px] bg-white shadow-sm shadow-zinc-900/5 ring-1 ring-zinc-100 dark:bg-[#1d3252] dark:ring-white/10">
                    <div class="border-b border-zinc-100 px-5 py-4 dark:border-white/10">
                        <h3 class="text-sm font-bold text-zinc-900 dark:text-white">Customer</h3>
                    </div>
                    <div class="px-5 py-4 text-xs">
                        @if ($attempt->user)
                            <a href="{{ route('admin.customer', $attempt->user) }}" wire:navigate class="text-sm font-semibold text-blue-600 hover:text-blue-700 dark:text-blue-300">{{ $attempt->user->name }}</a>
                            <p class="mt-0.5 text-zinc-500 dark:text-zinc-400">{{ $attempt->user->email }}</p>
                        @else
                            <p class="text-zinc-500 dark:text-zinc-400">No customer attached.</p>
                        @endif
                    </div>
                </div>

                {{-- What this payment is for: a pointer, never a copy - the
                     order page carries the full order story. --}}
                <div class="rounded-[12px] bg-white shadow-sm shadow-zinc-900/5 ring-1 ring-zinc-100 dark:bg-[#1d3252] dark:ring-white/10">
                    <div class="border-b border-zinc-100 px-5 py-4 dark:border-white/10">
                        <h3 class="text-sm font-bold text-zinc-900 dark:text-white">Payment for</h3>
                    </div>
                    <div class="px-5 py-4 text-xs">
                        @if ($attempt->order)
                            <a href="{{ route('admin.order', $attempt->order) }}" wire:navigate class="text-sm font-semibold text-blue-600 hover:text-blue-700 dark:text-blue-300">Order #{{ $attempt->order->order_number }}</a>
                        @else
                            <p class="text-sm font-semibold text-zinc-800 dark:text-zinc-200">Wallet funding</p>
                        @endif
                    </div>
                </div>

                {{-- Crypto settlement, when present --}}
                @if ($attempt->pay_currency)
                    <div class="rounded-[12px] bg-white shadow-sm shadow-zinc-900/5 ring-1 ring-zinc-100 dark:bg-[#1d3252] dark:ring-white/10">
                        <div class="border-b border-zinc-100 px-5 py-4 dark:border-white/10">
                            <h3 class="text-sm font-bold text-zinc-900 dark:text-white">Crypto settlement</h3>
                        </div>
                        <dl class="px-5 py-4 text-xs">
                            @foreach ([
                                'Pay currency' => strtoupper((string) $attempt->pay_currency),
                                'Pay amount' => $attempt->pay_amount,
                                'Actually paid' => $attempt->actually_paid,
                                'Network' => $attempt->network,
                                'Pay address' => $attempt->pay_address,
                                'Purchase ID' => $attempt->purchase_id,
                                'Outcome' => $attempt->outcome_amount ? $attempt->outcome_amount.' '.strtoupper((string) $attempt->outcome_currency) : null,
                            ] as $label => $value)
                                @if ($value !== null && $value !== '')
                                    <div class="flex items-start justify-between gap-4 border-b border-zinc-50 py-2 last:border-0 dark:border-white/5">
                                        <dt class="shrink-0 text-zinc-500 dark:text-zinc-400">{{ $label }}</dt>
                                        <dd class="break-all text-right font-mono font-medium text-zinc-800 dark:text-zinc-200">{{ $value }}</dd>
                                    </div>
                                @endif
                            @endforeach
                        </dl>
                    </div>
                @endif
            </div>
        </div>

        {{-- Webhook deliveries --}}
        <div class="rounded-[12px] bg-white shadow-sm shadow-zinc-900/5 ring-1 ring-zinc-100 dark:bg-[#1d3252] dark:ring-white/10">
            <div class="border-b border-zinc-100 px-5 py-4 dark:border-white/10">
                <h3 class="text-sm font-bold text-zinc-900 dark:text-white">Webhook deliveries ({{ $webhooks->count() }})</h3>
            </div>
            @if ($webhooks->isNotEmpty())
                <div class="overflow-x-auto">
                    <table class="w-full text-left text-[11px]">
                        <thead class="bg-zinc-50 text-[10px] uppercase tracking-wider text-zinc-800 dark:bg-white/5 dark:text-zinc-300">
                            <tr>
                                <th class="px-5 py-3 font-semibold">Received</th>
                                <th class="px-5 py-3 font-semibold">Event</th>
                                <th class="px-5 py-3 font-semibold">Processing</th>
                                <th class="px-5 py-3 font-semibold">Attempts</th>
                                <th class="px-5 py-3 font-semibold">Processed at</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($webhooks as $hook)
                                <tr class="border-t border-zinc-100 dark:border-white/5">
                                    <td class="px-5 py-3 text-zinc-700 dark:text-zinc-300">{{ $fmtDate($hook->created_at) }}</td>
                                    <td class="px-5 py-3 font-mono text-zinc-700 dark:text-zinc-300">{{ $hook->event_type ?? '—' }}</td>
                                    <td class="px-5 py-3"><x-admin.badge :tone="$hook->processing_status === 'processed' ? 'emerald' : ($hook->processing_status === 'failed' ? 'red' : 'amber')">{{ $hook->processing_status ?? ($hook->processed ? 'processed' : 'pending') }}</x-admin.badge></td>
                                    <td class="px-5 py-3 text-zinc-700 dark:text-zinc-300">{{ $hook->processing_attempts }}</td>
                                    <td class="px-5 py-3 text-zinc-700 dark:text-zinc-300">{{ $fmtDate($hook->processed_at) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @else
                <div class="px-5 py-8 text-center text-sm text-zinc-600 dark:text-zinc-400">No webhook deliveries matched this transaction's references.</div>
            @endif
        </div>

        {{-- Other attempts on the same order --}}
        @if ($siblings->isNotEmpty())
            <div class="rounded-[12px] bg-white shadow-sm shadow-zinc-900/5 ring-1 ring-zinc-100 dark:bg-[#1d3252] dark:ring-white/10">
                <div class="border-b border-zinc-100 px-5 py-4 dark:border-white/10">
                    <h3 class="text-sm font-bold text-zinc-900 dark:text-white">Other attempts on this order ({{ $siblings->count() }})</h3>
                </div>
                <div class="divide-y divide-zinc-100 dark:divide-white/5">
                    @foreach ($siblings as $sibling)
                        @php
                            $sv = $sibling->payment_status?->value ?? 'pending';
                            $st = match ($sv) {
                                'paid' => 'emerald',
                                'failed', 'expired' => 'red',
                                'refunded', 'partially_refunded' => 'zinc',
                                'processing', 'reserved' => 'blue',
                                default => 'amber',
                            };
                        @endphp
                        <a href="{{ route('admin.transaction', $sibling) }}" wire:navigate class="flex items-center justify-between gap-3 px-5 py-3 text-xs transition-colors hover:bg-blue-50 dark:hover:bg-blue-600/10">
                            <span class="font-mono text-zinc-700 dark:text-zinc-300">#{{ $sibling->id }} · <span class="capitalize">{{ $sibling->gateway }}</span> · {{ $fmtDate($sibling->created_at) }}</span>
                            <x-admin.badge :tone="$st">{{ $sibling->payment_status?->label() ?? 'Pending' }}</x-admin.badge>
                        </a>
                    @endforeach
                </div>
            </div>
        @endif
    </div>
</x-layouts.admin>

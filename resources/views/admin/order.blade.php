@php
    // Admin order detail — full picture of one order: status, customer, items
    // (with per-item fulfillment state: delivered / pending / failed), totals
    // and payment attempts. Admin-facing, so gateway/provider names are shown.

    // Status -> badge classes. Buckets shared across order / payment / fulfillment enums.
    $toneFor = function (?string $v): string {
        return match ($v) {
            'completed', 'paid', 'fulfilled' => 'bg-emerald-50 text-emerald-700 ring-emerald-200',
            'partially_completed', 'partially_fulfilled', 'partially_paid' => 'bg-blue-50 text-blue-700 ring-blue-200',
            'failed', 'cancelled', 'requires_attention', 'expired' => 'bg-red-50 text-red-700 ring-red-200',
            'refunded', 'partially_refunded' => 'bg-zinc-100 text-zinc-700 ring-zinc-200',
            default => 'bg-amber-50 text-amber-700 ring-amber-200',
        };
    };

    $fmtDate = fn ($d) => $d ? $d->format('M j, Y · g:i A') : null;
@endphp

<x-layouts.admin>
    <x-slot:heading>Order #{{ $order->order_number }}</x-slot:heading>
    <x-slot:subheading>
        Placed {{ $fmtDate($order->placed_at ?? $order->created_at) }}
        @if ($order->hasSuspectPricing())
            · <span class="inline-flex items-center gap-1 rounded-[12px] bg-amber-50 px-2 py-0.5 text-[10px] font-bold uppercase tracking-wide text-amber-700 ring-1 ring-amber-200">
                <svg class="h-3 w-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.732 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126zM12 15.75h.007v.008H12v-.008z"/>
                </svg>
                Suspect pricing
            </span>
            <span class="ml-1 text-[10px] text-zinc-500">(no exchange rate snapshot — display amount may be raw USD mis-labelled)</span>
        @endif
    </x-slot:subheading>

    <div class="flex flex-1 flex-col gap-6">

        {{-- Back link --}}
        <a href="{{ route('admin.orders') }}" class="inline-flex items-center gap-1.5 text-xs font-semibold text-blue-600 hover:text-blue-700">
            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 19.5L8.25 12l7.5-7.5"/>
            </svg>
            All orders
        </a>

        {{-- Status overview --}}
        <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
            @foreach ([
                ['Order status', $order->order_status?->value, $order->order_status?->label()],
                ['Payment', $order->payment_status?->value, $order->payment_status?->label()],
                ['Fulfilment', $order->fulfillment_status?->value, $order->fulfillment_status?->label()],
            ] as [$label, $value, $text])
                <div class="rounded-[16px] bg-white p-4 shadow-sm shadow-zinc-900/5 ring-1 ring-zinc-100">
                    <p class="text-[10px] font-semibold uppercase tracking-wider text-zinc-800">{{ $label }}</p>
                    <span class="mt-2 inline-flex items-center rounded-[12px] px-2.5 py-0.5 text-xs font-semibold ring-1 {{ $toneFor($value) }}">
                        {{ $text ?? 'Pending' }}
                    </span>
                </div>
            @endforeach
        </div>

        {{-- Customer + Order info --}}
        <div class="grid grid-cols-1 gap-6 lg:grid-cols-2">

            {{-- Customer --}}
            <div class="rounded-[12px] bg-white p-5 shadow-sm shadow-zinc-900/5 ring-1 ring-zinc-100">
                <h3 class="text-sm font-bold text-zinc-900">Customer</h3>
                <dl class="mt-3 space-y-2.5 text-xs">
                    <div class="flex justify-between gap-4">
                        <dt class="text-zinc-800">Name</dt>
                        <dd class="font-semibold text-zinc-900">{{ $order->user?->name ?? 'Unknown' }}</dd>
                    </div>
                    <div class="flex justify-between gap-4">
                        <dt class="text-zinc-800">Email</dt>
                        <dd class="font-medium text-zinc-700">{{ $order->user?->email ?? 'Unknown' }}</dd>
                    </div>
                    <div class="flex justify-between gap-4">
                        <dt class="text-zinc-800">Customer ID</dt>
                        <dd class="font-mono text-zinc-600">{{ $order->user_id }}</dd>
                    </div>
                </dl>
            </div>

            {{-- Order info --}}
            <div class="rounded-[12px] bg-white p-5 shadow-sm shadow-zinc-900/5 ring-1 ring-zinc-100">
                <h3 class="text-sm font-bold text-zinc-900">Order</h3>
                <dl class="mt-3 space-y-2.5 text-xs">
                    <div class="flex justify-between gap-4">
                        <dt class="text-zinc-800">Order number</dt>
                        <dd class="font-mono font-semibold text-zinc-900">#{{ $order->order_number }}</dd>
                    </div>
                    <div class="flex justify-between gap-4">
                        <dt class="text-zinc-800">Payment method</dt>
                        <dd class="font-semibold capitalize text-zinc-900">{{ $order->payment_method }}</dd>
                    </div>
                    <div class="flex justify-between gap-4">
                        <dt class="text-zinc-800">Currency</dt>
                        <dd class="font-medium text-zinc-700">{{ $order->display_currency }} <span class="text-zinc-800">(settles {{ $order->settlement_currency }})</span></dd>
                    </div>
                    @if ($order->provider_status)
                        <div class="flex justify-between gap-4">
                            <dt class="text-zinc-800">Provider status</dt>
                            <dd class="font-medium text-zinc-700">{{ $order->provider_status }}</dd>
                        </div>
                    @endif
                    @if ($order->provider_reference)
                        <div class="flex justify-between gap-4">
                            <dt class="text-zinc-800">Provider reference</dt>
                            <dd class="font-mono text-zinc-600">{{ $order->provider_reference }}</dd>
                        </div>
                    @endif
                    <div class="flex justify-between gap-4">
                        <dt class="text-zinc-800">Placed</dt>
                        <dd class="font-medium text-zinc-700">{{ $fmtDate($order->placed_at ?? $order->created_at) }}</dd>
                    </div>
                    @if ($order->completed_at)
                        <div class="flex justify-between gap-4">
                            <dt class="text-zinc-800">Completed</dt>
                            <dd class="font-medium text-emerald-700">{{ $fmtDate($order->completed_at) }}</dd>
                        </div>
                    @endif
                    @if ($order->failed_at)
                        <div class="flex justify-between gap-4">
                            <dt class="text-zinc-800">Failed</dt>
                            <dd class="font-medium text-red-700">{{ $fmtDate($order->failed_at) }}</dd>
                        </div>
                    @endif
                </dl>
            </div>
        </div>

        {{-- Items --}}
        <div class="rounded-[12px] bg-white shadow-sm shadow-zinc-900/5 ring-1 ring-zinc-100">
            <div class="border-b border-zinc-100 px-5 py-4">
                <h3 class="text-sm font-bold text-zinc-900">Items ({{ $order->items->count() }})</h3>
            </div>

            <div class="divide-y divide-zinc-100">
                @forelse ($order->items as $item)
                    @php
                        $snap = (array) $item->product_snapshot;
                        $vsnap = (array) $item->variant_snapshot;
                        $name = $snap['name'] ?? ($item->product?->name ?? 'Item');
                        $face = $vsnap['face_value'] ?? null;
                        // Scalar entries of the fulfillment payload (codes / pins / links).
                        $payload = collect((array) $item->fulfillment_payload)
                            ->filter(fn ($v) => is_scalar($v) && $v !== '');
                    @endphp
                    <div class="px-5 py-4">
                        <div class="flex flex-wrap items-start justify-between gap-3">
                            <div class="min-w-0">
                                <p class="text-sm font-semibold text-zinc-900">{{ $name }}</p>
                                <p class="mt-0.5 text-[11px] text-zinc-800">
                                    Qty {{ $item->quantity }}
                                    @if ($face) · Face {{ $face }} @endif
                                    · {{ $item->provider_name }}
                                    @if ($item->provider_offer_id) · {{ $item->provider_offer_id }} @endif
                                </p>
                            </div>
                            <div class="flex flex-col items-end gap-1">
                                    @if ($item->fulfillment_status?->value !== 'fulfilled')
                                        <div x-data="{ open: false, loading: false, manualPin: '' }" class="inline-block relative z-10">
                                            <button 
                                                type="button" 
                                                x-on:click="open = true"
                                                class="inline-flex items-center rounded-[12px] bg-white px-2 py-0.5 text-[10px] font-bold uppercase tracking-wide text-zinc-600 shadow-sm ring-1 ring-zinc-200 transition-colors hover:bg-zinc-50 hover:text-zinc-900 disabled:opacity-50 dark:bg-zinc-800 dark:text-zinc-400 dark:ring-zinc-700 dark:hover:bg-zinc-700 dark:hover:text-white"
                                            >
                                                Mark Fulfilled
                                            </button>

                                            <!-- Modal Backdrop -->
                                            <template x-teleport="body">
                                                <div x-show="open" style="display: none;" class="fixed inset-0 z-[100] flex items-center justify-center bg-zinc-900/50 backdrop-blur-sm transition-opacity" x-transition.opacity>
                                                    <!-- Modal Panel -->
                                                    <div x-show="open" @click.outside="if(!loading) open = false" x-transition class="w-full max-w-md transform overflow-hidden rounded-[20px] bg-white p-6 text-left align-middle shadow-2xl ring-1 ring-zinc-200 transition-all dark:bg-zinc-900 dark:ring-white/10 m-4">
                                                        <h3 class="text-lg font-semibold leading-6 text-zinc-900 dark:text-white">Confirm Manual Fulfillment</h3>
                                                        <div class="mt-2">
                                                            <p class="text-sm text-zinc-500 dark:text-zinc-400">
                                                                Are you sure you want to mark this item as manually fulfilled? This cannot be undone.
                                                            </p>
                                                        </div>

                                                        <!-- Input Field for PIN -->
                                                        <div class="mt-4">
                                                            <input 
                                                                type="text" 
                                                                x-model="manualPin"
                                                                placeholder="Enter PIN / Code" 
                                                                class="w-full rounded-xl border-zinc-300 bg-white px-4 py-3 text-base text-zinc-900 shadow-sm focus:border-blue-500 focus:ring-blue-500 dark:border-zinc-700 dark:bg-zinc-800 dark:text-white dark:focus:border-blue-400 dark:focus:ring-blue-400"
                                                            >
                                                        </div>

                                                        <div class="mt-6 flex justify-end gap-3">
                                                            <button 
                                                                type="button" 
                                                                @click="open = false"
                                                                class="inline-flex justify-center rounded-xl border border-zinc-300 bg-white px-4 py-2.5 text-sm font-semibold text-zinc-700 shadow-sm transition-colors hover:bg-zinc-50 focus:outline-none focus:ring-2 focus:ring-zinc-200 focus:ring-offset-2 dark:border-zinc-700 dark:bg-zinc-800 dark:text-zinc-300 dark:hover:bg-zinc-700 dark:focus:ring-zinc-700 dark:focus:ring-offset-zinc-900"
                                                            >
                                                                Cancel
                                                            </button>
                                                            <button 
                                                                type="button" 
                                                                x-on:click="loading = true; fetch('{{ route('admin.api.commerce.mark-manually-fulfilled', $item->id) }}', { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': '{{ csrf_token() }}', 'Accept': 'application/json' }, body: JSON.stringify({ pin: manualPin }) }).then(r => { window.location.reload(); }).catch(() => loading = false)"
                                                                :disabled="loading"
                                                                class="inline-flex justify-center rounded-xl bg-blue-600 px-4 py-2.5 text-sm font-semibold text-white shadow-sm transition-colors hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-offset-2 disabled:opacity-50 disabled:cursor-not-allowed"
                                                            >
                                                                <span x-show="!loading">Confirm & Fulfill</span>
                                                                <span x-show="loading" class="flex items-center gap-2">
                                                                    <svg class="h-4 w-4 animate-spin text-white" viewBox="0 0 24 24" fill="none">
                                                                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                                                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                                                                    </svg>
                                                                    Processing...
                                                                </span>
                                                            </button>
                                                        </div>
                                                    </div>
                                                </div>
                                            </template>
                                        </div>
                                    @endif
                                    <span class="inline-flex items-center rounded-[12px] px-2.5 py-0.5 text-[11px] font-semibold ring-1 {{ $toneFor($item->fulfillment_status?->value) }}">
                                        {{ $item->fulfillment_status?->label() ?? 'Not Started' }}
                                    </span>
                                {{-- Item subtotal — primary USD figure (truth), customer's
                                     display amount underneath as context. --}}
                                @php
                                    $rate = $order->exchangeRate();
                                    $itemUsdSubtotal = $rate
                                        ? round((float) $item->subtotal_amount / $rate, 4)
                                        : (float) $item->subtotal_amount;
                                @endphp
                                <p class="text-sm font-bold text-zinc-900">@moneyCode($itemUsdSubtotal, 'USD')</p>
                                @if (! $order->hasSuspectPricing() && strtoupper((string) $item->display_currency) !== 'USD')
                                    <p class="text-[10px] text-zinc-500">Customer: @moneyCode((float) $item->subtotal_amount, $item->display_currency)</p>
                                @endif
                            </div>
                        </div>

                        {{-- Per-item fulfilment detail --}}
                        <div class="mt-3 grid grid-cols-2 gap-x-4 gap-y-1.5 text-[11px] sm:grid-cols-4">
                            <div>
                                <p class="text-zinc-800">Unit price (USD)</p>
                                @php
                                    $itemUsdUnit = $rate
                                        ? round((float) $item->display_amount / $rate, 4)
                                        : (float) $item->display_amount;
                                @endphp
                                <p class="font-medium text-zinc-700">@moneyCode($itemUsdUnit, 'USD')</p>
                            </div>
                            <div>
                                <p class="text-zinc-800">Provider cost</p>
                                <p class="font-medium text-zinc-700">@moneyCode((float) $item->provider_cost_usd, 'USD')</p>
                            </div>
                            <div>
                                <p class="text-zinc-800">Delivered</p>
                                <p class="font-medium text-zinc-700">{{ $fmtDate($item->delivered_at) ?? '-' }}</p>
                            </div>
                            <div>
                                <p class="text-zinc-800">Failed</p>
                                <p class="font-medium text-zinc-700">{{ $fmtDate($item->failed_at) ?? '-' }}</p>
                            </div>
                        </div>

                        @if ($item->fulfillment_reference)
                            <p class="mt-2 text-[11px] text-zinc-800">Fulfilment ref: <span class="font-mono text-zinc-700">{{ $item->fulfillment_reference }}</span></p>
                        @endif

                        {{-- Delivered payload (redemption codes / pins / links) --}}
                        @if ($payload->isNotEmpty())
                            <div class="mt-3 rounded-[12px] bg-zinc-50 p-3 ring-1 ring-zinc-100 dark:bg-zinc-800 dark:ring-zinc-700">
                                <p class="text-[10px] font-semibold uppercase tracking-wider text-zinc-800 dark:text-zinc-300">Delivered details</p>
                                <dl class="mt-1.5 space-y-1 text-[11px]">
                                    @foreach ($payload as $key => $value)
                                        <div class="flex justify-between gap-4">
                                            <dt class="capitalize text-zinc-800 dark:text-zinc-400">{{ str_replace('_', ' ', $key) }}</dt>
                                            <dd class="font-mono font-semibold text-zinc-900 dark:text-zinc-200">{{ $value }}</dd>
                                        </div>
                                    @endforeach
                                </dl>
                            </div>
                        @endif

                        {{-- Failed reason --}}
                        @if ($item->fulfillment_status?->value === 'failed')
                            @php
                                $errorLog = \App\Models\FulfillmentLog::where('order_item_id', $item->id)->whereNotNull('error_message')->orderByDesc('processed_at')->first();
                            @endphp
                            @if ($errorLog)
                                <div class="mt-3 rounded-[12px] bg-red-50 p-3 ring-1 ring-red-100 dark:bg-red-500/10 dark:ring-red-500/20">
                                    <p class="text-[10px] font-semibold uppercase tracking-wider text-red-800 dark:text-red-400">Fulfillment Error</p>
                                    <p class="mt-1.5 text-[11px] font-mono text-red-900 dark:text-red-300 break-words">{{ $errorLog->error_message }}</p>
                                </div>
                            @endif
                        @endif
                    </div>
                @empty
                    <div class="px-5 py-12 text-center text-sm text-zinc-600">This order has no items.</div>
                @endforelse
            </div>

            {{-- Totals — USD is the platform's source of truth (we book revenue
                 in USD and pay suppliers in USD). The customer-paid display
                 amount sits underneath as context when it differs. --}}
            <div class="border-t border-zinc-100 px-5 py-4">
                <dl class="ml-auto max-w-sm space-y-1.5 text-xs">
                    <div class="flex justify-between gap-4">
                        <dt class="text-zinc-800">Subtotal (USD)</dt>
                        <dd class="font-medium text-zinc-700">@moneyCode($order->usdSubtotal(), 'USD')</dd>
                    </div>
                    <div class="flex justify-between gap-4">
                        <dt class="text-zinc-800">Markup (USD)</dt>
                        <dd class="font-medium text-zinc-700">@moneyCode($order->usdMarkup(), 'USD')</dd>
                    </div>
                    <div class="flex justify-between gap-4 border-t border-zinc-100 pt-1.5">
                        <dt class="font-bold text-zinc-900">Total (USD)</dt>
                        <dd class="font-bold text-zinc-900">@moneyCode($order->usdTotal(), 'USD')</dd>
                    </div>

                    @if (! $order->hasSuspectPricing() && strtoupper((string) $order->display_currency) !== 'USD')
                        <div class="mt-2 flex justify-between gap-4 border-t border-zinc-100 pt-2">
                            <dt class="text-zinc-600">Customer paid</dt>
                            <dd class="text-zinc-600">@moneyCode((float) $order->total_amount, $order->display_currency) @if ($order->exchangeRate()) <span class="text-[10px] text-zinc-400">@ {{ number_format($order->exchangeRate(), 4) }}</span>@endif</dd>
                        </div>
                    @endif
                </dl>
            </div>
        </div>

        {{-- Payment attempts --}}
        <div class="rounded-[12px] bg-white shadow-sm shadow-zinc-900/5 ring-1 ring-zinc-100">
            <div class="border-b border-zinc-100 px-5 py-4">
                <h3 class="text-sm font-bold text-zinc-900">Payment attempts ({{ $order->paymentAttempts->count() }})</h3>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full text-left text-[11px]">
                    <thead class="bg-zinc-50 text-[10px] uppercase tracking-wider text-zinc-800">
                        <tr>
                            <th class="px-5 py-3 font-semibold">Reference</th>
                            <th class="px-5 py-3 font-semibold">Gateway</th>
                            <th class="px-5 py-3 font-semibold">Amount</th>
                            <th class="px-5 py-3 font-semibold">Status</th>
                            <th class="px-5 py-3 font-semibold">Date</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-zinc-100">
                        @forelse ($order->paymentAttempts as $attempt)
                            <tr>
                                <td class="px-5 py-3 font-mono text-zinc-600">{{ $attempt->gateway_reference ?: $attempt->idempotency_key }}</td>
                                <td class="px-5 py-3 capitalize text-zinc-600">{{ $attempt->gateway }}</td>
                                <td class="px-5 py-3 font-semibold text-zinc-900">{{ $attempt->currency }} {{ number_format((float) $attempt->amount, 2) }}</td>
                                <td class="px-5 py-3">
                                    <span class="inline-flex items-center rounded-[12px] px-2.5 py-0.5 text-[11px] font-semibold ring-1 {{ $toneFor($attempt->payment_status?->value) }}">
                                        {{ $attempt->payment_status?->label() ?? 'Pending' }}
                                    </span>
                                </td>
                                <td class="px-5 py-3 text-zinc-600">{{ $fmtDate($attempt->created_at) }}</td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="5" class="px-5 py-10 text-center text-sm text-zinc-600">No payment attempts recorded.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>

    </div>
</x-layouts.admin>

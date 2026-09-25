@php
    use App\Models\Order;

    $tab = request()->query('tab', 'completed');
    $query = Order::with(['user', 'items']);

    if ($tab === 'completed') {
        $query->whereIn('order_status', ['completed', 'partially_completed']);
    } elseif ($tab === 'processing') {
        $query->where('order_status', 'processing');
    } elseif ($tab === 'failed-fulfillment') {
        $query->whereIn('payment_status', ['paid', 'reserved'])
              ->where('order_status', 'failed');
    } elseif ($tab === 'unpaid') {
        $query->whereNotIn('payment_status', ['paid', 'reserved', 'refunded']);
    } else { // 'all-paid'
        $query->whereIn('payment_status', ['paid', 'reserved', 'refunded']);
    }

    // `orders` grows on every checkout attempt, and the `unpaid` tab collects the
    // abandoned ones too, so the old limit(50) hid the tail of every tab for good:
    // an older failed fulfilment was simply unreachable from this page. paginate()
    // keeps the per-request cost flat and withQueryString() carries `tab` onto the
    // page links so the active tab survives paging. total() replaces the separate
    // count() (which ran a second query for the same number).
    // orderByDesc('id') breaks created_at ties, which are ordinary on a
    // second-granularity column: without it a tied order can render on two
    // consecutive pages, or on neither.
    $orders = $query->latest()->orderByDesc('id')->paginate(50)->withQueryString();
    $totalOrders = $orders->total();

    // Status pill tone — maps OrderStatus values to the canonical x-admin.badge
    // tone palette so every list page reads the same.
    $statusPillFor = function (?string $status): array {
        return match ($status) {
            'completed' => ['label' => 'Completed', 'tone' => 'emerald'],
            'partially_completed' => ['label' => 'Partial', 'tone' => 'blue'],
            'failed', 'cancelled' => ['label' => 'Failed', 'tone' => 'red'],
            'requires_attention' => ['label' => 'Attention', 'tone' => 'red'],
            'processing' => ['label' => 'Processing', 'tone' => 'blue'],
            default => ['label' => 'Pending', 'tone' => 'amber'],
        };
    };
@endphp

<x-layouts.admin>
    <x-slot:heading>Orders</x-slot:heading>
    <x-slot:subheading>
        {{ number_format($totalOrders) }} orders total.
        @if ($orders->isNotEmpty())
            Showing {{ number_format($orders->firstItem()) }}-{{ number_format($orders->lastItem()) }}.
        @endif
    </x-slot:subheading>

    <div class="mb-6 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
        <div class="inline-flex overflow-x-auto rounded-[12px] bg-zinc-100 p-1 font-medium text-sm text-zinc-600 dark:bg-zinc-800/50 dark:text-zinc-400">
            @php
                $tabs = [
                    'completed' => 'Completed',
                    'processing' => 'Processing',
                    'failed-fulfillment' => 'Failed Fulfillment',
                    'unpaid' => 'Unpaid / Abandoned',
                    'all-paid' => 'All Paid',
                ];
            @endphp
            @foreach($tabs as $key => $label)
                <a href="{{ route('admin.orders', ['tab' => $key]) }}"
                   class="whitespace-nowrap rounded-[12px] px-4 py-2 text-sm transition-all {{ $tab === $key ? 'bg-white text-zinc-900 shadow-sm ring-1 ring-zinc-900/5 dark:bg-[#1d3252] dark:text-white dark:ring-white/10' : 'hover:text-zinc-900 hover:bg-zinc-200/50 dark:hover:text-white dark:hover:bg-zinc-700/50' }}">
                    {{ $label }}
                </a>
            @endforeach
        </div>

        <a href="{{ route('admin.api.commerce.orders.export', ['tab' => $tab]) }}"
            class="inline-flex shrink-0 items-center justify-center gap-2 rounded-[12px] bg-blue-600 px-4 py-2 text-sm font-semibold text-white transition-colors hover:bg-blue-700">
            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" d="M12 4v12m0 0l-4-4m4 4l4-4M4 20h16"/>
            </svg>
            Export CSV
        </a>
    </div>

    {{-- Shared grid template — same pattern as Customers / Transactions /
         Products pages so every list reads the same. --}}
    <style>
        .order-row {
            display: grid;
            grid-template-columns:
                minmax(170px, 1.2fr)   /* Order # (copyable) */
                minmax(170px, 1.4fr)   /* Customer */
                minmax(60px,  0.4fr)   /* Items count */
                minmax(140px, 1fr)     /* Total */
                minmax(110px, 0.8fr)   /* Status */
                minmax(120px, 0.9fr);  /* Date */
            gap: 1.25rem;
            align-items: center;
            min-width: 900px;
        }
        /* Inset divider line between body rows */
        .order-body:not(:last-of-type)::after {
            content: '';
            position: absolute;
            left: 1.5rem;
            right: 1.5rem;
            bottom: 0;
            height: 1px;
            background-color: rgb(244 244 245);
            pointer-events: none;
        }
        html.dark .order-body:not(:last-of-type)::after {
            background-color: rgb(255 255 255 / 0.08);
        }
        .order-body:hover::after { display: none; }
    </style>

    <div class="overflow-hidden rounded-[12px] pb-3 bg-white shadow-sm shadow-zinc-900/[0.04] dark:bg-[#1d3252]">
        <div class="scroll-y-thin overflow-x-auto [scrollbar-width:thin] [&::-webkit-scrollbar]:h-1.5 [&::-webkit-scrollbar-thumb]:rounded-full [&::-webkit-scrollbar-thumb]:bg-zinc-300 dark:[&::-webkit-scrollbar-thumb]:bg-zinc-600">

        {{-- Header pill — light-blue bg, 2px blue ring. --}}
        <div class="order-row grid mx-3 my-3 rounded-[12px] bg-zinc-50 px-6 py-3 text-[10px] font-bold uppercase tracking-wider text-zinc-600 dark:bg-white/5 dark:text-zinc-300">
            <span class="col-order">Order #</span>
            <span>Customer</span>
            <span>Items</span>
            <span class="col-total">Total</span>
            <span class="col-status">Status</span>
            <span>Date</span>
        </div>

        @forelse ($orders as $order)
            @php
                $statusValue = $order->order_status?->value ?? 'pending';
                $status = $statusPillFor($statusValue);
                $orderNumber = '#'.$order->order_number;
            @endphp
            <a
                href="{{ route('admin.order', $order) }}"
                wire:navigate
                class="order-row order-body group relative mx-3 cursor-pointer bg-white px-6 py-3 transition-all hover:bg-blue-50 dark:bg-[#1d3252]"
            >
                {{-- Order # — click-to-copy chip. Stops the link nav so a
                     copy click doesn't open the order page. --}}
                <span class="col-order min-w-0">
                    <button
                        type="button"
                        x-data="{ copied: false }"
                        @click.stop.prevent="navigator.clipboard.writeText(@js($orderNumber)); copied = true; setTimeout(() => copied = false, 1500)"
                        :aria-label="copied ? 'Copied' : 'Copy order id'"
                        class="group/copy inline-flex max-w-full items-center gap-1 rounded-[12px] px-1.5 py-0.5 font-mono text-[12px] font-semibold text-blue-600 transition-colors hover:bg-blue-100 dark:text-blue-400 dark:hover:bg-blue-500/15"
                    >
                        <span class="truncate">{{ $orderNumber }}</span>
                        <svg x-show="! copied" class="h-3 w-3 shrink-0 opacity-0 transition-opacity group-hover/copy:opacity-100" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M8 5H6a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2v-1M8 5a2 2 0 002 2h2a2 2 0 002-2M8 5a2 2 0 012-2h2a2 2 0 012 2m0 0h2a2 2 0 012 2v3"/>
                        </svg>
                        <svg x-show="copied" x-cloak class="h-3 w-3 shrink-0 text-emerald-600 dark:text-emerald-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="3" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/>
                        </svg>
                    </button>
                    @if ($order->hasSuspectPricing())
                        <span class="ml-1 inline-flex items-center rounded-[12px] bg-amber-50 px-1.5 py-0.5 text-[9px] font-bold uppercase tracking-wide text-amber-700 ring-1 ring-amber-200" title="No exchange rate snapshot — display amount may be raw USD mis-labelled">!</span>
                    @endif
                </span>

                {{-- Customer --}}
                <span class="truncate text-[13px] font-medium text-zinc-900 dark:text-white">{{ $order->user?->name ?? 'Unknown' }}</span>

                {{-- Items count --}}
                <span class="truncate text-[13px] text-zinc-600 dark:text-zinc-400">{{ $order->items->count() }}</span>

                {{-- Total — USD primary, display currency secondary. --}}
                <span class="col-total truncate text-[13px]">
                    <span class="block font-bold tabular-nums text-zinc-900 dark:text-white">@moneyCode($order->usdTotal(), 'USD')</span>
                    @if (! $order->hasSuspectPricing() && strtoupper((string) $order->display_currency) !== 'USD')
                        <span class="block text-[10px] text-zinc-500 dark:text-zinc-400">@moneyCode((float) $order->total_amount, $order->display_currency)</span>
                    @endif
                </span>

                {{-- Status pill --}}
                <span class="col-status">
                    <x-admin.badge :tone="$status['tone']">{{ $status['label'] }}</x-admin.badge>
                </span>

                {{-- Date --}}
                <span class="truncate text-[12px] text-zinc-600 dark:text-zinc-400">{{ $order->created_at->format('M j, Y') }}</span>
            </a>
        @empty
            <div class="px-5 py-12 text-center text-sm text-zinc-600 dark:text-zinc-400">
                No orders yet.
            </div>
        @endforelse

        </div>

        @if ($orders->hasPages())
            <div class="border-t border-zinc-200 px-5 py-3 dark:border-zinc-700">
                {{ $orders->links() }}
            </div>
        @endif
    </div>
</x-layouts.admin>

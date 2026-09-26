<?php

use App\Domain\Payment\Enums\PaymentStatus;
use App\Domain\Shared\Enums\FundingStatus;
use App\Domain\Shared\Enums\WalletTransactionType;
use App\Models\PaymentAttempt;
use App\Models\Product;
use App\Models\WalletFunding;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Locked;
use Livewire\Volt\Component;
use Symfony\Component\HttpFoundation\StreamedResponse;

new
#[Layout('components.layouts.dashboard')]
#[Title('Transactions')]
class extends Component {
    /** Search by reference / description. */
    public string $search = '';

    /** Direction filter: all | credit | debit. */
    public string $filter = 'all';

    /** How many more rows each scroll to the bottom brings in. */
    private const CHUNK = 12;

    /**
     * How many rows are on the page right now. The ledger has no page numbers:
     * reaching the bottom grows this figure and the next chunk renders under
     * the rows already there, the same as the order history and the
     * notifications. Locked so the browser cannot ask for the whole ledger in
     * one request.
     */
    #[Locked]
    public int $visible = self::CHUNK;

    public function loadMore(): void
    {
        $this->visible += self::CHUNK;
    }

    public function updatedSearch(): void
    {
        $this->visible = self::CHUNK;
    }

    public function setFilter(string $filter): void
    {
        $this->filter = in_array($filter, ['all', 'credit', 'debit'], true) ? $filter : 'all';
        $this->visible = self::CHUNK;
    }

    /**
     * Stream the customer's wallet ledger as a CSV download. Respects the
     * current search + direction filter so the file matches what's on-screen.
     * Streamed (not buffered) so a multi-year ledger doesn't blow the PHP
     * memory limit. Filename includes the date so repeat exports don't
     * collide in the user's downloads folder.
     */
    public function downloadCsv(): StreamedResponse
    {
        $userId = Auth::id();
        $search = trim($this->search);
        $filter = $this->filter;

        $filename = 'transactions-'.now()->format('Y-m-d').'.csv';

        return response()->streamDownload(function () use ($userId, $search, $filter) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['Date', 'Reference', 'Direction', 'Category', 'Description', 'Amount', 'Currency', 'Balance after']);

            $query = \App\Models\User::find($userId)->walletTransactions()->latest();

            if ($search !== '') {
                $query->where(function ($q) use ($search) {
                    $q->where('reference', 'like', "%{$search}%")
                        ->orWhere('description', 'like', "%{$search}%");
                });
            }
            if ($filter === 'credit') {
                $query->where('type', WalletTransactionType::Credit);
            } elseif ($filter === 'debit') {
                $query->where('type', WalletTransactionType::Debit);
            }

            // chunkById keeps memory flat regardless of ledger size.
            $query->chunkById(500, function ($rows) use ($out) {
                foreach ($rows as $row) {
                    fputcsv($out, [
                        $row->created_at->format('Y-m-d H:i:s'),
                        $row->reference,
                        $row->type === WalletTransactionType::Credit ? 'Credit' : 'Debit',
                        $row->transaction_category?->label(),
                        $row->description,
                        number_format((float) $row->amount, 2, '.', ''),
                        $row->currency?->value,
                        $row->balance_after !== null ? number_format((float) $row->balance_after, 2, '.', '') : '',
                    ]);
                }
            });

            // Gateway order payments (card / mobile money / crypto) - part of
            // the on-screen unified feed, so the export carries them too.
            if ($filter !== 'credit') {
                $this->orderPaymentsQuery($search)->chunkById(500, function ($attempts) use ($out) {
                    foreach ($attempts as $attempt) {
                        fputcsv($out, [
                            ($attempt->confirmed_at ?? $attempt->created_at)->format('Y-m-d H:i:s'),
                            $attempt->order?->order_number ?? $attempt->gateway_reference,
                            'Debit',
                            'Order payment',
                            $attempt->customerMethodLabel(),
                            number_format((float) $attempt->amount, 2, '.', ''),
                            $attempt->currency,
                            '',
                        ]);
                    }
                });
            }

            fclose($out);
        }, $filename, [
            'Content-Type'  => 'text/csv',
            'Cache-Control' => 'no-store, no-cache, must-revalidate',
        ]);
    }

    /**
     * Paid gateway attempts on ORDERS (card / mobile money / crypto). These never
     * touch a wallet, so without this source a card-paying customer's Transactions
     * page only ever showed their Rcoin credits. Wallet-gateway attempts are
     * excluded - their wallet debit is already in the ledger.
     */
    private function orderPaymentsQuery(string $term)
    {
        $query = PaymentAttempt::query()
            ->where('user_id', Auth::id())
            ->whereNotNull('order_id')
            ->where('gateway', '!=', 'wallet')
            ->whereIn('payment_status', [PaymentStatus::Paid, PaymentStatus::Refunded, PaymentStatus::PartiallyRefunded])
            ->with('order:id,order_number')
            ->latest();

        if ($term !== '') {
            $query->where(function ($q) use ($term) {
                $q->where('gateway_reference', 'like', "%{$term}%")
                    ->orWhereHas('order', fn ($o) => $o->where('order_number', 'like', "%{$term}%"));
            });
        }

        return $query;
    }

    /** @return object Uniform row the blade renders regardless of source. */
    private function paymentRow(PaymentAttempt $attempt): object
    {
        return (object) [
            'key' => 'pay-'.$attempt->id,
            'is_credit' => false,
            'title' => $attempt->customerMethodLabel(),
            'sub' => $attempt->order?->order_number ? 'Order '.$attempt->order->order_number : 'Order payment',
            'amount' => (float) $attempt->amount,
            'symbol' => Product::currencySymbol($attempt->currency),
            'date' => $attempt->confirmed_at ?? $attempt->created_at,
            'balance_after' => null,
        ];
    }

    private function walletRow(\App\Models\WalletTransaction $txn): object
    {
        return (object) [
            'key' => 'txn-'.$txn->id,
            'is_credit' => $txn->type === WalletTransactionType::Credit,
            'title' => $txn->description ?: ($txn->transaction_category?->label() ?? 'Wallet transaction'),
            'sub' => $txn->transaction_category?->label() ?? $txn->type->label(),
            'amount' => (float) $txn->amount,
            'symbol' => $txn->currency?->symbol() ?? '',
            'date' => $txn->created_at,
            'balance_after' => $txn->balance_after !== null ? (float) $txn->balance_after : null,
        ];
    }

    public function with(): array
    {
        $term = trim($this->search);

        $query = Auth::user()->walletTransactions()->latest();

        if ($term !== '') {
            $query->where(function ($q) use ($term) {
                $q->where('reference', 'like', "%{$term}%")
                    ->orWhere('description', 'like', "%{$term}%");
            });
        }

        if ($this->filter === 'credit') {
            $query->where('type', WalletTransactionType::Credit);
        } elseif ($this->filter === 'debit') {
            $query->where('type', WalletTransactionType::Debit);
        }

        // Unified feed: wallet ledger + paid order payments, newest first. The two
        // sources live in different tables and are merged in PHP, so both are capped
        // to stop a long-lived account blowing memory.
        //
        // Each source is fetched at CAP + 1 rows purely to detect whether the cap
        // actually bit. Without that the truncation is SILENT: the in-memory
        // paginator below reports a total of 500, so a customer with more history
        // than that is shown a complete-looking list with no way to tell that older
        // activity exists. The extra row is discarded before rendering.
        $cap = 500;

        $walletRows = $query->limit($cap + 1)->get();
        $feedTruncated = $walletRows->count() > $cap;
        $rows = $walletRows->take($cap)->map(fn ($txn) => $this->walletRow($txn));

        if ($this->filter !== 'credit') {
            $paymentRows = $this->orderPaymentsQuery($term)->limit($cap + 1)->get();
            $feedTruncated = $feedTruncated || $paymentRows->count() > $cap;
            $rows = $rows->concat($paymentRows->take($cap)->map(fn ($a) => $this->paymentRow($a)));
        }

        $rows = $rows->sortByDesc('date')->values();

        // The feed is two queries merged and sorted in PHP, so the slice is
        // taken here rather than by a paginator: the rows already exist, and
        // the only question is how many of them to draw.
        $hasMoreTransactions = $rows->count() > $this->visible;
        $transactions = $rows->take($this->visible);

        // Non-completed funding attempts. A completed deposit already appears in
        // the ledger below as a credit, so here we surface only the in-flight and
        // failed ones so the customer can see a deposit is still pending or failed.
        // A failed funding is only shown when it actually reached a gateway
        // (its attempt holds a gateway reference). One that never did was
        // never a deposit: an amount typed and abandoned before any address or
        // charge existed, which the sweeper later marked failed. Telling the
        // customer that "failed" reads as a deposit they never made going wrong.
        $recentDeposits = WalletFunding::where('user_id', Auth::id())
            ->where(function ($query) {
                $query->whereIn('status', [FundingStatus::Pending, FundingStatus::Processing])
                    ->orWhere(function ($failed) {
                        $failed->where('status', FundingStatus::Failed)
                            ->whereHas('paymentAttempt', fn ($attempt) => $attempt->whereNotNull('gateway_reference'));
                    });
            })
            ->latest()
            ->limit(4)
            ->get();

        return [
            'transactions' => $transactions,
            'hasMoreTransactions' => $hasMoreTransactions,
            'recentDeposits' => $recentDeposits,
            'feedTruncated' => $feedTruncated,
            'feedCap' => $cap,
        ];
    }
}; ?>

@php
    // Unified status pill tones — same recipe as the admin list pages (and the
    // customer orders page) so every status badge in the app reads the same.
    $depositStatusUi = [
        'pending'    => ['Pending',    'bg-amber-50 text-amber-700 dark:bg-amber-500/15 dark:text-amber-300'],
        'processing' => ['Processing', 'bg-blue-600/10 text-blue-700 dark:bg-blue-600/15! dark:text-zinc-200!'],
        'failed'     => ['Failed',     'bg-red-50 text-red-700 dark:bg-red-500/15 dark:text-red-300'],
    ];
@endphp

<div class="flex w-full flex-col gap-5">

    {{-- Heading --}}
    <h1 class="hidden text-xl font-bold tracking-tight text-zinc-900 sm:text-3xl lg:block">Transactions</h1>

    {{-- Search --}}
    <div>
        <label for="txn-search" class="block text-sm font-bold text-zinc-900">Search transactions</label>
        <div class="relative mt-2">
            <svg class="pointer-events-none absolute left-4 top-1/2 h-5 w-5 -translate-y-1/2 text-zinc-900 dark:text-zinc-300" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/>
            </svg>
            <input
                id="txn-search"
                type="text"
                wire:model.live.debounce.300ms="search"
                placeholder="Search by reference or description"
                class="w-full rounded-full py-2 sm:rounded-[15px] sm:py-3 border-2 border-zinc-400 bg-[#ffffff] pl-12 pr-4 text-sm text-zinc-900 placeholder:text-zinc-500 outline-none transition-colors hover:border-zinc-500 focus:border-blue-500 focus:ring-2 focus:ring-blue-500/15 dark:text-white"
            >
            <div wire:loading wire:target="search" class="absolute right-3 top-1/2 -translate-y-1/2">
                <svg class="h-4 w-4 animate-spin text-blue-600" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                    <circle class="opacity-30" cx="12" cy="12" r="9" stroke="currentColor" stroke-width="3"/>
                    <path class="opacity-90" fill="currentColor" d="M12 3a9 9 0 0 1 9 9h-3a6 6 0 0 0-6-6V3z"/>
                </svg>
            </div>
        </div>
    </div>

    {{-- Direction filter pills + CSV export. The download honours the current
         search + filter so what the customer sees on screen is what they get
         in the file. --}}
    <div class="flex flex-wrap items-center justify-between gap-3">
        <div class="flex items-center gap-2">
            @foreach (['all' => 'All', 'credit' => 'Money in', 'debit' => 'Money out'] as $key => $label)
                <button
                    type="button"
                    wire:click="setFilter('{{ $key }}')"
                    class="rounded-full px-3.5 py-2 text-sm font-semibold transition-colors {{ $filter === $key ? 'bg-blue-600 text-white' : 'bg-[#ffffff] text-zinc-700 ring-1 ring-zinc-200 hover:bg-[#f4f6f5]' }}"
                >
                    {{ $label }}
                </button>
            @endforeach
        </div>

        <button
            type="button"
            wire:click="downloadCsv"
            wire:loading.attr="disabled"
            wire:target="downloadCsv"
            class="inline-flex items-center gap-1.5 rounded-full border border-zinc-200 bg-[#ffffff] px-3.5 py-2 text-sm font-semibold text-zinc-700 transition-colors hover:bg-[#f4f6f5] disabled:cursor-not-allowed disabled:opacity-60 dark:border-zinc-700/60 dark:bg-[#1d3252] dark:text-zinc-200 dark:hover:bg-[#26416b]"
        >
            <svg wire:loading.remove wire:target="downloadCsv" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" d="M3 16.5v2.25A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75V16.5M16.5 12L12 16.5m0 0L7.5 12m4.5 4.5V3"/>
            </svg>
            <svg wire:loading wire:target="downloadCsv" class="h-4 w-4 animate-spin" fill="none" viewBox="0 0 24 24" aria-hidden="true">
                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/>
                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z"/>
            </svg>
            <span wire:loading.remove wire:target="downloadCsv">Export CSV</span>
            <span wire:loading wire:target="downloadCsv">Preparing...</span>
        </button>
    </div>

    {{-- Recent deposits — in-flight / failed funding attempts. --}}
    @if ($recentDeposits->isNotEmpty())
        <div class="flex flex-col gap-2">
            <p class="text-sm font-bold text-zinc-900">Recent deposits</p>
            <div class="divide-y divide-zinc-200 overflow-hidden rounded-[12px] bg-[#ffffff] shadow-md shadow-zinc-900/[0.06] dark:shadow-none">
                @foreach ($recentDeposits as $deposit)
                    @php [$depLabel, $depClass] = $depositStatusUi[$deposit->status->value] ?? ['Pending', 'bg-amber-50 text-amber-700 dark:bg-amber-500/15 dark:text-amber-300']; @endphp
                    <div class="flex items-center gap-3 px-4 py-3" wire:key="dep-{{ $deposit->id }}">
                        <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-[8px] bg-[#f4f6f5] text-blue-600">
                            <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m0 0l6-6m-6 6l-6-6"/>
                            </svg>
                        </span>
                        <div class="min-w-0 flex-1">
                            <p class="truncate text-sm font-bold text-zinc-900">Wallet deposit</p>
                            <p class="truncate text-xs text-zinc-500">{{ $deposit->reference }} &middot; {{ $deposit->created_at->format('d M Y, H:i') }}</p>
                        </div>
                        <div class="flex shrink-0 flex-col items-end gap-1">
                            <p class="text-sm font-bold text-zinc-900">{{ $deposit->currency?->symbol() }}{{ number_format((float) $deposit->amount, 2) }}</p>
                            <span class="inline-flex w-fit items-center whitespace-nowrap rounded-full px-2.5 py-0.5 text-[11px] font-semibold {{ $depClass }}">{{ $depLabel }}</span>
                        </div>
                    </div>
                @endforeach
            </div>
        </div>
    @endif

    {{-- Ledger skeleton — overlays the list only while a search / filter / page
         change is in flight. Zero-CLS: <x-skeletons.table-row> matches the real
         row's dimensions exactly, so the list never jumps when data arrives. --}}
    <div
        wire:loading.flex
        wire:target="search, setFilter, gotoPage, nextPage, previousPage"
        class="skeleton-stagger-fast flex-col divide-y divide-zinc-200 overflow-hidden rounded-[12px] bg-[#ffffff] shadow-md shadow-zinc-900/[0.06] dark:shadow-none"
    >
        @for ($i = 0; $i < 6; $i++)
            <x-skeletons.table-row style="--i: {{ $i }}" />
        @endfor
    </div>

    {{-- Ledger --}}
    <div wire:loading.remove wire:target="search, setFilter, gotoPage, nextPage, previousPage" class="flex flex-col gap-5">
    @if ($transactions->isNotEmpty())
        <div class="divide-y divide-zinc-200 overflow-hidden rounded-[12px] bg-[#ffffff] shadow-md shadow-zinc-900/[0.06] dark:shadow-none">
            @foreach ($transactions as $txn)
                <div class="flex items-center gap-3 px-4 py-3.5" wire:key="{{ $txn->key }}">
                    {{-- Direction icon in a small rounded tile: a dark blue arrow
                         pointing in for money in, out for money out. --}}
                    <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-[10px] bg-[#f4f6f5]">
                        @if ($txn->is_credit)
                            <x-icons.txn-credit class="h-4 w-4" />
                        @else
                            <x-icons.txn-debit class="h-4 w-4" />
                        @endif
                    </span>
                    <div class="min-w-0 flex-1">
                        <p class="truncate text-sm font-bold text-zinc-900">{{ $txn->title }}</p>
                        <p class="truncate text-xs text-zinc-500">
                            {{ $txn->sub }}
                            &middot; {{ $txn->date->format('d M Y, H:i') }}
                        </p>
                    </div>
                    <div class="flex shrink-0 flex-col items-end gap-0.5">
                        <p class="text-sm font-bold {{ $txn->is_credit ? 'text-emerald-600' : 'text-red-600' }}">
                            {{ $txn->is_credit ? '+' : '-' }}{{ $txn->symbol }}{{ number_format($txn->amount, 2) }}
                        </p>
                        @if ($txn->balance_after !== null)
                            <p class="text-[11px] text-zinc-400">Balance {{ $txn->symbol }}{{ number_format($txn->balance_after, 2) }}</p>
                        @endif
                    </div>
                </div>
            @endforeach
        </div>
    @else
        {{-- Empty state --}}
        <div class="dash-shimmer rounded-[12px] bg-[#ffffff] px-6 py-16 text-center shadow-md shadow-zinc-900/[0.06] dark:shadow-none">
            <span class="mx-auto flex h-14 w-14 items-center justify-center rounded-[12px] bg-[#f4f6f5] text-blue-600">
                <svg class="h-7 w-7" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M2.25 8.25h19.5M2.25 9h19.5m-16.5 5.25h6m-6 2.25h3m-3.75 3h15a2.25 2.25 0 002.25-2.25V6.75A2.25 2.25 0 0019.5 4.5h-15a2.25 2.25 0 00-2.25 2.25v10.5A2.25 2.25 0 004.5 19.5z"/>
                </svg>
            </span>
            <p class="mt-4 text-base font-semibold text-zinc-900">
                @if (trim($search) !== '' || $filter !== 'all')
                    No matching transactions
                @else
                    No transactions yet
                @endif
            </p>
            <p class="mt-1 text-sm text-zinc-600">
                @if (trim($search) !== '' || $filter !== 'all')
                    Try a different search or filter.
                @else
                    Fund your wallet or make a purchase and it will show up here.
                @endif
            </p>
            @unless (trim($search) !== '' || $filter !== 'all')
                {{-- Same #f4f6f5 button as "Explore everything" on the empty orders page. --}}
                <a href="{{ route('dashboard.wallet') }}" wire:navigate class="mt-4 inline-flex items-center rounded-[12px] bg-[#f4f6f5] px-5 py-3 text-sm font-semibold text-zinc-900 transition-shadow hover:shadow-lg hover:shadow-zinc-900/[0.10] focus:outline-none focus-visible:ring-2 focus-visible:ring-blue-500/40 dark:border-2 dark:border-zinc-700/60 dark:text-white dark:hover:border-zinc-500">
                    Go to wallet
                </a>
            @endunless
        </div>
    @endif

    @if ($hasMoreTransactions)
        {{-- Scroll sentinel, as on the order history. As it nears the viewport
             it asks for the next chunk. Its key carries the loaded count, so
             every response hands the browser a fresh element that fires again
             at once if the new rows still leave it on screen. It leaves with
             the last transaction.

             The CSV beside the filters was never bound to what is drawn here:
             it streams the whole ledger in chunks, so an export is the
             complete history whether one chunk has been loaded or twenty. --}}
        <div
            wire:key="transactions-more-{{ $visible }}"
            wire:intersect.margin.400px="loadMore"
            class="flex items-center justify-center gap-2 py-4 text-xs font-semibold text-zinc-500 dark:text-zinc-400"
        >
            <svg class="h-4 w-4 animate-spin text-blue-600" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                <circle class="opacity-30" cx="12" cy="12" r="9" stroke="currentColor" stroke-width="3"/>
                <path class="opacity-90" fill="currentColor" d="M12 3a9 9 0 0 1 9 9h-3a6 6 0 0 0-6-6V3z"/>
            </svg>
            Loading more transactions
        </div>
    @endif
    </div>
</div>

@php
    use App\Models\Wallet;

    // withCount, NOT with('transactions'): the old eager load pulled every
    // wallet_transaction row for all 50 wallets into memory purely to print one
    // integer per row. wallet_transactions is the fastest-growing table in the
    // schema (every top-up, purchase, refund and Rcoin credit writes to it), and
    // limit(50) capped the wallets, never their ledgers. withCount pushes it down
    // to a SQL COUNT so the page cost stays flat as balances accumulate.
    // orderByDesc('id') breaks created_at ties so a wallet cannot straddle two
    // pages or fall between them.
    $wallets = Wallet::withCount('transactions')
        ->with('user')
        ->latest()
        ->orderByDesc('id')
        ->paginate(25)
        ->withQueryString();
    $totalWallets = Wallet::count();

    // Per-currency totals — DON'T sum across currencies (the previous "$100,104.69"
    // headline added NGN + USD + GHS + GBP + XAF as if they were the same unit).
    // Each row in the subheading reads "<symbol><amount> <code>", so the admin
    // sees an honest breakdown instead of a fake aggregate.
    $balancesByCurrency = Wallet::query()
        ->selectRaw('UPPER(currency) as currency, SUM(balance) as total')
        ->groupBy('currency')
        ->orderBy('currency')
        ->get()
        ->filter(fn ($row) => (float) $row->total > 0);
@endphp

<x-layouts.admin>
    <x-slot:heading>Wallets</x-slot:heading>
    <x-slot:subheading>
        {{ number_format($totalWallets) }} {{ \Illuminate\Support\Str::plural('wallet', $totalWallets) }}
        @if ($balancesByCurrency->isNotEmpty())
            ·
            @foreach ($balancesByCurrency as $row)
                @money((float) $row->total, $row->currency){{ ' ' }}{{ $row->currency }}@if (! $loop->last), @endif
            @endforeach
        @endif
    </x-slot:subheading>

    <div class="flex flex-1 flex-col gap-6">

        <div class="overflow-hidden rounded-[12px] bg-white shadow-sm shadow-zinc-900/[0.04] dark:bg-[#1d3252]">
            <div class="overflow-x-auto p-3">
                <table class="admin-table w-full text-left text-sm">
                    <thead>
                        <tr>
                            <th>Owner</th>
                            <th>Balance</th>
                            <th>Currency</th>
                            <th>Transactions</th>
                            <th>Status</th>
                            <th>Created</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($wallets as $wallet)
                            <tr>
                                <td>
                                    <div class="flex items-center gap-3">
                                        @php
                                            $rowAvatar = $wallet->user?->avatar_url ?: ($wallet->user?->initialsAvatar() ?? '');
                                        @endphp
                                        <img src="{{ $rowAvatar }}" alt="" class="h-9 w-9 shrink-0 rounded-[12px] object-cover ring-1 ring-blue-100 dark:ring-blue-500/30">
                                        <div class="leading-tight">
                                            <p class="text-[12px] font-semibold text-zinc-900 dark:text-white">{{ $wallet->user?->name ?? '—' }}</p>
                                            <p class="text-[11px] text-zinc-600 dark:text-zinc-400">{{ $wallet->user?->email ?? '—' }}</p>
                                        </div>
                                    </div>
                                </td>
                                <td class="font-semibold tabular-nums text-zinc-900 dark:text-white">@money((float) $wallet->balance, $wallet->currency->value)</td>
                                <td class="font-medium uppercase">{{ $wallet->currency }}</td>
                                <td class="tabular-nums">{{ number_format($wallet->transactions_count) }}</td>
                                <td>
                                    <x-admin.badge :tone="$wallet->is_active ? 'emerald' : 'zinc'">
                                        {{ $wallet->is_active ? 'Active' : 'Inactive' }}
                                    </x-admin.badge>
                                </td>
                                <td class="whitespace-nowrap">{{ $wallet->created_at->format('M j, Y') }}</td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="6" class="px-5 py-12 text-center text-sm text-zinc-600 dark:text-zinc-400">No wallets yet.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            @if ($wallets->hasPages())
                <div class="border-t border-zinc-200 px-5 py-3 dark:border-zinc-700">
                    {{ $wallets->links() }}
                </div>
            @endif
        </div>

    </div>
</x-layouts.admin>

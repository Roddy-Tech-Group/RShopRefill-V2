@php
    use App\Models\Wallet;

    $wallets = Wallet::with(['user', 'transactions'])->latest()->limit(50)->get();
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

        <div class="overflow-hidden rounded-[12px] border-[1.5px] border-white bg-white shadow-sm shadow-zinc-900/[0.04] dark:border-white dark:bg-[#1d3252]">
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
                                <td class="tabular-nums">{{ $wallet->transactions->count() }}</td>
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
        </div>

    </div>
</x-layouts.admin>

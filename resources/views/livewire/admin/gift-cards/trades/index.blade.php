<?php

use Livewire\Volt\Component;
use Livewire\WithPagination;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use App\Models\GiftCardTrade;

new
#[Layout('components.layouts.admin')]
#[Title('Gift Card Trades')]
class extends Component {
    use WithPagination;

    public string $search = '';
    public string $status = '';

    public function with(): array
    {
        $trades = GiftCardTrade::with(['user', 'rate.brand'])
            ->when($this->search, function ($query) {
                $query->where('uuid', 'like', '%' . $this->search . '%')
                      ->orWhereHas('user', fn($q) => $q->where('name', 'like', '%' . $this->search . '%'));
            })
            ->when($this->status, function ($query) {
                $query->where('status', $this->status);
            })
            // orderByDesc('id') breaks created_at ties so a trade cannot be shown
            // twice, or skipped entirely, while an admin works down the queue.
            ->latest()
            ->orderByDesc('id')
            ->paginate(15);

        return [
            'trades' => $trades,
        ];
    }
};
?>

<div>
    <x-slot:heading>Gift Card Trades</x-slot:heading>
    <x-slot:subheading>Review and process incoming gift card submissions.</x-slot:subheading>

    <div class="flex flex-col gap-6">
        {{-- Metrics Overview --}}
        <div class="grid grid-cols-1 gap-6 sm:grid-cols-2 lg:grid-cols-4">
            @php
                $metrics = [
                    ['label' => 'Pending Review', 'value' => \App\Models\GiftCardTrade::where('status', 'pending_review')->count()],
                    ['label' => 'Need More Info', 'value' => \App\Models\GiftCardTrade::where('status', 'need_more_info')->count()],
                    ['label' => 'Total Paid (All Time)', 'value' => '$' . number_format(\App\Models\GiftCardTrade::where('status', 'paid')->sum('calculated_payout'))],
                    ['label' => 'Total Submissions', 'value' => \App\Models\GiftCardTrade::count()],
                ];
            @endphp
            
            @foreach($metrics as $metric)
                <div class="flex flex-col justify-between rounded-[12px] border border-zinc-200 bg-white p-5 shadow-sm dark:border-white/10 dark:bg-white/5">
                    <span class="text-sm font-medium text-zinc-500 dark:text-zinc-400">{{ $metric['label'] }}</span>
                    <div class="mt-4 text-3xl font-bold tracking-tight text-zinc-900 dark:text-white">
                        {{ $metric['value'] }}
                    </div>
                </div>
            @endforeach
        </div>

        {{-- Filters & Search --}}
        <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
            <div class="w-full sm:max-w-xs">
                <flux:input wire:model.live.debounce.300ms="search" icon="magnifying-glass" placeholder="Search UUID or User..." />
            </div>
            
            <div class="flex items-center gap-3">
                <flux:select wire:model.live="status" class="w-48">
                    <option value="">All Statuses</option>
                    <option value="pending_review">Pending Review</option>
                    <option value="under_review">Under Review</option>
                    <option value="need_more_info">Need More Info</option>
                    <option value="approved">Approved</option>
                    <option value="paying_out">Paying Out</option>
                    <option value="paid">Paid</option>
                    <option value="rejected">Rejected</option>
                </flux:select>
            </div>
        </div>

        {{-- Trades Table --}}
        <div class="overflow-hidden rounded-[12px] bg-white shadow-sm shadow-zinc-900/[0.04] ring-1 ring-zinc-200 dark:bg-[#1d3252] dark:ring-white/10">
            <div class="overflow-x-auto p-3">
            <table class="admin-table w-full text-left text-sm">
                <thead>
                    <tr>
                        <th class="px-4 py-3 font-medium text-zinc-500">Trade ID / User</th>
                        <th class="px-4 py-3 font-medium text-zinc-500">Card Details</th>
                        <th class="px-4 py-3 font-medium text-zinc-500">Declared Value</th>
                        <th class="px-4 py-3 font-medium text-zinc-500">Expected Payout</th>
                        <th class="px-4 py-3 font-medium text-zinc-500">Status</th>
                        <th class="px-4 py-3 font-medium text-zinc-500">Date</th>
                        <th class="px-4 py-3"></th>
                    </tr>
                </thead>

                <tbody class="divide-y divide-zinc-200 dark:divide-white/10">
                    @forelse ($trades as $trade)
                        <tr class="hover:bg-zinc-50 dark:hover:bg-white/5">
                            <td class="px-4 py-3">
                                <div class="font-medium text-zinc-900 dark:text-white">
                                    {{ Str::limit($trade->uuid, 8, '') }}
                                </div>
                                <div class="text-xs text-zinc-500 dark:text-zinc-400">
                                    {{ $trade->user->name }}
                                </div>
                            </td>
                            <td class="px-4 py-3">
                                <div class="font-medium text-zinc-900 dark:text-white">
                                    {{ $trade->rate->brand->name ?? 'Unknown' }}
                                </div>
                                <div class="text-xs text-zinc-500 dark:text-zinc-400">
                                    {{ $trade->rate->country_code }}
                                </div>
                            </td>
                            <td class="px-4 py-3 font-medium text-zinc-900 dark:text-white">
                                ${{ number_format($trade->declared_value, 2) }}
                            </td>
                            <td class="px-4 py-3 font-medium text-blue-600 dark:text-blue-400">
                                {{ \App\Domain\Shared\Enums\Currency::tryFrom($trade->payout_currency)?->symbol() ?? $trade->payout_currency . ' ' }}{{ number_format($trade->calculated_payout, 2) }}
                                <div class="text-xs text-zinc-500">via {{ ucfirst($trade->payout_method) }}</div>
                            </td>
                            <td class="px-4 py-3">
                                <flux:badge color="{{ $trade->status->color() }}" size="sm" class="uppercase">
                                    {{ $trade->status->label() }}
                                </flux:badge>
                            </td>
                            <td class="px-4 py-3 text-sm text-zinc-500">
                                {{ $trade->created_at->diffForHumans() }}
                            </td>
                            <td class="px-4 py-3 text-right">
                                <flux:button href="{{ route('admin.gift-cards.trades.show', $trade->id) }}" wire:navigate variant="ghost" size="sm">
                                    Review
                                </flux:button>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7" class="px-4 py-12 text-center text-zinc-500">
                                No trades found.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
            </div>

            @if($trades->hasPages())
                <div class="border-t border-zinc-100 px-4 py-3 dark:border-white/10">
                    {{ $trades->links('vendor.pagination.circles-livewire') }}
                </div>
            @endif
        </div>
    </div>
</div>

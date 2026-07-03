<?php

use Livewire\Volt\Component;
use Livewire\WithPagination;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use App\Models\GiftCardTrade;

new
#[Layout('components.layouts.dashboard')]
#[Title('Gift Card Trade History')]
class extends Component {
    use WithPagination;

    public function with(): array
    {
        return [
            'trades' => GiftCardTrade::with('rate.brand')
                ->where('user_id', auth()->id())
                ->latest()
                ->paginate(15),
        ];
    }
};
?>

<div class="mx-auto max-w-5xl min-w-0 w-full">
    <div class="mb-6 flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
        <div>
            <h1 class="text-2xl font-bold tracking-tight text-zinc-900 dark:text-white">Trade History</h1>
            <p class="mt-1 text-sm text-zinc-500">View the status of your gift card trades.</p>
        </div>
        <div>
            <flux:button href="{{ route('dashboard.gift-cards.submit') }}" wire:navigate variant="primary">
                Trade New Card
            </flux:button>
        </div>
    </div>

    <div class="w-full overflow-hidden rounded-[12px] border border-zinc-200 bg-[#eff6ff] shadow-sm shadow-zinc-900/[0.04] dark:border-zinc-700 dark:shadow-none">
        @if($trades->isEmpty())
            <div class="flex flex-col items-center justify-center py-12 text-center">
                <div class="rounded-full bg-zinc-100 p-3 dark:bg-zinc-800">
                    <flux:icon.credit-card class="size-6 text-zinc-400" />
                </div>
                <h3 class="mt-4 text-sm font-semibold text-zinc-900 dark:text-white">No trades yet</h3>
                <p class="mt-1 max-w-sm text-xs text-zinc-500">You haven't submitted any gift cards for trading. Trade your first card to get started.</p>
                <div class="mt-6">
                    <flux:button href="{{ route('dashboard.gift-cards.submit') }}" wire:navigate variant="primary">
                        Start a Trade
                    </flux:button>
                </div>
            </div>
        @else
            <div class="w-full overflow-x-auto">
                <table class="w-full text-left text-sm text-zinc-600 dark:text-zinc-400">
                    <thead class="border-b border-zinc-200 bg-zinc-50 text-xs text-zinc-500 dark:border-white/10 dark:bg-zinc-800/50">
                        <tr>
                            <th scope="col" class="px-6 py-3 font-medium">Trade ID</th>
                            <th scope="col" class="px-6 py-3 font-medium">Gift Card</th>
                            <th scope="col" class="px-6 py-3 font-medium">Value</th>
                            <th scope="col" class="px-6 py-3 font-medium">Payout</th>
                            <th scope="col" class="px-6 py-3 font-medium">Status</th>
                            <th scope="col" class="px-6 py-3 font-medium">Date</th>
                            <th scope="col" class="px-6 py-3 font-medium text-right"><span class="sr-only">Action</span></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-zinc-200 bg-white dark:divide-white/10 dark:bg-transparent">
                        @foreach($trades as $trade)
                            <tr wire:click="" onclick="window.location.href='{{ route('dashboard.gift-cards.trades.show', $trade) }}'" class="cursor-pointer transition-colors hover:bg-zinc-50 dark:hover:bg-zinc-800/50">
                                <td class="whitespace-nowrap px-6 py-4 font-medium text-zinc-900 dark:text-white">
                                    {{ substr($trade->uuid, 0, 8) }}
                                </td>
                                <td class="px-6 py-4">
                                    <div class="font-medium text-zinc-900 dark:text-white">
                                        {{ $trade->rate->brand->name ?? 'Unknown' }} ({{ $trade->rate->country_code ?? '' }})
                                    </div>
                                </td>
                                <td class="whitespace-nowrap px-6 py-4 font-medium">
                                    ${{ number_format($trade->declared_value, 2) }}
                                </td>
                                <td class="whitespace-nowrap px-6 py-4 font-medium text-blue-600 dark:text-blue-400">
                                    {{ \App\Domain\Shared\Enums\Currency::tryFrom($trade->payout_currency)?->symbol() ?? $trade->payout_currency . ' ' }}{{ number_format($trade->calculated_payout, 2) }}
                                </td>
                                <td class="whitespace-nowrap px-6 py-4">
                                    <flux:badge color="{{ $trade->status->color() }}" size="sm" class="uppercase">
                                        {{ $trade->status->label() }}
                                    </flux:badge>
                                    @if($trade->status === \App\Enums\TradeStatus::NeedMoreInfo)
                                        <div class="mt-1 text-[10px] text-red-500">Requires Attention</div>
                                    @endif
                                </td>
                                <td class="whitespace-nowrap px-6 py-4 text-xs">
                                    {{ $trade->created_at->format('M j, Y') }}
                                </td>
                                <td class="whitespace-nowrap px-6 py-4 text-right">
                                    <flux:button href="{{ route('dashboard.gift-cards.trades.show', $trade) }}" variant="subtle" size="sm">
                                        View Trade
                                    </flux:button>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            @if($trades->hasPages())
                <div class="w-full overflow-x-auto border-t border-zinc-200 p-4 dark:border-white/10 [scrollbar-width:none] [&::-webkit-scrollbar]:hidden">
                    {{ $trades->links() }}
                </div>
            @endif
        @endif
    </div>
</div>

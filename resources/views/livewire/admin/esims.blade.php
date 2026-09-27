<?php

use App\Domain\Fulfillment\Enums\EsimStatus;
use App\Domain\Fulfillment\Services\EsimRefundService;
use App\Livewire\Traits\RefundsEsims;
use App\Models\Esim;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;
use Livewire\WithPagination;

new
#[Layout('components.layouts.admin')]
#[Title('Manage eSIMs')]
class extends Component {
    use RefundsEsims;
    use WithPagination;

    #[Url(history: true)] public string $search = '';

    #[Url(history: true)] public string $status = 'all';

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedStatus(): void
    {
        $this->resetPage();
    }

    /**
     * @return array<string, int>
     */
    #[Computed]
    public function counts(): array
    {
        $byStatus = Esim::query()->select('status', DB::raw('count(*) as total'))->groupBy('status')->pluck('total', 'status');

        return [
            'all' => (int) $byStatus->sum(),
            ...collect(EsimStatus::cases())->mapWithKeys(fn (EsimStatus $case) => [$case->value => (int) ($byStatus[$case->value] ?? 0)])->all(),
        ];
    }

    protected function afterEsimRefunded(): void
    {
        unset($this->counts);
    }

    public function with(): array
    {
        $term = trim($this->search);

        $esims = Esim::query()
            ->with(['user:id,name,email', 'order:id,order_number', 'orderItem:id,product_id', 'orderItem.product:id,name'])
            ->when($this->status !== 'all' && EsimStatus::tryFrom($this->status), fn ($query) => $query->where('status', $this->status))
            ->when($term !== '', fn ($query) => $query->where(function ($query) use ($term) {
                $query->where('iccid', 'like', "%{$term}%")
                    ->orWhere('package_name', 'like', "%{$term}%")
                    ->orWhereHas('order', fn ($order) => $order->where('order_number', 'like', "%{$term}%"))
                    ->orWhereHas('user', fn ($user) => $user->where('email', 'like', "%{$term}%")->orWhere('name', 'like', "%{$term}%"));
            }))
            ->latest('purchased_at')
            ->latest('id')
            ->paginate(25);

        $refunds = app(EsimRefundService::class);

        return [
            'esims' => $esims,
            'blocked' => $esims->getCollection()->mapWithKeys(fn (Esim $esim) => [$esim->id => $refunds->blockedReason($esim)]),
        ];
    }
}; ?>

<div>
    <x-slot:heading>Manage eSIMs</x-slot:heading>

    <div class="flex flex-col gap-6">
        @if (session('status'))
            <div class="rounded-[12px] bg-emerald-50 px-4 py-3 text-sm font-medium text-emerald-700 ring-1 ring-emerald-200 dark:bg-emerald-500/15 dark:text-emerald-300 dark:ring-emerald-500/30" role="status">{{ session('status') }}</div>
        @endif

        {{-- KPI strip --}}
        <div class="grid grid-cols-2 gap-3 sm:grid-cols-5">
            @foreach ([
                ['label' => 'All eSIMs', 'value' => $this->counts['all'], 'dot' => 'bg-zinc-400'],
                ['label' => 'Inactive', 'value' => $this->counts['inactive'], 'dot' => 'bg-blue-500'],
                ['label' => 'Active', 'value' => $this->counts['active'], 'dot' => 'bg-emerald-500'],
                ['label' => 'Data running low', 'value' => $this->counts['data_low'], 'dot' => 'bg-amber-500'],
                ['label' => 'Refunded', 'value' => $this->counts['refunded'], 'dot' => 'bg-purple-500'],
            ] as $stat)
                <div class="rounded-[12px] bg-white p-4 shadow-sm shadow-zinc-900/[0.04] dark:bg-[#1d3252]">
                    <p class="flex items-center gap-1.5 text-[11px] font-semibold uppercase tracking-wider text-zinc-600 dark:text-zinc-300">
                        <span class="inline-block h-1.5 w-1.5 rounded-full {{ $stat['dot'] }}"></span>
                        {{ $stat['label'] }}
                    </p>
                    <p class="mt-2 text-3xl font-bold tracking-tight tabular-nums text-zinc-900 dark:text-white">{{ number_format($stat['value']) }}</p>
                </div>
            @endforeach
        </div>

        {{-- Search + status filter --}}
        <div class="flex flex-col items-stretch gap-3 lg:flex-row lg:items-center">
            <div class="relative flex-1">
                <svg class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-zinc-500 dark:text-zinc-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/>
                </svg>
                <input
                    wire:model.live.debounce.250ms="search"
                    type="search"
                    aria-label="Search eSIMs"
                    placeholder="Search by ICCID, order number, customer or package..."
                    class="w-full rounded-[12px] border border-zinc-200 bg-white py-2.5 pl-10 pr-3 text-sm text-zinc-900 outline-none transition-colors focus:border-blue-500 focus:ring-2 focus:ring-blue-500/15 dark:border-zinc-700/60 dark:bg-[#1d3252] dark:text-white"
                />
            </div>

            <div class="flex flex-wrap items-center gap-1.5">
                @foreach (['all' => 'All', ...collect(\App\Domain\Fulfillment\Enums\EsimStatus::cases())->mapWithKeys(fn ($case) => [$case->value => $case->label()])->all()] as $value => $label)
                    @continue($value !== 'all' && $value !== $status && ($this->counts[$value] ?? 0) === 0)
                    <button
                        type="button"
                        wire:click="$set('status', '{{ $value }}')"
                        @class([
                            'rounded-[12px] px-3 py-1.5 text-xs font-semibold transition-colors',
                            'bg-blue-600 text-white' => $status === $value,
                            'bg-white text-zinc-700 ring-1 ring-zinc-200 hover:bg-zinc-50 dark:bg-[#1d3252] dark:text-zinc-300 dark:ring-zinc-700/60 dark:hover:bg-[#26416b]' => $status !== $value,
                        ])
                    >{{ $label }} <span class="tabular-nums opacity-70">{{ $this->counts[$value] ?? 0 }}</span></button>
                @endforeach
            </div>
        </div>

        {{-- Table --}}
        <div class="rounded-[12px] bg-white shadow-sm shadow-zinc-900/[0.04] dark:bg-[#1d3252]">
            <div class="overflow-x-auto p-3">
                <table class="admin-table w-full text-left text-sm">
                    <thead class="bg-zinc-50 text-[11px] uppercase tracking-wider text-zinc-600 dark:bg-[#0c1a36] dark:text-zinc-400">
                        <tr>
                            <th class="px-5 py-3 font-semibold">Package</th>
                            <th class="px-5 py-3 font-semibold">Status</th>
                            <th class="px-5 py-3 font-semibold">ICCID</th>
                            <th class="hidden px-5 py-3 font-semibold md:table-cell">eSIM user</th>
                            <th class="hidden px-5 py-3 font-semibold lg:table-cell">Data</th>
                            <th class="px-5 py-3 text-right font-semibold"><span class="sr-only">Actions</span></th>
                        </tr>
                    </thead>
                    <tbody class="divide-inset">
                        @forelse ($esims as $esim)
                            <tr wire:key="esim-{{ $esim->id }}" class="transition-colors hover:bg-blue-50 dark:hover:bg-[#26416b]/40">
                                <td class="max-w-[260px] px-5 py-3">
                                    <p class="truncate font-semibold text-zinc-900 dark:text-white">{{ $esim->orderItem?->product?->name ?? 'eSIM' }}</p>
                                    <p class="truncate text-xs text-zinc-500 dark:text-zinc-400">{{ $esim->package_name }} · {{ $esim->purchased_at->format('M j, Y') }}</p>
                                </td>
                                <td class="px-5 py-3">
                                    <x-admin.badge :tone="$esim->status->tone()">{{ $esim->status->label() }}</x-admin.badge>
                                </td>
                                <td class="whitespace-nowrap px-5 py-3 font-mono text-xs text-zinc-700 dark:text-zinc-300">{{ $esim->iccid }}</td>
                                <td class="hidden max-w-[220px] px-5 py-3 md:table-cell">
                                    <p class="truncate text-zinc-900 dark:text-white">{{ $esim->user?->name ?? 'Deleted account' }}</p>
                                    <p class="truncate text-xs text-zinc-500 dark:text-zinc-400">{{ $esim->user?->email }}</p>
                                </td>
                                <td class="hidden min-w-[170px] px-5 py-3 lg:table-cell">
                                    @if ($label = $esim->dataLabel())
                                        <p class="text-xs tabular-nums text-zinc-700 dark:text-zinc-300">{{ $label }}</p>
                                        @if (($percent = $esim->dataRemainingPercent()) !== null)
                                            <div class="mt-1.5 h-1.5 w-full overflow-hidden rounded-full bg-zinc-100 dark:bg-[#0c1a36]" role="img" aria-label="{{ $percent }}% of data left">
                                                <div @class(['h-full rounded-full', 'bg-amber-500' => $percent <= 25, 'bg-emerald-500' => $percent > 25]) style="width: {{ $percent }}%"></div>
                                            </div>
                                        @endif
                                    @else
                                        <span class="text-xs text-zinc-400 dark:text-zinc-500">Not checked yet</span>
                                    @endif
                                </td>
                                <td class="whitespace-nowrap px-5 py-3 text-right">
                                    {{-- The menu is fixed to the viewport so the table's scroll box never clips it. --}}
                                    <div x-data="{ open: false, top: 0, right: 0, toggle() { const box = this.$refs.trigger.getBoundingClientRect(); this.top = box.bottom + 4; this.right = window.innerWidth - box.right; this.open = ! this.open; } }" @click.outside="open = false" @keydown.escape.window="open = false" @scroll.window="open = false" @resize.window="open = false" class="inline-block text-left">
                                        <button type="button" x-ref="trigger" @click="toggle()" :aria-expanded="open" aria-haspopup="menu" aria-label="Actions for {{ $esim->iccid }}" class="flex h-8 w-8 items-center justify-center rounded-[8px] text-zinc-600 transition-colors hover:bg-zinc-100 dark:text-zinc-300 dark:hover:bg-[#26416b]">
                                            <svg class="h-4 w-4" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><circle cx="12" cy="5" r="1.75"/><circle cx="12" cy="12" r="1.75"/><circle cx="12" cy="19" r="1.75"/></svg>
                                        </button>
                                        <div x-show="open" x-cloak x-transition.opacity.duration.100ms role="menu" :style="`top: ${top}px; right: ${right}px`" class="fixed z-40 w-56 overflow-hidden rounded-[12px] bg-white py-1 shadow-lg ring-1 ring-zinc-200 dark:bg-[#1d3252] dark:ring-white/10">
                                            <a href="{{ route('admin.esims.show', $esim) }}" wire:navigate role="menuitem" class="block px-4 py-2 text-sm text-zinc-700 hover:bg-zinc-50 dark:text-zinc-200 dark:hover:bg-[#26416b]">View details</a>
                                            @if ($blocked[$esim->id])
                                                <span role="menuitem" aria-disabled="true" title="{{ $blocked[$esim->id] }}" class="block cursor-not-allowed px-4 py-2 text-sm text-zinc-400 dark:text-zinc-500">Refund</span>
                                            @else
                                                <button type="button" role="menuitem" @click="open = false" wire:click="openRefund({{ $esim->id }})" class="block w-full px-4 py-2 text-left text-sm font-semibold text-red-600 hover:bg-red-50 dark:text-red-400 dark:hover:bg-red-500/10">Refund</button>
                                            @endif
                                        </div>
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="6" class="px-5 py-16 text-center">
                                    <p class="text-base font-semibold text-zinc-900 dark:text-white">No eSIMs match</p>
                                    <p class="mt-1 text-sm text-zinc-500 dark:text-zinc-400">Try a different search or status.</p>
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            @if ($esims->hasPages())
                <div class="border-t border-zinc-100 px-5 py-3 dark:border-zinc-700/60">
                    {{ $esims->links('vendor.pagination.circles-livewire') }}
                </div>
            @endif
        </div>
    </div>

    @if ($refundingEsimId && $this->refundingEsim)
        <x-admin.esim-refund-modal
            :esim="$this->refundingEsim"
            :amount="$this->refundAmountFor($this->refundingEsim)"
            :reasons="$this->refundReasons()"
            :error="$refundError"
        />
    @endif
</div>

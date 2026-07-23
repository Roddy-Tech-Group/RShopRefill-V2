<?php

use Livewire\Volt\Component;
use Livewire\WithPagination;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use App\Models\GiftCardBrand;
use Illuminate\Validation\Rule;

new
#[Layout('components.layouts.admin')]
#[Title('Gift Card Brands')]
class extends Component {
    use WithPagination;

    public string $search = '';

    // Modal Form State
    public bool $isEditing = false;
    public ?int $editingId = null;
    public string $name = '';
    public string $currency = 'USD';
    public bool $is_active = true;

    public function updatingSearch()
    {
        $this->resetPage();
    }

    public function create()
    {
        $this->resetValidation();
        $this->isEditing = false;
        $this->editingId = null;
        $this->name = '';
        $this->currency = 'USD';
        $this->is_active = true;
        
        $this->dispatch('modal-open', name: 'brand-modal');
    }

    public function edit(GiftCardBrand $brand)
    {
        $this->resetValidation();
        $this->isEditing = true;
        $this->editingId = $brand->id;
        $this->name = $brand->name;
        $this->currency = $brand->currency;
        $this->is_active = $brand->is_active;
        
        $this->dispatch('modal-open', name: 'brand-modal');
    }

    public function save()
    {
        $this->validate([
            'name' => ['required', 'string', 'max:255', Rule::unique('gift_card_brands')->ignore($this->editingId)],
            'currency' => ['required', 'string', 'size:3'],
            'is_active' => ['boolean'],
        ]);

        GiftCardBrand::updateOrCreate(
            ['id' => $this->editingId],
            [
                'name' => $this->name,
                'currency' => strtoupper($this->currency),
                'is_active' => $this->is_active,
            ]
        );

        $this->dispatch('modal-close', name: 'brand-modal');
        
        Flux::toast($this->isEditing ? 'Brand updated.' : 'Brand created.', variant: 'success');
    }

    public function delete(GiftCardBrand $brand)
    {
        // Simple protection against deleting brands with active trades/rates.
        if ($brand->rates()->exists()) {
            Flux::toast('Cannot delete brand: it has associated rates.', variant: 'danger');
            return;
        }

        $brand->delete();
        Flux::toast('Brand deleted.', variant: 'success');
    }

    public function with(): array
    {
        return [
            'brands' => GiftCardBrand::withCount('rates')
                ->where('name', 'like', '%' . $this->search . '%')
                ->orderBy('name')
                ->paginate(20),
        ];
    }
};
?>

<div x-data="{ showModal: false }"
     @modal-open.window="if ($event.detail.name === 'brand-modal') showModal = true"
     @modal-close.window="if ($event.detail.name === 'brand-modal') showModal = false">
    {{-- Header --}}
    <div class="mb-6 flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
        <div>
            <h1 class="text-2xl font-bold tracking-tight text-zinc-900 dark:text-white">Gift Card Brands</h1>
            <p class="text-sm text-zinc-500 dark:text-zinc-400">Manage the gift card brands available for trading.</p>
        </div>
        <div class="flex items-center gap-3">
            <flux:input wire:model.live.debounce.300ms="search" placeholder="Search brands..." icon="magnifying-glass" class="w-full sm:w-64" />
            <flux:button wire:click="create" variant="primary">Add Brand</flux:button>
        </div>
    </div>

    {{-- Data Table --}}
    {{-- overflow-hidden clips hovered top/bottom rows to the card's rounded
         corners. Plain tables use border-collapse: collapse (Tailwind preflight
         default), so a <tr> can't take its own border-radius, so the card must clip. --}}
    <div class="overflow-hidden rounded-xl border border-zinc-200 bg-white shadow-sm dark:border-white/10 dark:bg-zinc-900">
        <div class="overflow-x-auto">
            <table class="w-full text-left text-sm whitespace-nowrap">
                <thead class="border-b border-zinc-200 bg-zinc-50/50 text-zinc-500 dark:border-white/10 dark:bg-zinc-800/50">
                    <tr>
                        <th class="px-4 py-3 font-medium">Name</th>
                        <th class="px-4 py-3 font-medium">Currency</th>
                        <th class="px-4 py-3 font-medium">Rates</th>
                        <th class="px-4 py-3 font-medium">Status</th>
                        <th class="px-4 py-3 font-medium text-right">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-zinc-200 dark:divide-white/10">
                    @forelse($brands as $brand)
                        <tr wire:key="{{ $brand->id }}" class="hover:bg-zinc-50 dark:hover:bg-white/[0.02]">
                            <td class="px-4 py-3 font-medium text-zinc-900 dark:text-white">
                                {{ $brand->name }}
                            </td>
                            <td class="px-4 py-3">
                                <span class="rounded bg-zinc-100 px-2 py-1 text-xs font-semibold text-zinc-600 dark:bg-zinc-800 dark:text-zinc-400">{{ $brand->currency }}</span>
                            </td>
                            <td class="px-4 py-3 text-zinc-500">{{ $brand->rates_count }}</td>
                            <td class="px-4 py-3">
                                @if($brand->is_active)
                                    <span class="inline-flex items-center gap-1.5 rounded-full bg-emerald-50 px-2 py-0.5 text-xs font-medium text-emerald-700 ring-1 ring-emerald-600/20 ring-inset dark:bg-emerald-400/10 dark:text-emerald-400 dark:ring-emerald-400/20">
                                        <span class="h-1.5 w-1.5 rounded-full bg-emerald-500"></span> Active
                                    </span>
                                @else
                                    <span class="inline-flex items-center gap-1.5 rounded-full bg-zinc-50 px-2 py-0.5 text-xs font-medium text-zinc-600 ring-1 ring-zinc-500/20 ring-inset dark:bg-zinc-400/10 dark:text-zinc-400 dark:ring-zinc-400/20">
                                        <span class="h-1.5 w-1.5 rounded-full bg-zinc-400"></span> Inactive
                                    </span>
                                @endif
                            </td>
                            <td class="px-4 py-3 text-right">
                                <flux:dropdown>
                                    <flux:button variant="ghost" size="sm" icon="ellipsis-horizontal" />
                                    <flux:menu>
                                        <flux:menu.item wire:click="edit({{ $brand->id }})" icon="pencil">Edit</flux:menu.item>
                                        <flux:menu.item wire:click="delete({{ $brand->id }})" wire:confirm="Are you sure you want to delete this brand?" icon="trash" variant="danger">Delete</flux:menu.item>
                                    </flux:menu>
                                </flux:dropdown>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="px-4 py-8 text-center text-zinc-500">No brands found.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        
        @if($brands->hasPages())
            <div class="border-t border-zinc-200 px-4 py-3 dark:border-white/10">
                {{ $brands->links('vendor.pagination.circles-livewire') }}
            </div>
        @endif
    </div>

    {{-- Create / Edit Modal --}}
    <template x-if="showModal">
        <div
            x-on:keydown.escape.window="showModal = false"
            class="fixed inset-0 z-[80] flex items-center justify-center p-4"
        >
            <div x-transition.opacity @click="showModal = false" class="absolute inset-0 bg-zinc-900/45" aria-hidden="true"></div>

            <div
                x-transition
                class="relative w-full max-w-md rounded-[10px] bg-white p-6 text-left shadow-2xl shadow-zinc-900/25 dark:bg-zinc-900"
                role="dialog"
                aria-modal="true"
            >
                <form wire:submit="save" class="flex flex-col gap-6">
                    <div class="flex items-start justify-between gap-3">
                        <div>
                            <h2 class="text-lg font-bold text-zinc-900 dark:text-white">{{ $isEditing ? 'Edit Brand' : 'Add Brand' }}</h2>
                            <p class="mt-0.5 text-xs text-zinc-500">Configure the gift card brand details.</p>
                        </div>
                        <x-close-button @click="showModal = false" />
                    </div>

                    <div class="flex flex-col gap-4">
                        <flux:input wire:model="name" label="Brand Name" placeholder="e.g. Apple" required />
                        <flux:input wire:model="currency" label="Default Currency" placeholder="e.g. USD" required />
                        
                        <flux:switch wire:model="is_active" label="Active" description="Allow users to submit trades for this brand." />
                    </div>

                    <div class="flex justify-end gap-2">
                        <flux:button type="button" variant="ghost" @click="showModal = false">Cancel</flux:button>
                        <flux:button type="submit" variant="primary">Save Brand</flux:button>
                    </div>
                </form>
            </div>
        </div>
    </template>
</div>

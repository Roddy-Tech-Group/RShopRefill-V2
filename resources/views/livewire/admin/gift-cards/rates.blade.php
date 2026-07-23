<?php

use Livewire\Volt\Component;
use Livewire\WithPagination;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use App\Models\GiftCardRate;
use App\Models\GiftCardBrand;

new
#[Layout('components.layouts.admin')]
#[Title('Gift Card Rates')]
class extends Component {
    use WithPagination;

    public string $search = '';

    // Modal Form State
    public bool $isEditing = false;
    public ?int $editingId = null;
    
    public $brand_id = '';
    public string $country_code = 'US';
    public string $currency = 'NGN';
    public $min_value = 10;
    public $max_value = 1000;
    public $rate = 1300;
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
        
        $this->brand_id = '';
        $this->country_code = 'US';
        $this->currency = 'NGN';
        $this->min_value = 10;
        $this->max_value = 1000;
        $this->rate = 1300;
        $this->is_active = true;

        $this->dispatch('modal-open', name: 'rate-modal');
    }

    public function edit(GiftCardRate $rate)
    {
        $this->resetValidation();
        $this->isEditing = true;
        $this->editingId = $rate->id;
        
        $this->brand_id = $rate->brand_id;
        $this->country_code = $rate->country_code;
        $this->currency = $rate->currency;
        $this->min_value = (float) $rate->min_value;
        $this->max_value = (float) $rate->max_value;
        $this->rate = (float) $rate->rate;
        $this->is_active = $rate->is_active;

        $this->dispatch('modal-open', name: 'rate-modal');
    }

    public function save()
    {
        $this->validate([
            'brand_id' => ['required', 'exists:gift_card_brands,id'],
            'country_code' => ['required', 'string', 'size:2'],
            'currency' => ['required', 'string', 'size:3'],
            'min_value' => ['required', 'numeric', 'min:1'],
            'max_value' => ['required', 'numeric', 'gte:min_value'],
            'rate' => ['required', 'numeric', 'min:0'],
            'is_active' => ['boolean'],
        ]);

        GiftCardRate::updateOrCreate(
            ['id' => $this->editingId],
            [
                'brand_id' => $this->brand_id,
                'country_code' => strtoupper($this->country_code),
                'currency' => strtoupper($this->currency),
                'min_value' => $this->min_value,
                'max_value' => $this->max_value,
                'rate' => $this->rate,
                'is_active' => $this->is_active,
            ]
        );

        $this->dispatch('modal-close', name: 'rate-modal');
        Flux::toast($this->isEditing ? 'Rate updated.' : 'Rate created.', variant: 'success');
    }

    public function delete(GiftCardRate $rate)
    {
        $rate->delete();
        Flux::toast('Rate deleted.', variant: 'success');
    }

    public function with(): array
    {
        $brands = \App\Models\GiftCardBrand::active()->orderBy('name')->get();
        $brandOptions = $brands->map(fn($b) => ['value' => $b->id, 'label' => $b->name])->toArray();
        
        $currencyOptions = collect(\App\Domain\Shared\Enums\Currency::cases())->map(fn($c) => ['value' => $c->value, 'label' => $c->value . ' - ' . $c->label()])->toArray();

        return [
            'rates' => GiftCardRate::with('brand')
                ->when($this->search, function ($query) {
                    $query->whereHas('brand', fn($q) => $q->where('name', 'like', '%' . $this->search . '%'))
                          ->orWhere('country_code', 'like', '%' . $this->search . '%')
                          ->orWhere('currency', 'like', '%' . $this->search . '%');
                })
                ->latest()
                ->paginate(10),
            'brands' => $brands,
            'brandOptions' => $brandOptions,
            'currencyOptions' => $currencyOptions,
        ];
    }
};
?>

<div x-data="{ showModal: false }"
     @modal-open.window="if ($event.detail.name === 'rate-modal') showModal = true"
     @modal-close.window="if ($event.detail.name === 'rate-modal') showModal = false">
    {{-- Header --}}
    <div class="mb-6 flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
        <div>
            <h1 class="text-2xl font-bold tracking-tight text-zinc-900 dark:text-white">Gift Card Rates</h1>
            <p class="text-sm text-zinc-500 dark:text-zinc-400">Manage the payout rates and limits for gift cards.</p>
        </div>
        <div class="flex items-center gap-3">
            <flux:input wire:model.live.debounce.300ms="search" placeholder="Search rates..." icon="magnifying-glass" class="w-full sm:w-64" />
            <flux:button wire:click="create" variant="primary">Add Rate</flux:button>
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
                        <th class="px-4 py-3 font-medium">Brand</th>
                        <th class="px-4 py-3 font-medium">Country</th>
                        <th class="px-4 py-3 font-medium">Currency</th>
                        <th class="px-4 py-3 font-medium">Value Range</th>
                        <th class="px-4 py-3 font-medium">Rate</th>
                        <th class="px-4 py-3 font-medium">Status</th>
                        <th class="px-4 py-3 font-medium text-right">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-zinc-200 dark:divide-white/10">
                    @forelse($rates as $rate)
                        <tr wire:key="{{ $rate->id }}" class="hover:bg-zinc-50 dark:hover:bg-white/[0.02]">
                            <td class="px-4 py-3 font-medium text-zinc-900 dark:text-white">
                                {{ $rate->brand->name ?? 'Unknown' }}
                            </td>
                            <td class="px-4 py-3">
                                <span class="rounded bg-zinc-100 px-2 py-1 text-xs font-semibold text-zinc-600 dark:bg-zinc-800 dark:text-zinc-400">{{ $rate->country_code }}</span>
                            </td>
                            <td class="px-4 py-3 font-medium text-zinc-900 dark:text-white">{{ $rate->currency }}</td>
                            <td class="px-4 py-3 text-zinc-500">${{ (float) $rate->min_value }} - ${{ (float) $rate->max_value }}</td>
                            <td class="px-4 py-3 font-semibold text-zinc-900 dark:text-white">
                                {{ number_format($rate->rate, 2) }}
                            </td>
                            <td class="px-4 py-3">
                                @if($rate->is_active)
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
                                        <flux:menu.item wire:click="edit({{ $rate->id }})" icon="pencil">Edit</flux:menu.item>
                                        <flux:menu.item wire:click="delete({{ $rate->id }})" wire:confirm="Are you sure you want to delete this rate?" icon="trash" variant="danger">Delete</flux:menu.item>
                                    </flux:menu>
                                </flux:dropdown>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7" class="px-4 py-8 text-center text-zinc-500">No rates found.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        
        @if($rates->hasPages())
            <div class="border-t border-zinc-200 px-4 py-3 dark:border-white/10">
                {{ $rates->links('vendor.pagination.circles-livewire') }}
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
                class="relative w-full max-w-lg rounded-[10px] bg-white p-6 text-left shadow-2xl shadow-zinc-900/25 dark:bg-zinc-900"
                role="dialog"
                aria-modal="true"
            >
                <form wire:submit="save" class="flex flex-col gap-6">
                    <div class="flex items-start justify-between gap-3">
                        <div>
                            <h2 class="text-lg font-bold text-zinc-900 dark:text-white">{{ $isEditing ? 'Edit Rate' : 'Add Rate' }}</h2>
                            <p class="mt-0.5 text-xs text-zinc-500">Configure payout rules for a specific card type and country.</p>
                        </div>
                        <x-close-button @click="showModal = false" />
                    </div>

                    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                        <div class="sm:col-span-2">
                            <x-custom-select 
                                wire:model="brand_id" 
                                label="Brand" 
                                :options="$brandOptions" 
                                placeholder="Select a brand..." 
                                searchable="true"
                                required="true" 
                            />
                        </div>
                        
                        <flux:input wire:model="country_code" label="Country Code" placeholder="US, GB, CA" required />
                        <x-custom-select 
                            wire:model="currency" 
                            label="Payout Currency" 
                            :options="$currencyOptions" 
                            placeholder="Select payout currency..." 
                            searchable="true"
                            required="true" 
                        />
                        
                        <flux:input wire:model="min_value" type="number" step="1" label="Min Value ($)" required />
                        <flux:input wire:model="max_value" type="number" step="1" label="Max Value ($)" required />
                        
                        <div class="sm:col-span-2">
                            <flux:input wire:model="rate" type="number" step="0.01" label="Exchange Rate" placeholder="1300" required />
                            <p class="mt-1 text-xs text-zinc-500">Example: 1300 means a $100 card pays out 130,000 in the selected currency.</p>
                        </div>
                        
                        <div class="sm:col-span-2">
                            <flux:switch wire:model="is_active" label="Active" description="Allow users to trade this specific rate." />
                        </div>
                    </div>

                    <div class="flex justify-end gap-2">
                        <flux:button type="button" variant="ghost" @click="showModal = false">Cancel</flux:button>
                        <flux:button type="submit" variant="primary">Save Rate</flux:button>
                    </div>
                </form>
            </div>
        </div>
    </template>
</div>

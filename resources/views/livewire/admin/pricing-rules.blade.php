{{-- FILE: resources/views/livewire/admin/pricing-rules.blade.php --}}
<?php

use App\Models\Category;
use App\Models\PricingRule;
use App\Models\Subcategory;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;

new
#[Layout('components.layouts.admin')]
#[Title('Pricing Rules')]
class extends Component {
    public ?int $editingId = null;
    public bool $showForm = false;

    public string $scope = 'category';
    public string $categoryId = '';
    public string $subcategoryId = '';
    public string $providerName = '';
    public string $markupType = 'percentage';
    public string $markupValue = '';
    public bool $isActive = true;

    #[Computed]
    public function pricingRules()
    {
        return PricingRule::with(['category', 'subcategory'])
            ->orderBy('category_id')
            ->orderBy('subcategory_id')
            ->get();
    }

    #[Computed]
    public function categories()
    {
        return Category::orderBy('name')->get(['id', 'name']);
    }

    /**
     * Suppliers present in the catalog, for the optional provider scope.
     * Pulled from real product rows - never a hardcoded list.
     */
    #[Computed]
    public function providers()
    {
        return \App\Models\Product::query()
            ->whereNotNull('provider_name')
            ->distinct()
            ->orderBy('provider_name')
            ->pluck('provider_name')
            ->map(fn ($p) => strtolower((string) $p))
            ->unique()
            ->values();
    }

    #[Computed]
    public function subcategories()
    {
        if (! $this->categoryId) {
            return collect();
        }

        return Subcategory::where('category_id', $this->categoryId)
            ->orderBy('name')
            ->get(['id', 'name']);
    }

    #[Computed]
    public function summary(): array
    {
        $all = $this->pricingRules;

        return [
            'total'          => $all->count(),
            'active'         => $all->where('is_active', true)->count(),
            'categories'     => $all->whereNotNull('category_id')->whereNull('subcategory_id')->count(),
            'subcategories'  => $all->whereNotNull('subcategory_id')->count(),
        ];
    }

    protected function rules(): array
    {
        return [
            'scope'          => 'required|in:category,subcategory',
            'categoryId'     => 'required|exists:categories,id',
            'subcategoryId'  => $this->scope === 'subcategory' ? 'required|exists:subcategories,id' : 'nullable',
            'providerName'   => ['nullable', 'string', \Illuminate\Validation\Rule::in($this->providers->all())],
            'markupType'     => 'required|in:percentage,fixed',
            'markupValue'    => 'required|numeric|min:0',
            'isActive'       => 'boolean',
        ];
    }

    public function updatedCategoryId(): void
    {
        $this->subcategoryId = '';
        unset($this->subcategories);
    }

    public function newRule(): void
    {
        $this->resetForm();
        $this->showForm = true;
    }

    public function edit(int $id): void
    {
        $rule = PricingRule::findOrFail($id);
        $this->editingId    = $rule->id;
        $this->categoryId   = (string) ($rule->category_id ?? '');
        $this->subcategoryId = (string) ($rule->subcategory_id ?? '');
        $this->providerName = strtolower((string) ($rule->provider_name ?? ''));
        $this->scope        = $rule->subcategory_id ? 'subcategory' : 'category';
        $this->markupType   = $rule->markup_type;
        $this->markupValue  = (string) $rule->markup_value;
        $this->isActive     = $rule->is_active;
        $this->resetValidation();
        unset($this->subcategories);
        $this->showForm = true;
    }

    public function save(): void
    {
        $this->validate();

        $payload = [
            'category_id'    => $this->categoryId ?: null,
            'subcategory_id' => $this->scope === 'subcategory' ? ($this->subcategoryId ?: null) : null,
            'product_id'     => null,
            'provider_name'  => $this->providerName !== '' ? strtolower($this->providerName) : null,
            'markup_type'    => $this->markupType,
            'markup_value'   => $this->markupValue,
            'is_active'      => $this->isActive,
        ];

        if ($this->editingId) {
            PricingRule::findOrFail($this->editingId)->update($payload);
            session()->flash('status', 'Pricing rule updated.');
        } else {
            PricingRule::create($payload);
            session()->flash('status', 'Pricing rule created.');
        }

        $this->resetForm();
        unset($this->pricingRules, $this->summary);
    }

    public function delete(int $id): void
    {
        PricingRule::findOrFail($id)->delete();
        session()->flash('status', 'Rule deleted.');
        unset($this->pricingRules, $this->summary);
    }

    public function toggleActive(int $id): void
    {
        $rule = PricingRule::findOrFail($id);
        $rule->update(['is_active' => ! $rule->is_active]);
        unset($this->pricingRules, $this->summary);
    }

    public function closeForm(): void
    {
        $this->resetForm();
    }

    private function resetForm(): void
    {
        $this->editingId     = null;
        $this->scope         = 'category';
        $this->categoryId    = '';
        $this->subcategoryId = '';
        $this->providerName  = '';
        $this->markupType    = 'percentage';
        $this->markupValue   = '';
        $this->isActive      = true;
        $this->showForm      = false;
        $this->resetValidation();
        unset($this->subcategories);
    }
}; ?>

<div>
    <x-slot:heading>Pricing Rules</x-slot:heading>
    <x-slot:subheading>Markup rules applied at checkout. Rules cascade: product rules override subcategory rules, which override category rules.</x-slot:subheading>

    <div class="flex flex-col gap-6">

        @if (session('status'))
            <div class="rounded-[12px] bg-emerald-50 px-4 py-3 text-sm font-medium text-emerald-700 ring-1 ring-emerald-200 dark:bg-emerald-500/15 dark:text-emerald-300 dark:ring-emerald-500/30">{{ session('status') }}</div>
        @endif

        {{-- Cascade explanation — a quick rules-of-the-road for admins so
             they don't have to guess which rule wins. --}}
        <div class="rounded-[12px] border-[1.5px] border-white bg-white p-5 shadow-sm shadow-zinc-900/[0.04] dark:border-white dark:bg-[#1d3252]">
            <div class="flex items-start gap-3">
                <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-[12px] bg-blue-50 text-blue-600 dark:bg-blue-600/15 dark:text-blue-300">
                    <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M11.25 11.25l.041-.02a.75.75 0 011.063.852l-.708 2.836a.75.75 0 001.063.853l.041-.021M21 12a9 9 0 11-18 0 9 9 0 0118 0zm-9-3.75h.008v.008H12V8.25z"/>
                    </svg>
                </span>
                <div class="min-w-0 flex-1">
                    <p class="text-sm font-bold text-zinc-900 dark:text-white">How pricing rules work</p>
                    <p class="mt-1 text-xs leading-relaxed text-zinc-600 dark:text-zinc-400">
                        Each rule adds markup on top of the supplier cost. The pricing engine resolves the markup in this order, stopping at the first match:
                    </p>
                    <ol class="mt-2 grid gap-2 text-xs text-zinc-700 dark:text-zinc-300 sm:grid-cols-3">
                        <li class="flex items-center gap-2 rounded-[8px] bg-zinc-50 px-3 py-2 dark:bg-[#0c1a36]/50">
                            <span class="flex h-5 w-5 shrink-0 items-center justify-center rounded-full bg-blue-600 text-[10px] font-bold text-white">1</span>
                            <span><strong>Product</strong> rule (most specific)</span>
                        </li>
                        <li class="flex items-center gap-2 rounded-[8px] bg-zinc-50 px-3 py-2 dark:bg-[#0c1a36]/50">
                            <span class="flex h-5 w-5 shrink-0 items-center justify-center rounded-full bg-amber-500 text-[10px] font-bold text-white">2</span>
                            <span><strong>Subcategory</strong> rule</span>
                        </li>
                        <li class="flex items-center gap-2 rounded-[8px] bg-zinc-50 px-3 py-2 dark:bg-[#0c1a36]/50">
                            <span class="flex h-5 w-5 shrink-0 items-center justify-center rounded-full bg-emerald-500 text-[10px] font-bold text-white">3</span>
                            <span><strong>Category</strong> rule (broadest)</span>
                        </li>
                    </ol>
                    <p class="mt-3 text-[11px] leading-relaxed text-zinc-500">
                        <strong>Percent</strong> scales with cost (use for variable-priced eSIMs / variable gift cards). <strong>Flat</strong> adds a fixed USD amount (use for fixed denominations where you want predictable margin per sale). Existing orders keep their original markup. Rule changes only affect new orders.
                    </p>
                </div>
            </div>
        </div>

        {{-- KPI strip --}}
        <div class="grid grid-cols-2 gap-3 sm:grid-cols-4">
            @foreach ([
                ['label' => 'Total rules',       'value' => $this->summary['total'],         'dot' => 'bg-blue-500'],
                ['label' => 'Active',             'value' => $this->summary['active'],        'dot' => 'bg-emerald-500'],
                ['label' => 'Category rules',     'value' => $this->summary['categories'],   'dot' => 'bg-amber-500'],
                ['label' => 'Subcategory rules',  'value' => $this->summary['subcategories'],'dot' => 'bg-purple-500'],
            ] as $stat)
                <div class="rounded-[12px] border-[1.5px] border-white bg-white p-4 shadow-sm shadow-zinc-900/[0.04] dark:border-white dark:bg-[#1d3252]">
                    <p class="flex items-center gap-1.5 text-[11px] font-semibold uppercase tracking-wider text-zinc-600 dark:text-zinc-300">
                        <span class="inline-block h-1.5 w-1.5 rounded-full {{ $stat['dot'] }}"></span>
                        {{ $stat['label'] }}
                    </p>
                    <p class="mt-2 text-3xl font-bold tracking-tight text-zinc-900 dark:text-white">{{ number_format($stat['value']) }}</p>
                </div>
            @endforeach
        </div>

        {{-- Header action --}}
        <div class="flex items-center justify-end">
            <button
                wire:click="newRule"
                type="button"
                class="inline-flex items-center gap-1.5 rounded-[12px] bg-blue-600 px-3 py-2 text-xs font-semibold text-white transition-colors hover:bg-blue-700"
            >
                <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 5v14M5 12h14"/>
                </svg>
                Add rule
            </button>
        </div>

        {{-- Table --}}
        <div class="overflow-hidden rounded-[12px] border-[1.5px] border-white bg-white shadow-sm shadow-zinc-900/[0.04] dark:border-white dark:bg-[#1d3252]">
            <div class="overflow-x-auto p-3">
                <table class="admin-table w-full text-left text-sm">
                    <thead>
                        <tr>
                            <th>Scope</th>
                            <th>Category</th>
                            <th class="hidden md:table-cell">Subcategory</th>
                            <th>Supplier</th>
                            <th>Markup</th>
                            <th>Status</th>
                            <th class="text-right">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($this->pricingRules as $rule)
                            <tr>
                                <td>
                                    <x-admin.badge :tone="$rule->subcategory_id ? 'amber' : 'blue'">
                                        {{ $rule->subcategory_id ? 'Subcategory' : 'Category' }}
                                    </x-admin.badge>
                                </td>
                                <td class="font-medium text-zinc-900 dark:text-white">
                                    {{ $rule->category?->name ?? '-' }}
                                </td>
                                <td class="hidden md:table-cell">
                                    {{ $rule->subcategory?->name ?? '-' }}
                                </td>
                                <td>
                                    @if ($rule->provider_name)
                                        <x-admin.badge tone="purple">{{ ucfirst($rule->provider_name) }}</x-admin.badge>
                                    @else
                                        <span class="text-zinc-500 dark:text-zinc-400">All</span>
                                    @endif
                                </td>
                                <td class="whitespace-nowrap tabular-nums">
                                    @if ($rule->markup_type === 'percentage')
                                        +{{ rtrim(rtrim(number_format((float) $rule->markup_value, 4), '0'), '.') }}%
                                    @else
                                        +${{ rtrim(rtrim(number_format((float) $rule->markup_value, 4), '0'), '.') }} flat
                                    @endif
                                </td>
                                <td>
                                    <button wire:click="toggleActive({{ $rule->id }})" type="button" class="cursor-pointer">
                                        <x-admin.badge :tone="$rule->is_active ? 'emerald' : 'zinc'">
                                            {{ $rule->is_active ? 'Active' : 'Inactive' }}
                                        </x-admin.badge>
                                    </button>
                                </td>
                                <td class="whitespace-nowrap text-right">
                                    <div class="inline-flex items-center gap-1.5">
                                        <button wire:click="edit({{ $rule->id }})" type="button" class="rounded-[5px] bg-blue-50 px-2.5 py-1 text-[11px] font-semibold text-blue-700 transition-colors hover:bg-blue-100 dark:bg-blue-500/15 dark:text-blue-300 dark:hover:bg-blue-500/25">Edit</button>
                                        <button wire:click="delete({{ $rule->id }})" wire:confirm="Delete this pricing rule? This cannot be undone." type="button" class="rounded-[5px] bg-red-50 px-2.5 py-1 text-[11px] font-semibold text-red-700 transition-colors hover:bg-red-100 dark:bg-red-500/15 dark:text-red-300 dark:hover:bg-red-500/25">Delete</button>
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="7" class="px-5 py-16 text-center">
                                    <p class="text-base font-semibold text-zinc-900 dark:text-white">No pricing rules yet</p>
                                    <p class="mt-1 text-sm text-zinc-500 dark:text-zinc-400">Click "Add rule" to create the first markup rule.</p>
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    {{-- Create / edit modal --}}
    @if ($showForm)
        <div class="fixed inset-0 z-50 flex items-center justify-center p-4" role="dialog" aria-modal="true">
            <div wire:click="closeForm" class="absolute inset-0 bg-zinc-900/40"></div>
            <form wire:submit="save" class="relative max-h-[90vh] w-full max-w-lg overflow-hidden rounded-[12px] bg-white shadow-2xl flex flex-col dark:bg-[#1d3252]">
                <div class="flex shrink-0 items-start justify-between gap-4 border-b border-zinc-100 px-5 py-4 dark:border-zinc-700/60">
                    <h3 class="text-sm font-bold text-zinc-900 dark:text-white">{{ $editingId ? 'Edit pricing rule' : 'New pricing rule' }}</h3>
                    <button type="button" wire:click="closeForm" aria-label="Close" class="flex h-8 w-8 items-center justify-center rounded-[12px] bg-zinc-100 text-zinc-600 transition-colors hover:bg-zinc-200 dark:bg-[#26416b] dark:text-zinc-300 dark:hover:bg-[#34507a]">
                        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg>
                    </button>
                </div>

                <div class="space-y-4 overflow-y-auto px-5 py-4">
                    {{-- Scope selector --}}
                    <div>
                        <label class="text-[10px] font-semibold uppercase tracking-wider text-zinc-800 dark:text-zinc-200">Scope</label>
                        <div class="mt-1.5 inline-flex w-full items-center rounded-[12px] bg-zinc-100 p-1 dark:bg-[#26416b]">
                            @foreach (['category' => 'Category', 'subcategory' => 'Subcategory'] as $value => $label)
                                <button
                                    type="button"
                                    wire:click="$set('scope', '{{ $value }}')"
                                    @class([
                                        'flex-1 rounded-[12px] py-1.5 text-xs font-semibold transition-colors',
                                        'bg-white text-zinc-900 shadow-sm ring-1 ring-zinc-200 dark:bg-[#1d3252] dark:text-white dark:ring-zinc-700/60' => $scope === $value,
                                        'text-zinc-600 hover:text-zinc-900 dark:text-zinc-300 dark:hover:text-white' => $scope !== $value,
                                    ])
                                >{{ $label }}</button>
                            @endforeach
                        </div>
                    </div>

                    {{-- Category select --}}
                    <div>
                        <label class="text-[10px] font-semibold uppercase tracking-wider text-zinc-800 dark:text-zinc-200">Category</label>
                        @php $categoryOptions = $this->categories->pluck('name', 'id')->all(); @endphp
                        <x-admin.select wire:model.live="categoryId" :options="$categoryOptions" placeholder="Select a category..." />
                        @error('categoryId') <p class="mt-1 text-[11px] font-medium text-red-600">{{ $message }}</p> @enderror
                    </div>

                    {{-- Supplier scope - optional. Suppliers price the same
                         category very differently (Airalo eSIMs vs Zendit
                         eSIMs), so a rule can target one supplier's products
                         within the scope. Leave on "All suppliers" for a
                         normal rule; a supplier-scoped rule outranks the
                         all-suppliers rule at the same level. --}}
                    <div>
                        <label class="text-[10px] font-semibold uppercase tracking-wider text-zinc-800 dark:text-zinc-200">Supplier</label>
                        @php $providerOptions = $this->providers->mapWithKeys(fn ($p) => [$p => ucfirst($p)])->all(); @endphp
                        <x-admin.select wire:model="providerName" :options="$providerOptions" placeholder="All suppliers" />
                        @error('providerName') <p class="mt-1 text-[11px] font-medium text-red-600">{{ $message }}</p> @enderror
                    </div>

                    {{-- Subcategory select (only when scope = subcategory) --}}
                    @if ($scope === 'subcategory')
                        <div>
                            <label class="text-[10px] font-semibold uppercase tracking-wider text-zinc-800 dark:text-zinc-200">Subcategory</label>
                            @php $subcategoryOptions = $this->subcategories->pluck('name', 'id')->all(); @endphp
                            <x-admin.select
                                wire:model="subcategoryId"
                                :options="$subcategoryOptions"
                                :placeholder="$categoryId ? 'Select a subcategory...' : 'Select a category first'"
                                :disabled="! $categoryId"
                            />
                            @error('subcategoryId') <p class="mt-1 text-[11px] font-medium text-red-600">{{ $message }}</p> @enderror
                        </div>
                    @endif

                    {{-- Markup type + value --}}
                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <label class="text-[10px] font-semibold uppercase tracking-wider text-zinc-800 dark:text-zinc-200">Markup type</label>
                            <x-admin.select wire:model="markupType" :options="['percentage' => 'Percent (%)', 'fixed' => 'Flat ($)']" />
                            @error('markupType') <p class="mt-1 text-[11px] font-medium text-red-600">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <label class="text-[10px] font-semibold uppercase tracking-wider text-zinc-800 dark:text-zinc-200">
                                Value {{ $markupType === 'percentage' ? '(%)' : '($)' }}
                            </label>
                            <input wire:model="markupValue" type="number" step="0.0001" min="0" placeholder="0.00" class="mt-1.5 w-full rounded-[12px] border border-zinc-200 bg-white px-3 py-2 text-sm tabular-nums text-zinc-900 outline-none focus:border-blue-500 focus:ring-2 focus:ring-blue-500/15 dark:border-zinc-700/60 dark:bg-[#0c1a36] dark:text-white">
                            @error('markupValue') <p class="mt-1 text-[11px] font-medium text-red-600">{{ $message }}</p> @enderror
                        </div>
                    </div>

                    <label class="flex items-center gap-2">
                        <input wire:model="isActive" type="checkbox" class="h-4 w-4 cursor-pointer accent-blue-600">
                        <span class="text-sm font-semibold text-zinc-700 dark:text-zinc-300">Active</span>
                    </label>
                </div>

                <div class="flex shrink-0 items-center justify-end gap-2 border-t border-zinc-100 bg-zinc-50 px-5 py-3 dark:border-zinc-700/60 dark:bg-[#0c1a36]/50">
                    <button type="button" wire:click="closeForm" class="inline-flex items-center rounded-[12px] px-3.5 py-2 text-xs font-semibold text-zinc-700 transition-colors hover:bg-zinc-100 dark:text-zinc-300 dark:hover:bg-[#26416b]">Cancel</button>
                    <button type="submit" class="inline-flex items-center rounded-[12px] bg-blue-600 px-4 py-2 text-xs font-semibold text-white transition-colors hover:bg-blue-700">{{ $editingId ? 'Save changes' : 'Create rule' }}</button>
                </div>
            </form>
        </div>
    @endif
</div>

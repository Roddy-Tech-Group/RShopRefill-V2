<?php

use App\Domain\Wallet\Jobs\SyncExchangeRatesJob;
use App\Models\CurrencyRate;
use App\Models\ExchangeRate;
use App\Models\Product;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;

new
#[Layout('components.layouts.admin')]
#[Title('Rate Management')]
class extends Component {
    /** Row currently being edited (id) or null. */
    public ?int $editingId = null;

    /** Editable form bound to the row in edit mode (or to the "add new" form). */
    public string $code = '';
    public string $name = '';
    public string $type = 'fiat';
    public string $rate_per_usd = '';
    public ?string $icon_path = null;
    public int $sort_order = 0;
    public bool $is_active = true;

    /** Toggles the create/edit modal. */
    public bool $showForm = false;

    /** Quick filter — kept in the URL so an admin can bookmark "show me stale". */
    #[Url(as: 'filter', except: 'all')]
    public string $typeFilter = 'all';

    /** Free-text search across code + name. */
    #[Url(except: '')]
    public string $search = '';

    /**
     * ISO country override for fiat codes whose first two letters aren't a
     * country code. Everything else derives the flag from the code prefix
     * (USD -> US, GBP -> GB, EUR -> EU, ...).
     */
    private const FLAG_OVERRIDES = [
        'XAF' => 'CM', // Central African CFA franc
        'XOF' => 'SN', // West African CFA franc
        'XCD' => 'AG', // East Caribbean dollar
    ];

    /** Country flag for a fiat currency code, or null. */
    public function flagUrl(string $code): ?string
    {
        $code = strtoupper($code);

        return Product::flagUrl(self::FLAG_OVERRIDES[$code] ?? substr($code, 0, 2));
    }

    protected function rules(): array
    {
        return [
            'code' => [
                'required',
                'string',
                'max:10',
                \Illuminate\Validation\Rule::unique('currency_rates', 'code')->ignore($this->editingId),
            ],
            'name' => 'required|string|max:60',
            'type' => 'required|in:fiat,crypto',
            'rate_per_usd' => 'required|numeric|min:0',
            'icon_path' => 'nullable|string|max:120',
            'sort_order' => 'integer|min:0',
            'is_active' => 'boolean',
        ];
    }

    #[Computed]
    public function allRates()
    {
        return CurrencyRate::orderBy('type')->orderBy('sort_order')->orderBy('code')->get();
    }

    /**
     * Visible row set — applies the type filter + free-text search to the
     * complete list. Counts on the KPI strip stay sourced from allRates so
     * filtering doesn't make the totals jump.
     */
    #[Computed]
    public function rates()
    {
        $rows = $this->allRates;

        if ($this->typeFilter !== 'all') {
            $rows = match ($this->typeFilter) {
                'fiat'     => $rows->where('type', 'fiat'),
                'crypto'   => $rows->where('type', 'crypto'),
                'inactive' => $rows->where('is_active', false),
                'stale'    => $rows->filter(function ($r) {
                    $live = $this->freshness[$r->code] ?? null;
                    return $live === null || $live['age_hours'] >= 24;
                }),
                default    => $rows,
            };
        }

        if ($this->search !== '') {
            $needle = mb_strtolower(trim($this->search));
            $rows = $rows->filter(fn ($r) =>
                str_contains(mb_strtolower($r->code), $needle) ||
                str_contains(mb_strtolower($r->name), $needle)
            );
        }

        return $rows->values();
    }

    /**
     * Freshness snapshot of every live exchange_rates row, keyed by target
     * currency so the table can look up "how old is this rate?" without an
     * N+1. The synced rates use USD as the base.
     */
    #[Computed]
    public function freshness(): array
    {
        return ExchangeRate::query()
            ->where('is_active', true)
            ->where('base_currency', 'USD')
            ->get()
            ->keyBy('target_currency')
            ->map(fn ($row) => [
                'fetched_at' => $row->fetched_at,
                'age_hours'  => (float) $row->fetched_at->diffInHours(now(), absolute: true),
                'provider'   => $row->provider,
            ])
            ->all();
    }

    /**
     * Aggregate KPI numbers for the top strip. Counts come from CurrencyRate
     * (definitions table); freshness counts come from ExchangeRate (the live
     * rates the runtime actually reads through CurrencyRateService).
     */
    #[Computed]
    public function summary(): array
    {
        $fresh    = 0;
        $stale    = 0;
        $critical = 0;
        $newest   = null;

        foreach ($this->freshness as $f) {
            $age = $f['age_hours'];
            if ($age < 24) {
                $fresh++;
            } elseif ($age < 48) {
                $stale++;
            } else {
                $critical++;
            }
            if ($newest === null || $f['fetched_at']->greaterThan($newest)) {
                $newest = $f['fetched_at'];
            }
        }

        return [
            'fiat'      => $this->allRates->where('type', 'fiat')->count(),
            'crypto'    => $this->allRates->where('type', 'crypto')->count(),
            'fresh'     => $fresh,
            'stale'     => $stale,
            'critical'  => $critical,
            'last_sync' => $newest,
        ];
    }

    public function newRate(): void
    {
        $this->reset(['editingId', 'code', 'name', 'rate_per_usd', 'icon_path', 'sort_order']);
        $this->type = 'fiat';
        $this->is_active = true;
        $this->resetValidation();
        $this->showForm = true;
    }

    public function edit(int $id): void
    {
        $rate = CurrencyRate::findOrFail($id);
        $this->editingId    = $rate->id;
        $this->code         = $rate->code;
        $this->name         = $rate->name;
        $this->type         = $rate->type;
        $this->rate_per_usd = (string) $rate->rate_per_usd;
        $this->icon_path    = $rate->icon_path;
        $this->sort_order   = $rate->sort_order;
        $this->is_active    = $rate->is_active;
        $this->resetValidation();
        $this->showForm = true;
    }

    public function closeForm(): void
    {
        $this->showForm = false;
        $this->reset(['editingId', 'code', 'name', 'rate_per_usd', 'icon_path', 'sort_order']);
        $this->type = 'fiat';
        $this->is_active = true;
        $this->resetValidation();
    }

    public function save(): void
    {
        $data = $this->validate();

        if ($this->editingId) {
            $rate = CurrencyRate::findOrFail($this->editingId);
            $rate->update($data);
            session()->flash('status', $this->code.' updated.');
        } else {
            $rate = CurrencyRate::create($data);
            session()->flash('status', $this->code.' added.');
        }

        // End-to-end: push the saved rate through to exchange_rates immediately
        // so the runtime CurrencyRateService picks it up on the very next
        // request - no waiting for a scheduled sync, no clicking "Run sync".
        $this->syncSingleRate($rate);

        $this->closeForm();
        unset($this->allRates, $this->rates, $this->freshness, $this->summary);
    }

    public function delete(int $id): void
    {
        $rate = CurrencyRate::findOrFail($id);
        $code = $rate->code;

        // Drop the matching exchange_rates rows (USD <-> code) so the runtime
        // doesn't keep serving a deleted definition's stale numbers.
        ExchangeRate::query()
            ->where(function ($q) use ($code) {
                $q->where(fn ($qq) => $qq->where('base_currency', 'USD')->where('target_currency', $code))
                  ->orWhere(fn ($qq) => $qq->where('base_currency', $code)->where('target_currency', 'USD'));
            })
            ->delete();

        $rate->delete();
        session()->flash('status', $code.' deleted.');
        unset($this->allRates, $this->rates, $this->freshness, $this->summary);
    }

    public function toggleActive(int $id): void
    {
        $rate = CurrencyRate::findOrFail($id);
        $rate->update(['is_active' => ! $rate->is_active]);

        // Mirror the active flag onto the live exchange_rates rows. Inactive
        // currencies stop being served by CurrencyRateService::resolveRate().
        ExchangeRate::query()
            ->where(function ($q) use ($rate) {
                $q->where(fn ($qq) => $qq->where('base_currency', 'USD')->where('target_currency', $rate->code))
                  ->orWhere(fn ($qq) => $qq->where('base_currency', $rate->code)->where('target_currency', 'USD'));
            })
            ->update(['is_active' => $rate->is_active]);

        unset($this->allRates, $this->rates, $this->freshness, $this->summary);
    }

    /**
     * Push a single CurrencyRate definition into exchange_rates (USD <-> code).
     * Mirrors the bulk SyncExchangeRatesJob but scoped to one row so a single
     * admin edit takes effect immediately.
     */
    protected function syncSingleRate(CurrencyRate $rate): void
    {
        $now = now();
        $expires = $now->copy()->addHours(24);

        // USD -> target
        ExchangeRate::updateOrCreate(
            ['base_currency' => 'USD', 'target_currency' => $rate->code, 'provider' => 'system_sync'],
            ['rate' => $rate->rate_per_usd, 'source' => 'currency_rates_db', 'is_active' => (bool) $rate->is_active, 'fetched_at' => $now, 'expires_at' => $expires],
        );

        // target -> USD inverse (skip when the rate is USD itself or zero)
        if ($rate->code !== 'USD' && (float) $rate->rate_per_usd > 0) {
            ExchangeRate::updateOrCreate(
                ['base_currency' => $rate->code, 'target_currency' => 'USD', 'provider' => 'system_sync'],
                ['rate' => round(1.0 / (float) $rate->rate_per_usd, 8), 'source' => 'currency_rates_db', 'is_active' => (bool) $rate->is_active, 'fetched_at' => $now, 'expires_at' => $expires],
            );
        }
    }

    /**
     * Run the full bulk sync (every active CurrencyRate -> ExchangeRate).
     * Useful after a bulk seeder run or when freshness clocks have all aged
     * past 24h and the admin wants one click to reset everything.
     */
    public function runSync(): void
    {
        SyncExchangeRatesJob::dispatchSync();
        session()->flash('status', 'All rates synced. Freshness clocks reset.');
        unset($this->freshness, $this->summary);
    }
}; ?>

<div>
    <x-slot:heading>Rate Management</x-slot:heading>
    <x-slot:subheading>{{ number_format($this->summary['fiat'] + $this->summary['crypto']) }} currencies defined. Sync pushes them into the live <code class="rounded-[5px] bg-zinc-100 px-1.5 py-0.5 text-[11px] text-zinc-700 dark:bg-zinc-700/50 dark:text-zinc-300">exchange_rates</code> table that runtime pricing reads.</x-slot:subheading>

    <div class="flex flex-1 flex-col gap-6">

        @if (session('status'))
            <div class="rounded-[12px] bg-emerald-50 px-4 py-3 text-sm font-medium text-emerald-700 ring-1 ring-emerald-200 dark:bg-emerald-500/15 dark:text-emerald-300 dark:ring-emerald-500/30">{{ session('status') }}</div>
        @endif

        {{-- Critical-stale warning banner. Surfaces the same StaleRateException
             that the runtime would throw, BEFORE a checkout hits it. --}}
        @if ($this->summary['critical'] > 0)
            <div class="flex items-start gap-3 rounded-[12px] bg-red-50 px-4 py-3 text-sm text-red-800 ring-1 ring-red-200 dark:bg-red-500/15 dark:text-red-300 dark:ring-red-500/30">
                <svg class="mt-0.5 h-5 w-5 shrink-0 text-red-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126ZM12 15.75h.007v.008H12v-.008Z"/>
                </svg>
                <div>
                    <p class="font-semibold">{{ $this->summary['critical'] }} rate{{ $this->summary['critical'] > 1 ? 's' : '' }} past the 48h critical threshold.</p>
                    <p class="mt-0.5 text-xs text-red-700 dark:text-red-300/80">Checkouts touching those currencies will throw StaleRateException. Click "Run sync" to refresh.</p>
                </div>
            </div>
        @endif

        {{-- KPI strip — same pattern as admin/products. Tints sit on the dot
             indicator, not the whole pill, so the strip reads quiet & scannable. --}}
        <div class="grid grid-cols-2 gap-3 sm:grid-cols-4">
            @foreach ([
                ['label' => 'Fiat',     'value' => $this->summary['fiat'],     'dot' => 'bg-emerald-500'],
                ['label' => 'Crypto',   'value' => $this->summary['crypto'],   'dot' => 'bg-amber-500'],
                ['label' => 'Fresh',    'value' => $this->summary['fresh'],    'dot' => 'bg-blue-500'],
                ['label' => 'Stale + critical', 'value' => $this->summary['stale'] + $this->summary['critical'], 'dot' => $this->summary['critical'] > 0 ? 'bg-red-500' : 'bg-amber-500'],
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

        {{-- Filter + search + action row. Matches admin/products layout: search
             takes the flex space on the left, type filter chips after, primary
             actions pinned to the right. --}}
        <div class="flex flex-col items-stretch gap-3 sm:flex-row sm:items-center">
            <div class="relative flex-1">
                <svg class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-zinc-600 dark:text-zinc-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/>
                </svg>
                <input
                    wire:model.live.debounce.250ms="search"
                    type="search"
                    placeholder="Search by code or name (e.g. USD, Bitcoin, Euro)"
                    class="w-full rounded-[12px] border border-zinc-200 bg-white py-2.5 pl-10 pr-3 text-sm text-zinc-900 outline-none transition-colors focus:border-blue-500 focus:ring-2 focus:ring-blue-500/15 dark:border-zinc-700/60 dark:bg-[#1d3252] dark:text-white"
                />
            </div>

            <div class="flex flex-wrap items-center gap-1.5">
                @foreach ([
                    'all'      => 'All',
                    'fiat'     => 'Fiat',
                    'crypto'   => 'Crypto',
                    'stale'    => 'Stale',
                    'inactive' => 'Inactive',
                ] as $value => $label)
                    <button
                        type="button"
                        wire:click="$set('typeFilter', '{{ $value }}')"
                        @class([
                            'rounded-[12px] px-3 py-1.5 text-xs font-semibold transition-colors',
                            'bg-blue-600 text-white' => $typeFilter === $value,
                            'bg-white text-zinc-700 ring-1 ring-zinc-200 hover:bg-zinc-50 dark:bg-[#1d3252] dark:text-zinc-300 dark:ring-zinc-700/60 dark:hover:bg-[#26416b]' => $typeFilter !== $value,
                        ])
                    >{{ $label }}</button>
                @endforeach
            </div>

            <div class="flex items-center gap-2">
                <button
                    wire:click="runSync"
                    wire:loading.attr="disabled"
                    wire:target="runSync"
                    type="button"
                    class="inline-flex items-center gap-1.5 rounded-[12px] border border-zinc-200 bg-white px-3 py-2 text-xs font-semibold text-zinc-700 transition-colors hover:bg-zinc-50 disabled:cursor-not-allowed disabled:opacity-60 dark:border-zinc-700/60 dark:bg-[#1d3252] dark:text-zinc-300 dark:hover:bg-[#26416b]"
                >
                    <svg wire:loading.remove wire:target="runSync" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M16.023 9.348h4.992v-.001M2.985 19.644v-4.992m0 0h4.992m-4.993 0 3.181 3.183a8.25 8.25 0 0 0 13.803-3.7M4.031 9.865a8.25 8.25 0 0 1 13.803-3.7l3.181 3.182m0-4.991v4.99"/>
                    </svg>
                    <svg wire:loading wire:target="runSync" class="h-4 w-4 animate-spin" fill="none" viewBox="0 0 24 24" aria-hidden="true">
                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/>
                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z"/>
                    </svg>
                    <span wire:loading.remove wire:target="runSync">Run sync</span>
                    <span wire:loading wire:target="runSync">Syncing...</span>
                </button>
                <button
                    wire:click="newRate"
                    type="button"
                    class="inline-flex items-center gap-1.5 rounded-[12px] bg-blue-600 px-3 py-2 text-xs font-semibold text-white transition-colors hover:bg-blue-700"
                >
                    <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 5v14M5 12h14"/>
                    </svg>
                    Add currency
                </button>
            </div>
        </div>

        {{-- Last-sync caption sits just under the action row so admins always
             know how stale the data they're staring at could be. --}}
        @if ($this->summary['last_sync'])
            <p class="-mt-3 text-[11px] text-zinc-500 dark:text-zinc-400">
                Last sync {{ $this->summary['last_sync']->diffForHumans() }}
                <span class="text-zinc-400 dark:text-zinc-600">·</span>
                {{ $this->summary['last_sync']->format('M j, Y H:i') }}
            </p>
        @endif

        {{-- Table card --}}
        <div class="overflow-hidden rounded-[12px] border-[1.5px] border-white bg-white shadow-sm shadow-zinc-900/[0.04] dark:border-white dark:bg-[#1d3252]">
            <div class="overflow-x-auto p-3">
                <table class="admin-table w-full text-left text-sm">
                    <thead>
                        <tr>
                            <th>Logo</th>
                            <th>Currency</th>
                            <th class="hidden lg:table-cell">Name</th>
                            <th class="hidden md:table-cell">Type</th>
                            <th>Rate / USD</th>
                            <th class="hidden sm:table-cell">Live freshness</th>
                            <th>Status</th>
                            <th class="text-right">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($this->rates as $rate)
                            @php
                                $live = $this->freshness[$rate->code] ?? null;
                                $age  = $live['age_hours'] ?? null;
                                $freshnessTone = match (true) {
                                    $live === null => 'zinc',
                                    $age < 24      => 'emerald',
                                    $age < 48      => 'amber',
                                    default        => 'red',
                                };
                                $freshnessLabel = match (true) {
                                    $live === null => 'Not synced',
                                    $age < 1       => 'Just now',
                                    $age < 24      => round($age).'h ago',
                                    $age < 48      => round($age).'h ago · stale',
                                    default        => round($age).'h ago · critical',
                                };
                            @endphp
                            @php
                                // Icon resolution chain:
                                //  - Fiat → prefer the country flag PNG (works in dark mode; the
                                //    global SVG invert filter mangles colored currency SVGs).
                                //  - Crypto → use the icon_path SVG (BTC/ETH/SOLANA/USDT are in
                                //    the dark-invert exception list so they keep their hues).
                                //  - Fallback chip on any error.
                                $iconUrl = $rate->icon_path ? asset('assets/'.$rate->icon_path) : null;
                                $flagUrl = $rate->type === 'fiat' ? $this->flagUrl($rate->code) : null;
                                $primaryIcon = $rate->type === 'fiat'
                                    ? ($flagUrl ?: $iconUrl)
                                    : ($iconUrl ?: $flagUrl);
                                $isFlag = $primaryIcon === $flagUrl;
                            @endphp
                            <tr>
                                <td>
                                    @if ($primaryIcon)
                                        <span class="relative inline-flex h-9 w-9">
                                            <img
                                                src="{{ $primaryIcon }}"
                                                alt="{{ $rate->code }}"
                                                class="h-9 w-9 rounded-[12px] {{ $isFlag ? 'object-cover' : 'object-contain' }} bg-zinc-50 ring-1 ring-zinc-100 dark:bg-[#0c1a36] dark:ring-white/10"
                                                loading="lazy"
                                                onerror="this.style.display='none'; this.nextElementSibling.style.display='flex';"
                                            >
                                            <span class="hidden h-9 w-9 items-center justify-center rounded-[12px] {{ $rate->type === 'crypto' ? 'bg-amber-500' : 'bg-emerald-500' }} text-xs font-bold text-white">
                                                {{ substr($rate->code, 0, 1) }}
                                            </span>
                                        </span>
                                    @else
                                        <span class="flex h-9 w-9 items-center justify-center rounded-[12px] {{ $rate->type === 'crypto' ? 'bg-amber-500' : 'bg-emerald-500' }} text-xs font-bold text-white">
                                            {{ substr($rate->code, 0, 1) }}
                                        </span>
                                    @endif
                                </td>
                                <td>
                                    <span class="font-bold tabular-nums text-zinc-900 dark:text-white">{{ $rate->code }}</span>
                                    <span class="mt-1 block md:hidden">
                                        <x-admin.badge :tone="$rate->type === 'crypto' ? 'amber' : 'emerald'">{{ $rate->type }}</x-admin.badge>
                                    </span>
                                </td>
                                <td class="hidden lg:table-cell">{{ $rate->name }}</td>
                                <td class="hidden md:table-cell">
                                    <x-admin.badge :tone="$rate->type === 'crypto' ? 'amber' : 'emerald'">{{ $rate->type }}</x-admin.badge>
                                </td>
                                <td class="whitespace-nowrap tabular-nums font-semibold text-zinc-900 dark:text-white">{{ rtrim(rtrim(number_format((float) $rate->rate_per_usd, 8), '0'), '.') }}</td>
                                <td class="hidden sm:table-cell">
                                    <x-admin.badge :tone="$freshnessTone">{{ $freshnessLabel }}</x-admin.badge>
                                </td>
                                <td>
                                    <button wire:click="toggleActive({{ $rate->id }})" type="button" class="cursor-pointer">
                                        <x-admin.badge :tone="$rate->is_active ? 'emerald' : 'zinc'">{{ $rate->is_active ? 'Active' : 'Inactive' }}</x-admin.badge>
                                    </button>
                                </td>
                                <td class="whitespace-nowrap text-right">
                                    <div class="inline-flex items-center gap-1.5">
                                        <button wire:click="edit({{ $rate->id }})" type="button" class="rounded-[5px] bg-blue-50 px-2.5 py-1 text-[11px] font-semibold text-blue-700 transition-colors hover:bg-blue-100 dark:bg-blue-500/15 dark:text-blue-300 dark:hover:bg-blue-500/25">Edit</button>
                                        <button wire:click="delete({{ $rate->id }})" wire:confirm="Delete {{ $rate->code }} permanently? This can't be undone." type="button" class="rounded-[5px] bg-red-50 px-2.5 py-1 text-[11px] font-semibold text-red-700 transition-colors hover:bg-red-100 dark:bg-red-500/15 dark:text-red-300 dark:hover:bg-red-500/25">Delete</button>
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="8" class="px-5 py-16 text-center">
                                    <p class="text-base font-semibold text-zinc-900 dark:text-white">No currencies match those filters</p>
                                    <p class="mt-1 text-sm text-zinc-600 dark:text-zinc-400">Clear the filters or click "Add currency" to define your first rate.</p>
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
                    <h3 class="text-sm font-bold text-zinc-900 dark:text-white">{{ $editingId ? 'Edit currency' : 'Add currency' }}</h3>
                    <button type="button" wire:click="closeForm" aria-label="Close" class="flex h-8 w-8 items-center justify-center rounded-[12px] bg-zinc-100 text-zinc-600 transition-colors hover:bg-zinc-200 dark:bg-[#26416b] dark:text-zinc-300 dark:hover:bg-[#34507a]">
                        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg>
                    </button>
                </div>
                <div class="space-y-4 overflow-y-auto px-5 py-4">
                    <div class="grid grid-cols-3 gap-4">
                        <div>
                            <label class="text-[10px] font-semibold uppercase tracking-wider text-zinc-800 dark:text-zinc-200">Code</label>
                            <input wire:model="code" type="text" placeholder="USD" class="mt-1.5 w-full rounded-[12px] border border-zinc-200 bg-white px-3 py-2 text-sm font-bold uppercase text-zinc-900 outline-none focus:border-blue-500 focus:ring-2 focus:ring-blue-500/15 dark:border-zinc-700/60 dark:bg-[#0c1a36] dark:text-white">
                            @error('code') <p class="mt-1 text-[11px] font-medium text-red-600">{{ $message }}</p> @enderror
                        </div>
                        <div class="col-span-2">
                            <label class="text-[10px] font-semibold uppercase tracking-wider text-zinc-800 dark:text-zinc-200">Name</label>
                            <input wire:model="name" type="text" placeholder="United States Dollar" class="mt-1.5 w-full rounded-[12px] border border-zinc-200 bg-white px-3 py-2 text-sm text-zinc-900 outline-none focus:border-blue-500 focus:ring-2 focus:ring-blue-500/15 dark:border-zinc-700/60 dark:bg-[#0c1a36] dark:text-white">
                            @error('name') <p class="mt-1 text-[11px] font-medium text-red-600">{{ $message }}</p> @enderror
                        </div>
                    </div>
                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <label class="text-[10px] font-semibold uppercase tracking-wider text-zinc-800 dark:text-zinc-200">Type</label>
                            <x-admin.select wire:model="type" :options="['fiat' => 'Fiat', 'crypto' => 'Crypto']" />
                        </div>
                        <div>
                            <label class="text-[10px] font-semibold uppercase tracking-wider text-zinc-800 dark:text-zinc-200">Rate per USD</label>
                            <input wire:model="rate_per_usd" type="number" step="0.00000001" min="0" placeholder="0.00" class="mt-1.5 w-full rounded-[12px] border border-zinc-200 bg-white px-3 py-2 text-sm tabular-nums text-zinc-900 outline-none focus:border-blue-500 focus:ring-2 focus:ring-blue-500/15 dark:border-zinc-700/60 dark:bg-[#0c1a36] dark:text-white">
                            @error('rate_per_usd') <p class="mt-1 text-[11px] font-medium text-red-600">{{ $message }}</p> @enderror
                        </div>
                    </div>
                    <div class="grid grid-cols-3 gap-4">
                        <div class="col-span-2">
                            <label class="text-[10px] font-semibold uppercase tracking-wider text-zinc-800 dark:text-zinc-200">Icon filename (optional)</label>
                            <input wire:model="icon_path" type="text" placeholder="BTC.svg" class="mt-1.5 w-full rounded-[12px] border border-zinc-200 bg-white px-3 py-2 text-sm text-zinc-900 outline-none focus:border-blue-500 focus:ring-2 focus:ring-blue-500/15 dark:border-zinc-700/60 dark:bg-[#0c1a36] dark:text-white">
                            <p class="mt-1 text-[10px] text-zinc-500 dark:text-zinc-400">Path under <code class="rounded bg-zinc-100 px-1 text-[10px] dark:bg-zinc-700/50">public/assets/</code>. Fiat falls back to a flag automatically.</p>
                        </div>
                        <div>
                            <label class="text-[10px] font-semibold uppercase tracking-wider text-zinc-800 dark:text-zinc-200">Sort order</label>
                            <input wire:model="sort_order" type="number" min="0" max="10000" class="mt-1.5 w-full rounded-[12px] border border-zinc-200 bg-white px-3 py-2 text-sm tabular-nums text-zinc-900 outline-none focus:border-blue-500 focus:ring-2 focus:ring-blue-500/15 dark:border-zinc-700/60 dark:bg-[#0c1a36] dark:text-white">
                        </div>
                    </div>
                    <label class="flex items-center gap-2">
                        <input wire:model="is_active" type="checkbox" class="h-4 w-4 cursor-pointer accent-blue-600">
                        <span class="text-sm font-semibold text-zinc-700 dark:text-zinc-300">Active</span>
                    </label>
                </div>
                <div class="flex shrink-0 items-center justify-end gap-2 border-t border-zinc-100 bg-zinc-50 px-5 py-3 dark:border-zinc-700/60 dark:bg-[#0c1a36]/50">
                    <button type="button" wire:click="closeForm" class="inline-flex items-center rounded-[12px] px-3.5 py-2 text-xs font-semibold text-zinc-700 transition-colors hover:bg-zinc-100 dark:text-zinc-300 dark:hover:bg-[#26416b]">Cancel</button>
                    <button type="submit" class="inline-flex items-center rounded-[12px] bg-blue-600 px-4 py-2 text-xs font-semibold text-white transition-colors hover:bg-blue-700">{{ $editingId ? 'Save changes' : 'Create currency' }}</button>
                </div>
            </form>
        </div>
    @endif
</div>

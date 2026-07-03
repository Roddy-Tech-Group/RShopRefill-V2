<?php

use App\Domain\Audit\Models\AuditLog;
use App\Models\Admin;
use App\Models\User;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;
use Livewire\WithPagination;

new
#[Layout('components.layouts.admin')]
#[Title('Account Activity')]
class extends Component {
    use WithPagination;

    #[Url(except: '')]
    public string $search = '';

    /** Filter by actor type. all | user | admin | system. */
    #[Url(except: 'all')]
    public string $actorFilter = 'all';

    /** Filter by action category. all | auth | security | financial | other. */
    #[Url(except: 'all')]
    public string $actionFilter = 'all';

    /** Currently inspected log row id, or null. Opens the detail modal. */
    public ?int $viewingId = null;

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function setActorFilter(string $value): void
    {
        $this->actorFilter = in_array($value, ['all', 'user', 'admin', 'system'], true) ? $value : 'all';
        $this->resetPage();
    }

    public function setActionFilter(string $value): void
    {
        $this->actionFilter = in_array($value, ['all', 'auth', 'security', 'financial', 'other'], true) ? $value : 'all';
        $this->resetPage();
    }

    public function viewLog(int $id): void
    {
        $this->viewingId = $id;
    }

    public function closeView(): void
    {
        $this->viewingId = null;
    }

    /**
     * Bucket every action verb into a small set of categories so the chip
     * filter stays compact. New action names default to "other" until they're
     * mapped here explicitly.
     */
    private function actionCategory(string $action): string
    {
        return match (true) {
            str_starts_with($action, 'user_login'), str_starts_with($action, 'password_reset') => 'auth',
            str_starts_with($action, 'transaction_pin') => 'security',
            str_contains($action, 'wallet'), str_contains($action, 'funding'), str_contains($action, 'refund'), str_contains($action, 'payment') => 'financial',
            default => 'other',
        };
    }

    /** Human-readable tone token for each category, used by the badge. */
    private function actionTone(string $action): string
    {
        return match ($this->actionCategory($action)) {
            'auth' => 'blue',
            'security' => 'amber',
            'financial' => 'emerald',
            default => 'zinc',
        };
    }

    public function with(): array
    {
        $query = AuditLog::query()->orderByDesc('id');

        if (($term = trim($this->search)) !== '') {
            $query->where(function ($q) use ($term) {
                $q->where('action', 'like', "%{$term}%")
                    ->orWhere('ip_address', 'like', "%{$term}%")
                    ->orWhere('correlation_id', 'like', "%{$term}%");
            });
        }

        if ($this->actorFilter === 'user') {
            $query->whereNotNull('actor_id');
        } elseif ($this->actorFilter === 'admin') {
            // Admin actors get stored as metadata.actor_type = App\Models\Admin
            // (the audit_logs.actor_id FK can only hold users.id).
            $query->whereJsonContains('metadata->actor_type', Admin::class);
        } elseif ($this->actorFilter === 'system') {
            $query->whereNull('actor_id')
                ->where(function ($q) {
                    $q->whereNull('metadata')
                        ->orWhereJsonDoesntContain('metadata->actor_type', Admin::class);
                });
        }

        if ($this->actionFilter !== 'all') {
            $query->where(function ($q) {
                $patterns = match ($this->actionFilter) {
                    'auth'     => ['user_login%', 'password_reset%'],
                    'security' => ['transaction_pin%'],
                    'financial' => ['%wallet%', '%funding%', '%refund%', '%payment%'],
                    'other'    => [],
                    default    => [],
                };
                foreach ($patterns as $p) {
                    $q->orWhere('action', 'like', $p);
                }
                if ($this->actionFilter === 'other') {
                    $q->whereNotIn('action', function ($sub) {
                        $sub->select('action')->from('audit_logs')
                            ->where('action', 'like', 'user_login%')
                            ->orWhere('action', 'like', 'password_reset%')
                            ->orWhere('action', 'like', 'transaction_pin%')
                            ->orWhere('action', 'like', '%wallet%')
                            ->orWhere('action', 'like', '%funding%')
                            ->orWhere('action', 'like', '%refund%')
                            ->orWhere('action', 'like', '%payment%');
                    });
                }
            });
        }

        // Eager-load the user actor; admin actors are read straight from
        // metadata in the view so no extra query is needed for them.
        $logs = $query->with('actor:id,name,email')->paginate(25);

        // KPI counters — full table totals (filters don't move them so the
        // numbers stay stable as the admin scrubs the chips).
        $today = AuditLog::whereDate('created_at', now()->toDateString())->count();
        $week  = AuditLog::where('created_at', '>=', now()->subDays(7))->count();
        $failed = AuditLog::where('action', 'like', '%failed%')->count();
        $total = AuditLog::count();

        $viewing = $this->viewingId ? AuditLog::with('actor:id,name,email')->find($this->viewingId) : null;

        return [
            'logs' => $logs,
            'summary' => [
                'today' => $today,
                'week' => $week,
                'failed' => $failed,
                'total' => $total,
            ],
            'viewing' => $viewing,
            'categoryFn' => fn ($action) => $this->actionCategory($action),
            'toneFn' => fn ($action) => $this->actionTone($action),
        ];
    }
}; ?>

@php
    // Resolve the actor row for a log: prefer the user relation (FK), then
    // fall back to the admin actor that's stored in metadata when an admin
    // performed the action (admins don't live in the users table).
    $actorLabel = function ($log) {
        if ($log->actor) {
            return ['name' => $log->actor->name, 'email' => $log->actor->email, 'kind' => 'user'];
        }
        $meta = $log->metadata ?? [];
        if (! empty($meta['actor_type']) && str_ends_with($meta['actor_type'], 'Admin')) {
            return ['name' => $meta['actor_email'] ?? 'Admin', 'email' => $meta['actor_email'] ?? '', 'kind' => 'admin'];
        }
        return ['name' => 'System', 'email' => '', 'kind' => 'system'];
    };
@endphp

<div>
    <x-slot:heading>Account Activity</x-slot:heading>
    <x-slot:subheading>{{ number_format($summary['total']) }} audit events captured. Every customer login, password reset, and security-sensitive action lands here.</x-slot:subheading>

    <div class="flex flex-1 flex-col gap-6">

        {{-- KPI strip --}}
        <div class="grid grid-cols-2 gap-3 sm:grid-cols-4">
            @foreach ([
                ['label' => 'Today',           'value' => $summary['today'],  'dot' => 'bg-blue-500'],
                ['label' => 'Last 7 days',     'value' => $summary['week'],   'dot' => 'bg-emerald-500'],
                ['label' => 'Failed attempts', 'value' => $summary['failed'], 'dot' => 'bg-red-500'],
                ['label' => 'Total events',    'value' => $summary['total'],  'dot' => 'bg-zinc-500'],
            ] as $stat)
                <div class="rounded-[12px] bg-white p-4 shadow-sm shadow-zinc-900/[0.04] ring-1 ring-zinc-100 dark:bg-[#1d3252] dark:ring-zinc-700/60">
                    <p class="flex items-center gap-1.5 text-[11px] font-semibold uppercase tracking-wider text-zinc-600 dark:text-zinc-300">
                        <span class="inline-block h-1.5 w-1.5 rounded-full {{ $stat['dot'] }}"></span>
                        {{ $stat['label'] }}
                    </p>
                    <p class="mt-2 text-3xl font-bold tracking-tight text-zinc-900 dark:text-white">{{ number_format($stat['value']) }}</p>
                </div>
            @endforeach
        </div>

        {{-- Filters --}}
        <div class="flex flex-col items-stretch gap-3 sm:flex-row sm:items-center">
            <div class="relative flex-1">
                <svg class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-zinc-600 dark:text-zinc-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/>
                </svg>
                <input
                    wire:model.live.debounce.300ms="search"
                    type="search"
                    placeholder="Search by action, IP, or correlation ID"
                    class="w-full rounded-[12px] border border-zinc-200 bg-white py-2.5 pl-10 pr-3 text-sm text-zinc-900 outline-none transition-colors focus:border-blue-500 focus:ring-2 focus:ring-blue-500/15 dark:border-zinc-700/60 dark:bg-[#1d3252] dark:text-white"
                />
            </div>

            <div class="flex flex-wrap items-center gap-1.5">
                @foreach ([
                    'all' => 'All actors', 'user' => 'Customers', 'admin' => 'Admins', 'system' => 'System',
                ] as $value => $label)
                    <button
                        type="button"
                        wire:click="setActorFilter('{{ $value }}')"
                        @class([
                            'rounded-[12px] px-3 py-1.5 text-xs font-semibold transition-colors',
                            'bg-blue-600 text-white' => $actorFilter === $value,
                            'bg-white text-zinc-700 ring-1 ring-zinc-200 hover:bg-zinc-50 dark:bg-[#1d3252] dark:text-zinc-300 dark:ring-zinc-700/60 dark:hover:bg-[#26416b]' => $actorFilter !== $value,
                        ])
                    >{{ $label }}</button>
                @endforeach
            </div>
        </div>

        <div class="flex flex-wrap items-center gap-1.5">
            <span class="text-[11px] font-semibold uppercase tracking-wider text-zinc-500 dark:text-zinc-400">Category</span>
            @foreach ([
                'all' => 'All', 'auth' => 'Login & reset', 'security' => 'Security', 'financial' => 'Financial', 'other' => 'Other',
            ] as $value => $label)
                <button
                    type="button"
                    wire:click="setActionFilter('{{ $value }}')"
                    @class([
                        'rounded-[12px] px-3 py-1.5 text-xs font-semibold transition-colors',
                        'bg-blue-600 text-white' => $actionFilter === $value,
                        'bg-white text-zinc-700 ring-1 ring-zinc-200 hover:bg-zinc-50 dark:bg-[#1d3252] dark:text-zinc-300 dark:ring-zinc-700/60 dark:hover:bg-[#26416b]' => $actionFilter !== $value,
                    ])
                >{{ $label }}</button>
            @endforeach
        </div>

        {{-- Log table --}}
        <div class="overflow-hidden rounded-[12px] border-[1.5px] border-white bg-white shadow-sm shadow-zinc-900/[0.04] dark:border-white dark:bg-[#1d3252]">
            <div class="overflow-x-auto p-3">
                <table class="admin-table w-full text-left text-sm">
                    <thead>
                        <tr>
                            <th>When</th>
                            <th>Actor</th>
                            <th>Action</th>
                            <th class="hidden lg:table-cell">Target</th>
                            <th class="hidden md:table-cell">IP</th>
                            <th class="text-right">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($logs as $log)
                            @php
                                $actor = $actorLabel($log);
                                $isFailed = str_contains($log->action, 'failed');
                            @endphp
                            <tr>
                                <td class="whitespace-nowrap px-5 py-3 text-zinc-700 dark:text-zinc-300">
                                    <div class="text-sm">{{ $log->created_at->diffForHumans() }}</div>
                                    <div class="text-[10px] text-zinc-500 dark:text-zinc-400">{{ $log->created_at->format('M j, Y H:i:s') }}</div>
                                </td>
                                <td class="px-5 py-3">
                                    <div class="flex items-center gap-2">
                                        @if ($actor['kind'] === 'admin')
                                            <span class="flex h-7 w-7 shrink-0 items-center justify-center rounded-[12px] bg-fuchsia-500/15 text-[11px] font-bold text-fuchsia-700 dark:text-fuchsia-300">A</span>
                                        @elseif ($actor['kind'] === 'user')
                                            <span class="flex h-7 w-7 shrink-0 items-center justify-center rounded-[12px] bg-blue-500/15 text-[11px] font-bold text-blue-700 dark:text-blue-300">{{ strtoupper(substr($actor['name'], 0, 1)) }}</span>
                                        @else
                                            <span class="flex h-7 w-7 shrink-0 items-center justify-center rounded-[12px] bg-zinc-200 text-[11px] font-bold text-zinc-700 dark:bg-zinc-700 dark:text-zinc-300">S</span>
                                        @endif
                                        <div class="min-w-0">
                                            <div class="truncate text-sm font-semibold text-zinc-900 dark:text-white">{{ $actor['name'] }}</div>
                                            @if ($actor['email'])
                                                <div class="truncate text-[10px] text-zinc-500 dark:text-zinc-400">{{ $actor['email'] }}</div>
                                            @endif
                                        </div>
                                    </div>
                                </td>
                                <td class="px-5 py-3">
                                    <x-admin.badge :tone="$isFailed ? 'red' : $toneFn($log->action)">{{ $log->action }}</x-admin.badge>
                                </td>
                                <td class="hidden px-5 py-3 text-zinc-700 dark:text-zinc-300 lg:table-cell">
                                    @if ($log->model_type)
                                        <div class="text-xs">{{ class_basename($log->model_type) }}</div>
                                        @if ($log->model_id)
                                            <div class="text-[10px] text-zinc-500 dark:text-zinc-400">#{{ $log->model_id }}</div>
                                        @endif
                                    @else
                                        <span class="text-zinc-400">-</span>
                                    @endif
                                </td>
                                <td class="hidden whitespace-nowrap px-5 py-3 font-mono text-[11px] text-zinc-600 dark:text-zinc-400 md:table-cell">{{ $log->ip_address ?: '-' }}</td>
                                <td class="whitespace-nowrap px-5 py-3 text-right">
                                    <button wire:click="viewLog({{ $log->id }})" type="button" class="rounded-[5px] bg-blue-50 px-2.5 py-1 text-[11px] font-semibold text-blue-700 transition-colors hover:bg-blue-100 dark:bg-blue-500/15 dark:text-blue-300 dark:hover:bg-blue-500/25">View</button>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="6" class="px-5 py-16 text-center">
                                    <p class="text-base font-semibold text-zinc-900 dark:text-white">No audit events match those filters</p>
                                    <p class="mt-1 text-sm text-zinc-600 dark:text-zinc-400">Clear the search or pick a different category.</p>
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            @if ($logs->hasPages())
                <div class="border-t border-zinc-100 px-4 py-3 dark:border-zinc-700/60">
                    {{ $logs->links() }}
                </div>
            @endif
        </div>
    </div>

    {{-- Detail modal --}}
    @if ($viewing)
        @php $actor = $actorLabel($viewing); @endphp
        <div class="fixed inset-0 z-50 flex items-center justify-center p-4" role="dialog" aria-modal="true">
            <div wire:click="closeView" class="absolute inset-0 bg-zinc-900/40"></div>
            <div class="relative flex max-h-[90vh] w-full max-w-2xl flex-col overflow-hidden rounded-[12px] bg-white shadow-2xl dark:bg-[#1d3252]">
                <div class="flex shrink-0 items-start justify-between gap-4 border-b border-zinc-100 px-5 py-4 dark:border-zinc-700/60">
                    <div>
                        <h3 class="text-sm font-bold text-zinc-900 dark:text-white">Audit event #{{ $viewing->id }}</h3>
                        <p class="mt-0.5 text-xs text-zinc-500 dark:text-zinc-400">{{ $viewing->created_at->format('M j, Y H:i:s') }} - {{ $viewing->created_at->diffForHumans() }}</p>
                    </div>
                    <button type="button" wire:click="closeView" aria-label="Close" class="flex h-8 w-8 items-center justify-center rounded-[12px] bg-zinc-100 text-zinc-600 transition-colors hover:bg-zinc-200 dark:bg-[#26416b] dark:text-zinc-300 dark:hover:bg-[#34507a]">
                        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg>
                    </button>
                </div>
                <div class="space-y-4 overflow-y-auto px-5 py-4 text-sm">
                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <p class="text-[10px] font-semibold uppercase tracking-wider text-zinc-500 dark:text-zinc-400">Actor</p>
                            <p class="mt-1 font-semibold text-zinc-900 dark:text-white">{{ $actor['name'] }}</p>
                            @if ($actor['email'])
                                <p class="text-xs text-zinc-500 dark:text-zinc-400">{{ $actor['email'] }}</p>
                            @endif
                        </div>
                        <div>
                            <p class="text-[10px] font-semibold uppercase tracking-wider text-zinc-500 dark:text-zinc-400">Action</p>
                            <p class="mt-1"><x-admin.badge :tone="str_contains($viewing->action, 'failed') ? 'red' : $toneFn($viewing->action)">{{ $viewing->action }}</x-admin.badge></p>
                        </div>
                    </div>

                    @if ($viewing->model_type)
                        <div>
                            <p class="text-[10px] font-semibold uppercase tracking-wider text-zinc-500 dark:text-zinc-400">Target</p>
                            <p class="mt-1 font-mono text-xs text-zinc-700 dark:text-zinc-300">{{ $viewing->model_type }}{{ $viewing->model_id ? '#'.$viewing->model_id : '' }}</p>
                        </div>
                    @endif

                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <p class="text-[10px] font-semibold uppercase tracking-wider text-zinc-500 dark:text-zinc-400">IP address</p>
                            <p class="mt-1 font-mono text-xs text-zinc-700 dark:text-zinc-300">{{ $viewing->ip_address ?: '-' }}</p>
                        </div>
                        <div>
                            <p class="text-[10px] font-semibold uppercase tracking-wider text-zinc-500 dark:text-zinc-400">Correlation ID</p>
                            <p class="mt-1 break-all font-mono text-xs text-zinc-700 dark:text-zinc-300">{{ $viewing->correlation_id ?: '-' }}</p>
                        </div>
                    </div>

                    @if ($viewing->user_agent)
                        <div>
                            <p class="text-[10px] font-semibold uppercase tracking-wider text-zinc-500 dark:text-zinc-400">User agent</p>
                            <p class="mt-1 break-words text-xs text-zinc-700 dark:text-zinc-300">{{ $viewing->user_agent }}</p>
                        </div>
                    @endif

                    @if (! empty($viewing->metadata))
                        <div>
                            <p class="text-[10px] font-semibold uppercase tracking-wider text-zinc-500 dark:text-zinc-400">Metadata</p>
                            <pre class="mt-1 max-h-48 overflow-auto rounded-[12px] bg-zinc-50 p-3 text-[11px] text-zinc-700 dark:bg-[#0c1a36] dark:text-zinc-300">{{ json_encode($viewing->metadata, JSON_PRETTY_PRINT) }}</pre>
                        </div>
                    @endif

                    @if (! empty($viewing->before_state) || ! empty($viewing->after_state))
                        <div class="grid grid-cols-1 gap-3 md:grid-cols-2">
                            @if (! empty($viewing->before_state))
                                <div>
                                    <p class="text-[10px] font-semibold uppercase tracking-wider text-zinc-500 dark:text-zinc-400">Before</p>
                                    <pre class="mt-1 max-h-48 overflow-auto rounded-[12px] bg-zinc-50 p-3 text-[11px] text-zinc-700 dark:bg-[#0c1a36] dark:text-zinc-300">{{ json_encode($viewing->before_state, JSON_PRETTY_PRINT) }}</pre>
                                </div>
                            @endif
                            @if (! empty($viewing->after_state))
                                <div>
                                    <p class="text-[10px] font-semibold uppercase tracking-wider text-zinc-500 dark:text-zinc-400">After</p>
                                    <pre class="mt-1 max-h-48 overflow-auto rounded-[12px] bg-zinc-50 p-3 text-[11px] text-zinc-700 dark:bg-[#0c1a36] dark:text-zinc-300">{{ json_encode($viewing->after_state, JSON_PRETTY_PRINT) }}</pre>
                                </div>
                            @endif
                        </div>
                    @endif
                </div>
                <div class="flex shrink-0 items-center justify-end gap-2 border-t border-zinc-100 bg-zinc-50 px-5 py-3 dark:border-zinc-700/60 dark:bg-[#0c1a36]/50">
                    <button type="button" wire:click="closeView" class="inline-flex items-center rounded-[12px] bg-blue-600 px-4 py-2 text-xs font-semibold text-white transition-colors hover:bg-blue-700">Close</button>
                </div>
            </div>
        </div>
    @endif
</div>

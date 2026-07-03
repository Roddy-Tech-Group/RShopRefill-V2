{{-- FILE: resources/views/livewire/admin/support-tickets.blade.php --}}
<?php

use App\Models\ContactMessage;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;
use Livewire\WithPagination;

new
#[Layout('components.layouts.admin')]
#[Title('Support Tickets')]
class extends Component {
    use WithPagination;

    #[Url(except: '')]
    public string $search = '';

    #[Url(as: 'status', except: 'all')]
    public string $statusFilter = 'all';

    public bool $showMessage = false;
    public ?int $viewingId = null;

    #[Computed]
    public function counts(): array
    {
        $all = ContactMessage::query();

        return [
            'new'      => (clone $all)->where('status', 'new')->count(),
            'read'     => (clone $all)->where('status', 'read')->count(),
            'resolved' => (clone $all)->where('status', 'resolved')->count(),
            'total'    => (clone $all)->count(),
        ];
    }

    #[Computed]
    public function activeMessage(): ?ContactMessage
    {
        return $this->viewingId ? ContactMessage::find($this->viewingId) : null;
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedStatusFilter(): void
    {
        $this->resetPage();
    }

    public function viewMessage(int $id): void
    {
        $this->viewingId = $id;
        $this->showMessage = true;
        unset($this->activeMessage);

        // Auto-mark as read when opened.
        $msg = ContactMessage::find($id);
        if ($msg && $msg->status === 'new') {
            $msg->update(['status' => 'read']);
            unset($this->counts);
        }
    }

    public function closeMessage(): void
    {
        $this->showMessage = false;
        $this->viewingId = null;
        unset($this->activeMessage);
    }

    public function markRead(int $id): void
    {
        ContactMessage::findOrFail($id)->update(['status' => 'read']);
        unset($this->counts);

        if ($this->viewingId === $id) {
            unset($this->activeMessage);
        }
    }

    public function markResolved(int $id): void
    {
        ContactMessage::findOrFail($id)->update(['status' => 'resolved']);
        unset($this->counts);

        if ($this->viewingId === $id) {
            unset($this->activeMessage);
            $this->showMessage = false;
            $this->viewingId = null;
        }

        session()->flash('status', 'Ticket marked as resolved.');
    }

    public function with(): array
    {
        $query = ContactMessage::query()->latest();

        if ($this->statusFilter !== 'all') {
            $query->where('status', $this->statusFilter);
        }

        if ($this->search !== '') {
            $needle = '%' . trim($this->search) . '%';
            $query->where(function ($q) use ($needle) {
                $q->where('name', 'like', $needle)
                    ->orWhere('email', 'like', $needle)
                    ->orWhere('subject', 'like', $needle);
            });
        }

        return [
            'messages' => $query->paginate(20),
        ];
    }
}; ?>

<div>
    <x-slot:heading>Support Tickets</x-slot:heading>
    <x-slot:subheading>Contact form messages submitted by customers. Open a message to read the full body and update its status.</x-slot:subheading>

    <div class="flex flex-col gap-6">

        @if (session('status'))
            <div class="rounded-[12px] bg-emerald-50 px-4 py-3 text-sm font-medium text-emerald-700 ring-1 ring-emerald-200 dark:bg-emerald-500/15 dark:text-emerald-300 dark:ring-emerald-500/30">{{ session('status') }}</div>
        @endif

        {{-- KPI strip --}}
        <div class="grid grid-cols-2 gap-3 sm:grid-cols-4">
            @foreach ([
                ['label' => 'New',      'value' => $this->counts['new'],      'dot' => 'bg-blue-500'],
                ['label' => 'Read',     'value' => $this->counts['read'],     'dot' => 'bg-zinc-400'],
                ['label' => 'Resolved', 'value' => $this->counts['resolved'], 'dot' => 'bg-emerald-500'],
                ['label' => 'Total',    'value' => $this->counts['total'],    'dot' => 'bg-amber-500'],
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

        {{-- Search + filter row --}}
        <div class="flex flex-col items-stretch gap-3 sm:flex-row sm:items-center">
            <div class="relative flex-1">
                <svg class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-zinc-500 dark:text-zinc-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/>
                </svg>
                <input
                    wire:model.live.debounce.250ms="search"
                    type="search"
                    placeholder="Search by name, email or subject..."
                    class="w-full rounded-[12px] border border-zinc-200 bg-white py-2.5 pl-10 pr-3 text-sm text-zinc-900 outline-none transition-colors focus:border-blue-500 focus:ring-2 focus:ring-blue-500/15 dark:border-zinc-700/60 dark:bg-[#1d3252] dark:text-white"
                />
            </div>

            <div class="flex flex-wrap items-center gap-1.5">
                @foreach (['all' => 'All', 'new' => 'New', 'read' => 'Read', 'resolved' => 'Resolved'] as $value => $label)
                    <button
                        type="button"
                        wire:click="$set('statusFilter', '{{ $value }}')"
                        @class([
                            'rounded-[12px] px-3 py-1.5 text-xs font-semibold transition-colors',
                            'bg-blue-600 text-white' => $statusFilter === $value,
                            'bg-white text-zinc-700 ring-1 ring-zinc-200 hover:bg-zinc-50 dark:bg-[#1d3252] dark:text-zinc-300 dark:ring-zinc-700/60 dark:hover:bg-[#26416b]' => $statusFilter !== $value,
                        ])
                    >{{ $label }}</button>
                @endforeach
            </div>
        </div>

        {{-- Table --}}
        <div class="overflow-hidden rounded-[12px] border-[1.5px] border-white bg-white shadow-sm shadow-zinc-900/[0.04] dark:border-white dark:bg-[#1d3252]">
            <div class="overflow-x-auto p-3">
                <table class="admin-table w-full text-left text-sm">
                    <thead class="bg-zinc-50 text-[11px] uppercase tracking-wider text-zinc-600 dark:bg-[#0c1a36] dark:text-zinc-400">
                        <tr>
                            <th class="px-5 py-3 font-semibold">ID</th>
                            <th class="px-5 py-3 font-semibold">From</th>
                            <th class="px-5 py-3 font-semibold">Subject</th>
                            <th class="hidden px-5 py-3 font-semibold md:table-cell">Order</th>
                            <th class="px-5 py-3 font-semibold">Status</th>
                            <th class="hidden px-5 py-3 font-semibold sm:table-cell">Date</th>
                            <th class="px-5 py-3 text-right font-semibold">Action</th>
                        </tr>
                    </thead>
                    <tbody class="divide-inset">
                        @forelse ($messages as $msg)
                            @php
                                $statusTone = match ($msg->status) {
                                    'new'      => 'blue',
                                    'read'     => 'zinc',
                                    'resolved' => 'emerald',
                                    default    => 'zinc',
                                };
                            @endphp
                            <tr class="transition-colors hover:bg-zinc-50 dark:hover:bg-[#26416b]/40">
                                <td class="px-5 py-3 tabular-nums text-zinc-500 dark:text-zinc-400">#{{ $msg->id }}</td>
                                <td class="px-5 py-3">
                                    <p class="font-semibold text-zinc-900 dark:text-white">{{ $msg->name }}</p>
                                    <p class="text-xs text-zinc-500 dark:text-zinc-400">{{ $msg->email }}</p>
                                </td>
                                <td class="max-w-[220px] px-5 py-3">
                                    <p class="truncate text-zinc-700 dark:text-zinc-300">{{ $msg->subject ?? '(no subject)' }}</p>
                                </td>
                                <td class="hidden px-5 py-3 md:table-cell">
                                    @if ($msg->order_id)
                                        <span class="font-mono text-xs text-zinc-600 dark:text-zinc-400">{{ $msg->order_id }}</span>
                                    @else
                                        <span class="text-zinc-400 dark:text-zinc-600">-</span>
                                    @endif
                                </td>
                                <td class="px-5 py-3">
                                    <x-admin.badge :tone="$statusTone">{{ $msg->status }}</x-admin.badge>
                                </td>
                                <td class="hidden whitespace-nowrap px-5 py-3 text-xs text-zinc-500 dark:text-zinc-400 sm:table-cell">{{ $msg->created_at->format('M j, Y') }}</td>
                                <td class="whitespace-nowrap px-5 py-3 text-right">
                                    <button
                                        wire:click="viewMessage({{ $msg->id }})"
                                        type="button"
                                        class="rounded-[5px] bg-blue-50 px-2.5 py-1 text-[11px] font-semibold text-blue-700 transition-colors hover:bg-blue-100 dark:bg-blue-500/15 dark:text-blue-300 dark:hover:bg-blue-500/25"
                                    >View</button>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="7" class="px-5 py-16 text-center">
                                    <p class="text-base font-semibold text-zinc-900 dark:text-white">No messages match those filters</p>
                                    <p class="mt-1 text-sm text-zinc-500 dark:text-zinc-400">Try a different search or status filter.</p>
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            @if ($messages->hasPages())
                <div class="border-t border-zinc-100 px-5 py-3 dark:border-zinc-700/60">
                    {{ $messages->links() }}
                </div>
            @endif
        </div>
    </div>

    {{-- View message modal --}}
    @if ($showMessage && $this->activeMessage)
        @php $msg = $this->activeMessage; @endphp
        <div class="fixed inset-0 z-50 flex items-center justify-center p-4" role="dialog" aria-modal="true">
            <div wire:click="closeMessage" class="absolute inset-0 bg-zinc-900/40"></div>
            <div class="relative max-h-[90vh] w-full max-w-xl overflow-hidden rounded-[12px] bg-white shadow-2xl flex flex-col dark:bg-[#1d3252]">
                <div class="flex shrink-0 items-start justify-between gap-4 border-b border-zinc-100 px-5 py-4 dark:border-zinc-700/60">
                    <div>
                        <h3 class="text-sm font-bold text-zinc-900 dark:text-white">{{ $msg->subject ?? '(no subject)' }}</h3>
                        <p class="mt-0.5 text-xs text-zinc-500 dark:text-zinc-400">Ticket #{{ $msg->id }}</p>
                    </div>
                    <button type="button" wire:click="closeMessage" aria-label="Close" class="flex h-8 w-8 shrink-0 items-center justify-center rounded-[12px] bg-zinc-100 text-zinc-600 transition-colors hover:bg-zinc-200 dark:bg-[#26416b] dark:text-zinc-300 dark:hover:bg-[#34507a]">
                        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg>
                    </button>
                </div>

                <div class="overflow-y-auto px-5 py-4 space-y-4">
                    {{-- Sender details --}}
                    <div class="grid grid-cols-2 gap-4 text-sm">
                        <div>
                            <p class="text-[10px] font-semibold uppercase tracking-wider text-zinc-500 dark:text-zinc-400">From</p>
                            <p class="mt-1 font-semibold text-zinc-900 dark:text-white">{{ $msg->name }}</p>
                            <p class="text-xs text-zinc-500 dark:text-zinc-400">{{ $msg->email }}</p>
                        </div>
                        <div>
                            <p class="text-[10px] font-semibold uppercase tracking-wider text-zinc-500 dark:text-zinc-400">Received</p>
                            <p class="mt-1 text-zinc-700 dark:text-zinc-300">{{ $msg->created_at->format('M j, Y') }}</p>
                            <p class="text-xs text-zinc-500 dark:text-zinc-400">{{ $msg->created_at->format('g:i A') }}</p>
                        </div>
                        @if ($msg->order_id)
                            <div>
                                <p class="text-[10px] font-semibold uppercase tracking-wider text-zinc-500 dark:text-zinc-400">Order ID</p>
                                <p class="mt-1 font-mono text-xs text-zinc-700 dark:text-zinc-300">{{ $msg->order_id }}</p>
                            </div>
                        @endif
                        <div>
                            <p class="text-[10px] font-semibold uppercase tracking-wider text-zinc-500 dark:text-zinc-400">Status</p>
                            <div class="mt-1">
                                @php
                                    $tone = match ($msg->status) {
                                        'new'      => 'blue',
                                        'read'     => 'zinc',
                                        'resolved' => 'emerald',
                                        default    => 'zinc',
                                    };
                                @endphp
                                <x-admin.badge :tone="$tone">{{ $msg->status }}</x-admin.badge>
                            </div>
                        </div>
                        @if ($msg->ip_address)
                            <div>
                                <p class="text-[10px] font-semibold uppercase tracking-wider text-zinc-500 dark:text-zinc-400">IP Address</p>
                                <p class="mt-1 font-mono text-xs text-zinc-500 dark:text-zinc-400">{{ $msg->ip_address }}</p>
                            </div>
                        @endif
                    </div>

                    {{-- Message body --}}
                    <div>
                        <p class="text-[10px] font-semibold uppercase tracking-wider text-zinc-500 dark:text-zinc-400">Message</p>
                        <div class="mt-2 rounded-[12px] border border-zinc-100 bg-zinc-50 px-4 py-3 text-sm leading-relaxed text-zinc-700 dark:border-zinc-700/60 dark:bg-[#0c1a36] dark:text-zinc-300">
                            {{ $msg->message }}
                        </div>
                    </div>
                </div>

                <div class="flex shrink-0 items-center justify-between gap-2 border-t border-zinc-100 bg-zinc-50 px-5 py-3 dark:border-zinc-700/60 dark:bg-[#0c1a36]/50">
                    <button type="button" wire:click="closeMessage" class="inline-flex items-center rounded-[12px] px-3.5 py-2 text-xs font-semibold text-zinc-700 transition-colors hover:bg-zinc-100 dark:text-zinc-300 dark:hover:bg-[#26416b]">Close</button>
                    <div class="flex items-center gap-2">
                        @if ($msg->status !== 'read')
                            <button
                                type="button"
                                wire:click="markRead({{ $msg->id }})"
                                class="inline-flex items-center rounded-[12px] border border-zinc-200 bg-white px-3 py-2 text-xs font-semibold text-zinc-700 transition-colors hover:bg-zinc-50 dark:border-zinc-700/60 dark:bg-[#1d3252] dark:text-zinc-300 dark:hover:bg-[#26416b]"
                            >Mark as read</button>
                        @endif
                        @if ($msg->status !== 'resolved')
                            <button
                                type="button"
                                wire:click="markResolved({{ $msg->id }})"
                                class="inline-flex items-center rounded-[12px] bg-blue-600 px-3 py-2 text-xs font-semibold text-white transition-colors hover:bg-blue-700"
                            >Mark resolved</button>
                        @endif
                    </div>
                </div>
            </div>
        </div>
    @endif
</div>

<?php

use App\Domain\Notification\Enums\DeliveryStatus;
use App\Domain\Notification\Jobs\RetryFailedNotificationsJob;
use App\Domain\Notification\Jobs\SendAsynchronousNotificationJob;
use App\Domain\Notification\Services\NotificationAuditService;
use App\Models\Notification;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;
use Livewire\WithPagination;

new
#[Layout('components.layouts.admin')]
#[Title('Notifications')]
class extends Component {
    use WithPagination;

    /** Status filter: all | pending | sent | failed. */
    public string $filter = 'all';

    /** System-wide delivery metrics (NotificationAuditService). */
    #[Computed]
    public function metrics(): array
    {
        return app(NotificationAuditService::class)->getSystemMetrics();
    }

    public function setFilter(string $filter): void
    {
        $this->filter = $filter;
        $this->resetPage();
    }

    /**
     * Re-queue a failed/pending notification for delivery. Mirrors
     * NotificationAdminApiController::retry so both entry points behave the same.
     */
    public function retry(string $id): void
    {
        $notification = Notification::with('user')->find($id);

        if (! $notification || $notification->status === DeliveryStatus::Sent) {
            return;
        }

        $notification->update([
            'status' => DeliveryStatus::Pending,
            'failed_at' => null,
        ]);

        // Rebuild the original mailable when `type` holds a mailable class name.
        $mailable = null;
        if ($notification->type && class_exists($notification->type)) {
            $mailable = new $notification->type($notification->user);
        }

        SendAsynchronousNotificationJob::dispatch(
            user: $notification->user,
            title: $notification->title,
            message: $notification->message,
            mailable: $mailable,
            priority: $notification->priority,
            metadata: $notification->metadata ?? [],
            channels: [$notification->channel],
        );

        $this->dispatch('notification-retried');
    }

    /**
     * Re-queue every failed notification in one click. Runs the same auto-retry
     * sweep that the scheduler fires every 15 minutes, but synchronously so the
     * admin sees the change in real time.
     */
    public function retryAllFailed(): void
    {
        RetryFailedNotificationsJob::dispatchSync();
        $this->dispatch('notification-retried');
    }

    /** Permanently delete a notification record from the audit log. */
    public function delete(string $id): void
    {
        Notification::whereKey($id)->delete();
        $this->dispatch('notification-deleted');
    }

    public function with(): array
    {
        $query = Notification::with('user')->latest();

        if (in_array($this->filter, ['pending', 'sent', 'failed'], true)) {
            $query->where('status', $this->filter);
        }

        return [
            'notifications' => $query->paginate(20),
        ];
    }
}; ?>

@php
    // Status badge tone.
    $statusTone = [
        'sent'    => 'bg-emerald-500',
        'failed'  => 'bg-red-500',
        'pending' => 'bg-amber-500',
    ];
    $priorityTone = [
        'critical' => 'bg-red-500',
        'high'     => 'bg-amber-500',
        'normal'   => 'bg-zinc-400',
    ];
@endphp

<div
    x-data="{
        show: false,
        note: { id: null, recipient: '', email: '', title: '', message: '', channel: '', priority: '', status: '', time: '' },
        open(data) { this.note = data; this.show = true; },
        close() { this.show = false; },
    }"
>
    <x-slot:heading>Notifications</x-slot:heading>
    <x-slot:subheading>Delivery monitoring and audit log for every customer notification.</x-slot:subheading>

    <div class="flex flex-col gap-6">

        {{-- Delivery metrics --}}
        <div class="grid grid-cols-2 gap-3 lg:grid-cols-4">
            @php
                $m = $this->metrics;
                $cards = [
                    ['Delivery attempts', number_format($m['total_delivery_attempts']), 'bg-blue-100', 'text-blue-700'],
                    ['Delivered',         number_format($m['successful_deliveries']),  'bg-emerald-100', 'text-emerald-700'],
                    ['Failed',            number_format($m['failed_deliveries']),      'bg-red-100', 'text-red-700'],
                    ['Success rate',      $m['success_rate'].'%',                      'bg-zinc-100', 'text-zinc-700'],
                ];
            @endphp
            @foreach ($cards as [$label, $value, $bg, $fg])
                <div class="rounded-[20px] bg-white p-5 shadow-sm shadow-zinc-900/5 ring-1 ring-zinc-100">
                    <span class="inline-flex items-center rounded-[5px] {{ $bg }} {{ $fg }} px-2 py-0.5 text-[11px] font-bold uppercase tracking-wide">{{ $label }}</span>
                    <p class="mt-3 text-2xl font-extrabold tabular-nums text-zinc-900">{{ $value }}</p>
                </div>
            @endforeach
        </div>

        {{-- Filter pills + bulk-retry action. The auto-retry job runs on the
             scheduler every 15 minutes, but the button gives the admin a way
             to flush ALL failed rows immediately after fixing the root cause
             (rate-limited provider, swapped API key, etc.). --}}
        <div class="flex flex-wrap items-center gap-3">
            <div class="inline-flex w-max items-center rounded-[12px] bg-zinc-100 p-1" role="tablist" aria-label="Filter notifications">
                @foreach (['all' => 'All', 'pending' => 'Pending', 'sent' => 'Sent', 'failed' => 'Failed'] as $value => $label)
                    <button
                        type="button"
                        wire:click="setFilter('{{ $value }}')"
                        @class([
                            'inline-flex items-center justify-center rounded-[12px] px-3.5 py-1.5 text-xs font-semibold transition-all',
                            'bg-white text-zinc-900 shadow-sm ring-1 ring-zinc-200' => $filter === $value,
                            'text-zinc-600 hover:text-zinc-900' => $filter !== $value,
                        ])
                    >
                        {{ $label }}
                    </button>
                @endforeach
            </div>

            @if (($this->metrics['failed_deliveries'] ?? 0) > 0)
                <button
                    type="button"
                    wire:click="retryAllFailed"
                    wire:loading.attr="disabled"
                    wire:target="retryAllFailed"
                    wire:confirm="Re-queue every failed notification? Each row is capped at {{ \App\Domain\Notification\Jobs\RetryFailedNotificationsJob::MAX_AUTO_RETRIES }} auto-retries."
                    class="inline-flex items-center gap-1.5 rounded-[12px] bg-blue-600 px-3.5 py-1.5 text-xs font-semibold text-white transition-colors hover:bg-blue-700 disabled:opacity-50"
                >
                    <svg wire:loading.remove wire:target="retryAllFailed" class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M16.023 9.348h4.992v-.001M2.985 19.644v-4.992m0 0h4.992m-4.993 0l3.181 3.183a8.25 8.25 0 0013.803-3.7M4.031 9.865a8.25 8.25 0 0113.803-3.7l3.181 3.182m0-4.991v4.99"/>
                    </svg>
                    <svg wire:loading wire:target="retryAllFailed" class="h-3.5 w-3.5 animate-spin" fill="none" viewBox="0 0 24 24" aria-hidden="true">
                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/>
                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z"/>
                    </svg>
                    <span wire:loading.remove wire:target="retryAllFailed">Retry all failed</span>
                    <span wire:loading wire:target="retryAllFailed">Retrying...</span>
                </button>
            @endif

            <span class="ml-auto text-[11px] text-zinc-500">
                Auto-retry runs every 15 min, capped at {{ \App\Domain\Notification\Jobs\RetryFailedNotificationsJob::MAX_AUTO_RETRIES }} attempts per notification.
            </span>
        </div>

        {{-- Notifications table --}}
        <div class="overflow-hidden rounded-[20px] bg-white shadow-sm shadow-zinc-900/5 ring-1 ring-zinc-100">
            <div class="overflow-x-auto [scrollbar-width:none] [&::-webkit-scrollbar]:hidden">
                <table class="w-full min-w-[720px] text-left text-sm">
                    <thead>
                        <tr class="border-b border-zinc-100 text-[11px] font-bold uppercase tracking-wide text-zinc-500">
                            <th class="px-5 py-3">Recipient</th>
                            <th class="px-5 py-3">Notification</th>
                            <th class="px-5 py-3">Channel</th>
                            <th class="px-5 py-3">Priority</th>
                            <th class="px-5 py-3">Status</th>
                            <th class="px-5 py-3">Created</th>
                            <th class="px-5 py-3 text-right">Action</th>
                        </tr>
                    </thead>
                    <tbody class="divide-inset">
                        @forelse ($notifications as $n)
                            <tr
                                wire:key="admin-note-{{ $n->id }}"
                                @click="open({ id: @js((string) $n->id), recipient: @js($n->user?->name ?? 'Unknown'), email: @js($n->user?->email ?? ''), title: @js($n->title), message: @js($n->message), channel: @js($n->channel->value), priority: @js($n->priority->value), status: @js($n->status->value), time: @js($n->created_at->format('M j, Y g:i A')) })"
                                class="cursor-pointer transition-colors hover:bg-zinc-50"
                            >
                                <td class="px-5 py-3.5">
                                    <p class="font-semibold text-zinc-900">{{ $n->user?->name ?? 'Unknown' }}</p>
                                    <p class="text-xs text-zinc-500">{{ $n->user?->email }}</p>
                                </td>
                                <td class="px-5 py-3.5">
                                    <p class="font-medium text-zinc-900">{{ $n->title }}</p>
                                    <p class="max-w-[280px] truncate text-xs text-zinc-500">{{ $n->message }}</p>
                                </td>
                                <td class="px-5 py-3.5 capitalize text-zinc-700">{{ $n->channel->value }}</td>
                                <td class="px-5 py-3.5">
                                    <span class="inline-flex items-center rounded-[5px] {{ $priorityTone[$n->priority->value] ?? 'bg-zinc-400' }} px-2 py-0.5 text-[10px] font-bold uppercase tracking-wide text-white">{{ $n->priority->value }}</span>
                                </td>
                                <td class="px-5 py-3.5">
                                    <span class="inline-flex items-center rounded-[5px] {{ $statusTone[$n->status->value] ?? 'bg-zinc-400' }} px-2 py-0.5 text-[10px] font-bold uppercase tracking-wide text-white">{{ $n->status->value }}</span>
                                </td>
                                <td class="px-5 py-3.5 text-zinc-600">{{ $n->created_at->format('M j, Y g:i A') }}</td>
                                <td class="px-5 py-3.5">
                                    <div class="flex items-center justify-end gap-2">
                                        @if ($n->status !== \App\Domain\Notification\Enums\DeliveryStatus::Sent)
                                            <button
                                                type="button"
                                                @click.stop
                                                wire:click="retry('{{ $n->id }}')"
                                                wire:loading.attr="disabled"
                                                wire:target="retry('{{ $n->id }}')"
                                                class="inline-flex items-center gap-1.5 rounded-[12px] bg-blue-600 px-3 py-1.5 text-xs font-semibold text-white transition-colors hover:bg-blue-700 disabled:opacity-50"
                                            >
                                                <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5" aria-hidden="true">
                                                    <path stroke-linecap="round" stroke-linejoin="round" d="M16.023 9.348h4.992v-.001M2.985 19.644v-4.992m0 0h4.992m-4.993 0l3.181 3.183a8.25 8.25 0 0013.803-3.7M4.031 9.865a8.25 8.25 0 0113.803-3.7l3.181 3.182m0-4.991v4.99"/>
                                                </svg>
                                                Retry
                                            </button>
                                        @endif

                                        {{-- Small delete button (5px radius). --}}
                                        <button
                                            type="button"
                                            @click.stop
                                            wire:click="delete('{{ $n->id }}')"
                                            data-confirm="Delete this notification from the log? This cannot be undone."
                                            data-confirm-title="Delete notification"
                                            data-confirm-text="Delete"
                                            data-confirm-tone="danger"
                                            class="inline-flex h-8 w-8 shrink-0 items-center justify-center rounded-[5px] text-zinc-400 transition-colors hover:bg-red-50 hover:text-red-600"
                                            aria-label="Delete notification"
                                            title="Delete"
                                        >
                                            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
                                                <path stroke-linecap="round" stroke-linejoin="round" d="M6 7h12M9 7V5a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v2m-7 0v12a1 1 0 0 0 1 1h6a1 1 0 0 0 1-1V7"/>
                                            </svg>
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="7" class="px-5 py-16 text-center">
                                    <p class="text-sm font-semibold text-zinc-900">No notifications</p>
                                    <p class="mt-1 text-xs text-zinc-500">Nothing matches this filter yet.</p>
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            @if ($notifications->hasPages())
                <div class="border-t border-zinc-100 px-5 py-3">
                    {{ $notifications->links() }}
                </div>
            @endif
        </div>

        <x-action-message on="notification-retried" class="text-sm font-medium text-emerald-600">
            Notification re-queued for delivery.
        </x-action-message>
        <x-action-message on="notification-deleted" class="text-sm font-medium text-emerald-600">
            Notification deleted.
        </x-action-message>
    </div>

    {{-- Slide-up detail sheet (opened by clicking a row). --}}
    <div
        x-show="show"
        x-transition:enter="transition-opacity ease-out duration-300"
        x-transition:enter-start="opacity-0"
        x-transition:enter-end="opacity-100"
        x-transition:leave="transition-opacity ease-in duration-200"
        x-transition:leave-start="opacity-100"
        x-transition:leave-end="opacity-0"
        @click="close()"
        style="display:none;"
        class="fixed inset-0 z-[60] bg-zinc-900/40"
    ></div>

    <div
        x-show="show"
        @keydown.escape.window="close()"
        style="display:none;"
        class="fixed inset-x-0 bottom-0 z-[70] flex justify-center"
        role="dialog"
        aria-modal="true"
    >
        <div
            x-show="show"
            x-transition:enter="transition ease-out duration-300"
            x-transition:enter-start="translate-y-full"
            x-transition:enter-end="translate-y-0"
            x-transition:leave="transition ease-in duration-200"
            x-transition:leave-start="translate-y-0"
            x-transition:leave-end="translate-y-full"
            class="w-full max-w-lg rounded-t-[20px] bg-white p-5 pb-7 shadow-2xl shadow-zinc-900/20"
        >
            <div class="mx-auto mb-4 h-1.5 w-10 rounded-[12px] bg-zinc-200"></div>

            <div class="flex items-start gap-2">
                <h2 class="flex-1 text-base font-bold text-zinc-900" x-text="note.title"></h2>
                <button type="button" @click="close()" class="-mr-1 -mt-1 flex h-8 w-8 shrink-0 items-center justify-center rounded-[5px] text-zinc-400 transition-colors hover:bg-zinc-100 hover:text-zinc-700" aria-label="Close">
                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12"/></svg>
                </button>
            </div>

            <div class="mt-1 flex flex-wrap items-center gap-x-3 gap-y-1 text-[11px] text-zinc-500">
                <span x-text="note.recipient"></span>
                <span x-show="note.email" class="text-zinc-400" x-text="note.email"></span>
            </div>

            <div class="mt-3 flex flex-wrap gap-2">
                <span class="inline-flex items-center rounded-[5px] bg-zinc-100 px-2 py-0.5 text-[10px] font-bold uppercase tracking-wide text-zinc-600" x-text="'Channel: ' + note.channel"></span>
                <span class="inline-flex items-center rounded-[5px] bg-zinc-100 px-2 py-0.5 text-[10px] font-bold uppercase tracking-wide text-zinc-600" x-text="'Priority: ' + note.priority"></span>
                <span class="inline-flex items-center rounded-[5px] bg-zinc-100 px-2 py-0.5 text-[10px] font-bold uppercase tracking-wide text-zinc-600" x-text="'Status: ' + note.status"></span>
            </div>

            <p class="mt-3 whitespace-pre-line text-sm leading-relaxed text-zinc-700" x-text="note.message"></p>
            <p class="mt-3 text-[11px] text-zinc-400" x-text="note.time"></p>

            <div class="mt-6 flex items-center gap-3">
                <button
                    type="button"
                    @click="$wire.delete(note.id); close()"
                    class="flex-1 rounded-[12px] px-4 py-2.5 text-sm font-semibold text-red-600 ring-1 ring-red-200 transition-colors hover:bg-red-50"
                >
                    Delete
                </button>
            </div>
        </div>
    </div>
</div>

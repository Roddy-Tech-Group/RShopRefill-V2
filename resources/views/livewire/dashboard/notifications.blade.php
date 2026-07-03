<?php

use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;
use Livewire\WithPagination;

new
#[Layout('components.layouts.dashboard')]
#[Title('Notifications')]
class extends Component {
    use WithPagination;

    /** Mark a single notification as read. */
    public function markRead(string $id): void
    {
        $notification = Auth::user()->notifications()->whereKey($id)->first();

        if ($notification && ! $notification->read_at) {
            $notification->markAsRead();
        }
    }

    /** Mark every unread notification as read. */
    public function markAllRead(): void
    {
        Auth::user()->notifications()->whereNull('read_at')->update(['read_at' => now()]);
    }

    /** Permanently delete a single notification. */
    public function deleteNotification(string $id): void
    {
        Auth::user()->notifications()->whereKey($id)->delete();
    }

    public function with(): array
    {
        return [
            'notifications' => Auth::user()->notifications()->latest()->paginate(15),
            'unreadCount' => Auth::user()->notifications()->whereNull('read_at')->count(),
        ];
    }
}; ?>

@php
    // Priority -> accent colour for the notification icon tile.
    $toneColor = fn ($priority) => match (is_object($priority) ? $priority->value : $priority) {
        'critical' => 'bg-red-500',
        'high'     => 'bg-amber-500',
        default    => 'bg-blue-600',
    };
@endphp

<div
    class="flex w-full flex-col gap-5"
    x-data="{
        show: false,
        note: { id: null, title: '', message: '', time: '', read: false },
        open(data) { this.note = data; this.show = true; },
        close() { this.show = false; },
    }"
>

    {{-- Heading — H1 desktop only (mobile uses the layout's slim top bar); the
         "Mark all read" button keeps showing on mobile, pushed right via ml-auto. --}}
    <div class="flex items-center justify-between gap-3">
        <h1 class="hidden text-xl font-bold tracking-tight text-zinc-900 sm:text-3xl lg:block">Notifications</h1>
        @if ($unreadCount > 0)
            <button
                type="button"
                wire:click="markAllRead"
                class="ml-auto shrink-0 rounded-[6px] bg-blue-600 px-3.5 py-2 text-xs font-semibold text-white transition-colors hover:bg-blue-700"
            >
                Mark all read
            </button>
        @endif
    </div>

    @if ($notifications->isNotEmpty())
        <div class="divide-y divide-zinc-200 overflow-hidden rounded-[12px] border border-zinc-200 dark:border-zinc-700">
            @foreach ($notifications as $note)
                <div
                    wire:key="note-{{ $note->id }}"
                    class="group relative flex items-stretch transition-colors hover:bg-zinc-50 dark:hover:bg-white/5 {{ $note->read_at ? '' : 'bg-blue-50/50 dark:bg-blue-500/10' }}"
                >
                    {{-- Clickable area — opens the detail sheet. --}}
                    <button
                        type="button"
                        @click="open({ id: @js((string) $note->id), title: @js($note->title), message: @js($note->message), time: @js($note->created_at->diffForHumans()), read: @js((bool) $note->read_at) })"
                        class="flex min-w-0 flex-1 items-start gap-3 px-4 py-3.5 text-left"
                    >
                        {{-- Priority icon tile --}}
                        <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-[8px] {{ $toneColor($note->priority) }}">
                            <img src="{{ asset('assets/' . rawurlencode('notification 2.svg')) }}" alt="" class="h-5 w-5 brightness-0 invert" loading="lazy">
                        </span>

                        <div class="min-w-0 flex-1">
                            <div class="flex items-center gap-2">
                                <p class="truncate text-sm font-bold text-zinc-900">{{ $note->title }}</p>
                                @unless ($note->read_at)
                                    <span class="h-2 w-2 shrink-0 rounded-[12px] bg-blue-600" aria-label="Unread"></span>
                                @endunless
                            </div>
                            <p class="mt-0.5 line-clamp-2 text-xs leading-relaxed text-zinc-600">{{ $note->message }}</p>
                            <p class="mt-1 text-[11px] text-zinc-400">{{ $note->created_at->diffForHumans() }}</p>
                        </div>
                    </button>

                    {{-- Small delete button, pinned to the right (5px radius). --}}
                    <button
                        type="button"
                        wire:click="deleteNotification('{{ $note->id }}')"
                        data-confirm="Delete this notification? This cannot be undone."
                        data-confirm-title="Delete notification"
                        data-confirm-text="Delete"
                        data-confirm-tone="danger"
                        class="m-2 flex h-8 w-8 shrink-0 items-center justify-center self-center rounded-full text-zinc-400 ring-1 ring-zinc-200 transition-colors hover:bg-red-50 hover:text-red-600 hover:ring-red-200 dark:ring-zinc-700 dark:hover:bg-red-500/15 dark:hover:ring-red-500/30"
                        aria-label="Delete notification"
                        title="Delete"
                    >
                        <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/>
                        </svg>
                    </button>
                </div>
            @endforeach
        </div>

        @if ($notifications->hasPages())
            <div>{{ $notifications->links() }}</div>
        @endif
    @else
        {{-- Empty state --}}
        <div class="rounded-[12px] bg-white px-6 py-16 text-center ring-1 ring-zinc-200">
            <span class="mx-auto flex h-14 w-14 items-center justify-center rounded-[12px] bg-blue-50">
                <img src="{{ asset('assets/' . rawurlencode('notification 2.svg')) }}" alt="" class="h-7 w-7" style="filter: brightness(0) saturate(100%) invert(40%) sepia(95%) saturate(1500%) hue-rotate(205deg);" loading="lazy">
            </span>
            <p class="mt-4 text-base font-semibold text-zinc-900">You're all caught up</p>
            <p class="mt-1 text-sm text-zinc-600">Order updates, wallet activity and security alerts will show up here.</p>
        </div>
    @endif

    {{-- Slide-up detail sheet. Backdrop sits below; the sheet slides up from the
         bottom edge. Mark as read / Delete call straight through to Livewire. --}}
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
            <p class="mt-1 text-[11px] text-zinc-400" x-text="note.time"></p>
            <p class="mt-3 whitespace-pre-line text-sm leading-relaxed text-zinc-700" x-text="note.message"></p>

            <div class="mt-6 flex items-center gap-3">
                <button
                    type="button"
                    x-show="! note.read"
                    @click="$wire.markRead(note.id); note.read = true"
                    class="flex-1 rounded-[12px] bg-blue-600 px-4 py-2.5 text-sm font-semibold text-white transition-colors hover:bg-blue-700"
                >
                    Mark as read
                </button>
                <button
                    type="button"
                    @click="$wire.deleteNotification(note.id); close()"
                    class="rounded-[12px] px-4 py-2.5 text-sm font-semibold text-red-600 ring-1 ring-red-200 transition-colors hover:bg-red-50"
                    :class="note.read ? 'flex-1' : ''"
                >
                    Delete
                </button>
            </div>
        </div>
    </div>
</div>

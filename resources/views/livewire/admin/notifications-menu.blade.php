<?php

use App\Models\AdminNotification;
use Livewire\Attributes\Computed;
use Livewire\Volt\Component;

/**
 * Admin notification bell + dropdown. Reads the shared admin_notifications feed
 * (KYC submissions, etc.). Embedded in the admin layout top bar.
 */
new class extends Component {
    /** Most recent notifications shown in the panel. */
    #[Computed]
    public function items()
    {
        return AdminNotification::latest()->limit(15)->get();
    }

    /** Unread badge count. */
    #[Computed]
    public function unreadCount(): int
    {
        return AdminNotification::whereNull('read_at')->count();
    }

    /** Mark one read, then go to its target page. */
    public function open(int $id)
    {
        $notification = AdminNotification::find($id);

        if (! $notification) {
            return null;
        }

        if (! $notification->read_at) {
            $notification->update(['read_at' => now()]);
        }

        return redirect($notification->url ?: route('admin.dashboard'));
    }

    public function markAllRead(): void
    {
        AdminNotification::whereNull('read_at')->update(['read_at' => now()]);
        unset($this->items, $this->unreadCount);
    }
}; ?>

<div
    x-data="{ open: false }"
    @click.outside="open = false"
    @keydown.escape.window="open = false"
    class="relative"
>
    {{-- Bell --}}
    <button
        type="button"
        @click="open = ! open"
        :aria-expanded="open.toString()"
        aria-label="Notifications"
        class="relative flex h-11 w-11 items-center justify-center rounded-[12px] text-zinc-600 transition-colors hover:bg-blue-100"
    >
        <img src="{{ asset('assets/'.rawurlencode('notification 2.svg')) }}" alt="" class="h-6 w-6" loading="lazy">
        @if ($this->unreadCount > 0)
            <span class="pointer-events-none absolute -top-0.5 -right-0.5 inline-flex">
                <span class="absolute inset-0 inline-flex animate-ping rounded-[12px] bg-red-400 opacity-75"></span>
                <span class="relative inline-flex h-5 min-w-[20px] items-center justify-center rounded-[12px] bg-red-500 px-1 text-[10px] font-bold text-white">{{ $this->unreadCount > 99 ? '99+' : $this->unreadCount }}</span>
            </span>
        @endif
    </button>

    {{-- Dropdown --}}
    <div
        x-show="open"
        x-cloak
        x-transition:enter="transition ease-out duration-150"
        x-transition:enter-start="opacity-0 -translate-y-1"
        x-transition:enter-end="opacity-100 translate-y-0"
        x-transition:leave="transition ease-in duration-100"
        x-transition:leave-end="opacity-0 -translate-y-1"
        class="absolute right-0 top-full z-50 mt-2 w-[340px] max-w-[calc(100vw-2rem)] overflow-hidden rounded-[12px] bg-white shadow-xl shadow-zinc-900/10 ring-1 ring-zinc-200"
        role="menu"
    >
        <div class="flex items-center justify-between border-b border-zinc-100 px-4 py-3">
            <div class="flex items-center gap-3">
                <p class="text-sm font-bold text-zinc-900">Notifications</p>
                <button
                    x-data="{ pushEnabled: false }"
                    x-init="navigator.serviceWorker.ready.then(reg => reg.pushManager.getSubscription()).then(sub => pushEnabled = !!sub);"
                    @click="pushEnabled ? window.RshopPush?.unsubscribe().then(() => pushEnabled = false) : window.RshopPush?.subscribe().then(res => pushEnabled = res)"
                    type="button"
                    class="relative inline-flex h-4 w-7 shrink-0 cursor-pointer rounded-full transition-colors duration-200 ease-in-out focus:outline-none"
                    :class="pushEnabled ? 'bg-blue-600' : 'bg-zinc-200'"
                    title="Toggle Web Push"
                >
                    <span class="pointer-events-none inline-block h-4 w-4 transform rounded-full bg-white shadow ring-0 transition duration-200 ease-in-out" :class="pushEnabled ? 'translate-x-3' : 'translate-x-0'"></span>
                </button>
            </div>
            @if ($this->unreadCount > 0)
                <button type="button" wire:click="markAllRead" class="text-xs font-semibold text-blue-600 transition-colors hover:text-blue-700">Mark all read</button>
            @endif
        </div>

        <div class="max-h-80 overflow-y-auto [scrollbar-width:thin]">
            @forelse ($this->items as $n)
                <button
                    type="button"
                    wire:click="open({{ $n->id }})"
                    class="flex w-full items-start gap-3 px-4 py-3 text-left transition-colors hover:bg-zinc-50 dark:hover:bg-white/5 {{ $n->read_at ? '' : 'bg-blue-50/60 dark:bg-blue-500/10' }}"
                    role="menuitem"
                >
                    <span class="mt-0.5 flex h-9 w-9 shrink-0 items-center justify-center rounded-[12px] bg-blue-600">
                        <svg class="h-4.5 w-4.5 text-white" style="height:1.125rem;width:1.125rem;" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75L11.25 15 15 9.75M21 12c0 1.268-.63 2.39-1.593 3.068a3.745 3.745 0 01-1.043 3.296 3.745 3.745 0 01-3.296 1.043A3.745 3.745 0 0112 21c-1.268 0-2.39-.63-3.068-1.593a3.746 3.746 0 01-3.296-1.043 3.745 3.745 0 01-1.043-3.296A3.745 3.745 0 013 12c0-1.268.63-2.39 1.593-3.068a3.745 3.745 0 011.043-3.296 3.746 3.746 0 013.296-1.043A3.746 3.746 0 0112 3c1.268 0 2.39.63 3.068 1.593a3.746 3.746 0 013.296 1.043 3.746 3.746 0 011.043 3.296A3.745 3.745 0 0121 12z"/>
                        </svg>
                    </span>
                    <span class="min-w-0 flex-1">
                        <span class="flex items-start justify-between gap-2">
                            <span class="truncate text-sm font-semibold text-zinc-900">{{ $n->title }}</span>
                            @unless ($n->read_at)
                                <span class="mt-1 h-2 w-2 shrink-0 rounded-[12px] bg-blue-600"></span>
                            @endunless
                        </span>
                        <span class="mt-0.5 block text-xs leading-snug text-zinc-600 line-clamp-2">{{ $n->message }}</span>
                        <span class="mt-1 block text-[11px] text-zinc-400">{{ $n->created_at->diffForHumans() }}</span>
                    </span>
                </button>
            @empty
                <div class="px-4 py-12 text-center">
                    <span class="mx-auto flex h-12 w-12 items-center justify-center rounded-[12px] bg-zinc-100 text-zinc-400">
                        <img src="{{ asset('assets/'.rawurlencode('notification 2.svg')) }}" alt="" class="h-6 w-6 opacity-40" loading="lazy">
                    </span>
                    <p class="mt-3 text-sm font-semibold text-zinc-900">You're all caught up</p>
                    <p class="mt-0.5 text-xs text-zinc-600">New notifications will appear here.</p>
                </div>
            @endforelse
        </div>
    </div>
</div>

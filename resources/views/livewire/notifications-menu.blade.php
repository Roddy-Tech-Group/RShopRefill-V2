<?php

use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Volt\Component;

/**
 * Customer notification bell + dropdown panel. Embedded in the dashboard layout
 * via <livewire:notifications-menu />. Session-authenticated (web guard) — it
 * queries the Notification model directly, no API token needed.
 *
 * `tone` adapts the bell colour to its background: 'light' for the blue mobile
 * hero, 'dark' for the white inner top bar.
 */
new class extends Component {
    /** 'dark' (on white) or 'light' (on the blue hero). */
    public string $tone = 'dark';

    /** Most recent notifications shown in the panel. */
    #[Computed]
    public function items()
    {
        return Auth::user()
            ->notifications()
            ->latest()
            ->limit(20)
            ->get();
    }

    /** Unread badge count. */
    #[Computed]
    public function unreadCount(): int
    {
        return Auth::user()->notifications()->whereNull('read_at')->count();
    }

    public function markRead(string $id): void
    {
        $notification = Auth::user()->notifications()->whereKey($id)->first();

        if ($notification && ! $notification->read_at) {
            $notification->markAsRead();
            unset($this->items, $this->unreadCount);
        }
    }

    public function markAllRead(): void
    {
        Auth::user()->notifications()->whereNull('read_at')->update(['read_at' => now()]);
        unset($this->items, $this->unreadCount);
    }
}; ?>

@php
    // Priority -> the icon tile's tint (light fill, coloured glyph).
    $tileTone = fn ($priority) => match ($priority?->value) {
        'critical' => 'bg-red-500/10 text-red-600 dark:bg-red-500/15 dark:text-red-400',
        'high'     => 'bg-amber-500/10 text-amber-600 dark:bg-amber-500/15 dark:text-amber-400',
        default    => 'bg-blue-600/10 text-blue-600 dark:bg-blue-500/15 dark:text-blue-300',
    };
    // Category (the row's type) -> glyph. Keyword match, so a new category
    // still gets a sensible icon; the bell is the fallback.
    $glyphFor = function (?string $type): string {
        $t = strtolower((string) $type);

        return match (true) {
            str_contains($t, 'order') || str_contains($t, 'purchase') || str_contains($t, 'gift') || str_contains($t, 'esim') || str_contains($t, 'airtime') || str_contains($t, 'bill') || str_contains($t, 'receipt') || str_contains($t, 'refund') || str_contains($t, 'invoice') => 'M15.75 10.5V6a3.75 3.75 0 10-7.5 0v4.5m11.356-1.993l1.263 12c.07.665-.45 1.243-1.119 1.243H4.25a1.125 1.125 0 01-1.12-1.243l1.264-12A1.125 1.125 0 015.513 7.5h12.974c.576 0 1.059.435 1.119 1.007z',
            str_contains($t, 'wallet') || str_contains($t, 'fund') || str_contains($t, 'payment') || str_contains($t, 'transaction') || str_contains($t, 'cashback') || str_contains($t, 'rcoin') || str_contains($t, 'fx_') || str_contains($t, 'checkout') => 'M21 12a2.25 2.25 0 00-2.25-2.25H15a3 3 0 11-6 0H5.25A2.25 2.25 0 003 12m18 0v6a2.25 2.25 0 01-2.25 2.25H5.25A2.25 2.25 0 013 18v-6m18 0V9M3 12V9m18 0a2.25 2.25 0 00-2.25-2.25H5.25A2.25 2.25 0 003 9m18 0V6a2.25 2.25 0 00-2.25-2.25H5.25A2.25 2.25 0 003 6v3',
            str_contains($t, 'security') || str_contains($t, 'login') || str_contains($t, 'otp') || str_contains($t, 'password') || str_contains($t, 'auth') || str_contains($t, 'device') || str_contains($t, 'pin_') || str_contains($t, 'two_factor') || str_contains($t, 'kyc') || str_contains($t, 'account') => 'M9 12.75L11.25 15 15 9.75m-3-7.036A11.959 11.959 0 013.598 6 11.99 11.99 0 003 9.749c0 5.592 3.824 10.29 9 11.623 5.176-1.332 9-6.03 9-11.622 0-1.31-.21-2.571-.598-3.751h-.152c-3.196 0-6.1-1.248-8.25-3.285z',
            str_contains($t, 'market') || str_contains($t, 'promo') || str_contains($t, 'campaign') || str_contains($t, 'offer') || str_contains($t, 'newsletter') || str_contains($t, 'referral') || str_contains($t, 'announcement') || str_contains($t, 'product_update') => 'M10.34 15.84c-.688-.06-1.386-.09-2.09-.09H7.5a4.5 4.5 0 110-9h.75c.704 0 1.402-.03 2.09-.09m0 9.18c.253.962.584 1.892.985 2.783.247.55.06 1.21-.463 1.511l-.657.38c-.551.318-1.26.117-1.527-.461a20.845 20.845 0 01-1.44-4.282m3.102.069a18.03 18.03 0 01-.59-4.59c0-1.586.205-3.124.59-4.59m0 9.18a23.848 23.848 0 018.835 2.535M10.34 6.66a23.847 23.847 0 008.835-2.535m0 0A23.74 23.74 0 0018.795 3m.38 1.125a23.91 23.91 0 011.014 5.395m-1.014 8.855c-.118.38-.245.754-.38 1.125m.38-1.125a23.91 23.91 0 001.014-5.395m0-3.46c.495.413.811 1.035.811 1.73 0 .695-.316 1.317-.811 1.73m0-3.46a24.347 24.347 0 010 3.46',
            default => 'M14.857 17.082a23.848 23.848 0 005.454-1.31A8.967 8.967 0 0118 9.75V9A6 6 0 006 9v.75a8.967 8.967 0 01-2.312 6.022c1.733.64 3.56 1.085 5.455 1.31m5.714 0a24.255 24.255 0 01-5.714 0m5.714 0a3 3 0 11-5.714 0',
        };
    };
    // A row's action: metadata.url (what campaigns and pushes carry) with its
    // label, if the sender gave one. Same-origin links navigate in place.
    $actionFor = function ($n): ?array {
        $meta = (array) ($n->metadata ?? []);
        $url = $meta['url'] ?? null;
        if (! is_string($url) || $url === '') {
            return null;
        }
        $label = $meta['cta'] ?? $meta['action_label'] ?? $meta['label'] ?? 'View';
        $internal = str_starts_with($url, '/') || str_starts_with($url, rtrim((string) config('app.url'), '/'));

        return ['url' => $url, 'label' => (string) $label, 'internal' => $internal];
    };
@endphp

{{-- Keep the bell reachable even when the inbox is empty. The unread badge
     continues to appear only when there are unread notifications. --}}
<div
    x-data="{ open: false }"
    @click.outside="open = false"
    @keydown.escape.window="open = false"
    class="relative"
>
    {{-- Bell button --}}
    <button
        type="button"
        @click="open = !open"
        :aria-expanded="open.toString()"
        aria-label="Notifications"
        @class([
            'relative flex shrink-0 items-center justify-center transition-colors active:scale-95',
            'dashboard-notification-chip' => $tone === 'chip',
            'h-10 w-10' => $tone !== 'chip',
            'rounded-full bg-blue-100 ring-2 ring-blue-200 text-blue-700 hover:bg-blue-200 dark:bg-white/15 dark:ring-white/30 dark:text-white dark:hover:bg-white/25' => $tone === 'light',
            'rounded-full text-zinc-700 hover:bg-zinc-100' => $tone === 'dark',
            // 'chip' is the dashboard top bar's round outlined button (white on the blue bar), matching the cart beside it.
            'h-9 w-9 rounded-full border-2 border-white/30 text-white hover:bg-white/15 dark:border-white/10 dark:text-zinc-200 dark:hover:bg-white/5 focus:outline-none focus-visible:ring-2 focus-visible:ring-white/60' => $tone === 'chip',
        ])
    >
        @if ($tone === 'chip')
            {{-- Drawn inline, not masked: a mask can only ever produce a
                 silhouette of the source file, and the reference's bell is an
                 outline. currentColor keeps it on the button's own ink. --}}
            <svg class="h-6 w-6 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                <path d="M18 8.5a6 6 0 1 0-12 0c0 4.2-1.2 5.9-1.9 6.6a.8.8 0 0 0 .55 1.4h14.7a.8.8 0 0 0 .55-1.4c-.7-.7-1.9-2.4-1.9-6.6Z"/>
                <path d="M10.2 19.5a2.1 2.1 0 0 0 3.6 0"/>
            </svg>
        @else
            <img
                src="{{ asset('assets/'.rawurlencode('notification 2.svg')) }}"
                alt=""
                @class([
                    'h-6 w-6 shrink-0',
                    'brightness-0 dark:invert' => $tone === 'light',
                    'brightness-0' => $tone === 'dark',
                ])
            >
        @endif
        @if ($this->unreadCount > 0)
            {{-- The count sits straight on the bell. It carries no ring: a pale
                 one read as a white border around the red on the dark header. --}}
            <span class="absolute inline-flex items-center justify-center rounded-full bg-red-500 px-1 font-bold text-white {{ $tone === 'chip' ? '-top-1 -right-1 h-4 min-w-[16px] text-[9px]' : '-top-0.5 -right-0.5 h-5 min-w-[20px] text-[10px]' }}">
                {{ $this->unreadCount > 99 ? '99+' : $this->unreadCount }}
            </span>
        @endif
    </button>

    {{-- Backdrop (phones only): the panel is a bottom sheet there, and this
         dims the page behind it. Tapping it closes the sheet. --}}
    <div
        x-show="open"
        x-cloak
        x-transition.opacity.duration.200ms
        @click="open = false"
        class="dashboard-notification-backdrop fixed inset-0 z-40 bg-zinc-900/45 lg:hidden"
        aria-hidden="true"
    ></div>

    {{-- The panel. Desktop: the frame pull-down beside the other three, one
         card row per notification (icon tile, title, message, its action,
         a Read pill). Phones: the same rows as a bottom sheet sliding up
         under a handle (app.css: .dashboard-notification-panel in the phone
         block). The enter/leave states are classes, not utilities, so the
         same markup fades down on desktop and slides up on phones. --}}
    <div
        x-show="open"
        x-cloak
        x-transition:enter="transition ease-out duration-150 dash-popup-motion"
        x-transition:enter-start="dash-popup-hidden"
        x-transition:enter-end="dash-popup-shown"
        x-transition:leave="transition ease-in duration-100 dash-popup-motion"
        x-transition:leave-start="dash-popup-shown"
        x-transition:leave-end="dash-popup-hidden"
        {{-- Same surface as the search, cart and account pull-downs: 12px, frosted
             white, pinned inside the frame on desktop by dashboard-frame-popup.
             The edge is the border app.css gives every dashboard popup, so no
             ring on top of it. --}}
        class="dashboard-frame-popup dashboard-notification-panel absolute right-0 top-full z-50 mt-3 flex w-[440px] max-w-[calc(100vw-2rem)] flex-col overflow-hidden rounded-[12px] bg-white/80 backdrop-blur-xl shadow-xl shadow-zinc-900/15"
        role="menu"
        aria-label="Notifications"
    >
        {{-- Sheet handle (phones) --}}
        <div class="flex shrink-0 justify-center pt-2.5 lg:hidden" aria-hidden="true">
            <span class="h-1.5 w-12 rounded-full bg-zinc-300 dark:bg-white/25"></span>
        </div>

        {{-- Header --}}
        <div class="flex shrink-0 items-center justify-between gap-3 px-5 pb-2 pt-4">
            <h3 class="text-[18px] font-semibold text-zinc-900 dark:text-white">Notifications</h3>
            @if ($this->unreadCount > 0)
                <span class="inline-flex items-center rounded-[5px] bg-blue-600 px-1.5 py-0.5 text-[10px] font-bold text-white">{{ $this->unreadCount }} new</span>
            @endif
        </div>

        {{-- List: one card row per notification, hairlines between --}}
        <div class="dashboard-notification-list min-h-0 flex-1 overflow-y-auto lg:max-h-[60vh]">
            @forelse ($this->items as $n)
                @php $action = $actionFor($n); @endphp
                <div class="dashboard-notification-row flex items-start gap-3 px-5 py-4 {{ $n->read_at ? 'dashboard-notification-row-read' : '' }}" role="menuitem">
                    <span class="mt-0.5 flex h-10 w-10 shrink-0 items-center justify-center rounded-[10px] {{ $tileTone($n->priority) }}">
                        <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" d="{{ $glyphFor($n->type) }}"/>
                        </svg>
                    </span>
                    <div class="min-w-0 flex-1">
                        <div class="flex items-start justify-between gap-3">
                            <p class="text-[15px] font-semibold leading-snug text-zinc-900 dark:text-white">{{ $n->title }}</p>
                            @unless ($n->read_at)
                                <button
                                    type="button"
                                    wire:click="markRead('{{ $n->id }}')"
                                    class="inline-flex shrink-0 items-center gap-1 rounded-full bg-zinc-100 px-3 py-1.5 text-[13px] font-semibold text-zinc-700 transition-colors hover:bg-zinc-200 dark:bg-white/10 dark:text-zinc-200 dark:hover:bg-white/15"
                                    aria-label="Mark as read"
                                >
                                    <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5"/></svg>
                                    Read
                                </button>
                            @endunless
                        </div>
                        <p class="mt-1 text-[14px] leading-snug text-zinc-600 dark:text-zinc-400">{{ $n->message }}</p>
                        <div class="mt-3 flex flex-wrap items-center gap-x-3 gap-y-2">
                            @if ($action)
                                <a
                                    href="{{ $action['url'] }}"
                                    @if ($action['internal']) wire:navigate.hover @click="open = false" @else target="_blank" rel="noopener" @endif
                                    class="inline-flex items-center rounded-full bg-blue-600 px-4 py-2 text-[13px] font-semibold text-white transition-colors hover:bg-blue-700"
                                >{{ $action['label'] }}</a>
                            @endif
                            <span class="text-[12px] text-zinc-400 dark:text-zinc-500">{{ $n->created_at->diffForHumans() }}</span>
                        </div>
                    </div>
                </div>
            @empty
                <div class="px-4 py-12 text-center">
                    <span class="mx-auto flex h-12 w-12 items-center justify-center rounded-[12px] bg-zinc-100 text-zinc-400 dark:bg-white/10 dark:text-zinc-500">
                        <svg class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M14.857 17.082a23.848 23.848 0 005.454-1.31A8.967 8.967 0 0118 9.75V9A6 6 0 006 9v.75a8.967 8.967 0 01-2.312 6.022c1.733.64 3.56 1.085 5.455 1.31m5.714 0a24.255 24.255 0 01-5.714 0m5.714 0a3 3 0 11-5.714 0"/>
                        </svg>
                    </span>
                    <p class="mt-3 text-sm font-semibold text-zinc-900 dark:text-white">You're all caught up</p>
                    <p class="mt-0.5 text-xs text-zinc-600 dark:text-zinc-400">New notifications will appear here.</p>
                </div>
            @endforelse
        </div>

        {{-- Mark all read - blue pill at the bottom of the panel. --}}
        @if ($this->unreadCount > 0)
            <div class="shrink-0 px-4 pb-4 pt-2">
                <button type="button" wire:click="markAllRead" class="w-full rounded-full bg-blue-600 px-4 py-2.5 text-[13px] font-semibold text-white transition-colors hover:bg-blue-700">
                    Mark all read
                </button>
            </div>
        @endif
    </div>
</div>

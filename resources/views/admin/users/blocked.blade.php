@php
    use App\Models\User;

    // One moderation surface: every account that is banned, suspended or in
    // Protected Mode, newest action first. Short by nature; no pagination
    // until it earns one.
    $blocked = User::query()
        ->where(fn ($q) => $q->whereNotNull('banned_at')->orWhereNotNull('suspended_at')->orWhereNotNull('restricted_at'))
        ->limit(200)
        ->get()
        // Newest action first, whichever kind it was. Sorted in PHP: the list
        // is short by nature and GREATEST() does not exist on SQLite.
        ->sortByDesc(fn (User $user) => max(
            $user->banned_at?->timestamp ?? 0,
            $user->suspended_at?->timestamp ?? 0,
            $user->restricted_at?->timestamp ?? 0,
        ))
        ->values();

    $fmtDate = fn ($d) => $d?->format('M j, Y · g:i A');
@endphp

<x-layouts.admin>
    <x-slot:heading>Blocked &amp; Restricted</x-slot:heading>
    <x-slot:subheading>
        {{ $blocked->count() }} account(s) currently banned, suspended or in Protected Mode.
    </x-slot:subheading>

    <div class="overflow-hidden rounded-[12px] bg-white shadow-sm shadow-zinc-900/[0.04] ring-1 ring-zinc-200 dark:bg-[#1d3252] dark:ring-white/10">
        @forelse ($blocked as $user)
            <div class="flex flex-wrap items-center justify-between gap-3 border-b border-zinc-100 px-5 py-4 last:border-0 dark:border-white/10">
                <div class="min-w-0">
                    <a href="{{ route('admin.customer', $user) }}" wire:navigate class="text-sm font-semibold text-zinc-900 hover:underline dark:text-white">{{ $user->name }}</a>
                    <p class="truncate text-xs text-zinc-500 dark:text-zinc-400">{{ $user->email }}</p>
                    @if ($user->suspension_reason)
                        <p class="mt-1 max-w-xl text-xs text-zinc-600 dark:text-zinc-300">Reason: {{ $user->suspension_reason }}</p>
                    @endif
                </div>
                <div class="flex flex-wrap items-center gap-1.5">
                    @if ($user->banned_at)
                        <x-admin.badge tone="red">Banned {{ $fmtDate($user->banned_at) }}</x-admin.badge>
                    @endif
                    @if ($user->suspended_at)
                        <x-admin.badge tone="amber">Suspended {{ $fmtDate($user->suspended_at) }}</x-admin.badge>
                    @endif
                    @if ($user->restricted_at)
                        <x-admin.badge tone="amber">Protected Mode {{ $fmtDate($user->restricted_at) }}</x-admin.badge>
                    @endif
                    <a href="{{ route('admin.customer', $user) }}" wire:navigate class="rounded-[10px] border border-zinc-200 bg-white px-3 py-1.5 text-xs font-semibold text-zinc-700 transition-colors hover:bg-zinc-50 dark:border-zinc-700/60 dark:bg-[#26416b] dark:text-zinc-200 dark:hover:bg-[#2d4a7a]">
                        Review
                    </a>
                </div>
            </div>
        @empty
            <p class="px-5 py-10 text-center text-sm text-zinc-500 dark:text-zinc-400">Nobody is blocked or restricted right now.</p>
        @endforelse
    </div>
</x-layouts.admin>

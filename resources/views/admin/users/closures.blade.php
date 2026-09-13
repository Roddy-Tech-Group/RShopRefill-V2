@php
    use App\Models\AccountClosureFeedback;
    use App\Models\ClosedAccountIdentity;
    use App\Models\User;

    // The one page for the closure lifecycle: the health tiles up top, and the
    // only actionable list (grace-period closures, cancellable) below. Closed
    // accounts are anonymised, so there is nothing per-row worth listing.
    $pending = User::query()
        ->whereNotNull('closure_requested_at')
        ->whereNull('anonymised_at')
        ->orderBy('closure_requested_at')
        ->get();

    $closedTotal = User::whereNotNull('anonymised_at')->count();
    $lastAnonymised = User::whereNotNull('anonymised_at')->max('anonymised_at');
    $identitiesHeld = ClosedAccountIdentity::count();
    $nextIdentityPurge = ClosedAccountIdentity::min('retain_until');
    $feedbackTotal = AccountClosureFeedback::count();

    $fmtDate = fn ($d) => $d ? \Illuminate\Support\Carbon::parse($d)->format('M j, Y') : 'never';
@endphp

<x-layouts.admin>
    <x-slot:heading>Account Closures</x-slot:heading>
    <x-slot:subheading>
        Closure requests at a glance.
    </x-slot:subheading>

    <div class="space-y-6">
        <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
            <div class="rounded-[12px] bg-white p-5 ring-1 ring-zinc-200 dark:bg-[#1d3252] dark:ring-white/10">
                <p class="text-xs font-semibold uppercase tracking-wider text-zinc-500 dark:text-zinc-400">Closures in grace period</p>
                <p class="mt-1 text-2xl font-bold tabular-nums text-zinc-900 dark:text-white">{{ number_format($pending->count()) }}</p>
            </div>
            <div class="rounded-[12px] bg-white p-5 ring-1 ring-zinc-200 dark:bg-[#1d3252] dark:ring-white/10">
                <p class="text-xs font-semibold uppercase tracking-wider text-zinc-500 dark:text-zinc-400">Accounts closed all-time</p>
                <p class="mt-1 text-2xl font-bold tabular-nums text-zinc-900 dark:text-white">{{ number_format($closedTotal) }}</p>
                <p class="mt-1 text-xs text-zinc-400">last: {{ $fmtDate($lastAnonymised) }}</p>
            </div>
            <div class="rounded-[12px] bg-white p-5 ring-1 ring-zinc-200 dark:bg-[#1d3252] dark:ring-white/10">
                <p class="text-xs font-semibold uppercase tracking-wider text-zinc-500 dark:text-zinc-400">Identities in compliance hold</p>
                <p class="mt-1 text-2xl font-bold tabular-nums text-zinc-900 dark:text-white">{{ number_format($identitiesHeld) }}</p>
                <p class="mt-1 text-xs text-zinc-400">first purge due: {{ $fmtDate($nextIdentityPurge) }}</p>
            </div>
            <div class="rounded-[12px] bg-white p-5 ring-1 ring-zinc-200 dark:bg-[#1d3252] dark:ring-white/10">
                <p class="text-xs font-semibold uppercase tracking-wider text-zinc-500 dark:text-zinc-400">Exit feedback collected</p>
                <p class="mt-1 text-2xl font-bold tabular-nums text-zinc-900 dark:text-white">{{ number_format($feedbackTotal) }}</p>
            </div>
        </div>

        <div class="overflow-hidden rounded-[12px] bg-white shadow-sm shadow-zinc-900/[0.04] ring-1 ring-zinc-200 dark:bg-[#1d3252] dark:ring-white/10">
            <h2 class="border-b border-zinc-100 px-5 py-3 text-xs font-bold uppercase tracking-wider text-zinc-500 dark:border-white/10 dark:text-zinc-400">Pending Closure</h2>
            @forelse ($pending as $user)
                <div class="flex flex-wrap items-center justify-between gap-3 border-b border-zinc-100 px-5 py-4 last:border-0 dark:border-white/10">
                    <div class="min-w-0">
                        <a href="{{ route('admin.customer', $user) }}" wire:navigate class="text-sm font-semibold text-zinc-900 hover:underline dark:text-white">{{ $user->name }}</a>
                        <p class="truncate text-xs text-zinc-500 dark:text-zinc-400">{{ $user->email }}</p>
                    </div>
                    <div class="flex flex-wrap items-center gap-2">
                        <x-admin.badge tone="amber">Requested {{ $fmtDate($user->closure_requested_at) }}</x-admin.badge>
                        <x-admin.badge tone="zinc">Anonymises {{ $fmtDate($user->closesOn()) }}</x-admin.badge>
                        <form method="POST" action="{{ route('admin.customer.close', $user) }}"
                              data-confirm="Cancel the pending closure? The customer keeps their account."
                              data-confirm-title="Cancel closure"
                              data-confirm-text="Cancel closure"
                              data-confirm-tone="success">
                            @csrf
                            <button type="submit" class="rounded-[10px] bg-emerald-50 px-3 py-1.5 text-xs font-semibold text-emerald-700 ring-1 ring-emerald-200 transition-colors hover:bg-emerald-100">
                                Cancel closure
                            </button>
                        </form>
                    </div>
                </div>
            @empty
                <p class="px-5 py-8 text-center text-sm text-zinc-500 dark:text-zinc-400">No closures pending.</p>
            @endforelse
        </div>
    </div>
</x-layouts.admin>

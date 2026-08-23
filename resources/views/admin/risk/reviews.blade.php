@php
    use App\Domain\Risk\Enums\RiskReviewStatus;

    $bandTone = fn (?string $band): string => match ($band) {
        'CRITICAL' => 'red',
        'HIGH' => 'amber',
        'MEDIUM' => 'blue',
        default => 'zinc',
    };
    $statusTone = fn (string $status): string => match ($status) {
        'approved' => 'emerald',
        'rejected' => 'red',
        'escalated' => 'purple',
        default => 'amber',
    };

    $tabs = [
        'pending' => 'Pending',
        'escalated' => 'Escalated',
        'approved' => 'Approved',
        'rejected' => 'Rejected',
        'all' => 'All',
    ];
@endphp

<x-layouts.admin>
    <x-slot:heading>Risk Reviews</x-slot:heading>
    <x-slot:subheading>
        Actions the fraud/risk engine held for a human. SLA target: {{ $slaHours }}h.
    </x-slot:subheading>

    @if (session('status'))
        <div class="mb-4 rounded-[12px] bg-emerald-50 px-4 py-3 text-sm font-medium text-emerald-700 ring-1 ring-emerald-200 dark:bg-emerald-500/10 dark:text-emerald-300 dark:ring-emerald-500/30">
            {{ session('status') }}
        </div>
    @endif

    <div class="mb-6 inline-flex overflow-x-auto rounded-[12px] bg-zinc-100 p-1 font-medium text-sm text-zinc-600 dark:bg-zinc-800/50 dark:text-zinc-400">
        @foreach ($tabs as $key => $label)
            <a href="{{ route('admin.risk.reviews', ['status' => $key]) }}"
               class="whitespace-nowrap rounded-[12px] px-4 py-2 text-sm transition-all {{ $status === $key ? 'bg-white text-zinc-900 shadow-sm ring-1 ring-zinc-900/5 dark:bg-[#1d3252] dark:text-white dark:ring-white/10' : 'hover:text-zinc-900 hover:bg-zinc-200/50 dark:hover:text-white dark:hover:bg-zinc-700/50' }}">
                {{ $label }}
                @if (($counts[$key] ?? 0) > 0)
                    <span class="ml-1 text-xs opacity-70">{{ $counts[$key] }}</span>
                @endif
            </a>
        @endforeach
    </div>

    <div class="overflow-hidden rounded-[12px] bg-white shadow-sm shadow-zinc-900/[0.04] ring-1 ring-zinc-200 dark:bg-[#1d3252] dark:ring-white/10">
        <div class="overflow-x-auto">
            <table class="w-full min-w-[820px] text-left text-sm">
                <thead class="border-b border-zinc-200 bg-zinc-50 text-xs font-semibold uppercase tracking-wide text-zinc-500 dark:border-white/10 dark:bg-white/5 dark:text-zinc-400">
                    <tr>
                        <th class="px-6 py-3">Customer</th>
                        <th class="px-6 py-3">Context</th>
                        <th class="px-6 py-3">Score / Band</th>
                        <th class="px-6 py-3">Reasons</th>
                        <th class="px-6 py-3">Status</th>
                        <th class="px-6 py-3">Age</th>
                        <th class="px-6 py-3"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-zinc-100 dark:divide-white/5">
                    @forelse ($reviews as $review)
                        <tr class="hover:bg-zinc-50/70 dark:hover:bg-white/5">
                            <td class="px-6 py-4">
                                <div class="font-medium text-zinc-900 dark:text-white">{{ $review->user?->name ?? 'Guest' }}</div>
                                <div class="text-xs text-zinc-500 dark:text-zinc-400">{{ $review->user?->email }}</div>
                            </td>
                            <td class="px-6 py-4 capitalize text-zinc-700 dark:text-zinc-300">{{ $review->context }}</td>
                            <td class="px-6 py-4">
                                <span class="font-semibold text-zinc-900 dark:text-white">{{ $review->score }}</span>
                                <x-admin.badge :tone="$bandTone($review->band)" class="ml-2">{{ $review->band ?? '-' }}</x-admin.badge>
                            </td>
                            <td class="px-6 py-4 text-xs text-zinc-500 dark:text-zinc-400">
                                {{ implode(', ', array_slice($review->reason_codes ?? [], 0, 3)) }}
                                @if (count($review->reason_codes ?? []) > 3)
                                    +{{ count($review->reason_codes) - 3 }}
                                @endif
                            </td>
                            <td class="px-6 py-4">
                                <x-admin.badge :tone="$statusTone($review->status->value)">{{ $review->status->label() }}</x-admin.badge>
                            </td>
                            <td class="px-6 py-4 text-xs">
                                <span class="{{ $review->isSlaBreached() ? 'font-semibold text-red-600 dark:text-red-400' : 'text-zinc-500 dark:text-zinc-400' }}">
                                    {{ $review->created_at?->diffForHumans(null, true) }}
                                    @if ($review->isSlaBreached())
                                        · SLA breached
                                    @endif
                                </span>
                            </td>
                            <td class="px-6 py-4 text-right">
                                <a href="{{ route('admin.risk.review', $review) }}" class="text-sm font-semibold text-blue-600 hover:text-blue-700 dark:text-blue-400">Review</a>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7" class="px-6 py-12 text-center text-sm text-zinc-500 dark:text-zinc-400">No reviews in this view.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <div class="mt-4">
        {{ $reviews->links() }}
    </div>
</x-layouts.admin>

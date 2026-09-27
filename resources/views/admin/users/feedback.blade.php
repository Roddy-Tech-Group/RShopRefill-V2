@php
    use App\Models\AccountClosureFeedback;

    // ?window=30|90|all — how far back the aggregates look.
    $window = in_array(request()->query('window'), ['30', '90', 'all'], true) ? request()->query('window') : '90';
    $since = $window === 'all' ? null : now()->subDays((int) $window);

    $rows = AccountClosureFeedback::query()
        ->when($since, fn ($q) => $q->where('created_at', '>=', $since))
        ->orderByDesc('created_at')
        ->get();

    // Reason counts across the window. A submission can tick several reasons,
    // so percentages are of SUBMISSIONS, not of ticks.
    $counts = collect(AccountClosureFeedback::REASONS)->map(
        fn ($label, $key) => $rows->filter(fn ($f) => in_array($key, $f->reasons, true))->count()
    );
    $max = max(1, (int) $counts->max());
    $total = $rows->count();

    $comments = $rows->filter(fn ($f) => filled($f->comment))->take(100);
@endphp

<x-layouts.admin>
    <x-slot:heading>
        <div class="flex items-end justify-between gap-3">
            <span>Exit Feedback</span>
            <div class="flex gap-1">
                @foreach (['30' => '30 days', '90' => '90 days', 'all' => 'All time'] as $value => $label)
                    <a href="{{ route('admin.users.feedback', ['window' => $value]) }}"
                       class="rounded-[12px] px-3 py-1.5 text-xs font-semibold transition-colors {{ $window === $value ? 'bg-blue-600 text-white' : 'border border-zinc-200 bg-white text-zinc-700 hover:bg-zinc-50 dark:border-zinc-700/60 dark:bg-[#1d3252] dark:text-zinc-300' }}">
                        {{ $label }}
                    </a>
                @endforeach
            </div>
        </div>
    </x-slot:heading>
    <x-slot:subheading>
        {{ number_format($total) }} customer(s) left feedback when closing their account.
    </x-slot:subheading>

    <div class="space-y-6">
        {{-- Reason counts as labelled magnitude bars: one hue, value labels
             on every row (short list, so labels beat a tooltip here). --}}
        <div class="rounded-[12px] bg-white p-5 shadow-sm shadow-zinc-900/[0.04] dark:bg-[#1d3252]">
            <h2 class="mb-4 text-xs font-bold uppercase tracking-wider text-zinc-500 dark:text-zinc-400">Why customers say they leave</h2>
            <div class="space-y-3">
                @foreach (AccountClosureFeedback::REASONS as $key => $label)
                    @php $count = (int) $counts[$key]; @endphp
                    <div class="flex items-center gap-3">
                        <span class="w-56 shrink-0 truncate text-sm text-zinc-700 dark:text-zinc-200">{{ $label }}</span>
                        <div class="h-4 flex-1 overflow-hidden rounded-[4px] bg-zinc-100 dark:bg-white/10">
                            <div class="h-full rounded-r-[4px] bg-blue-600 dark:bg-blue-500" style="width: {{ (int) round(100 * $count / $max) }}%"></div>
                        </div>
                        <span class="w-20 shrink-0 text-right text-sm font-semibold tabular-nums text-zinc-900 dark:text-white">
                            {{ $count }}<span class="ml-1 text-xs font-normal text-zinc-400">{{ $total > 0 ? '(' . (int) round(100 * $count / $total) . '%)' : '' }}</span>
                        </span>
                    </div>
                @endforeach
            </div>
        </div>

        <div class="overflow-hidden rounded-[12px] bg-white shadow-sm shadow-zinc-900/[0.04] dark:bg-[#1d3252]">
            <h2 class="border-b border-zinc-100 px-5 py-3 text-xs font-bold uppercase tracking-wider text-zinc-500 dark:border-white/10 dark:text-zinc-400">In their own words</h2>
            @forelse ($comments as $feedback)
                <div class="border-b border-zinc-100 px-5 py-3.5 last:border-0 dark:border-white/10">
                    <p class="text-sm text-zinc-700 dark:text-zinc-200">&ldquo;{{ $feedback->comment }}&rdquo;</p>
                    <p class="mt-1 text-xs text-zinc-400">
                        {{ $feedback->created_at->format('M j, Y') }}
                        @if ($feedback->reasons !== [])
                            · {{ collect($feedback->reasons)->map(fn ($r) => AccountClosureFeedback::REASONS[$r] ?? $r)->implode(', ') }}
                        @endif
                    </p>
                </div>
            @empty
                <p class="px-5 py-8 text-center text-sm text-zinc-500 dark:text-zinc-400">No written comments in this window.</p>
            @endforelse
        </div>
    </div>
</x-layouts.admin>

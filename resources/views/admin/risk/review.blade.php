@php
    $bandTone = match ($review->band) {
        'CRITICAL' => 'red',
        'HIGH' => 'amber',
        'MEDIUM' => 'blue',
        default => 'zinc',
    };
    $statusTone = match ($review->status->value) {
        'approved' => 'emerald',
        'rejected' => 'red',
        'escalated' => 'purple',
        default => 'amber',
    };
@endphp

<x-layouts.admin>
    <x-slot:heading>Risk Review</x-slot:heading>
    <x-slot:subheading>
        <a href="{{ route('admin.risk.reviews') }}" class="text-blue-600 hover:text-blue-700 dark:text-blue-400">&larr; Back to queue</a>
    </x-slot:subheading>

    @error('reason')
        <div class="mb-4 rounded-[12px] bg-red-50 px-4 py-3 text-sm font-medium text-red-700 ring-1 ring-red-200 dark:bg-red-500/10 dark:text-red-300 dark:ring-red-500/30">
            {{ $message }}
        </div>
    @enderror

    <div class="grid gap-6 lg:grid-cols-3">
        {{-- Summary + signals --}}
        <div class="lg:col-span-2 space-y-6">
            <div class="rounded-[12px] bg-white p-6 shadow-sm dark:bg-[#1d3252]">
                <div class="flex flex-wrap items-center gap-3">
                    <span class="text-2xl font-bold text-zinc-900 dark:text-white">Score {{ $review->score }}</span>
                    <x-admin.badge :tone="$bandTone">{{ $review->band ?? '-' }}</x-admin.badge>
                    <x-admin.badge :tone="$statusTone">{{ $review->status->label() }}</x-admin.badge>
                    @if ($review->isSlaBreached())
                        <x-admin.badge tone="red">SLA breached</x-admin.badge>
                    @endif
                </div>

                <dl class="mt-4 grid grid-cols-2 gap-4 text-sm">
                    <div>
                        <dt class="text-zinc-500 dark:text-zinc-400">Customer</dt>
                        <dd class="font-medium text-zinc-900 dark:text-white">{{ $review->user?->name ?? 'Guest' }}</dd>
                        <dd class="text-xs text-zinc-500 dark:text-zinc-400">{{ $review->user?->email }}</dd>
                    </div>
                    <div>
                        <dt class="text-zinc-500 dark:text-zinc-400">Context</dt>
                        <dd class="font-medium capitalize text-zinc-900 dark:text-white">{{ $review->context }}</dd>
                    </div>
                    <div>
                        <dt class="text-zinc-500 dark:text-zinc-400">Amount</dt>
                        <dd class="font-medium text-zinc-900 dark:text-white">${{ number_format((float) ($review->assessment?->amount_usd ?? 0), 2) }}</dd>
                    </div>
                    <div>
                        <dt class="text-zinc-500 dark:text-zinc-400">Opened</dt>
                        <dd class="font-medium text-zinc-900 dark:text-white">{{ $review->created_at?->format('M j, Y H:i') }}</dd>
                    </div>
                    @if ($review->assessment?->ip_address)
                        <div>
                            <dt class="text-zinc-500 dark:text-zinc-400">IP</dt>
                            <dd class="font-mono text-xs text-zinc-700 dark:text-zinc-300">{{ $review->assessment->ip_address }}</dd>
                        </div>
                    @endif
                    @if ($review->assessment?->device_uuid)
                        <div>
                            <dt class="text-zinc-500 dark:text-zinc-400">Device</dt>
                            <dd class="font-mono text-xs text-zinc-700 dark:text-zinc-300">{{ Str::limit($review->assessment->device_uuid, 18) }}</dd>
                        </div>
                    @endif
                </dl>
            </div>

            {{-- Signals that fired --}}
            <div class="overflow-hidden rounded-[12px] bg-white shadow-sm dark:bg-[#1d3252]">
                <div class="border-b border-zinc-200 px-6 py-3 text-xs font-semibold uppercase tracking-wide text-zinc-500 dark:border-white/10 dark:text-zinc-400">
                    Signals ({{ $review->assessment?->signals->count() ?? 0 }})
                </div>
                <table class="w-full text-left text-sm admin-table">
                    <tbody class="">
                        @forelse ($review->assessment?->signals ?? [] as $signal)
                            <tr>
                                <td class="px-6 py-3 font-mono text-xs font-semibold text-zinc-900 dark:text-white">{{ $signal->code }}</td>
                                <td class="px-6 py-3 text-zinc-500 dark:text-zinc-400">+{{ $signal->weight }}</td>
                                <td class="px-6 py-3 text-xs text-zinc-500 dark:text-zinc-400">{{ json_encode($signal->detail) }}</td>
                            </tr>
                        @empty
                            <tr><td class="px-6 py-4 text-sm text-zinc-500 dark:text-zinc-400">No signals recorded.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>

        {{-- Decision panel --}}
        <div class="space-y-4">
            @if ($review->status->isDecided())
                <div class="rounded-[12px] bg-white p-6 shadow-sm dark:bg-[#1d3252]">
                    <h3 class="text-sm font-semibold text-zinc-900 dark:text-white">Decision</h3>
                    <p class="mt-2 text-sm text-zinc-600 dark:text-zinc-300">
                        {{ $review->status->label() }} by admin #{{ $review->decided_by }} on {{ $review->decided_at?->format('M j, Y H:i') }}.
                    </p>
                    @if ($review->decision_reason)
                        <p class="mt-2 text-sm italic text-zinc-500 dark:text-zinc-400">"{{ $review->decision_reason }}"</p>
                    @endif
                    @if ($review->hasLiveClearance())
                        <p class="mt-2 text-xs text-emerald-600 dark:text-emerald-400">Clearance active until {{ $review->cleared_until?->format('M j, H:i') }}.</p>
                    @endif
                </div>
            @else
                <form method="POST" action="{{ route('admin.risk.review.approve', $review) }}" class="rounded-[12px] bg-white p-6 shadow-sm dark:bg-[#1d3252]">
                    @csrf
                    <h3 class="text-sm font-semibold text-zinc-900 dark:text-white">Approve &amp; release</h3>
                    <p class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">Clears the customer's retry for {{ (int) config('risk.review.clearance_hours', 24) }}h.</p>
                    <input type="text" name="reason" placeholder="Reason (optional)" class="mt-3 w-full rounded-[8px] border-zinc-300 text-sm dark:border-white/10 dark:bg-white/5">
                    <button type="submit" class="mt-3 w-full rounded-[12px] bg-emerald-600 px-4 py-2 text-sm font-semibold text-white hover:bg-emerald-700">Approve</button>
                </form>

                <form method="POST" action="{{ route('admin.risk.review.reject', $review) }}" class="rounded-[12px] bg-white p-6 shadow-sm dark:bg-[#1d3252]">
                    @csrf
                    <h3 class="text-sm font-semibold text-zinc-900 dark:text-white">Reject</h3>
                    <p class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">Keeps the action refused. Reason required.</p>
                    <input type="text" name="reason" placeholder="Reason (required)" required class="mt-3 w-full rounded-[8px] border-zinc-300 text-sm dark:border-white/10 dark:bg-white/5">
                    <button type="submit" class="mt-3 w-full rounded-[12px] bg-red-600 px-4 py-2 text-sm font-semibold text-white hover:bg-red-700">Reject</button>
                </form>

                <form method="POST" action="{{ route('admin.risk.review.escalate', $review) }}" class="rounded-[12px] bg-white p-6 shadow-sm dark:bg-[#1d3252]">
                    @csrf
                    <h3 class="text-sm font-semibold text-zinc-900 dark:text-white">Escalate</h3>
                    <input type="text" name="reason" placeholder="Note (optional)" class="mt-3 w-full rounded-[8px] border-zinc-300 text-sm dark:border-white/10 dark:bg-white/5">
                    <button type="submit" class="mt-3 w-full rounded-[12px] bg-purple-600 px-4 py-2 text-sm font-semibold text-white hover:bg-purple-700">Escalate</button>
                </form>
            @endif
        </div>
    </div>
</x-layouts.admin>

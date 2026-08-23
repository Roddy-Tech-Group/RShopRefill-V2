{{--
    Transaction report preview — /admin/transactions/{attempt}/report.

    Same preview-first pattern as the order report: the exact PDF inline, with
    Download and Share. Viewing is audited by the controller; downloading is
    audited separately by the pdf endpoint.
--}}
<x-layouts.admin>
    <x-slot:heading>Transaction Report: #{{ $attempt->id }}</x-slot:heading>
    <x-slot:subheading>Preview the report below, then download or share it.</x-slot:subheading>

    <div class="flex flex-1 flex-col gap-4">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <a href="{{ route('admin.transaction', $attempt) }}" class="inline-flex items-center gap-1.5 text-xs font-semibold text-blue-600 hover:text-blue-700">
                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 19.5L8.25 12l7.5-7.5"/>
                </svg>
                Back to transaction
            </a>

            <div class="flex items-center gap-2" x-data="{ shared: false }">
                <button
                    type="button"
                    @click="
                        const payload = { title: 'Transaction Report: #{{ $attempt->id }}', url: '{{ route('admin.transaction.report', $attempt) }}' };
                        if (navigator.share) { navigator.share(payload).catch(() => {}); }
                        else { navigator.clipboard?.writeText(payload.url); shared = true; setTimeout(() => shared = false, 2000); }
                    "
                    class="inline-flex items-center gap-2 rounded-[12px] px-4 py-2 text-sm font-semibold text-zinc-700 ring-1 ring-zinc-300 transition-colors hover:bg-zinc-100 dark:text-zinc-200 dark:ring-white/15 dark:hover:bg-white/5"
                >
                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M7.217 10.907a2.25 2.25 0 100 2.186m0-2.186c.18.324.283.696.283 1.093s-.103.77-.283 1.093m0-2.186l9.566-5.314m-9.566 7.5l9.566 5.314m0 0a2.25 2.25 0 103.935 2.186 2.25 2.25 0 00-3.935-2.186zm0-12.814a2.25 2.25 0 103.933-2.185 2.25 2.25 0 00-3.933 2.185z"/>
                    </svg>
                    <span x-text="shared ? 'Link copied' : 'Share'"></span>
                </button>

                <a href="{{ route('admin.transaction.report.pdf', [$attempt, 'download' => 1]) }}"
                    class="inline-flex items-center gap-2 rounded-[12px] bg-blue-600 px-4 py-2 text-sm font-semibold text-white transition-colors hover:bg-blue-700">
                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 4v12m0 0l-4-4m4 4l4-4M4 20h16"/>
                    </svg>
                    Download
                </a>
            </div>
        </div>

        {{-- The exact PDF, inline. What you see is what Download saves. --}}
        <div class="overflow-hidden rounded-[12px] ring-1 ring-zinc-200 shadow-sm dark:ring-white/10">
            <iframe
                src="{{ route('admin.transaction.report.pdf', $attempt) }}"
                title="Transaction report preview"
                class="h-[78vh] w-full bg-white"
            ></iframe>
        </div>
    </div>
</x-layouts.admin>

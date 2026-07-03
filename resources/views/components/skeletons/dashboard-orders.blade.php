{{--
    Zero-CLS skeleton for the Order history page (livewire/dashboard/orders).
    Shown as the #[Lazy] placeholder while the component boots, then the real
    content swaps in. Mirrors the live layout exactly - heading, search + expired
    filter, then the single orders card with stacked row placeholders - so nothing
    jumps when the data arrives.
--}}
<div class="flex w-full flex-col gap-5" aria-hidden="true">

    {{-- Heading (desktop only, matches the real lg:block heading) --}}
    <x-skeleton class="hidden h-8 w-48 lg:block" />

    {{-- Search field + expired-orders filter --}}
    <div>
        <x-skeleton class="h-4 w-32" />
        <div class="mt-2 flex items-center gap-3">
            <x-skeleton class="h-12 flex-1" rounded="rounded-[12px]" />
            <x-skeleton class="h-6 w-28" />
        </div>
    </div>

    {{-- Orders list - one card with stacked order rows (logo + name/date + status/total). --}}
    <div class="skeleton-stagger-fast divide-y divide-zinc-200 overflow-hidden rounded-[12px] border border-zinc-200 bg-[#eff6ff] shadow-md shadow-zinc-900/[0.06] dark:border-zinc-700 dark:shadow-none">
        @for ($i = 0; $i < 5; $i++)
            <div class="flex items-center gap-3 p-4" style="--i: {{ $i }}">
                <x-skeleton class="h-12 w-12 shrink-0" rounded="rounded-[12px]" />
                <div class="min-w-0 flex-1 space-y-2">
                    <x-skeleton class="h-4 w-40 max-w-[60%]" />
                    <x-skeleton class="h-3 w-24 max-w-[40%]" />
                </div>
                <div class="flex shrink-0 flex-col items-end gap-2">
                    <x-skeleton class="h-5 w-16" rounded="rounded-[5px]" />
                    <x-skeleton class="h-3 w-12" />
                </div>
            </div>
        @endfor
    </div>
</div>

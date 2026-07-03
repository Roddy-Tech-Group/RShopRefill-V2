{{--
    Zero-CLS skeleton for the admin dashboard (/admin/dashboard).
    Mirrors the real layout so the page does not jump when the data swaps in.
    Single root element (Livewire placeholder requirement).
--}}
<div class="flex flex-1 flex-col gap-4 sm:gap-6" aria-hidden="true">

    {{-- ── KPI cards (5 across on lg, 2 on sm, 3 on md) ──────────── --}}
    <div class="grid grid-cols-1 gap-3 sm:grid-cols-2 sm:gap-4 md:grid-cols-3 lg:grid-cols-5">
        @for ($i = 0; $i < 5; $i++)
            <div class="relative flex flex-col overflow-hidden rounded-[20px] bg-white p-4 shadow-sm shadow-zinc-900/5 ring-1 ring-zinc-100 sm:p-5 {{ $i === 4 ? 'sm:col-span-2 md:col-span-3 lg:col-span-1' : '' }}" style="--i: {{ $i }}">
                <div class="flex items-start justify-between gap-2">
                    <x-skeleton class="h-10 w-10 sm:h-11 sm:w-11" rounded="rounded-[12px]" />
                    <x-skeleton shape="circle" class="h-12 w-12" />
                </div>
                <x-skeleton class="mt-4 h-3 w-20" />
                <x-skeleton class="mt-1.5 h-6 w-28" />
                <x-skeleton class="mt-auto pt-3 h-3 w-32" />
            </div>
        @endfor
    </div>

    {{-- ── Charts row (map + trends) ────────────────────────────── --}}
    <div class="grid grid-cols-1 gap-4 xl:grid-cols-2">

        {{-- Best Selling Countries map placeholder --}}
        <div class="flex min-w-0 flex-col overflow-hidden rounded-[20px] bg-white p-5 shadow-sm shadow-zinc-900/5 ring-1 ring-zinc-100">
            <div class="flex items-start justify-between gap-3">
                <x-skeleton class="h-5 w-48" />
                <x-skeleton class="h-7 w-24" rounded="rounded-[12px]" />
            </div>
            <x-skeleton class="mt-5 aspect-[16/10] w-full" rounded="rounded-[12px]" />
            <div class="mt-4 flex items-center justify-between gap-3">
                <x-skeleton class="h-7 w-28" rounded="rounded-[12px]" />
                <x-skeleton class="h-7 w-32" rounded="rounded-[12px]" />
            </div>
        </div>

        {{-- Sales / Cost trends chart placeholder. Mirrors the real
             ApexCharts area-chart silhouette (two overlaid smooth lines
             with faint area fills) so the swap-in does not feel like a
             different widget. Built from two inline SVGs sized to the same
             ~h-56 box the real chart occupies. --}}
        <div class="flex min-w-0 flex-col overflow-hidden rounded-[20px] bg-white p-5 shadow-sm shadow-zinc-900/5 ring-1 ring-zinc-100">
            <div class="flex items-start justify-between gap-3">
                <div class="space-y-2">
                    <x-skeleton class="h-5 w-32" />
                    <x-skeleton class="h-3.5 w-48" />
                </div>
                <x-skeleton class="h-7 w-28" rounded="rounded-[12px]" />
            </div>

            <div class="relative mt-5 h-56 w-full overflow-hidden">
                {{-- Faint horizontal gridlines so the empty chart area has
                     some structure, matching the real chart's y-axis grid. --}}
                <div class="absolute inset-x-0 top-[20%] h-px bg-zinc-100"></div>
                <div class="absolute inset-x-0 top-[40%] h-px bg-zinc-100"></div>
                <div class="absolute inset-x-0 top-[60%] h-px bg-zinc-100"></div>
                <div class="absolute inset-x-0 top-[80%] h-px bg-zinc-100"></div>

                {{-- Pulse animation matches the .skeleton shimmer timing so
                     this looks like part of the same loading state. --}}
                <svg
                    viewBox="0 0 600 224"
                    preserveAspectRatio="none"
                    class="absolute inset-0 h-full w-full animate-pulse text-zinc-200"
                    aria-hidden="true"
                >
                    {{-- Cost line area fill --}}
                    <path
                        d="M 0 170 C 80 150, 120 130, 200 140 C 280 150, 340 100, 420 110 C 500 120, 540 90, 600 80 L 600 224 L 0 224 Z"
                        fill="currentColor"
                        opacity="0.35"
                    />
                    {{-- Sales line area fill --}}
                    <path
                        d="M 0 130 C 80 110, 120 90, 200 100 C 280 110, 340 60, 420 70 C 500 80, 540 50, 600 40 L 600 224 L 0 224 Z"
                        fill="currentColor"
                        opacity="0.5"
                    />
                    {{-- Cost stroke (the line itself) --}}
                    <path
                        d="M 0 170 C 80 150, 120 130, 200 140 C 280 150, 340 100, 420 110 C 500 120, 540 90, 600 80"
                        fill="none"
                        stroke="currentColor"
                        stroke-width="2.5"
                        stroke-linecap="round"
                        class="text-zinc-300"
                    />
                    {{-- Sales stroke (the line itself) --}}
                    <path
                        d="M 0 130 C 80 110, 120 90, 200 100 C 280 110, 340 60, 420 70 C 500 80, 540 50, 600 40"
                        fill="none"
                        stroke="currentColor"
                        stroke-width="2.5"
                        stroke-linecap="round"
                        class="text-zinc-400"
                    />
                </svg>
            </div>

            <div class="mt-4">
                <x-skeleton class="h-7 w-32" rounded="rounded-[12px]" />
            </div>
        </div>
    </div>

    {{-- ── Tables row (latest users + latest transactions) ────────── --}}
    <div class="grid grid-cols-1 gap-4 xl:grid-cols-2">

        @for ($table = 0; $table < 2; $table++)
            <div class="overflow-hidden rounded-[20px] bg-white shadow-sm shadow-zinc-900/5 ring-1 ring-zinc-100">
                <div class="flex items-center justify-between gap-3 p-5">
                    <x-skeleton class="h-5 w-40" />
                    <x-skeleton class="h-3.5 w-16" />
                </div>

                <div class="skeleton-stagger-fast border-t border-zinc-100">
                    @for ($i = 0; $i < 5; $i++)
                        <div class="flex items-center gap-3 px-5 py-3.5 {{ $i > 0 ? 'border-t border-zinc-100' : '' }}" style="--i: {{ $i }}">
                            <x-skeleton class="h-9 w-9 shrink-0" rounded="rounded-[12px]" />
                            <div class="min-w-0 flex-1 space-y-2">
                                <x-skeleton class="h-3.5 w-40 max-w-[70%]" />
                                <x-skeleton class="h-3 w-24 max-w-[40%]" />
                            </div>
                            <div class="flex shrink-0 flex-col items-end gap-2">
                                <x-skeleton class="h-3.5 w-16" />
                                <x-skeleton class="h-3 w-12" />
                            </div>
                        </div>
                    @endfor
                </div>
            </div>
        @endfor
    </div>
</div>

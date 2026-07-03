{{--
    Zero-CLS skeleton for the customer dashboard overview (livewire/dashboard/overview).
    Shown as the #[Lazy] placeholder while the real component boots. Mirrors the real
    layout — same card shells, grid columns and gaps — so nothing jumps when the data
    arrives. Single root element (Livewire placeholder requirement).
--}}
<div aria-hidden="true">

    {{-- ── MOBILE (< lg) ───────────────────────────────────────── --}}
    <div class="flex flex-col gap-5 lg:hidden">

        {{-- Quick Actions card --}}
        <div class="skeleton-stagger-fast rounded-[12px] bg-[#eff6ff] p-5 border border-zinc-200 shadow-md shadow-zinc-900/[0.06] dark:border-zinc-700 dark:shadow-none">
            <x-skeleton class="h-5 w-32" style="--i: 0" />
            <div class="mt-4 grid grid-cols-3 gap-3">
                @for ($i = 0; $i < 6; $i++)
                    <div class="flex flex-col items-center gap-1.5" style="--i: {{ $i }}">
                        <x-skeleton shape="circle" class="h-12 w-12" />
                        <x-skeleton class="h-3 w-12" />
                    </div>
                @endfor
            </div>
        </div>

        {{-- Rcoin card --}}
        <div class="rounded-[12px] bg-[#eff6ff] p-5 border border-zinc-200 shadow-md shadow-zinc-900/[0.06] dark:border-zinc-700 dark:shadow-none">
            <div class="flex items-start gap-3">
                <x-skeleton class="h-10 w-10 shrink-0" rounded="rounded-[12px]" />
                <div class="flex-1 space-y-2">
                    <x-skeleton class="h-3.5 w-24" />
                    <x-skeleton class="h-6 w-28" />
                </div>
            </div>
            <x-skeleton class="mt-4 h-3 w-56" />
            <x-skeleton class="mt-4 h-10 w-full" rounded="rounded-[12px]" />
        </div>

        {{-- Recent Orders card --}}
        <div class="rounded-[12px] bg-[#eff6ff] p-5 border border-zinc-200 shadow-md shadow-zinc-900/[0.06] dark:border-zinc-700 dark:shadow-none">
            <div class="flex items-center justify-between">
                <x-skeleton class="h-5 w-32" />
                <x-skeleton class="h-3.5 w-12" />
            </div>
            <div class="skeleton-stagger-fast mt-4 space-y-3">
                @for ($i = 0; $i < 3; $i++)
                    <div class="flex items-center gap-3" style="--i: {{ $i }}">
                        <x-skeleton class="h-12 w-12 shrink-0" rounded="rounded-[12px]" />
                        <div class="min-w-0 flex-1 space-y-2">
                            <x-skeleton class="h-3.5 w-40 max-w-[70%]" />
                            <x-skeleton class="h-3 w-24 max-w-[45%]" />
                        </div>
                    </div>
                @endfor
            </div>
        </div>

        {{-- Shop by Category card (4 x 2 grid) --}}
        <div class="rounded-[12px] bg-[#eff6ff] p-5 border border-zinc-200 shadow-md shadow-zinc-900/[0.06] dark:border-zinc-700 dark:shadow-none">
            <div class="flex items-center justify-between">
                <x-skeleton class="h-5 w-40" />
                <x-skeleton class="h-3.5 w-12" />
            </div>
            <div class="mt-4 grid grid-cols-4 gap-3">
                @for ($i = 0; $i < 8; $i++)
                    <div class="flex flex-col items-center gap-2">
                        <x-skeleton shape="circle" class="h-12 w-12" />
                        <x-skeleton class="h-3 w-12" />
                    </div>
                @endfor
            </div>
        </div>

        {{-- Popular Gift Cards card. Mirrors the real <x-home.brand-row>
             mobile layout: title block, then a horizontal row of 16:10 cards
             with their name + price-range underneath. --}}
        <div class="rounded-[12px] bg-[#eff6ff] p-5 border border-zinc-200 shadow-md shadow-zinc-900/[0.06] dark:border-zinc-700 dark:shadow-none">
            <div class="flex items-start justify-between gap-3">
                <div class="min-w-0 flex-1 space-y-2">
                    <x-skeleton class="h-5 w-44" />
                    <x-skeleton class="h-3.5 w-32" />
                </div>
                <x-skeleton shape="circle" class="h-8 w-8 shrink-0" />
            </div>
            <div class="mt-4 -mx-1 flex gap-3 overflow-hidden">
                @for ($i = 0; $i < 4; $i++)
                    <div class="w-32 shrink-0 px-1">
                        <x-skeleton class="aspect-[16/10] w-full" rounded="rounded-[15px]" />
                        <x-skeleton class="mt-2 h-4 w-3/4" />
                        <x-skeleton class="mt-1.5 h-3 w-1/2" />
                    </div>
                @endfor
            </div>
        </div>

        {{-- Promo block --}}
        <x-skeleton class="h-[148px] w-full" rounded="rounded-[12px]" />

        {{-- Recent Transactions card --}}
        <div class="rounded-[12px] bg-[#eff6ff] p-5 border border-zinc-200 shadow-md shadow-zinc-900/[0.06] dark:border-zinc-700 dark:shadow-none">
            <div class="flex items-center justify-between">
                <x-skeleton class="h-5 w-36" />
                <x-skeleton class="h-3.5 w-12" />
            </div>
            <div class="skeleton-stagger-fast mt-4 space-y-3">
                @for ($i = 0; $i < 4; $i++)
                    <div class="flex items-center gap-3" style="--i: {{ $i }}">
                        <x-skeleton class="h-11 w-11 shrink-0" rounded="rounded-[12px]" />
                        <div class="min-w-0 flex-1 space-y-2">
                            <x-skeleton class="h-3.5 w-36 max-w-[70%]" />
                            <x-skeleton class="h-3 w-20 max-w-[45%]" />
                        </div>
                        <div class="flex shrink-0 flex-col items-end gap-2">
                            <x-skeleton class="h-3.5 w-14" />
                            <x-skeleton class="h-3 w-10" />
                        </div>
                    </div>
                @endfor
            </div>
        </div>
    </div>

    {{-- ── DESKTOP (lg+) ───────────────────────────────────────── --}}
    <div class="hidden lg:block">
        <div class="grid grid-cols-1 gap-6 lg:grid-cols-12">

            {{-- LEFT column --}}
            <div class="flex flex-col gap-6 lg:col-span-8">

                {{-- Heading row --}}
                <div class="flex items-start justify-between">
                    <div class="space-y-2">
                        <x-skeleton class="h-8 w-72" />
                        <x-skeleton class="h-4 w-60" />
                    </div>
                    <x-skeleton class="h-9 w-28" rounded="rounded-[12px]" />
                </div>

                {{-- Wallet / Quick Actions / Recent Order row --}}
                <div class="grid grid-cols-1 gap-6 xl:grid-cols-3">
                    @for ($i = 0; $i < 3; $i++)
                        <div class="rounded-[12px] bg-[#eff6ff] p-5 border border-zinc-200 shadow-md shadow-zinc-900/[0.06] dark:border-zinc-700 dark:shadow-none">
                            <x-skeleton class="h-4 w-24" />
                            <x-skeleton class="mt-4 h-7 w-32" />
                            <div class="mt-5 space-y-2.5">
                                <x-skeleton class="h-3.5 w-full" />
                                <x-skeleton class="h-3.5 w-4/5" />
                                <x-skeleton class="h-3.5 w-2/3" />
                            </div>
                        </div>
                    @endfor
                </div>

                {{-- Trust strip --}}
                <div class="flex items-center gap-4 rounded-[12px] bg-[#eff6ff] p-5 border border-zinc-200 shadow-md shadow-zinc-900/[0.06] dark:border-zinc-700 dark:shadow-none">
                    <x-skeleton class="h-12 w-12 shrink-0" rounded="rounded-[12px]" />
                    <div class="flex-1 space-y-2">
                        <x-skeleton class="h-4 w-48" />
                        <x-skeleton class="h-3 w-72" />
                    </div>
                </div>

                {{-- Shop by Category + Popular Gift Cards card --}}
                <div class="rounded-[12px] bg-[#eff6ff] border border-zinc-200 shadow-md shadow-zinc-900/[0.06] dark:border-zinc-700 dark:shadow-none">
                    <div class="p-6">
                        <x-skeleton class="h-5 w-40" />
                        <div class="mt-4 grid grid-cols-8 gap-3">
                            @for ($i = 0; $i < 8; $i++)
                                <div class="flex flex-col items-center gap-2">
                                    <x-skeleton shape="circle" class="h-14 w-14" />
                                    <x-skeleton class="h-3 w-12" />
                                </div>
                            @endfor
                        </div>
                    </div>
                    <div class="border-t border-zinc-100"></div>
                    <div class="p-6">
                        <div class="mb-4 flex items-end justify-between gap-4">
                            <div class="space-y-2">
                                <x-skeleton class="h-5 w-48" />
                                <x-skeleton class="h-4 w-60" />
                            </div>
                            <x-skeleton class="h-4 w-12 shrink-0" />
                        </div>
                        {{-- Matches the real brand-row layout: 5 cards, aspect-[16/10] tiles. --}}
                        <div class="grid grid-cols-5 gap-5">
                            @for ($i = 0; $i < 5; $i++)
                                <div class="rounded-[12px] bg-white p-3 shadow-sm shadow-zinc-900/[0.04] ring-1 ring-zinc-100">
                                    <x-skeleton class="aspect-[16/10] w-full" rounded="rounded-[15px]" />
                                    <x-skeleton class="mt-3 h-4 w-3/4" />
                                </div>
                            @endfor
                        </div>
                    </div>
                </div>
            </div>

            {{-- RIGHT rail --}}
            <div class="flex flex-col gap-6 lg:col-span-4">

                {{-- Rcoin card --}}
                <div class="rounded-[12px] bg-[#eff6ff] p-5 border border-zinc-200 shadow-md shadow-zinc-900/[0.06] dark:border-zinc-700 dark:shadow-none">
                    <div class="flex items-start gap-3">
                        <x-skeleton class="h-10 w-10 shrink-0" rounded="rounded-[12px]" />
                        <div class="flex-1 space-y-2">
                            <x-skeleton class="h-3.5 w-24" />
                            <x-skeleton class="h-6 w-28" />
                        </div>
                    </div>
                    <x-skeleton class="mt-4 h-3 w-56" />
                    <x-skeleton class="mt-4 h-10 w-full" rounded="rounded-[12px]" />
                </div>

                {{-- Promo block --}}
                <x-skeleton class="h-44 w-full" rounded="rounded-[12px]" />

                {{-- Recent Transactions card --}}
                <div class="rounded-[12px] bg-[#eff6ff] p-5 border border-zinc-200 shadow-md shadow-zinc-900/[0.06] dark:border-zinc-700 dark:shadow-none">
                    <div class="flex items-center justify-between">
                        <x-skeleton class="h-5 w-36" />
                        <x-skeleton class="h-3.5 w-12" />
                    </div>
                    <div class="skeleton-stagger-fast mt-4 space-y-3">
                        @for ($i = 0; $i < 4; $i++)
                            <div class="flex items-center gap-3" style="--i: {{ $i }}">
                                <x-skeleton class="h-11 w-11 shrink-0" rounded="rounded-[12px]" />
                                <div class="min-w-0 flex-1 space-y-2">
                                    <x-skeleton class="h-3.5 w-36 max-w-[70%]" />
                                    <x-skeleton class="h-3 w-20 max-w-[45%]" />
                                </div>
                                <div class="flex shrink-0 flex-col items-end gap-2">
                                    <x-skeleton class="h-3.5 w-14" />
                                    <x-skeleton class="h-3 w-10" />
                                </div>
                            </div>
                        @endfor
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

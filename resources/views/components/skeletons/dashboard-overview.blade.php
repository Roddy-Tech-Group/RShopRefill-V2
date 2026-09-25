{{--
    Zero-CLS skeleton for the customer dashboard overview (livewire/dashboard/overview).
    Shown as the #[Lazy] placeholder while the real component boots. Mirrors the real
    layout section for section - same card shells (rounded-[15px] white, shadow-md),
    grid columns, gaps and item counts - so nothing jumps when the data arrives.
    Single root element (Livewire placeholder requirement).

    Keep it in step with overview.blade.php. It had drifted: it still drew a trust
    strip, a category grid and an Rcoin card on mobile that the page no
    longer has, a three-card row where the page has two, and blue bordered cards
    where the page's are white.
--}}
@php
    $card = 'rounded-[15px] bg-[#ffffff] p-5 shadow-md shadow-zinc-900/[0.06] dark:shadow-none';
@endphp
<div aria-hidden="true">

    {{-- ── MOBILE (< lg) ───────────────────────────────────────── --}}
    <div class="flex flex-col gap-5 lg:hidden">

        {{-- PURCHASE: a heading and four cards, two across --}}
        <div>
            <x-skeleton class="h-3 w-20" />
            <div class="skeleton-stagger-fast mt-3 grid grid-cols-2 gap-4">
                @for ($i = 0; $i < 4; $i++)
                    <div class="{{ $card }} rounded-[10px]" style="--i: {{ $i }}">
                        <x-skeleton class="h-10 w-10" />
                        <x-skeleton class="mt-5 h-4 w-24" />
                        <x-skeleton class="mt-2 h-4 w-full" />
                        <x-skeleton class="mt-1 h-4 w-2/3" />
                        <x-skeleton shape="circle" class="mt-5 h-9 w-9" />
                    </div>
                @endfor
            </div>
        </div>

        {{-- Give the Perfect Gift promo --}}
        <x-skeleton class="h-[148px] w-full" rounded="rounded-[12px]" />

        {{-- Recent Orders: up to 5, "+" circle in the header --}}
        <div class="{{ $card }}">
            <div class="flex items-center justify-between">
                <x-skeleton class="h-5 w-32" />
                <x-skeleton shape="circle" class="h-8 w-8" />
            </div>
            <div class="skeleton-stagger-fast mt-4 space-y-3">
                @for ($i = 0; $i < 5; $i++)
                    <div class="flex items-center gap-3" style="--i: {{ $i }}">
                        <x-skeleton class="h-12 w-12 shrink-0" rounded="rounded-[12px]" />
                        <div class="min-w-0 flex-1 space-y-2">
                            <div class="flex items-center justify-between gap-2">
                                <x-skeleton class="h-3.5 w-32 max-w-[60%]" />
                                <x-skeleton class="h-5 w-16 shrink-0" rounded="rounded-[5px]" />
                            </div>
                            <div class="flex items-center justify-between gap-2">
                                <x-skeleton class="h-3 w-20 max-w-[45%]" />
                                <x-skeleton class="h-2.5 w-12 shrink-0" />
                            </div>
                        </div>
                    </div>
                @endfor
            </div>
        </div>

        {{-- Recent Transactions --}}
        <div class="{{ $card }}">
            <div class="flex items-center justify-between">
                <x-skeleton class="h-5 w-36" />
                <x-skeleton shape="circle" class="h-8 w-8" />
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

            {{-- Heading row across the full width: greeting + subtitle --}}
            <div class="flex items-start justify-between lg:col-span-12">
                <div class="space-y-2">
                    <x-skeleton class="h-7 w-56" />
                    <x-skeleton class="h-4 w-72" />
                </div>
            </div>

            {{-- Row one: wallet, Recent Orders, Rcoin (three across at every desktop width) --}}
                <div class="grid grid-cols-1 gap-6 lg:col-span-12 lg:grid-cols-3">
                    {{-- Wallet balance --}}
                    <div class="{{ $card }} flex flex-col justify-center gap-4">
                        <div class="flex items-start justify-between">
                            <div class="space-y-2">
                                <x-skeleton class="h-3 w-36" />
                                <x-skeleton class="h-8 w-40" />
                                <x-skeleton class="h-3 w-28" />
                            </div>
                            <x-skeleton shape="circle" class="h-12 w-12 shrink-0" />
                        </div>
                        {{-- Top Up pill (right-aligned), then the wallet switcher bar --}}
                        <div class="flex justify-end">
                            <x-skeleton class="h-9 w-24" rounded="rounded-full" />
                        </div>
                        <x-skeleton class="h-10 w-full" rounded="rounded-[12px]" />
                    </div>

                    {{-- Recent Orders: 3 on desktop --}}
                    <div class="{{ $card }}">
                        <div class="flex items-center justify-between">
                            <x-skeleton class="h-5 w-32" />
                            <x-skeleton class="h-5 w-14" rounded="rounded-md" />
                        </div>
                        <div class="skeleton-stagger-fast mt-4 space-y-3">
                            @for ($i = 0; $i < 3; $i++)
                                <div class="flex items-center gap-3" style="--i: {{ $i }}">
                                    <x-skeleton class="h-12 w-12 shrink-0" rounded="rounded-[12px]" />
                                    <div class="min-w-0 flex-1 space-y-2">
                                        <div class="flex items-center justify-between gap-2">
                                            <x-skeleton class="h-3.5 w-32 max-w-[60%]" />
                                            <x-skeleton class="h-5 w-16 shrink-0" rounded="rounded-[5px]" />
                                        </div>
                                        <div class="flex items-center justify-between gap-2">
                                            <x-skeleton class="h-3 w-20 max-w-[45%]" />
                                            <x-skeleton class="h-2.5 w-12 shrink-0" />
                                        </div>
                                    </div>
                                </div>
                            @endfor
                        </div>
                    </div>
                    {{-- Rcoin card --}}
                    <div class="{{ $card }} flex min-w-0 flex-col">
                        <div class="flex items-start gap-3">
                            <x-skeleton class="h-10 w-10 shrink-0" rounded="rounded-[12px]" />
                            <div class="flex-1 space-y-2">
                                <x-skeleton class="h-3.5 w-24" />
                                <x-skeleton class="h-6 w-28" />
                            </div>
                        </div>
                        <x-skeleton class="mt-4 h-3 w-56" />
                        <div class="flex-1" aria-hidden="true"></div>
                        <x-skeleton class="mt-4 h-10 w-full" rounded="rounded-[12px]" />
                    </div>
                </div>

                {{-- Quick Actions + Popular Gift Cards (one card, divider between) --}}
                <div class="rounded-[15px] bg-[#ffffff] shadow-md shadow-zinc-900/[0.06] lg:col-span-8 dark:shadow-none">
                    <div class="p-5 sm:p-6">
                        <div class="flex items-center justify-between">
                            <x-skeleton class="h-5 w-32" />
                            <x-skeleton class="h-5 w-14" rounded="rounded-md" />
                        </div>
                        <div class="skeleton-stagger-fast mt-4 grid grid-cols-4 gap-3 sm:grid-cols-8">
                            @for ($i = 0; $i < 8; $i++)
                                <div class="flex flex-col items-center gap-2" style="--i: {{ $i }}">
                                    <x-skeleton shape="circle" class="h-14 w-14" />
                                    <x-skeleton class="h-3 w-12" />
                                </div>
                            @endfor
                        </div>
                    </div>
                    <div class="border-t border-zinc-100"></div>
                    <div class="p-5 sm:p-6">
                        <div class="mb-4 flex items-end justify-between gap-4">
                            <div class="space-y-2">
                                <x-skeleton class="h-5 w-44" />
                                <x-skeleton class="h-4 w-60" />
                            </div>
                            <div class="flex shrink-0 items-center gap-2">
                                <x-skeleton class="h-10 w-16" rounded="rounded-[12px]" />
                                <x-skeleton class="h-10 w-10" rounded="rounded-[12px]" />
                                <x-skeleton class="h-10 w-10" rounded="rounded-[12px]" />
                            </div>
                        </div>
                        {{-- brand-row at per-view 3, two rows deep --}}
                        <div class="skeleton-stagger-fast grid grid-cols-3 gap-5">
                            @for ($i = 0; $i < 6; $i++)
                                <div style="--i: {{ $i }}">
                                    <x-skeleton class="aspect-[16/10] w-full" rounded="rounded-[15px]" />
                                    <x-skeleton class="mt-2 h-4 w-3/4" />
                                    <x-skeleton class="mt-1.5 h-3.5 w-1/2" />
                                </div>
                            @endfor
                        </div>
                    </div>
                </div>
                {{-- Row two, right: promo + Recent Transactions, sized by the quick actions card --}}
                <div class="flex flex-col gap-6 lg:col-span-4 lg:min-h-0 lg:[contain:size]">

                {{-- Give the Perfect Gift promo --}}
                <x-skeleton class="h-44 w-full shrink-0" rounded="rounded-[12px]" />

                {{-- Recent Transactions --}}
                <div class="{{ $card }} lg:min-h-0 lg:flex-1 lg:overflow-hidden">
                    <div class="flex items-center justify-between">
                        <x-skeleton class="h-5 w-36" />
                        <x-skeleton class="h-5 w-14" rounded="rounded-md" />
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

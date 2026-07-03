{{--
    Zero-CLS skeleton for the Settings page (livewire/settings/profile).
    Shown as the #[Lazy] placeholder while the component boots. Mirrors the real
    section stack - heading, Personal Information (avatar + info rows), Security,
    Notifications - so nothing jumps when the data swaps in.
--}}
@php
    $card = 'overflow-hidden rounded-[12px] border border-zinc-200 bg-[#eff6ff] shadow-md shadow-zinc-900/[0.06] dark:border-zinc-700 dark:shadow-none';
@endphp
<div class="flex w-full flex-col gap-6 pb-4" aria-hidden="true">

    {{-- Heading (desktop) --}}
    <div class="hidden space-y-2 lg:block">
        <x-skeleton class="h-8 w-40" />
        <x-skeleton class="h-3.5 w-72 max-w-full" />
    </div>

    {{-- Personal Information - avatar hero + info rows --}}
    <section>
        <x-skeleton class="mb-2.5 hidden h-5 w-44 lg:block" />
        <div class="{{ $card }}">
            <div class="flex items-center gap-4 px-5 pt-6 pb-5 sm:px-6">
                <x-skeleton class="h-20 w-20 shrink-0" rounded="rounded-[12px]" />
                <div class="flex-1 space-y-2.5">
                    <x-skeleton class="h-5 w-36" />
                    <x-skeleton class="h-3.5 w-48 max-w-full" />
                </div>
            </div>
            <div class="skeleton-stagger-fast divide-inset">
                @for ($i = 0; $i < 4; $i++)
                    <div class="flex items-center justify-between px-5 py-3.5 sm:px-6" style="--i: {{ $i }}">
                        <div class="space-y-2">
                            <x-skeleton class="h-3 w-20" />
                            <x-skeleton class="h-4 w-40 max-w-full" />
                        </div>
                        <x-skeleton class="h-8 w-8 shrink-0" rounded="rounded-[12px]" />
                    </div>
                @endfor
            </div>
        </div>
    </section>

    {{-- Security --}}
    <section>
        <x-skeleton class="mb-2.5 h-5 w-24" />
        <div class="{{ $card }}">
            @for ($i = 0; $i < 2; $i++)
                <div class="flex items-center gap-4 p-5">
                    <x-skeleton class="h-10 w-10 shrink-0" rounded="rounded-[12px]" />
                    <div class="flex-1 space-y-2">
                        <x-skeleton class="h-4 w-32" />
                        <x-skeleton class="h-3 w-56 max-w-full" />
                    </div>
                </div>
            @endfor
        </div>
    </section>

    {{-- Notifications - toggle rows --}}
    <section>
        <x-skeleton class="mb-2.5 h-5 w-28" />
        <div class="divide-inset {{ $card }}">
            @for ($i = 0; $i < 3; $i++)
                <div class="flex items-center justify-between p-5">
                    <div class="space-y-2">
                        <x-skeleton class="h-4 w-40" />
                        <x-skeleton class="h-3 w-56 max-w-full" />
                    </div>
                    <x-skeleton class="h-6 w-11 shrink-0" rounded="rounded-full" />
                </div>
            @endfor
        </div>
    </section>
</div>

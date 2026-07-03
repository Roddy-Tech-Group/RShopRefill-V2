{{--
    How it works. 3-step buying process: Pick a product, Pay with crypto, Receive instantly.
    Mobile: horizontal scroll carousel. Desktop (sm+): 3 cards side-by-side.
--}}
@php
    // Live product count, reusing the same 10-minute cache the hero fills so the
    // figure stays in sync and never hard-codes a number. Floored to the nearest
    // thousand for clean marketing copy.
    $catalogStats = \Illuminate\Support\Facades\Cache::remember(
        'hero.catalog_stats',
        now()->addMinutes(10),
        fn () => [
            'variants'  => (int) \App\Models\ProductVariant::where('is_available', true)->count(),
            'countries' => (int) \App\Models\Product::where('is_active', true)
                ->whereNotNull('country_code')
                ->distinct('country_code')
                ->count('country_code'),
        ],
    );
    $productsRounded = number_format(max(1000, intdiv($catalogStats['variants'], 1000) * 1000));
@endphp

<section data-reveal aria-label="How it works">

    <h2 class="mb-5 text-xl font-bold text-zinc-900 sm:text-2xl">How it works</h2>

    {{-- Mobile: horizontal scroll. Desktop: 3-col grid. Mobile py-2 prevents the card ring/shadow from being clipped. --}}
    <div class="-mx-4 overflow-x-auto px-4 py-2 [scrollbar-width:none] [&::-webkit-scrollbar]:hidden sm:mx-0 sm:overflow-visible sm:px-0 sm:py-0">
        <div class="flex w-max gap-4 sm:grid sm:w-full sm:grid-cols-3 sm:gap-5">

        {{-- Step 1 — Pick a product --}}
        <article class="w-72 shrink-0 overflow-hidden rounded-[12px] bg-zinc-100 dark:bg-[#1d3252] ring-1 ring-zinc-200 shadow-sm sm:w-auto">
            <div class="flex aspect-[4/3] items-center justify-center overflow-hidden bg-zinc-100 dark:bg-[#1d3252]">
                <x-illo name="cardFan" class="h-full w-full" />
            </div>
            <div class="bg-zinc-100 dark:bg-[#1d3252] p-5">
                <h3 class="text-base font-semibold text-zinc-900">1. Pick a product or service</h3>
                <p class="mt-1.5 text-sm leading-relaxed text-zinc-600">
                    Choose from {{ $productsRounded }}+ gift cards, eSIMs, flights, stays and mobile top-ups made ready for you to simplify your shopping experience.
                </p>
            </div>
        </article>

        {{-- Step 2 — Pay with crypto --}}
        <article class="w-72 shrink-0 overflow-hidden rounded-[12px] bg-zinc-100 dark:bg-[#1d3252] ring-1 ring-zinc-200 shadow-sm sm:w-auto">
            <div class="flex aspect-[4/3] items-center justify-center overflow-hidden bg-zinc-100 dark:bg-[#1d3252]">
                <x-illo name="payWeb" class="h-full w-full" />
            </div>
            <div class="bg-zinc-100 dark:bg-[#1d3252] p-5">
                <h3 class="text-base font-semibold text-zinc-900">2. Pay with Cards, Crypto, Mobile Money, Bank Transfers etc</h3>
                <p class="mt-1.5 text-sm leading-relaxed text-zinc-600">
                    Access {{ $productsRounded }}+ digital products including gift cards, eSIMs, flights, hotel stays, mobile top-ups, and more, all in one seamless platform.
                </p>
            </div>
        </article>

        {{-- Step 3 — Receive instantly --}}
        <article class="w-72 shrink-0 overflow-hidden rounded-[12px] bg-zinc-100 dark:bg-[#1d3252] ring-1 ring-zinc-200 shadow-sm sm:w-auto">
            <div class="flex aspect-[4/3] items-center justify-center overflow-hidden bg-zinc-100 dark:bg-[#1d3252]">
                <x-illo name="payout" class="h-full w-full" />
            </div>
            <div class="bg-zinc-100 dark:bg-[#1d3252] p-5">
                <h3 class="text-base font-semibold text-zinc-900">3. Receive instantly</h3>
                <p class="mt-1.5 text-sm leading-relaxed text-zinc-600">
                    Your product arrives in seconds, to your email address and your clients dashboard if you are signed up with us ready to use.
                </p>
            </div>
        </article>

        </div>
    </div>
</section>

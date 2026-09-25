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

    // The copy names what we actually sell, so Flights and Stays drop out of
    // the sentence when their flag is off. Leaving them in would promise a
    // service with no catalogue, no page and no nav entry behind it.
    $offerings = array_values(array_filter([
        __('home.offering_gift_cards'),
        __('home.offering_esims'),
        \App\Support\FeatureFlag::categoryVisible('flights') ? __('home.offering_flights') : null,
        \App\Support\FeatureFlag::categoryVisible('stays') ? __('home.offering_stays') : null,
        __('home.offering_topups'),
    ]));
    $offeringsAnd = \Illuminate\Support\Arr::join($offerings, __('home.list_glue'), __('home.list_last_glue'));
    $offeringsList = implode(__('home.list_glue'), $offerings);
@endphp

<section data-reveal aria-label="{{ __('home.how_heading') }}">

    <h2 class="mb-5 text-xl font-bold text-zinc-900 sm:text-2xl">{{ __('home.how_heading') }}</h2>

    {{-- Mobile: horizontal scroll. Desktop: 3-col grid. Mobile py-2 prevents the card ring/shadow from being clipped. --}}
    <div class="-mx-4 overflow-x-auto px-4 py-2 [scrollbar-width:none] [&::-webkit-scrollbar]:hidden sm:mx-0 sm:overflow-visible sm:px-0 sm:py-0">
        <div class="flex w-max gap-4 sm:grid sm:w-full sm:grid-cols-3 sm:gap-5">

        {{-- Step 1 — Pick a product --}}
        <article class="w-72 shrink-0 overflow-hidden rounded-[12px] bg-zinc-100 dark:bg-[var(--ash)] ring-1 ring-zinc-200 shadow-sm sm:w-auto">
            <div class="flex aspect-[4/3] items-center justify-center overflow-hidden bg-zinc-100 dark:bg-[var(--ash)]">
                <x-illo name="cardFan" class="h-full w-full" />
            </div>
            <div class="bg-zinc-100 dark:bg-[var(--ash)] p-5">
                <h3 class="text-base font-semibold text-zinc-900">{{ __('home.how_step1_title') }}</h3>
                <p class="mt-1.5 text-sm leading-relaxed text-zinc-600">
                    {{ __('home.how_step1_body', ['count' => $productsRounded, 'offerings' => $offeringsAnd]) }}
                </p>
            </div>
        </article>

        {{-- Step 2 — Pay with crypto --}}
        <article class="w-72 shrink-0 overflow-hidden rounded-[12px] bg-zinc-100 dark:bg-[var(--ash)] ring-1 ring-zinc-200 shadow-sm sm:w-auto">
            <div class="flex aspect-[4/3] items-center justify-center overflow-hidden bg-zinc-100 dark:bg-[var(--ash)]">
                <x-illo name="payWeb" class="h-full w-full" />
            </div>
            <div class="bg-zinc-100 dark:bg-[var(--ash)] p-5">
                <h3 class="text-base font-semibold text-zinc-900">{{ __('home.how_step2_title') }}</h3>
                <p class="mt-1.5 text-sm leading-relaxed text-zinc-600">
                    {{ __('home.how_step2_body', ['count' => $productsRounded, 'offerings' => $offeringsList]) }}
                </p>
            </div>
        </article>

        {{-- Step 3 — Receive instantly --}}
        <article class="w-72 shrink-0 overflow-hidden rounded-[12px] bg-zinc-100 dark:bg-[var(--ash)] ring-1 ring-zinc-200 shadow-sm sm:w-auto">
            <div class="flex aspect-[4/3] items-center justify-center overflow-hidden bg-zinc-100 dark:bg-[var(--ash)]">
                <x-illo name="delivery" class="h-full w-full" />
            </div>
            <div class="bg-zinc-100 dark:bg-[var(--ash)] p-5">
                <h3 class="text-base font-semibold text-zinc-900">{{ __('home.how_step3_title') }}</h3>
                <p class="mt-1.5 text-sm leading-relaxed text-zinc-600">
                    {{ __('home.how_step3_body') }}
                </p>
            </div>
        </article>

        </div>
    </div>
</section>

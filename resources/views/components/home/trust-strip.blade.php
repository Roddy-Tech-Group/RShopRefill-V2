{{-- Trust signals strip. 4 separate cards.
     Mobile: 2 columns x 2 rows. Desktop (sm+): 4 columns x 1 row.
     Each icon is loaded as a file so it renders in its original colours.
     The "Wide Selection" tile reads the live product-variant count from the
     catalog (cached 10 min) so the marketing number tracks the real DB. --}}
@php
    $catalogVariantCount = \Illuminate\Support\Facades\Cache::remember(
        'trust_strip.variant_count',
        now()->addMinutes(10),
        fn () => (int) \App\Models\ProductVariant::where('is_available', true)->count(),
    );
    // Round down to the nearest hundred so the public number reads cleanly
    // even as the catalog grows by tens at a time, then floor at 100 so the
    // tile never reads "0+" on an empty/seeded environment.
    $catalogVariantsRounded = max(100, intdiv($catalogVariantCount, 100) * 100);
@endphp
<section data-reveal aria-label="Why shop with {{ $siteName }}">
    <ul class="grid grid-cols-2 gap-3 sm:grid-cols-4 sm:gap-5">

        {{-- Best Prices --}}
        <li class="flex min-h-[110px] items-center gap-3 rounded-[14px] border border-zinc-200 p-4 sm:min-h-0 sm:p-5 dark:border-zinc-700">
            <img src="{{ asset('assets/' . rawurlencode('best prices.svg')) }}" alt="" class="h-7 w-7 shrink-0 object-contain" loading="lazy">
            <div class="min-w-0 leading-tight">
                <p class="text-base font-semibold text-zinc-900 dark:text-white">Best Prices</p>
                <p class="text-sm text-zinc-600 dark:text-zinc-400">Competitive rates</p>
            </div>
        </li>

        {{-- Wide Selection --}}
        <li class="flex min-h-[110px] items-center gap-3 rounded-[14px] border border-zinc-200 p-4 sm:min-h-0 sm:p-5 dark:border-zinc-700">
            <img src="{{ asset('assets/' . rawurlencode('wide selection.svg')) }}" alt="" class="h-7 w-7 shrink-0 object-contain" loading="lazy">
            <div class="min-w-0 leading-tight">
                <p class="text-base font-semibold text-zinc-900 dark:text-white">Wide Selection</p>
                <p class="text-sm text-zinc-600 dark:text-zinc-400">{{ number_format($catalogVariantsRounded) }}+ products</p>
            </div>
        </li>

        {{-- Trusted by Thousands --}}
        <li class="flex min-h-[110px] items-center gap-3 rounded-[14px] border border-zinc-200 p-4 sm:min-h-0 sm:p-5 dark:border-zinc-700">
            <img src="{{ asset('assets/' . rawurlencode('trusted by millions.svg')) }}" alt="" class="h-7 w-7 shrink-0 object-contain" loading="lazy">
            <div class="min-w-0 leading-tight">
                <p class="text-base font-semibold text-zinc-900 dark:text-white">Trusted by Thousands</p>
                <p class="text-sm text-zinc-600 dark:text-zinc-400">Join our global community</p>
            </div>
        </li>

        {{-- Easy & Fast --}}
        <li class="flex min-h-[110px] items-center gap-3 rounded-[14px] border border-zinc-200 p-4 sm:min-h-0 sm:p-5 dark:border-zinc-700">
            <img src="{{ asset('assets/' . rawurlencode('Easy & Fast.webp')) }}" alt="" class="h-7 w-7 shrink-0 object-contain brightness-0 dark:invert" loading="lazy">
            <div class="min-w-0 leading-tight">
                <p class="text-base font-semibold text-zinc-900 dark:text-white">Easy & Fast</p>
                <p class="text-sm text-zinc-600 dark:text-zinc-400">Simple 3-step checkout</p>
            </div>
        </li>

    </ul>
</section>

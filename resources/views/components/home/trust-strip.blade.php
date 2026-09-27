{{-- Trust signals, as a compact inline row under the payment pyramid.
     Was four bordered cards; the panel already provides the surface, so a
     second set of boxes inside it read as boxes-in-a-box.
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
    <ul class="flex flex-wrap items-start justify-center gap-x-10 gap-y-6 sm:gap-x-14">

        @php
            // Title + supporting line, in one place: the row renders them
            // identically and only the copy differs.
            $signals = [
                ['icon' => 'best prices.svg',         'title' => 'Best Prices',          'note' => 'Competitive rates',       'mono' => false],
                ['icon' => 'wide selection.svg',      'title' => 'Wide Selection',       'note' => number_format($catalogVariantsRounded).'+ products', 'mono' => false],
                ['icon' => 'trusted by millions.svg', 'title' => 'Trusted by Thousands', 'note' => 'Join our global community', 'mono' => false],
                ['icon' => 'Easy & Fast.webp',        'title' => 'Easy & Fast',          'note' => 'Simple 3-step checkout',  'mono' => true],
            ];
        @endphp

        @foreach ($signals as $signal)
            <li class="flex items-center gap-2.5">
                <img
                    src="{{ asset('assets/' . rawurlencode($signal['icon'])) }}"
                    alt=""
                    @class([
                        'h-6 w-6 shrink-0 object-contain',
                        'brightness-0 dark:invert' => $signal['mono'],
                    ])
                    loading="lazy"
                >
                <span class="leading-tight">
                    <span class="block text-[15px] font-semibold text-zinc-900 dark:text-white">{{ $signal['title'] }}</span>
                    <span class="block text-[13px] text-[#0c1a36]/90 dark:text-zinc-400">{{ $signal['note'] }}</span>
                </span>
            </li>
        @endforeach

    </ul>
</section>

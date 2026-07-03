@php
    // About page. Dark-mode safe: bg-white/bg-zinc-50/bg-blue-50 remap to navy in
    // dark; coloured accents (text-blue-700) read in both themes.
    $img = fn (string $file) => asset('assets/'.rawurlencode($file));

    // Live catalog counters. Cached for 10 minutes so the about page stays fast.
    // Each value is floored to a marketing-round number so the copy reads
    // cleanly without odd granular figures (e.g. 23,756 -> 23,000+).
    $aboutStats = \Illuminate\Support\Facades\Cache::remember(
        'about.catalog_stats',
        now()->addMinutes(10),
        fn () => [
            'variants'   => (int) \App\Models\ProductVariant::where('is_available', true)->count(),
            'countries'  => (int) \App\Models\Product::where('is_active', true)
                ->whereNotNull('country_code')
                ->distinct('country_code')
                ->count('country_code'),
            'categories' => (int) \App\Models\Category::count(),
            'currencies' => (int) \App\Models\Product::whereNotNull('currency_code')
                ->distinct('currency_code')
                ->count('currency_code'),
        ],
    );
    $productsFloor   = max(1000, intdiv($aboutStats['variants'], 1000) * 1000);
    $countriesFloor  = max(10, intdiv($aboutStats['countries'], 10) * 10);
    $currenciesFloor = max(10, intdiv($aboutStats['currencies'], 10) * 10);

    $stats = [
        ['target' => $productsFloor,           'decimals' => 0, 'suffix' => '+',  'label' => 'Products'],
        ['target' => $countriesFloor,          'decimals' => 0, 'suffix' => '+',  'label' => 'Countries'],
        ['target' => $aboutStats['categories'],'decimals' => 0, 'suffix' => '+',  'label' => 'Categories'],
        ['target' => $currenciesFloor,         'decimals' => 0, 'suffix' => '+',  'label' => 'Currencies'],
        ['target' => 99.9,                     'decimals' => 1, 'suffix' => '%',  'label' => 'Uptime'],
        ['text' => 'Daily', 'label' => 'Support'],
    ];
@endphp

<x-layouts.app.header :title="'About Us | '.$siteName">

    {{-- ── Hero ──────────────────────────────────────────────── --}}
    <section class="border-b border-zinc-100 bg-blue-50">
        <div class="mx-auto w-full max-w-[1140px] px-4 py-14 text-center sm:px-6 sm:py-20">
            <span class="inline-flex items-center gap-2 rounded-[5px] bg-blue-100 px-3 py-1.5 text-[11px] font-bold uppercase tracking-[0.14em] text-blue-700">About {{ $siteName }}</span>
            <h1 class="mt-5 text-3xl font-bold tracking-tight text-blue-600 sm:text-4xl lg:text-5xl">The Global Digital Ecosystem</h1>
            <p class="mx-auto mt-3 max-w-xl text-sm leading-relaxed text-zinc-600 sm:text-base">
                One unified platform where commerce, travel, connectivity and payments work seamlessly together, for everyone, everywhere.
            </p>
        </div>
    </section>

    {{-- ── Trust banner ──────────────────────────────────────── --}}
    <section class="px-4 py-8 sm:px-6 sm:py-10">
        <div class="relative mx-auto w-full overflow-hidden bg-white ring-1 ring-zinc-100" style="max-width: 1400px; border-radius: 10px;">
            {{-- Slanted translucent-blue divider (matches the story card). --}}
            <div class="absolute inset-0" style="clip-path: polygon(0 60%, 100% 30%, 100% 100%, 0 100%); background-color: rgba(37, 99, 235, 0.12);" aria-hidden="true"></div>

            <div class="relative flex items-center justify-between gap-6 px-6 py-8 sm:px-8 sm:py-10">
                <img src="{{ $img('About svg 1.svg') }}" alt="" class="no-dark-invert hidden h-24 w-auto shrink-0 lg:block" loading="lazy">
                <dl class="grid flex-1 grid-cols-2 gap-x-4 gap-y-8 sm:grid-cols-3 lg:grid-cols-6">
                @foreach ($stats as $stat)
                    <div class="text-center">
                        @isset($stat['text'])
                            <dt class="text-2xl font-extrabold tracking-tight text-zinc-900 sm:text-3xl">{{ $stat['text'] }}</dt>
                        @else
                            <dt
                                class="text-2xl font-extrabold tracking-tight text-zinc-900 sm:text-3xl"
                                x-data="{ n: 0 }"
                                x-init="
                                    const tgt = {{ $stat['target'] }};
                                    if (window.matchMedia('(prefers-reduced-motion: reduce)').matches) { n = tgt; return; }
                                    const io = new IntersectionObserver((es) => {
                                        if (es[0].isIntersecting) {
                                            io.disconnect();
                                            let s = null;
                                            const tk = (t) => {
                                                if (! s) s = t;
                                                const p = Math.min((t - s) / 1400, 1);
                                                n = tgt * (1 - Math.pow(1 - p, 3));
                                                if (p < 1) requestAnimationFrame(tk); else n = tgt;
                                            };
                                            requestAnimationFrame(tk);
                                        }
                                    }, { threshold: 0.4 });
                                    io.observe($el);
                                "
                            ><span x-text="n.toFixed({{ $stat['decimals'] }})"></span>{{ $stat['suffix'] }}</dt>
                        @endisset
                        <dd class="mt-1 text-xs font-semibold uppercase tracking-wider text-zinc-500">{{ $stat['label'] }}</dd>
                    </div>
                @endforeach
                </dl>
                <img src="{{ $img('about 2.webp') }}" alt="" class="hidden h-24 w-auto shrink-0 lg:block" loading="lazy">
            </div>
        </div>
    </section>

    {{-- ── Story card ────────────────────────────────────────── --}}
    <section class="mx-auto w-full max-w-[1140px] px-4 py-12 sm:px-6 sm:py-16">
        <div class="overflow-hidden rounded-[28px] bg-white shadow-sm shadow-zinc-900/5 ring-1 ring-zinc-100">
            <div class="grid grid-cols-1 lg:grid-cols-2">

                {{-- Left: about text with a slanting white/blue divider --}}
                <div class="relative overflow-hidden bg-white">
                    {{-- Slanted light-blue fill across the lower portion (diagonal divider). --}}
                    <div class="absolute inset-0" style="clip-path: polygon(0 60%, 100% 35%, 100% 100%, 0 100%); background-color: rgba(37, 99, 235, 0.12);" aria-hidden="true"></div>
                    <div class="relative p-7 sm:p-10">
                    <span class="inline-flex items-center gap-2 rounded-[5px] bg-blue-100 px-3 py-1.5 text-[11px] font-bold uppercase tracking-[0.14em] text-blue-700">Our story</span>
                    <h2 class="mt-4 text-2xl font-bold tracking-tight text-zinc-900 sm:text-3xl">About {{ $siteName }}</h2>

                    <div class="mt-5 space-y-4 text-sm leading-relaxed text-zinc-600 sm:text-base">
                        <p>{{ $siteName }} was founded in 2024 with a simple but powerful vision: make global digital commerce accessible, instant, and stress-free for everyone, everywhere. What started as a small operation manually selling digital products quickly revealed a bigger problem in the global market. Millions of people across different countries faced the same challenges every day: failed international payments, region restrictions, limited access to global brands, unreliable top-up services, and the constant struggle of finding trusted platforms to complete simple digital purchases. The idea behind {{ $siteName }} was born from solving these real-world frustrations. In the early stages, orders were processed manually, customer by customer, with a strong focus on trust, speed, and reliability. As demand grew, the founder crossed paths with a young, ambitious, and highly innovative technology visionary who would later become the company's Chief Technology Officer (CTO). United by a shared mission and relentless drive to build something meaningful, they combined operational experience with advanced technology to transform a small digital service into a scalable international platform. Together, they built {{ $siteName }}, a 24/7 global digital marketplace designed to simplify modern life.</p>

                        <p>Today, {{ $siteName }} connects users to digital products and essential services across multiple countries and regions. From gift cards purchased directly from global brands, to instant eSIM activation that keeps travelers connected anywhere in the world, to airtime and utility top-ups that remove dependence on physical stores, the platform is designed around convenience, accessibility, and speed. Beyond digital products, {{ $siteName }} continues expanding its ecosystem to include flights, global stays, and modern financial accessibility tools that help users move, shop, travel, and live without borders. By supporting crypto and multiple payment methods, the platform empowers users to spend value in the way most convenient to them, whether paying bills, sending gifts, purchasing services, or managing everyday digital needs. {{ $siteName }} is more than a marketplace. It is infrastructure for a connected generation. The company's mission is to eliminate the stress of failed payments, region limitations, and fragmented digital services by creating one unified platform where technology, commerce, travel, and connectivity work seamlessly together.</p>

                        <div class="rounded-[12px] bg-white p-5 shadow-sm shadow-zinc-900/5 ring-1 ring-zinc-100">
                            <p class="text-base font-bold text-blue-700">Built for speed.</p>
                            <p class="text-base font-bold text-blue-700">Designed for convenience.</p>
                            <p class="text-base font-bold text-blue-700">Powered by innovation.</p>
                        </div>

                        <p class="font-semibold text-blue-600">{{ $siteName }} is redefining how the world accesses digital services, making everyday life smarter, simpler, and truly borderless.</p>
                    </div>
                    </div>
                </div>

                {{-- Right: image (full-bleed photo filling the panel) --}}
                <div class="relative order-first bg-blue-50 lg:order-none" style="min-height: 20rem;">
                    <img src="{{ $img('About Us.webp') }}" alt="The people behind {{ $siteName }}" class="object-cover" style="position: absolute; inset: 0; height: 100%; width: 100%;" loading="lazy">
                </div>
            </div>
        </div>
    </section>

</x-layouts.app.header>

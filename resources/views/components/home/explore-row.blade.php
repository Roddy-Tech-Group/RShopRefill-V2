{{--
    eSIMs, flights and stays row. 3 photo cards with a dark backdrop-blur strip at the bottom for the icon + title + subtitle.
    Drop the 3 background photos into public/assets/ using the filenames below:
      - card-esim.jpg     (city street scene with phone)
      - card-flights.jpg  (plane wing above sky/ocean)
      - card-stays.jpg    (resort, palms, pool)
--}}
<section data-reveal aria-label="eSIMs, flights and stays">

    <div class="mb-4 min-w-0">
        <h2 class="text-lg font-bold text-zinc-900 sm:text-xl">eSIMs, flights & stays</h2>
        <p class="mt-0.5 text-base text-zinc-600">Connect, explore, and relax.</p>
    </div>

    <div class="grid grid-cols-1 gap-5 sm:grid-cols-3">

        {{-- eSIM (auto-fades between the two animated eSIM scenes every 4
             seconds, pauses on hover). Inline SVGs - no image fetch. --}}
        <article
            x-data="{
                current: 0,
                paused: false,
                init() {
                    setInterval(() => {
                        if (this.paused) return;
                        this.current = (this.current + 1) % 2;
                    }, 4000);
                }
            }"
            @mouseenter="paused = true"
            @mouseleave="paused = false"
            class="group relative aspect-[16/10] overflow-hidden rounded-[12px] bg-[#070D1A] ring-1 ring-zinc-200 shadow-sm"
        >
            <div :class="current === 0 ? 'opacity-100' : 'opacity-0'" class="absolute inset-0 transition-opacity duration-700 ease-in-out group-hover:scale-105">
                <x-illos.esim-people class="h-full w-full object-cover" preserveAspectRatio="xMidYMid slice" />
            </div>
            <div :class="current === 1 ? 'opacity-100' : 'opacity-0'" class="absolute inset-0 transition-opacity duration-700 ease-in-out group-hover:scale-105">
                <x-illos.esim-travelers class="h-full w-full object-cover" preserveAspectRatio="xMidYMid slice" />
            </div>
            <div class="absolute inset-x-0 bottom-0 h-[35%] bg-black/55 backdrop-blur-[2px]" aria-hidden="true"></div>
            <div class="absolute inset-x-0 bottom-0 p-5 text-white">
                <div class="flex items-center gap-2">
                    <img src="{{ asset('assets/' . rawurlencode('esim.svg')) }}" alt="" class="h-5 w-5 shrink-0 brightness-0 invert" loading="lazy">
                    <h3 class="text-xl font-bold leading-tight">eSIM</h3>
                </div>
                <p class="mt-1 text-sm leading-snug text-white/90">Ditch physical SIM cards and stay connected globally with instant eSIM activation.</p>
            </div>
            <a href="{{ route('shop.esims') }}" wire:navigate class="absolute inset-0 z-20" aria-label="Browse eSIMs"></a>
        </article>

        {{-- Flights (auto-fades between travel.webp and flight2.webp every 4 seconds, pauses on hover) --}}
        <article
            x-data="{
                images: ['{{ asset('assets/travel.webp') }}', '{{ asset('assets/flight2.webp') }}'],
                current: 0,
                paused: false,
                init() {
                    setInterval(() => {
                        if (this.paused) return;
                        this.current = (this.current + 1) % this.images.length;
                    }, 4000);
                }
            }"
            @mouseenter="paused = true"
            @mouseleave="paused = false"
            class="group relative aspect-[16/10] overflow-hidden rounded-[12px] ring-1 ring-zinc-200 shadow-sm"
        >
            <template x-for="(src, i) in images" :key="src">
                <img
                    :src="src"
                    alt="Flights"
                    :class="current === i ? 'opacity-100' : 'opacity-0'"
                    class="absolute inset-0 h-full w-full object-cover transition-opacity duration-700 ease-in-out group-hover:scale-105"
                    loading="lazy"
                >
            </template>
            <div class="absolute inset-x-0 bottom-0 h-[35%] bg-black/55 backdrop-blur-[2px]" aria-hidden="true"></div>
            <div class="absolute inset-x-0 bottom-0 p-5 text-white">
                <div class="flex items-center gap-2">
                    <img src="{{ asset('assets/' . rawurlencode('flight 2.svg')) }}" alt="" class="h-5 w-5 shrink-0 brightness-0 invert" loading="lazy">
                    <h3 class="text-xl font-bold leading-tight">Flights</h3>
                </div>
                <p class="mt-1 text-sm leading-snug text-white/90">Travel smarter with secure bookings, fast confirmations, and global access from anywhere in the world.</p>
            </div>
        </article>

        {{-- Stays (auto-fades between stay.webp and stay2.webp every 4 seconds, pauses on hover) --}}
        <article
            x-data="{
                images: ['{{ asset('assets/stay.webp') }}', '{{ asset('assets/stay2.webp') }}'],
                current: 0,
                paused: false,
                init() {
                    setInterval(() => {
                        if (this.paused) return;
                        this.current = (this.current + 1) % this.images.length;
                    }, 4000);
                }
            }"
            @mouseenter="paused = true"
            @mouseleave="paused = false"
            class="group relative aspect-[16/10] overflow-hidden rounded-[12px] ring-1 ring-zinc-200 shadow-sm"
        >
            <template x-for="(src, i) in images" :key="src">
                <img
                    :src="src"
                    alt="Stays"
                    :class="current === i ? 'opacity-100' : 'opacity-0'"
                    class="absolute inset-0 h-full w-full object-cover transition-opacity duration-700 ease-in-out group-hover:scale-105"
                    loading="lazy"
                >
            </template>
            <div class="absolute inset-x-0 bottom-0 h-[35%] bg-black/55 backdrop-blur-[2px]" aria-hidden="true"></div>
            <div class="absolute inset-x-0 bottom-0 p-5 text-white">
                <div class="flex items-center gap-2">
                    <img src="{{ asset('assets/' . rawurlencode('stay 2.svg')) }}" alt="" class="h-5 w-5 shrink-0 brightness-0 invert" loading="lazy">
                    <h3 class="text-xl font-bold leading-tight">Stays</h3>
                </div>
                <p class="mt-1 text-sm leading-snug text-white/90">Turn digital currency into real-world travel, comfort, and unforgettable experiences.</p>
            </div>
        </article>

    </div>
</section>

@php
    // How It Works - marketing page. Customer-facing, so copy stays generic (no
    // payment-provider names). Brand assets are reused from public/assets.
    $img = fn (string $file) => asset('assets/'.rawurlencode($file));
    // Master Rcoin switch - when off, the Rcoin example card and earn copy
    // disappear from the Step 2 panel so we don't promise rewards we don't pay.
    $rcoinEnabled = (bool) \App\Models\Setting::get('rcoin_enabled', true);

    $features = [
        ['icon' => 'fast.webp',            'title' => 'Effortless and quick',      'desc' => 'Pick, pay and receive in seconds. No queues, no waiting, no friction.'],
        ['icon' => 'secure payments.svg', 'title' => 'Privacy and security',       'desc' => 'Your details and every payment are protected with strong security at each step.'],
        ['icon' => 'wide selection.svg',  'title' => 'Wide selection of products', 'desc' => 'Gift cards, eSIMs, mobile top-ups and bill payments from across the world.'],
    ];

    $giftLogos = collect([
        ['src' => 'apple.webp',      'name' => 'Apple',       'tagline' => 'For everything Apple',     'color' => '#a2aaad'],
        ['src' => 'amazon.webp',     'name' => 'Amazon',      'tagline' => 'Shop millions of items',   'color' => '#ff9900'],
        ['src' => 'steam.webp',      'name' => 'Steam',       'tagline' => 'Games and more',           'color' => '#1b2838'],
        ['src' => 'playstation.webp','name' => 'PlayStation', 'tagline' => 'Play has no limits',       'color' => '#0070d1'],
        ['src' => 'netflix.webp',   'name' => 'Netflix',     'tagline' => 'Stories without limits',   'color' => '#e50914'],
        ['src' => 'googleplay.webp', 'name' => 'Google Play', 'tagline' => 'Apps, games and more',     'color' => '#34a853'],
        ['src' => 'spotify.webp',   'name' => 'Spotify',     'tagline' => 'Music for everyone',       'color' => '#1db954'],
        ['src' => 'nintendo.webp',  'name' => 'Nintendo',    'tagline' => 'Play anywhere',            'color' => '#e60012'],
        ['src' => 'twitch.webp',    'name' => 'Twitch',      'tagline' => 'Support your streamers',   'color' => '#9146ff'],
    ])->map(fn ($l) => ['src' => $img($l['src']), 'name' => $l['name'], 'tagline' => $l['tagline'], 'color' => $l['color']])->all();

    // 'mono' => flatten the blue line-art icon to black in light mode + white in
    // dark mode (brightness-0 dark:invert). Coloured crypto logos stay as-is.
    $payments = [
        ['src' => 'credit card payment.webp',    'name' => 'Card',          'mono' => true],
        ['src' => 'apply pay.webp',              'name' => 'Apple Pay',     'mono' => true],
        ['src' => 'Bank transfer.webp',          'name' => 'Bank Transfer', 'mono' => true],
        ['src' => 'MOMO.svg',                   'name' => 'Mobile Money', 'mono' => true],
        ['src' => 'BTC.svg',                    'name' => 'Bitcoin'],
        ['src' => 'USDT.svg',                   'name' => 'USDT'],
        ['src' => 'ETH.svg',                    'name' => 'Ethereum'],
        ['src' => 'Wallet.svg',                 'name' => 'Wallet'],
    ];
@endphp

<x-layouts.app.header :title="'How It Works | '.$siteName">

    <style>
        @keyframes hiwMarquee { from { transform: translateX(0); } to { transform: translateX(-50%); } }
        .hiw-marquee { animation: hiwMarquee 28s linear infinite; }
        .hiw-marquee:hover { animation-play-state: paused; }
        @media (prefers-reduced-motion: reduce) { .hiw-marquee { animation: none; } }
    </style>

    {{-- ── Hero (matches About / FAQ / Help pattern: soft blue-50 bg,
         blue-600 headline, blue-100 eyebrow chip) ─────────────────── --}}
    <section class="border-b border-zinc-100 bg-blue-50">
        <div class="mx-auto w-full max-w-[1140px] px-4 py-14 text-center sm:px-6 sm:py-20">
            <span class="inline-flex items-center gap-2 rounded-[5px] bg-blue-100 px-3 py-1.5 text-[11px] font-bold uppercase tracking-[0.14em] text-blue-700">How it works</span>
            <h1 class="mt-5 text-3xl font-bold tracking-tight text-blue-600 sm:text-4xl lg:text-5xl">Shopping made simple</h1>
            <p class="mx-auto mt-3 max-w-xl text-sm leading-relaxed text-zinc-600 sm:text-base">
                From gift cards to eSIMs, buying digital products on {{ $siteName }} takes just a few taps. Here is how it all comes together.
            </p>
        </div>
    </section>

    {{-- ── Intro: text left, picture right ───────────────────── --}}
    <section class="mx-auto w-full max-w-[1140px] px-4 py-14 sm:px-6 sm:py-20">
        <div class="grid grid-cols-1 items-center gap-10 lg:grid-cols-2 lg:gap-14">
            <div class="text-center lg:text-left">
                <h2 class="text-2xl font-bold tracking-tight text-zinc-900 sm:text-3xl">How It Works</h2>
                <p class="mx-auto mt-4 max-w-xl text-sm leading-relaxed text-zinc-600 sm:text-base lg:mx-0">
                    One secure account powering your entire digital lifestyle. Explore global gift cards, gaming, entertainment, eSIMs, subscriptions, and more all in one place.
                </p>
                <p class="mx-auto mt-3 max-w-xl text-sm leading-relaxed text-zinc-600 sm:text-base lg:mx-0">
                    Choose your product, checkout with your preferred payment method, and receive instant delivery directly to your dashboard and email within seconds.
                </p>
                <p class="mt-5 text-base font-bold text-blue-600">Fast. Secure. Borderless.</p>
                <p class="mt-1 text-sm font-semibold text-zinc-900">Digital access without limits.</p>
                <div class="mt-7 flex flex-col gap-3 sm:flex-row sm:justify-center lg:justify-start">
                    <a href="{{ route('shop.gift-cards') }}" wire:navigate class="inline-flex items-center justify-center gap-2 rounded-[6px] bg-blue-600 px-5 py-3 text-sm font-semibold text-white transition-colors hover:bg-blue-700">
                        Start shopping
                        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M8.25 4.5l7.5 7.5-7.5 7.5"/></svg>
                    </a>
                    <a href="{{ route('shop.help') }}" wire:navigate class="inline-flex items-center justify-center gap-2 rounded-[6px] border border-zinc-200 bg-white px-5 py-3 text-sm font-semibold text-zinc-700 transition-colors hover:bg-zinc-50">
                        Visit Help Center
                    </a>
                </div>
            </div>
            <div class="relative mx-auto w-full max-w-md lg:max-w-none">
                <div class="overflow-hidden rounded-[24px] ring-1 ring-zinc-100">
                    <img src="{{ $img('How it work 11.webp') }}" alt="How shopping works on {{ $siteName }}" class="h-full w-full object-cover" loading="lazy">
                </div>
            </div>
        </div>
    </section>

    {{-- ── Easy and convenient: 3-up feature grid ────────────── --}}
    <section class="border-y border-zinc-100 bg-zinc-50">
        <div class="mx-auto w-full max-w-[1140px] px-4 py-14 sm:px-6 sm:py-16">
            <div class="text-center">
                <h2 class="text-2xl font-bold tracking-tight text-zinc-900 sm:text-3xl">Easy and convenient</h2>
                <p class="mx-auto mt-2 max-w-lg text-sm text-zinc-600">Built to be the fastest, safest way to buy digital products anywhere.</p>
            </div>
            <div class="mt-9 grid grid-cols-1 gap-5 sm:grid-cols-3">
                @foreach ($features as $feature)
                    <div class="rounded-[12px] bg-white p-6 text-center ring-1 ring-zinc-100">
                        <span class="mx-auto flex h-14 w-14 items-center justify-center rounded-[12px] bg-blue-50">
                            <img src="{{ $img($feature['icon']) }}" alt="" class="h-7 w-7 object-contain" loading="lazy">
                        </span>
                        <h3 class="mt-4 text-base font-bold text-zinc-900">{{ $feature['title'] }}</h3>
                        <p class="mt-1.5 text-sm leading-relaxed text-zinc-600">{{ $feature['desc'] }}</p>
                    </div>
                @endforeach
            </div>
        </div>
    </section>

    {{-- ── Steps ─────────────────────────────────────────────── --}}
    <section class="mx-auto w-full max-w-[1140px] px-4 py-14 sm:px-6 sm:py-16">

        {{-- Step 1: rotating gift-card logos (left) + text (right) --}}
        <div class="grid grid-cols-1 items-center gap-10 lg:grid-cols-2 lg:gap-14">
            <div
                x-data="{ cards: @js($giftLogos), i: 0 }"
                x-init="setInterval(() => i = (i + 1) % cards.length, 3000)"
                class="order-1 rounded-[24px] bg-blue-50 p-6 ring-1 ring-zinc-100 sm:p-8"
            >
                <p class="text-xs font-semibold uppercase tracking-wider text-zinc-500">Popular gift cards</p>

                {{-- Rotating gift card — styled like a physical card, so it stays
                     light in both themes (theme-independent colours). --}}
                <div class="relative mx-auto mt-5 aspect-[1.586/1] w-full max-w-md">
                    <template x-for="(card, idx) in cards" :key="idx">
                        <div
                            x-show="i === idx"
                            x-transition:enter="transition ease-out duration-500"
                            x-transition:enter-start="opacity-0 scale-95"
                            x-transition:enter-end="opacity-100 scale-100"
                            x-transition:leave="transition ease-in duration-300"
                            x-transition:leave-start="opacity-100"
                            x-transition:leave-end="opacity-0"
                            class="absolute inset-0 flex flex-col items-center justify-center gap-4 overflow-hidden rounded-[12px] bg-[#f5f5f7] p-6 shadow-lg shadow-zinc-900/15 ring-1 ring-black/5"
                        >
                            <img :src="card.src" :alt="card.name" class="h-20 max-w-[65%] object-contain sm:h-24">
                            <p class="text-center text-lg font-bold text-[#18181b] sm:text-xl" x-text="card.tagline"></p>
                            <span class="absolute inset-x-0 bottom-0 h-2.5" :style="{ backgroundColor: card.color }"></span>
                        </div>
                    </template>
                </div>
            </div>
            <div class="order-2 text-center lg:text-left">
                <span class="inline-flex h-9 items-center rounded-[12px] bg-blue-600 px-4 text-sm font-bold text-white">Step 1</span>
                <h3 class="mt-4 text-xl font-bold text-zinc-900 sm:text-2xl">Pick your product</h3>
                <p class="mt-2 max-w-md text-sm leading-relaxed text-zinc-600 lg:mx-0 mx-auto">
                    Browse hundreds of brands across gift cards, eSIMs, mobile top-ups and bill payments. Find what you need in your region in seconds.
                </p>
            </div>
        </div>

        {{-- Step 2: text (left) + price/Rcoin example card (right) --}}
        <div class="mt-16 grid grid-cols-1 items-center gap-10 lg:grid-cols-2 lg:gap-14">
            <div class="order-2 text-center lg:order-1 lg:text-left">
                <span class="inline-flex h-9 items-center rounded-[12px] bg-blue-600 px-4 text-sm font-bold text-white">Step 2</span>
                <h3 class="mt-4 text-xl font-bold text-zinc-900 sm:text-2xl">{{ $rcoinEnabled ? 'Choose an amount and earn' : 'Choose an amount' }}</h3>
                <p class="mt-2 max-w-md text-sm leading-relaxed text-zinc-600 lg:mx-0 mx-auto">
                    Select a value and see your estimated price up front, in your own currency.{{ $rcoinEnabled ? ' Every order earns you Rcoin you can redeem later.' : '' }}
                </p>
            </div>
            <div class="order-1 lg:order-2">
                <div class="mx-auto w-full max-w-sm rounded-[24px] bg-white p-6 shadow-sm shadow-zinc-900/5 ring-1 ring-zinc-100">
                    <div class="flex items-center gap-3">
                        <span class="flex h-12 w-12 items-center justify-center rounded-[12px] bg-zinc-50 ring-1 ring-zinc-100">
                            <img src="{{ $img('amazon.webp') }}" alt="" class="h-7 w-7 object-contain" loading="lazy">
                        </span>
                        <div>
                            <p class="text-sm font-bold text-zinc-900">Amazon Gift Card</p>
                            <p class="text-xs text-zinc-600">United States</p>
                        </div>
                    </div>
                    <div class="mt-5 space-y-3 text-sm">
                        <div class="flex items-center justify-between">
                            <span class="text-zinc-600">Face value</span>
                            <span class="font-semibold text-zinc-900">$50.00</span>
                        </div>
                        <div class="flex items-center justify-between">
                            <span class="text-zinc-600">Estimated price</span>
                            <span class="font-semibold text-zinc-900">$51.00</span>
                        </div>
                        @if ($rcoinEnabled)
                            <div class="flex items-center justify-between rounded-[12px] bg-blue-50 px-3 py-2.5">
                                <span class="font-medium text-blue-700">Rcoin earned</span>
                                <span class="font-bold text-blue-700">+25 Rcoin</span>
                            </div>
                        @endif
                    </div>
                </div>
            </div>
        </div>
    </section>

    {{-- ── We accept: payment carousel ───────────────────────── --}}
    <section class="border-y border-zinc-100 bg-zinc-50">
        <div class="mx-auto w-full max-w-[1140px] px-4 py-12 sm:px-6 sm:py-14">
            <p class="text-center text-xs font-semibold uppercase tracking-wider text-zinc-500">We accept</p>
            <div class="relative mt-6 overflow-hidden">
                <div class="hiw-marquee flex w-max items-center gap-4">
                    @foreach (array_merge($payments, $payments) as $pay)
                        <div class="flex shrink-0 items-center gap-2.5 rounded-[12px] bg-white px-4 py-3 ring-1 ring-zinc-100">
                            <img src="{{ $img($pay['src']) }}" alt="" @class(['h-6 w-6 object-contain', 'brightness-0 dark:invert' => ! empty($pay['mono'])]) loading="lazy">
                            <span class="whitespace-nowrap text-sm font-semibold text-zinc-800">{{ $pay['name'] }}</span>
                        </div>
                    @endforeach
                </div>
            </div>
        </div>
    </section>

    {{-- ── Steps 3 + 4 ───────────────────────────────────────── --}}
    <section class="mx-auto w-full max-w-[1140px] px-4 py-14 sm:px-6 sm:py-16">

        {{-- Step 3: payment-methods image (left) + text (right) --}}
        <div class="grid grid-cols-1 items-center gap-10 lg:grid-cols-2 lg:gap-14">
            <div class="order-1">
                <div class="rounded-[24px] bg-blue-50 p-7 ring-1 ring-zinc-100">
                    <div class="grid grid-cols-2 gap-3">
                        @foreach (array_slice($payments, 0, 6) as $pay)
                            <div class="flex items-center gap-2.5 rounded-[12px] bg-white px-3 py-3 ring-1 ring-zinc-100">
                                <img src="{{ $img($pay['src']) }}" alt="" @class(['h-6 w-6 shrink-0 object-contain', 'brightness-0 dark:invert' => ! empty($pay['mono'])]) loading="lazy">
                                <span class="truncate text-xs font-semibold text-zinc-800">{{ $pay['name'] }}</span>
                            </div>
                        @endforeach
                    </div>
                </div>
            </div>
            <div class="order-2 text-center lg:text-left">
                <span class="inline-flex h-9 items-center rounded-[12px] bg-blue-600 px-4 text-sm font-bold text-white">Step 3</span>
                <h3 class="mt-4 text-xl font-bold text-zinc-900 sm:text-2xl">Pay your way</h3>
                <p class="mt-2 max-w-md text-sm leading-relaxed text-zinc-600 lg:mx-0 mx-auto">
                    Settle with your wallet balance, card, bank transfer, mobile money or crypto. Pick whatever is easiest, the choice is always yours.
                </p>
            </div>
        </div>

        {{-- Step 4: text (left) + enjoy image (right) --}}
        <div class="mt-16 grid grid-cols-1 items-center gap-10 lg:grid-cols-2 lg:gap-14">
            <div class="order-2 text-center lg:order-1 lg:text-left">
                <span class="inline-flex h-9 items-center rounded-[12px] bg-blue-600 px-4 text-sm font-bold text-white">Step 4</span>
                <h3 class="mt-4 text-xl font-bold text-zinc-900 sm:text-2xl">Enjoy your product or service</h3>
                <p class="mt-2 max-w-md text-sm leading-relaxed text-zinc-600 lg:mx-0 mx-auto">
                    Your codes, PINs and activation details land in your dashboard and email the moment your payment clears. Redeem and enjoy right away.
                </p>
            </div>
            <div class="order-1 lg:order-2">
                <div class="mx-auto w-full max-w-md overflow-hidden rounded-[24px] bg-blue-50 p-6 ring-1 ring-zinc-100">
                    <img src="{{ $img('step 3.webp') }}" alt="Enjoy your product instantly" class="mx-auto h-auto w-full max-w-sm object-contain" loading="lazy">
                </div>
            </div>
        </div>
    </section>

    {{-- ── Final CTA ─────────────────────────────────────────── --}}
    <section class="bg-blue-600">
        <div class="mx-auto w-full max-w-[1140px] px-4 py-16 text-center sm:px-6">
            <h2 class="text-2xl font-bold text-white sm:text-3xl">Ready to shop today?</h2>
            <p class="mx-auto mt-2 max-w-md text-sm leading-relaxed text-blue-100">
                Join thousands buying digital products the smarter way. It only takes a minute to get started.
            </p>
            <div class="mt-7 flex flex-col gap-3 sm:flex-row sm:justify-center">
                <a href="{{ route('shop.gift-cards') }}" wire:navigate class="inline-flex items-center justify-center gap-2 rounded-[6px] bg-white px-6 py-3 text-sm font-semibold text-blue-700 transition-colors hover:bg-blue-50">
                    Start shopping
                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M8.25 4.5l7.5 7.5-7.5 7.5"/></svg>
                </a>
                @guest
                    <button type="button" @click="$dispatch('open-auth-modal', { mode: 'register' })" class="inline-flex items-center justify-center gap-2 rounded-[6px] border border-white/40 px-6 py-3 text-sm font-semibold text-white transition-colors hover:bg-white/10">
                        Create an account
                    </button>
                @endguest
            </div>
        </div>
    </section>

</x-layouts.app.header>

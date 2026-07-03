@php
    // Branded "coming soon" page, shared by /flights and /stays. The `service`
    // data flag (set on the route) selects the per-service copy and artwork.
    $service = $service ?? 'flights';

    $m = match ($service) {
        'stays' => [
            'name'     => 'Stays',
            'tagline'  => 'Book stays anywhere. Pay your way.',
            'blurb'    => "Hotels, apartments and getaways are coming to ".$siteName.". Soon you'll book a place to stay anywhere in the world and settle with your wallet, card or crypto, all from one account.",
            'image'    => 'stay2.webp',
            'icon'     => 'stay 2.svg',
            'accent'   => 'bg-orange-500',
            'features' => [
                ['Worldwide stays', 'Hotels and apartments across every region we serve.'],
                ['Pay your way', 'Wallet balance, card or crypto at checkout.'],
                ['Instant confirmation', 'Booking details delivered straight to your dashboard.'],
            ],
        ],
        default => [
            'name'     => 'Flights',
            'tagline'  => 'Book flights. Pay with crypto or wallet.',
            'blurb'    => "Flight booking is landing on ".$siteName." soon. Search routes worldwide and pay with your wallet, card or crypto, all from one place, no card borders, no stress.",
            'image'    => 'flight2.webp',
            'icon'     => 'flight 2.svg',
            'accent'   => 'bg-indigo-500',
            'features' => [
                ['Global routes', 'Search and compare flights across the world.'],
                ['Pay your way', 'Wallet balance, card or crypto at checkout.'],
                ['Tickets in your dashboard', 'E-tickets delivered the moment you book.'],
            ],
        ],
    };

    // Route name swaps between storefront + dashboard chrome so the CTA keeps
    // the user on whichever side they entered from.
    $inDash = request()->is('dashboard/shop*') && auth()->check();
    $shopRoute = fn (string $name, $params = []) => route(($inDash ? 'dashboard.shop.' : 'shop.').$name, $params);
@endphp

<x-shop.layout :title="$m['name'] . ' - Coming Soon | '.$siteName">

    {{-- Mobile category picker (dark pill + slide-up sheet) so customers can
         hop to a live category from the coming-soon page. `$service` matches
         the category slug ('flights' / 'stays'). --}}
    <div class="mx-auto w-full max-w-[1140px] px-4 pt-4 sm:hidden">
        <x-shop.category-picker :active="$service" />
    </div>

    <section class="mx-auto w-full max-w-[1140px] px-4 py-12 sm:px-6 sm:py-16 lg:py-24">
        <div class="grid grid-cols-1 items-center gap-10 lg:grid-cols-2 lg:gap-14">

            {{-- ── Copy ─────────────────────────────────────────────── --}}
            <div class="text-center lg:text-left">
                {{-- Coming-soon badge --}}
                <span class="inline-flex items-center gap-2 rounded-[12px] bg-blue-100 px-3 py-1.5 text-[11px] font-bold uppercase tracking-[0.14em] text-blue-700">
                    <span class="flex h-4 w-4 items-center justify-center rounded-[5px] {{ $m['accent'] }}">
                        <img src="{{ asset('assets/' . rawurlencode($m['icon'])) }}" alt="" class="h-2.5 w-2.5 brightness-0 invert" loading="lazy">
                    </span>
                    Coming soon
                </span>

                <h1 class="mt-5 text-3xl font-bold tracking-tight text-zinc-900 sm:text-4xl lg:text-5xl">{{ $m['name'] }}</h1>
                <p class="mt-2 text-lg font-semibold text-blue-600">{{ $m['tagline'] }}</p>
                <p class="mx-auto mt-3 max-w-md text-sm leading-relaxed text-zinc-600 sm:text-base lg:mx-0">{{ $m['blurb'] }}</p>

                {{-- What to expect --}}
                <ul class="mx-auto mt-7 max-w-md space-y-3.5 text-left lg:mx-0">
                    @foreach ($m['features'] as [$title, $desc])
                        <li class="flex items-start gap-3">
                            <span class="mt-0.5 flex h-5 w-5 shrink-0 items-center justify-center rounded-[12px] bg-blue-600">
                                <svg class="h-3 w-3 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="3" aria-hidden="true">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5"/>
                                </svg>
                            </span>
                            <div>
                                <p class="text-sm font-bold text-zinc-900">{{ $title }}</p>
                                <p class="mt-0.5 text-xs text-zinc-600">{{ $desc }}</p>
                            </div>
                        </li>
                    @endforeach
                </ul>

                {{-- CTAs — point at what is live today --}}
                <div class="mt-8 flex flex-col gap-3 sm:flex-row sm:justify-center lg:justify-start">
                    <a href="{{ $shopRoute('gift-cards') }}" wire:navigate
                        class="inline-flex items-center justify-center gap-2 rounded-[6px] bg-blue-600 px-5 py-3 text-sm font-semibold text-white transition-colors hover:bg-blue-700">
                        Shop what's available
                        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M8.25 4.5l7.5 7.5-7.5 7.5"/>
                        </svg>
                    </a>
                    <a href="{{ route('home') }}" wire:navigate
                        class="inline-flex items-center justify-center gap-2 rounded-[6px] border border-zinc-200 bg-white px-5 py-3 text-sm font-semibold text-zinc-700 transition-colors hover:bg-zinc-50">
                        Back to home
                    </a>
                </div>
            </div>

            {{-- ── Visual ───────────────────────────────────────────── --}}
            <div class="relative mx-auto w-full max-w-md lg:max-w-none">
                <div class="overflow-hidden rounded-[24px] shadow-xl shadow-zinc-900/10 ring-1 ring-zinc-200">
                    <img
                        src="{{ asset('assets/' . rawurlencode($m['image'])) }}"
                        alt="{{ $m['name'] }} on {{ $siteName }}"
                        class="aspect-[4/3] h-full w-full object-cover"
                        loading="lazy"
                    >
                </div>

                {{-- Floating launch chip --}}
                <div class="absolute -bottom-5 left-5 flex items-center gap-2.5 rounded-[12px] bg-white p-3 pr-4 shadow-lg shadow-zinc-900/10 ring-1 ring-zinc-100">
                    <span class="flex h-9 w-9 items-center justify-center rounded-[12px] {{ $m['accent'] }}">
                        <img src="{{ asset('assets/' . rawurlencode($m['icon'])) }}" alt="" class="h-5 w-5 brightness-0 invert" loading="lazy">
                    </span>
                    <div class="leading-tight">
                        <p class="text-[10px] font-semibold uppercase tracking-wider text-zinc-500">{{ $m['name'] }}</p>
                        <p class="text-sm font-bold text-zinc-900">Launching soon</p>
                    </div>
                </div>
            </div>

        </div>
    </section>

</x-shop.layout>

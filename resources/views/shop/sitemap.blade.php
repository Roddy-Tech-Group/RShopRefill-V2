@php
    // HTML sitemap — a human-friendly index of every section. Dark-mode safe.
    $columns = [
        // GoMarket stays out of the index until its feature flag is on.
        'Shop' => array_values(array_filter([
            ['Gift Cards', route('shop.gift-cards')],
            \App\Support\FeatureFlag::on('gomarket', false) ? ['GoMarket', route('shop.gomarket')] : null,
            ['eSIMs', route('shop.esims')],
            ['Mobile top up', route('shop.topups')],
            ['Bill payments', route('shop.bills')],
            \App\Support\FeatureFlag::categoryVisible('flights') ? ['Flights', route('shop.flights')] : null,
            \App\Support\FeatureFlag::categoryVisible('stays') ? ['Stays', route('shop.stays')] : null,
            ['Cart', route('shop.cart')],
        ])),
        'Your account' => [
            ['Dashboard', route('dashboard')],
            ['Wallet', route('dashboard.wallet')],
            ['Orders', route('dashboard.orders')],
            ['Transactions', route('dashboard.transactions')],
            ['Profile', route('dashboard.profile')],
        ],
        'Help & company' => [
            ['Help Center', route('shop.help')],
            ['How it works', route('shop.how-it-works')],
            ['eSIM Installation Guide', route('shop.esim-guide')],
            ['Contact us', route('shop.contact')],
            ['About us', route('shop.about')],
        ],
        'Legal' => [
            ['Privacy Policy', route('shop.privacy')],
            ['Terms of Service', route('shop.terms')],
            ['Cookie Policy', route('shop.cookie-policy')],
            ['Refund Policy', route('shop.refund-policy')],
            ['Compliance', route('shop.compliance')],
            ['Security', route('shop.security')],
            ['Accessibility', route('shop.accessibility')],
        ],
    ];
@endphp

<x-layouts.app.header :title="'Sitemap | '.$siteName">

    {{-- ── Hero ──────────────────────────────────────────────── --}}
    <section class="bg-[#0c1337] dark:bg-[#0c1a36]">
        <div class="mx-auto w-full max-w-[1140px] px-4 py-14 text-center sm:px-6 sm:py-20">
            <span class="inline-flex items-center gap-2 rounded-[5px] bg-white/10 px-3 py-1.5 text-[11px] font-bold uppercase tracking-[0.14em] text-white">Sitemap</span>
            <h1 class="mt-5 text-3xl font-bold tracking-tight text-white sm:text-4xl">Find your way around</h1>
            <p class="mx-auto mt-3 max-w-xl text-sm leading-relaxed text-white/75 sm:text-base">Every section of {{ $siteName }}, all in one place.</p>
        </div>
    </section>

    {{-- ── Link columns ──────────────────────────────────────── --}}
    <div class="mx-auto w-full max-w-[1140px] px-4 py-12 sm:px-6 sm:py-16">
        <div class="grid grid-cols-1 gap-8 sm:grid-cols-2 lg:grid-cols-4">
            @foreach ($columns as $heading => $links)
                <nav aria-label="{{ $heading }}">
                    <h2 class="text-base font-bold text-zinc-900">{{ $heading }}</h2>
                    <ul class="mt-4 space-y-2.5 text-sm">
                        @foreach ($links as [$label, $href])
                            <li><a href="{{ $href }}" wire:navigate class="text-zinc-600 transition-colors hover:text-blue-600">{{ $label }}</a></li>
                        @endforeach
                    </ul>
                </nav>
            @endforeach
        </div>
    </div>

</x-layouts.app.header>

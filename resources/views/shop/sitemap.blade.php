@php
    // HTML sitemap — a human-friendly index of every section. Dark-mode safe.
    $columns = [
        'Shop' => [
            ['Gift Cards', route('shop.gift-cards')],
            ['eSIMs', route('shop.esims')],
            ['Mobile top up', route('shop.topups')],
            ['Bill payments', route('shop.bills')],
            ['Flights', route('shop.flights')],
            ['Stays', route('shop.stays')],
            ['Cart', route('shop.cart')],
        ],
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
            ['Contact us', route('shop.contact')],
            ['About us', route('shop.about')],
        ],
        'Legal' => [
            ['Privacy Policy', route('shop.privacy')],
            ['Terms of Service', route('shop.terms')],
            ['Cookie Policy', route('shop.cookie-policy')],
            ['Refund Policy', route('shop.refund-policy')],
            ['Compliance', route('shop.compliance')],
            ['Accessibility', route('shop.accessibility')],
        ],
    ];
@endphp

<x-layouts.app.header :title="'Sitemap | '.$siteName">

    {{-- ── Hero ──────────────────────────────────────────────── --}}
    <section class="border-b border-zinc-100 bg-blue-50">
        <div class="mx-auto w-full max-w-[1140px] px-4 py-14 text-center sm:px-6 sm:py-20">
            <span class="inline-flex items-center gap-2 rounded-[5px] bg-blue-100 px-3 py-1.5 text-[11px] font-bold uppercase tracking-[0.14em] text-blue-700">Sitemap</span>
            <h1 class="mt-5 text-3xl font-bold tracking-tight text-zinc-900 sm:text-4xl">Find your way around</h1>
            <p class="mx-auto mt-3 max-w-xl text-sm leading-relaxed text-zinc-600 sm:text-base">Every section of {{ $siteName }}, all in one place.</p>
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

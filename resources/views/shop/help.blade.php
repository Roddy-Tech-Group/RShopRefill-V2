@php
    // Help Center — FAQ + topic filters + contact details. Customer-facing,
    // so copy stays generic (no payment-provider names). Search and topic cards
    // filter the FAQ list client-side via Alpine.
    //
    // FAQ entries are CMS-managed (faqs table). Topic icons stay in code: they
    // pair with brand visuals, not editorial content, so they live with the view.
    $supportEmail = 'support@rshoprefill.com';

    $faqs = \App\Models\Faq::published()->ordered()->get()->map(fn ($faq) => [
        'cat' => $faq->topic,
        'q' => $faq->question,
        'a' => $faq->answer,
    ])->all();

    // Topic icons — keyed by topic name so any topic that exists in the DB but
    // doesn't have an icon falls back to a sensible default.
    $topicIconPaths = [
        'Orders & Delivery'           => 'M3.75 6.75h16.5M3.75 12h16.5m-16.5 5.25h16.5',
        'Payments & Wallet'           => 'M2.25 8.25h19.5M2.25 9h19.5m-16.5 5.25h6m-6 2.25h3M3.75 19.5h16.5a1.5 1.5 0 001.5-1.5V6a1.5 1.5 0 00-1.5-1.5H3.75A1.5 1.5 0 002.25 6v12a1.5 1.5 0 001.5 1.5z',
        'Gift Cards & eSIMs'          => 'M21 11.25v8.25a1.5 1.5 0 01-1.5 1.5H5.25a1.5 1.5 0 01-1.5-1.5v-8.25M12 4.875A2.625 2.625 0 109.375 7.5H12m0-2.625V7.5m0-2.625A2.625 2.625 0 1114.625 7.5H12m0 0V21m-8.625-9.75h18c.621 0 1.125-.504 1.125-1.125v-1.5c0-.621-.504-1.125-1.125-1.125h-18c-.621 0-1.125.504-1.125 1.125v1.5c0 .621.504 1.125 1.125 1.125z',
        'Account & Verification'      => 'M15.75 6a3.75 3.75 0 11-7.5 0 3.75 3.75 0 017.5 0zM4.501 20.118a7.5 7.5 0 0114.998 0A17.933 17.933 0 0112 21.75c-2.676 0-5.216-.584-7.499-1.632z',
        'Transaction PIN & Security'  => 'M16.5 10.5V6.75a4.5 4.5 0 10-9 0v3.75m-.75 11.25h10.5a2.25 2.25 0 002.25-2.25v-6.75a2.25 2.25 0 00-2.25-2.25H6.75a2.25 2.25 0 00-2.25 2.25v6.75a2.25 2.25 0 002.25 2.25z',
        'Refunds & Disputes'          => 'M9 15L3 9m0 0l6-6M3 9h12a6 6 0 010 12h-3',
    ];
    $defaultIconPath = 'M12 9v2m0 4h.01M4.93 19h14.14a2 2 0 001.74-3L13.74 4a2 2 0 00-3.48 0L3.19 16a2 2 0 001.74 3z';

    // Topic cards derive from the FAQ list so removing a topic from the DB
    // also drops the card. Description lives in code for now — could move to
    // site_settings if editors need to edit it.
    $topicDescriptions = [
        'Orders & Delivery'           => 'Tracking, codes and delivery times.',
        'Payments & Wallet'           => 'Methods, funding and currencies.',
        'Gift Cards & eSIMs'          => 'Region locks and activation.',
        'Account & Verification'      => 'KYC, limits and your details.',
        'Transaction PIN & Security'  => 'Set, change and protect your PIN.',
        'Refunds & Disputes'          => 'Failed orders and reversals.',
    ];
    $faqsByTopic = collect($faqs)->groupBy('cat');
    $topics = $faqsByTopic->keys()->map(fn ($cat) => [
        'cat' => $cat,
        'desc' => $topicDescriptions[$cat] ?? '',
        'path' => $topicIconPaths[$cat] ?? $defaultIconPath,
        'count' => $faqsByTopic->get($cat)->count(),
    ])->values()->all();
@endphp

<x-layouts.app.header :title="'Help Center | '.$siteName">

    <div
        x-data="{
            q: '',
            open: null,
            pick(cat) {
                this.q = cat;
                this.$nextTick(() => document.getElementById('faq')?.scrollIntoView({ behavior: 'smooth', block: 'start' }));
            },
            // True while at least one FAQ row's data-faq haystack matches the query
            // (server-rendered rows filter themselves via x-show, same as the eSIM list).
            hasResults() {
                const t = this.q.trim().toLowerCase();
                if (! t) { return true; }
                return [...this.$root.querySelectorAll('[data-faq]')].some((el) => el.dataset.faq.includes(t));
            },
        }"
    >
        {{-- ── Hero + search ─────────────────────────────────────── --}}
        <section class="border-b border-zinc-100 bg-blue-50">
            <div class="mx-auto w-full max-w-[1000px] px-4 py-14 text-center sm:px-6 sm:py-20">
                <span class="inline-flex items-center gap-2 rounded-[5px] bg-blue-100 px-3 py-1.5 text-[11px] font-bold uppercase tracking-[0.14em] text-blue-700">
                    Help Center
                </span>
                <h1 class="mt-5 text-3xl font-bold tracking-tight text-zinc-900 sm:text-4xl lg:text-5xl">How can we help?</h1>
                <p class="mx-auto mt-3 max-w-xl text-sm leading-relaxed text-zinc-600 sm:text-base">
                    Search our FAQs or browse a topic below. Still stuck? Our team is one message away.
                </p>

                <div class="relative mx-auto mt-7 max-w-xl">
                    <svg class="pointer-events-none absolute left-4 top-1/2 h-5 w-5 -translate-y-1/2 text-zinc-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-5.197-5.197m0 0A7.5 7.5 0 105.196 5.196a7.5 7.5 0 0010.607 10.607z"/>
                    </svg>
                    <input
                        x-model="q"
                        type="search"
                        placeholder="Search for answers (e.g. wallet, eSIM, refund)"
                        class="w-full rounded-[12px] border border-zinc-200 bg-white py-3.5 pl-12 pr-4 text-sm text-zinc-900 shadow-sm shadow-zinc-900/5 outline-none transition-colors placeholder:text-zinc-500 focus:border-blue-500 focus:ring-2 focus:ring-blue-500/15"
                    >
                </div>
            </div>
        </section>

        {{-- ── Topic cards. Sticky heading on the left, square topic cards on
             the right (stacks on mobile). ──────────────────────────── --}}
        <section class="mx-auto w-full max-w-[1140px] px-4 py-12 sm:px-6 sm:py-14">
            <div class="grid gap-8 lg:grid-cols-[240px_1fr] lg:gap-12">
                <div class="lg:sticky lg:top-[156px] self-start">
                    <h2 class="text-lg font-bold text-zinc-900 dark:text-white">Browse by topic</h2>
                    <p class="mt-1.5 text-sm leading-relaxed text-zinc-600 dark:text-zinc-400">Jump straight to the answers for a specific area.</p>
                </div>
                <div class="grid grid-cols-2 gap-4 sm:grid-cols-3">
                    @foreach ($topics as $topic)
                        <button
                            type="button"
                            @click="pick(@js($topic['cat']))"
                            class="group flex aspect-square flex-col items-center justify-center gap-4 rounded-[12px] border border-zinc-200 bg-white p-5 text-center transition-all hover:border-blue-300 hover:shadow-md hover:shadow-blue-600/5 focus:outline-none focus-visible:ring-2 focus-visible:ring-blue-500/40"
                        >
                            <span class="flex h-14 w-14 shrink-0 items-center justify-center rounded-[14px] bg-blue-50 text-blue-600 transition-colors group-hover:bg-blue-600 group-hover:text-white">
                                <svg class="h-7 w-7" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75" aria-hidden="true">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="{{ $topic['path'] }}"/>
                                </svg>
                            </span>
                            <div>
                                <p class="text-sm font-bold text-zinc-900">{{ $topic['cat'] }}</p>
                                <p class="mt-1 text-xs leading-relaxed text-zinc-600">{{ $topic['desc'] }}</p>
                                <p class="mt-2 text-[11px] font-semibold uppercase tracking-wide text-blue-600">{{ $topic['count'] }} {{ \Illuminate\Support\Str::plural('article', $topic['count']) }}</p>
                            </div>
                        </button>
                    @endforeach
                </div>
            </div>
        </section>

        {{-- ── How it works ──────────────────────────────────────── --}}
        <section id="how-it-works" class="border-y border-zinc-100 bg-zinc-50">
            <div class="mx-auto w-full max-w-[1140px] px-4 py-12 sm:px-6 sm:py-14">
                <h2 class="text-lg font-bold text-zinc-900">How it works</h2>
                <div class="mt-5 grid grid-cols-1 gap-4 sm:grid-cols-3">
                    @foreach ([
                        ['1', 'Choose your item', 'Pick a gift card, eSIM, top-up or bill payment in your region.'],
                        ['2', 'Pay your way', 'Settle with your wallet, card, bank transfer, mobile money or crypto.'],
                        ['3', 'Instant delivery', 'Your codes land in your dashboard and email the moment payment clears.'],
                    ] as [$step, $title, $desc])
                        <div class="rounded-[12px] bg-white p-5 ring-1 ring-zinc-100">
                            <span class="flex h-9 w-9 items-center justify-center rounded-[12px] bg-blue-600 text-sm font-bold text-white">{{ $step }}</span>
                            <p class="mt-3 text-sm font-bold text-zinc-900">{{ $title }}</p>
                            <p class="mt-1 text-xs leading-relaxed text-zinc-600">{{ $desc }}</p>
                        </div>
                    @endforeach
                </div>
            </div>
        </section>

        {{-- ── FAQ. Sticky heading on the left, the questions on the right.
             Each row filters itself against the search via its data-faq
             haystack (same pattern as the eSIM location list). ──────────── --}}
        <section id="faq" class="mx-auto w-full max-w-[1140px] px-4 py-12 sm:px-6 sm:py-16 scroll-mt-24">
            <div class="grid gap-8 lg:grid-cols-[240px_1fr] lg:gap-12">
                <div class="lg:sticky lg:top-[156px] self-start">
                    <h2 class="text-lg font-bold text-zinc-900 dark:text-white">Frequently asked questions</h2>
                    <button type="button" x-show="q" x-cloak @click="q = ''" class="mt-2 text-xs font-semibold text-blue-600 hover:text-blue-700 dark:text-blue-400">Clear filter</button>
                </div>

                <div class="space-y-3">
                    @foreach ($faqs as $i => $faq)
                        <div
                            data-faq="{{ \Illuminate\Support\Str::lower($faq['q'].' '.$faq['a'].' '.$faq['cat']) }}"
                            x-show="q.trim() === '' || $el.dataset.faq.includes(q.trim().toLowerCase())"
                            class="overflow-hidden rounded-[12px] border border-zinc-200 bg-white"
                        >
                            <button
                                type="button"
                                @click="open === {{ $i }} ? open = null : open = {{ $i }}"
                                class="flex w-full items-center justify-between gap-4 px-5 py-4 text-left"
                            >
                                <span class="text-sm font-semibold text-zinc-900">{{ $faq['q'] }}</span>
                                <svg class="h-4 w-4 shrink-0 text-zinc-500 transition-transform duration-200" :class="open === {{ $i }} && 'rotate-180'" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5" aria-hidden="true">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7"/>
                                </svg>
                            </button>
                            <div x-show="open === {{ $i }}" x-collapse x-cloak>
                                <p class="px-5 pb-4 text-sm leading-relaxed text-zinc-600">{{ $faq['a'] }}</p>
                            </div>
                        </div>
                    @endforeach

                    <div x-show="! hasResults()" x-cloak class="rounded-[12px] border border-dashed border-zinc-300 bg-white px-5 py-10 text-center">
                        <p class="text-sm font-semibold text-zinc-900">No answers matched your search.</p>
                        <p class="mt-1 text-xs text-zinc-600">Try a different term, or reach out to our team below.</p>
                    </div>
                </div>
            </div>
        </section>

        {{-- ── Contact ───────────────────────────────────────────── --}}
        <section id="contact" class="border-t border-zinc-100 bg-blue-600 scroll-mt-24">
            <div class="mx-auto w-full max-w-[1140px] px-4 py-14 sm:px-6 sm:py-16">
                <div class="grid grid-cols-1 items-center gap-8 lg:grid-cols-2">
                    <div>
                        <h2 class="text-2xl font-bold text-white sm:text-3xl">Still need a hand?</h2>
                        <p class="mt-2 max-w-md text-sm leading-relaxed text-blue-100">
                            Open Live Chat any time for the fastest help, or send us a message with your order number. Our support team is available 24/7, and you never lose money - every payment is delivered or refunded to your wallet.
                        </p>
                        <div class="mt-6 flex flex-wrap gap-3">
                            <a href="mailto:{{ $supportEmail }}" class="inline-flex items-center gap-2 rounded-[6px] bg-white px-5 py-3 text-sm font-semibold text-blue-700 transition-colors hover:bg-blue-50">
                                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M21.75 6.75v10.5a2.25 2.25 0 01-2.25 2.25h-15a2.25 2.25 0 01-2.25-2.25V6.75m19.5 0A2.25 2.25 0 0019.5 4.5h-15a2.25 2.25 0 00-2.25 2.25m19.5 0v.243a2.25 2.25 0 01-1.07 1.916l-7.5 4.615a2.25 2.25 0 01-2.36 0L3.32 8.91a2.25 2.25 0 01-1.07-1.916V6.75"/>
                                </svg>
                                Email support
                            </a>
                            <a href="{{ route('dashboard.orders') }}" wire:navigate class="inline-flex items-center gap-2 rounded-[6px] border border-white/40 px-5 py-3 text-sm font-semibold text-white transition-colors hover:bg-white/10">
                                Track an order
                            </a>
                        </div>
                    </div>

                    <div class="rounded-[12px] bg-white/10 p-6 ring-1 ring-white/20 backdrop-blur-sm">
                        <dl class="space-y-4 text-sm">
                            <div class="flex items-center justify-between gap-4">
                                <dt class="text-blue-100">Support email</dt>
                                <dd><a href="mailto:{{ $supportEmail }}" class="font-semibold text-white hover:underline">{{ $supportEmail }}</a></dd>
                            </div>
                            <div class="flex items-center justify-between gap-4">
                                <dt class="text-blue-100">Live chat</dt>
                                <dd class="font-semibold text-white">24/7, around the clock</dd>
                            </div>
                            <div class="flex items-center justify-between gap-4">
                                <dt class="text-blue-100">Email response</dt>
                                <dd class="font-semibold text-white">Within 24 hours</dd>
                            </div>
                        </dl>
                        <div class="mt-5 flex items-center gap-2 border-t border-white/15 pt-5">
                            <a href="{{ \App\Models\SiteSetting::get('social.facebook', 'https://facebook.com/rshoprefills') }}" target="_blank" rel="noopener noreferrer" aria-label="Facebook" class="flex h-9 w-9 items-center justify-center rounded-[12px] bg-white/10 text-white transition-colors hover:bg-white/20">
                                <svg viewBox="0 0 24 24" class="h-4 w-4" fill="currentColor" aria-hidden="true"><path d="M24 12.073c0-6.627-5.373-12-12-12s-12 5.373-12 12c0 5.99 4.388 10.954 10.125 11.854v-8.385H7.078v-3.47h3.047V9.43c0-3.007 1.792-4.669 4.533-4.669 1.312 0 2.686.235 2.686.235v2.953H15.83c-1.491 0-1.956.925-1.956 1.874v2.25h3.328l-.532 3.47h-2.796v8.385C19.612 23.027 24 18.062 24 12.073z"/></svg>
                            </a>
                            <a href="{{ \App\Models\SiteSetting::get('social.x', 'https://x.com/rshoprefills') }}" target="_blank" rel="noopener noreferrer" aria-label="X" class="flex h-9 w-9 items-center justify-center rounded-[12px] bg-white/10 text-white transition-colors hover:bg-white/20">
                                <svg viewBox="0 0 24 24" class="h-3.5 w-3.5" fill="currentColor" aria-hidden="true"><path d="M18.244 2.25h3.308l-7.227 8.26 8.502 11.24H16.17l-5.214-6.817L4.99 21.75H1.68l7.73-8.835L1.254 2.25H8.08l4.713 6.231zm-1.161 17.52h1.833L7.084 4.126H5.117L17.083 19.77z"/></svg>
                            </a>
                            <a href="{{ \App\Models\SiteSetting::get('social.instagram', 'https://instagram.com/rshoprefills') }}" target="_blank" rel="noopener noreferrer" aria-label="Instagram" class="flex h-9 w-9 items-center justify-center rounded-[12px] bg-white/10 text-white transition-colors hover:bg-white/20">
                                <svg viewBox="0 0 24 24" class="h-4 w-4" fill="currentColor" aria-hidden="true"><path d="M12 2.163c3.204 0 3.584.012 4.849.07 3.252.148 4.771 1.691 4.919 4.919.058 1.265.07 1.644.07 4.849 0 3.205-.012 3.584-.07 4.849-.149 3.225-1.664 4.771-4.919 4.919-1.266.058-1.644.07-4.849.07-3.204 0-3.584-.012-4.849-.07-3.26-.149-4.771-1.699-4.919-4.92-.058-1.265-.07-1.644-.07-4.849 0-3.204.013-3.583.07-4.849.149-3.227 1.664-4.771 4.919-4.919 1.266-.057 1.645-.069 4.849-.069zM12 0C8.741 0 8.333.014 7.053.072 2.695.272.273 2.69.073 7.052.014 8.333 0 8.741 0 12c0 3.259.014 3.668.072 4.948.2 4.358 2.618 6.78 6.98 6.98C8.333 23.986 8.741 24 12 24c3.259 0 3.668-.014 4.948-.072 4.354-.2 6.782-2.618 6.979-6.98.059-1.28.073-1.689.073-4.948 0-3.259-.014-3.667-.072-4.947-.196-4.354-2.617-6.78-6.979-6.98C15.668.014 15.259 0 12 0zm0 5.838a6.162 6.162 0 100 12.324 6.162 6.162 0 000-12.324zM12 16a4 4 0 110-8 4 4 0 010 8zm6.406-11.845a1.44 1.44 0 100 2.881 1.44 1.44 0 000-2.881z"/></svg>
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        </section>
    </div>

</x-layouts.app.header>

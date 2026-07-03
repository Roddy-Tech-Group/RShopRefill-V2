{{-- Discover Global eSIM: the worldwide-coverage eSIM's plans rendered with the
     SAME card as the eSIM detail page (tier + type badges, data/validity/mins/SMS/
     iMessage/FaceTime list, See more, price), inside the shared gift-card carousel
     (with desktop scroll buttons). Voice (US number) plans first, data-only under.
     Tapping a card selects it and pops a glass buy bar from the top - wired to the
     global cart so customers Add to cart / Buy now in place (no navigation).
     Self-fetching + self-guarding: renders nothing when the product/plans aren't
     available. Used on the home page and the dashboard. --}}
@props([
    // The carousel uses a full-bleed (100vw) track that only lines up in a
    // viewport-centered container. The dashboard renders this inside an
    // off-center column, so it passes :contained="true" to keep the track within
    // its own width (otherwise it overflows onto the right rail).
    'contained' => false,
])
@php
    $dg = \App\Http\Controllers\EsimStoreController::discoverGlobal();
    $onDashboard = request()->is('dashboard*') && auth()->check();
    // Buy now lands on the right checkout for the surface we're on.
    $checkoutUrl = $onDashboard
        ? route('dashboard.shop.checkout')
        : route('shop.checkout');

    if ($dg) {
        // See-more keeps dashboard users inside the in-dashboard eSIM store; storefront
        // visitors go to the public eSIM page.
        $esimUrl = $onDashboard
            ? route('dashboard.shop.esims')
            : route('shop.esim', $dg['product']->slug);
        $voicePlans = collect($dg['plans'])->where('is_voice', true)->values();
        $dataPlans = collect($dg['plans'])->where('is_voice', false)->values();
        // Tier badge cycles by card position (TRIP shows no badge) - same as the eSIM page.
        // Each tier maps to a status-dot colour on the global pill.
        $tiers = ['TRIP', 'EXPLORER', 'ADVENTURER', 'NOMAD'];
        $tierDots = [null, 'blue', 'purple', 'amber'];
        $groups = [
            ['title' => 'Global United States real numbers', 'plans' => $voicePlans],
            [
                'title' => 'Browsing globally anywhere you are',
                'subtitle' => 'This plan is for global data only, no phone number. Choose voice if you want the +1 real USA number.',
                'plans' => $dataPlans,
            ],
        ];
    }
@endphp

@if ($dg)
    <section
        aria-label="Discover Global eSIM plans"
        x-data="{
            plans: @js($dg['plans']),
            selectedId: null,
            detailsId: null,
            showDetails: false,
            cartState: 'idle',
            checkoutUrl: @js($checkoutUrl),
            plan() { return this.plans.find((p) => p.id === this.selectedId) || null; },
            detailsPlan() { return this.plans.find((p) => p.id === this.detailsId) || null; },
            async addToCart() {
                if (this.cartState !== 'idle' || ! this.selectedId) { return false; }
                this.cartState = 'loading';
                const ok = await this.$store.cart.add(this.selectedId, 1);
                if (ok) {
                    this.cartState = 'success';
                    clearTimeout(this._t);
                    this._t = setTimeout(() => { this.cartState = 'idle'; this.selectedId = null; }, 1600);
                } else {
                    this.cartState = 'idle';
                }
                return ok;
            },
            async buyNow() {
                if (! this.selectedId || this.$store.cart.loading) { return; }
                const ok = await this.$store.cart.add(this.selectedId, 1, null, null, true);
                if (ok) { window.location.href = this.checkoutUrl; }
            },
        }"
        x-effect="showDetails ? window.rshopScrollLock?.lock() : window.rshopScrollLock?.unlock()"
    >
        <div>
            <h2 class="text-xl font-bold tracking-tight text-zinc-900 dark:text-white sm:text-2xl">Discover Global USA <img src="{{ \App\Models\Product::flagUrl('US') }}" alt="USA flag" class="inline-block h-5 w-7 rounded-[2px] object-cover align-middle ring-1 ring-zinc-200 dark:ring-zinc-700"> eSIM</h2>
            <p class="mt-1 text-sm text-zinc-600 dark:text-zinc-400">A USA number for you - whether you're not in the United States, or you're not traveling there but still want a US number that can call worldwide from wherever you are. Choose from the plans below.</p>
        </div>

        @foreach ($groups as $group)
            @if ($group['plans']->isNotEmpty())
                <div class="mt-6">
                    <x-home.brand-row :title="$group['title']" :subtitle="$group['subtitle'] ?? null" :view-all-href="$esimUrl" :view-all-variant="$onDashboard ? 'plus' : 'link'" :carousel="true" :cols="5" :bleed="! $contained">
                        @foreach ($group['plans'] as $idx => $plan)
                            <button
                                type="button"
                                @click="selectedId = {{ $plan['id'] }}"
                                :class="selectedId === {{ $plan['id'] }} ? 'border-2 border-blue-600 dark:border-blue-500' : 'border-2 border-zinc-200 hover:border-blue-300 dark:border-[#24364f] dark:hover:border-blue-500/50'"
                                class="esim-tile flex h-full w-[70vw]! min-w-[70vw]! flex-col rounded-[14px] bg-transparent px-4 py-4 text-left transition-colors focus:outline-none sm:w-60! sm:min-w-60!"
                            >
                                {{-- Type badge ("Data only" / "Voice") plus a tier chip shown
                                     ONLY on voice plans. On data plans the variable-length tier
                                     wrapped and made cards uneven, so data plans carry the single
                                     "Data only" badge - same as the eSIM detail page. --}}
                                <div class="flex flex-nowrap items-center gap-1">
                                    @if ($plan['is_voice'] && $tiers[$idx % 4] !== 'TRIP')
                                        <x-ui.pill :dot="$tierDots[$idx % 4]" class="uppercase tracking-wider">{{ $tiers[$idx % 4] }}</x-ui.pill>
                                    @endif
                                    <x-ui.pill :dot="$plan['is_voice'] ? 'blue' : 'zinc'" class="uppercase tracking-wider">{{ $plan['is_voice'] ? 'Voice' : 'Data only' }}</x-ui.pill>
                                </div>

                                <p class="mt-3 text-lg font-bold text-zinc-900 dark:text-white">{{ $plan['data'] }} {{ $plan['days'] }} Days</p>

                                <div class="my-3 h-px bg-zinc-200 dark:bg-zinc-700"></div>

                                <ul class="space-y-2 text-sm text-zinc-900 dark:text-white">
                                    <li class="flex items-center gap-2">
                                        <svg class="h-4 w-4 shrink-0 text-zinc-700 dark:text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M8.288 15.038a5.25 5.25 0 017.424 0M5.106 11.856c3.807-3.808 9.98-3.808 13.788 0M1.924 8.674c5.565-5.565 14.587-5.565 20.152 0M12.53 18.22l-.53.53-.53-.53a.75.75 0 011.06 0z"/></svg>
                                        <span>{{ $plan['data'] }} of data</span>
                                    </li>
                                    <li class="flex items-center gap-2">
                                        <svg class="h-4 w-4 shrink-0 text-zinc-700 dark:text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6h4.5m4.5 0a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                                        <span>{{ $plan['days'] }} day validity</span>
                                    </li>
                                    @if ($plan['is_voice'] && $plan['voice'])
                                        <li class="flex items-center gap-2">
                                            <svg class="h-4 w-4 shrink-0 text-zinc-700 dark:text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M2.25 6.75c0 8.284 6.716 15 15 15h2.25a2.25 2.25 0 002.25-2.25v-1.372c0-.516-.351-.966-.852-1.091l-4.423-1.106c-.44-.11-.902.055-1.173.417l-.97 1.293c-.282.376-.769.542-1.21.38a12.035 12.035 0 01-7.143-7.143c-.162-.441.004-.928.38-1.21l1.293-.97c.363-.271.527-.734.417-1.173L6.963 3.102a1.125 1.125 0 00-1.091-.852H4.5A2.25 2.25 0 002.25 4.5v2.25z"/></svg>
                                            <span>{{ $plan['voice'] }}</span>
                                        </li>
                                    @endif
                                    @if ($plan['is_voice'] && $plan['sms'])
                                        <li class="flex items-center gap-2">
                                            <svg class="h-4 w-4 shrink-0 text-zinc-700 dark:text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M8.625 9.75a.375.375 0 11-.75 0 .375.375 0 01.75 0zm0 0H8.25m4.125 0a.375.375 0 11-.75 0 .375.375 0 01.75 0zm0 0H12m4.125 0a.375.375 0 11-.75 0 .375.375 0 01.75 0zm0 0h-.375M21 12c0 4.556-4.03 8.25-9 8.25a9.764 9.764 0 01-2.555-.337A5.972 5.972 0 015.41 20.97a5.969 5.969 0 01-.474-.065 4.48 4.48 0 00.978-2.025c.09-.457-.133-.901-.467-1.226C3.93 16.178 3 14.189 3 12c0-4.556 4.03-8.25 9-8.25s9 3.694 9 8.25z"/></svg>
                                            <span>{{ $plan['sms'] }}</span>
                                        </li>
                                    @endif
                                    @if ($plan['is_voice'])
                                        <li class="flex items-center gap-2">
                                            <svg class="h-4 w-4 shrink-0 text-zinc-700 dark:text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M2.25 12.76c0 1.6 1.123 2.994 2.707 3.227 1.087.16 2.185.283 3.293.369V21l4.184-4.183a1.14 1.14 0 01.778-.332 48.294 48.294 0 005.83-.498c1.585-.233 2.708-1.626 2.708-3.228V6.741c0-1.602-1.123-2.995-2.707-3.228A48.394 48.394 0 0012 3c-2.392 0-4.744.175-7.043.513C3.373 3.746 2.25 5.14 2.25 6.741v6.018z"/></svg>
                                            <span>Unlimited iMessage</span>
                                        </li>
                                        <li class="flex items-center gap-2">
                                            <svg class="h-4 w-4 shrink-0 text-zinc-700 dark:text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M15.75 10.5l4.72-4.72a.75.75 0 011.28.53v11.38a.75.75 0 01-1.28.53l-4.72-4.72M4.5 18.75h9a2.25 2.25 0 002.25-2.25v-9a2.25 2.25 0 00-2.25-2.25h-9A2.25 2.25 0 002.25 7.5v9a2.25 2.25 0 002.25 2.25z"/></svg>
                                            <span>FaceTime</span>
                                        </li>
                                    @endif
                                </ul>

                                @if ($plan['note'])
                                    <p class="mt-3 text-xs text-zinc-700 dark:text-white">{{ $plan['note'] }}</p>
                                @endif

                                {{-- See more (left) + price (right) share one row, pinned to the
                                     card bottom so every card lines up regardless of feature count. --}}
                                <div class="mt-auto flex items-center justify-between gap-2 pt-3">
                                    <span @click.stop="detailsId = {{ $plan['id'] }}; showDetails = true" @keydown.enter.stop="detailsId = {{ $plan['id'] }}; showDetails = true" role="button" tabindex="0" class="inline-flex cursor-pointer items-center {{ $onDashboard ? 'rounded-md px-1.5 text-[10px]' : 'rounded-[8px] px-2 text-[11px]' }} bg-blue-50 py-0.5 font-semibold text-blue-600 ring-1 ring-inset ring-blue-200 transition-colors hover:bg-blue-100 dark:bg-blue-500/15 dark:text-blue-300 dark:ring-blue-500/30 dark:hover:bg-blue-500/25">See more</span>
                                    <p class="text-lg font-bold tabular-nums text-zinc-900 dark:text-white">{{ $plan['price_label'] }}</p>
                                </div>
                            </button>
                        @endforeach
                    </x-home.brand-row>
                </div>
            @endif
        @endforeach

        {{-- Inline buy bar - same fixed glass toast pattern as the eSIM page. --}}
        <div
            x-show="selectedId"
            x-cloak
            x-transition:enter="transition ease-out duration-300"
            x-transition:enter-start="opacity-0 -translate-y-6"
            x-transition:enter-end="opacity-100 translate-y-0"
            x-transition:leave="transition ease-in duration-200"
            x-transition:leave-start="opacity-100 translate-y-0"
            x-transition:leave-end="opacity-0 -translate-y-6"
            style="display:none;"
            class="fixed inset-x-0 top-3 z-[60] px-3 sm:px-5"
        >
            <div class="relative mx-auto flex w-full max-w-[640px] flex-wrap items-center gap-3 rounded-[20px] border border-white/40 bg-white/70 px-4 py-3 shadow-[0_14px_44px_-12px_rgba(15,23,42,0.4)] backdrop-blur-2xl backdrop-saturate-150 dark:border-white/10 dark:bg-[#1d3252]/80">
                <button type="button" @click="selectedId = null" aria-label="Deselect plan" class="flex h-9 w-9 shrink-0 items-center justify-center rounded-[12px] bg-white text-zinc-700 ring-1 ring-zinc-200 transition-colors hover:bg-zinc-50 dark:bg-[#0c1a36] dark:text-white dark:ring-[#24364f] dark:hover:bg-[#34507a]">
                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.25" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg>
                </button>
                <div class="min-w-0">
                    <p class="truncate text-sm font-bold text-zinc-900 dark:text-white"><span x-text="plan()?.data"></span> <span class="font-normal text-zinc-500 dark:text-zinc-400" x-text="plan()?.is_voice ? 'Voice eSIM' : 'Data eSIM'"></span></p>
                    <p class="text-xs text-zinc-500 dark:text-zinc-400"><span x-text="plan() && plan().days > 0 ? (plan().days + (plan().days === 1 ? ' day' : ' days') + ' validity') : 'Flexible validity'"></span></p>
                </div>
                <div class="ml-auto flex items-center gap-3">
                    <p class="text-lg font-extrabold tabular-nums text-zinc-900 dark:text-white" x-text="plan()?.price_label"></p>
                    <button
                        type="button"
                        @click="addToCart()"
                        :disabled="cartState !== 'idle'"
                        :class="cartState === 'success' ? 'border-emerald-500 bg-emerald-500 text-white' : 'border-blue-600 text-blue-600 hover:bg-blue-600 hover:text-white dark:text-blue-300 dark:hover:text-white'"
                        class="hidden h-11 items-center rounded-[12px] border-2 px-4 text-sm font-semibold transition-colors disabled:opacity-60 sm:inline-flex"
                    >
                        <span x-show="cartState !== 'success'">Add to cart</span>
                        <span x-show="cartState === 'success'" style="display:none;">Added</span>
                    </button>
                    <button
                        type="button"
                        @click="buyNow()"
                        :disabled="$store.cart.loading"
                        class="inline-flex h-11 items-center rounded-[12px] bg-blue-600 px-5 text-sm font-semibold text-white shadow-lg shadow-blue-600/25 transition-colors hover:bg-blue-700 focus:outline-none focus-visible:ring-2 focus-visible:ring-blue-500/40 disabled:opacity-60"
                    >Buy now</button>
                </div>
            </div>
        </div>
        {{-- Package details modal - opened by "See more" on any plan card. --}}
        <div x-show="showDetails" x-cloak style="display:none;" class="fixed inset-0 z-[70] flex items-end justify-center px-3 pb-3 sm:items-center sm:p-4" role="dialog" aria-modal="true" aria-labelledby="dg-details-title">
            <div x-show="showDetails" @click="showDetails = false" x-transition.opacity class="absolute inset-0 bg-zinc-900/40 dark:bg-black/60"></div>
            <div x-show="showDetails" x-transition class="esim-tile relative max-h-[85vh] w-full max-w-lg overflow-y-auto rounded-3xl bg-[#eff6ff] p-6 shadow-2xl sm:rounded-[14px] dark:bg-[#0c1a36] dark:ring-1 dark:ring-white/10">
                <div class="flex items-start justify-between gap-4">
                    <h2 id="dg-details-title" class="flex items-center gap-2.5 text-lg font-bold text-zinc-900 dark:text-white">
                        <span class="flex h-7 w-7 items-center justify-center rounded-[8px] bg-blue-50 text-blue-600 dark:bg-blue-500/15 dark:text-blue-300">
                            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M12 21a9 9 0 100-18 9 9 0 000 18zm0 0c2.485 0 4.5-4.03 4.5-9S14.485 3 12 3m0 18c-2.485 0-4.5-4.03-4.5-9S9.515 3 12 3M3 12h18"/></svg>
                        </span>
                        Discover Global
                    </h2>
                    <button type="button" @click="showDetails = false" aria-label="Close" class="flex h-9 w-9 items-center justify-center rounded-[12px] bg-zinc-100 text-zinc-600 transition-colors hover:bg-zinc-200 dark:bg-[#0c1a36] dark:text-zinc-200 dark:hover:bg-[#34507a]"><svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg></button>
                </div>

                <div class="mt-5">
                    <p class="text-sm font-bold text-zinc-900 dark:text-white">Package</p>
                    <div class="mt-3 flex flex-wrap gap-2">
                        <span class="inline-flex flex-col rounded-[12px] bg-zinc-50 px-3 py-2 ring-1 ring-zinc-100 dark:bg-[#0c1a36] dark:ring-[#24364f]">
                            <span class="text-[11px] font-medium uppercase tracking-wide text-zinc-500 dark:text-zinc-400">Coverage</span>
                            <span class="text-sm font-semibold text-zinc-900 dark:text-white">Worldwide</span>
                        </span>
                        <span class="inline-flex flex-col rounded-[12px] bg-zinc-50 px-3 py-2 ring-1 ring-zinc-100 dark:bg-[#0c1a36] dark:ring-[#24364f]">
                            <span class="text-[11px] font-medium uppercase tracking-wide text-zinc-500 dark:text-zinc-400">Data</span>
                            <span class="text-sm font-semibold text-zinc-900 dark:text-white" x-text="detailsPlan()?.data || '-'"></span>
                        </span>
                        <span x-show="detailsPlan()?.is_voice" x-cloak class="inline-flex flex-col rounded-[12px] bg-zinc-50 px-3 py-2 ring-1 ring-zinc-100 dark:bg-[#0c1a36] dark:ring-[#24364f]">
                            <span class="text-[11px] font-medium uppercase tracking-wide text-zinc-500 dark:text-zinc-400">Calls</span>
                            <span class="text-sm font-semibold text-zinc-900 dark:text-white" x-text="detailsPlan()?.voice || '-'"></span>
                        </span>
                        <span x-show="detailsPlan()?.is_voice" x-cloak class="inline-flex flex-col rounded-[12px] bg-zinc-50 px-3 py-2 ring-1 ring-zinc-100 dark:bg-[#0c1a36] dark:ring-[#24364f]">
                            <span class="text-[11px] font-medium uppercase tracking-wide text-zinc-500 dark:text-zinc-400">Texts</span>
                            <span class="text-sm font-semibold text-zinc-900 dark:text-white" x-text="detailsPlan()?.sms || '-'"></span>
                        </span>
                        <span class="inline-flex flex-col rounded-[12px] bg-zinc-50 px-3 py-2 ring-1 ring-zinc-100 dark:bg-[#0c1a36] dark:ring-[#24364f]">
                            <span class="text-[11px] font-medium uppercase tracking-wide text-zinc-500 dark:text-zinc-400">Validity</span>
                            <span class="text-sm font-semibold text-zinc-900 dark:text-white" x-text="(detailsPlan()?.days > 0 ? detailsPlan().days + ' days' : 'Flexible')"></span>
                        </span>
                    </div>

                    {{-- iMessage / Apple services - voice plans only. --}}
                    <div x-show="detailsPlan()?.is_voice" x-cloak class="mt-5">
                        <p class="text-sm font-bold text-zinc-900 dark:text-white">iMessage &amp; Apple services</p>
                        <div class="mt-3 grid grid-cols-2 gap-2.5">
                            @foreach ([
                                ['t' => 'Unlimited iMessage', 'd' => 'No limit messaging other Apple devices.', 'icon' => 'M2.25 12.76c0 1.6 1.123 2.994 2.707 3.227 1.087.16 2.185.283 3.293.369V21l4.184-4.183a1.14 1.14 0 01.778-.332 48.294 48.294 0 005.83-.498c1.585-.233 2.708-1.626 2.708-3.228V6.741c0-1.602-1.123-2.995-2.707-3.228A48.394 48.394 0 0012 3c-2.392 0-4.744.175-7.043.513C3.373 3.746 2.25 5.14 2.25 6.741v6.018z'],
                                ['t' => 'iMessage Calls', 'd' => 'Voice calls over iMessage / data.', 'icon' => 'M2.25 6.75c0 8.284 6.716 15 15 15h2.25a2.25 2.25 0 002.25-2.25v-1.372c0-.516-.351-.966-.852-1.091l-4.423-1.106c-.44-.11-.902.055-1.173.417l-.97 1.293c-.282.376-.769.542-1.21.38a12.035 12.035 0 01-7.143-7.143c-.162-.441.004-.928.38-1.21l1.293-.97c.363-.271.527-.734.417-1.173L6.963 3.102a1.125 1.125 0 00-1.091-.852H4.5A2.25 2.25 0 002.25 4.5v2.25z'],
                                ['t' => 'iMessage text', 'd' => 'Send and receive texts over iMessage.', 'icon' => 'M8.625 9.75a.375.375 0 11-.75 0 .375.375 0 01.75 0zm0 0H8.25m4.125 0a.375.375 0 11-.75 0 .375.375 0 01.75 0zm0 0H12m4.125 0a.375.375 0 11-.75 0 .375.375 0 01.75 0zm0 0h-.375M21 12c0 4.556-4.03 8.25-9 8.25a9.764 9.764 0 01-2.555-.337A5.972 5.972 0 015.41 20.97a5.969 5.969 0 01-.474-.065 4.48 4.48 0 00.978-2.025c.09-.457-.133-.901-.467-1.226C3.93 16.178 3 14.189 3 12c0-4.556 4.03-8.25 9-8.25s9 3.694 9 8.25z'],
                                ['t' => 'FaceTime', 'd' => 'Video and audio FaceTime over data.', 'icon' => 'M15.75 10.5l4.72-4.72a.75.75 0 011.28.53v11.38a.75.75 0 01-1.28.53l-4.72-4.72M4.5 18.75h9a2.25 2.25 0 002.25-2.25v-9a2.25 2.25 0 00-2.25-2.25h-9A2.25 2.25 0 002.25 7.5v9a2.25 2.25 0 002.25 2.25z'],
                            ] as $svc)
                                <div class="flex items-start gap-2.5 rounded-[12px] bg-zinc-50 p-3 ring-1 ring-zinc-100 dark:bg-[#0c1a36] dark:ring-[#24364f]">
                                    <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-[12px] bg-blue-600/10 text-blue-600 dark:bg-blue-500/20 dark:text-blue-300">
                                        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="{{ $svc['icon'] }}"/></svg>
                                    </span>
                                    <div class="min-w-0">
                                        <p class="text-xs font-bold text-zinc-900 dark:text-white">{{ $svc['t'] }}</p>
                                        <p class="mt-0.5 text-[11px] leading-snug text-zinc-500 dark:text-zinc-400">{{ $svc['d'] }}</p>
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    </div>

                    {{-- Top-up + iMessage note (voice) / data-only note. --}}
                    <div x-show="detailsPlan()?.is_voice" x-cloak class="mt-4 rounded-[12px] bg-blue-50 p-4 ring-1 ring-blue-100 dark:bg-[#0c1a36] dark:ring-blue-500/20">
                        <p class="flex items-center gap-2 text-sm font-bold text-zinc-900 dark:text-white">
                            <svg class="h-4 w-4 text-blue-600 dark:text-blue-300" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M16.023 9.348h4.992v-.001M2.985 19.644v-4.992m0 0h4.992m-4.993 0l3.181 3.183a8.25 8.25 0 0013.803-3.7M4.031 9.865a8.25 8.25 0 0113.803-3.7l3.181 3.182m0-4.991v4.99"/></svg>
                            Top-ups &amp; iMessage
                        </p>
                        <p class="mt-1 text-sm leading-relaxed text-zinc-600 dark:text-white">You can top up this plan any time for local calls, texts and regular data browsing. Using iMessage is always free and unlimited - it never counts against your allowance.</p>
                    </div>
                    <div x-show="detailsPlan() && ! detailsPlan().is_voice" x-cloak class="mt-4 rounded-[12px] bg-zinc-50 p-4 ring-1 ring-zinc-100 dark:bg-[#0c1a36] dark:ring-[#24364f]">
                        <p class="flex items-center gap-2 text-sm font-bold text-zinc-900 dark:text-white">
                            <svg class="h-4 w-4 text-zinc-500 dark:text-zinc-300" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M8.288 15.038a5.25 5.25 0 017.424 0M5.106 11.856c3.807-3.808 9.98-3.808 13.788 0M1.924 8.674c5.565-5.565 14.587-5.565 20.152 0M12.53 18.22l-.53.53-.53-.53a.75.75 0 011.06 0z"/></svg>
                            Data only
                        </p>
                        <p class="mt-1 text-sm leading-relaxed text-zinc-600 dark:text-white">This is a data-only plan - it keeps you connected online with mobile data for the full validity period. It has no calls, texts or phone number, just internet access.</p>
                    </div>

                    {{-- What happens when the allowance runs out - voice plans only
                         (it talks about calls/SMS/iMessage, which data-only plans lack). --}}
                    <div x-show="detailsPlan()?.is_voice" x-cloak class="mt-4 rounded-[12px] bg-zinc-50 p-4 ring-1 ring-zinc-100 dark:bg-[#0c1a36] dark:ring-[#24364f]">
                        <p class="text-sm leading-relaxed text-zinc-700 dark:text-zinc-200">After your data, SMS and calls finish, you can still call, text and FaceTime over iMessage, WhatsApp and your WiFi. To keep using normal texts, calls and data, top up before you let your eSIM expire. If you plan to keep using it, a top-up auto-renews your eSIM to the top-up plan you choose. On your Web App orders page you can manage your eSIM, see remaining data, credit and SMS, top up your eSIM (only from there) and install your eSIM from there too. Have fun 😊</p>
                    </div>

                    {{-- Add to cart straight from the details modal. --}}
                    <div class="mt-6 flex items-center justify-between gap-3 border-t border-zinc-100 pt-5 dark:border-zinc-700/60">
                        <p class="text-xl font-extrabold tabular-nums text-zinc-900 dark:text-white" x-text="detailsPlan()?.price_label"></p>
                        <button type="button" @click="selectedId = detailsId; showDetails = false; addToCart()" class="inline-flex h-11 items-center rounded-[12px] bg-blue-600 px-5 text-sm font-semibold text-white shadow-lg shadow-blue-600/25 transition-colors hover:bg-blue-700 disabled:opacity-60" :disabled="$store.cart.loading">Add to cart</button>
                    </div>

                    <p class="mt-4 text-xs leading-relaxed text-zinc-500 dark:text-zinc-400">To complete your order, confirm your device is eSIM-compatible and network-unlocked.</p>
                </div>
            </div>
        </div>
    </section>
@endif

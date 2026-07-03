{{-- Services + referral promo strip. Dark glass panel with a looping ambient
     video background. Left = pitch copy. Right = primary CTAs + a copy-the-
     referral-code chip (or guest prompt to set up an account). Echoes the
     Cryptorefills "agents" panel structure but for the storefront. --}}
@php
    $authUser = auth()->user();
    $referralCode = $authUser?->referral_code;
    $referralUrl  = $referralCode ? rtrim(url('/'), '/') . '/?ref=' . $referralCode : null;
@endphp

<div>
    {{-- Section heading sits above the dark panel, matching the rhythm of the
         brand rows / explore row above. --}}
    <div class="mb-4">
        <h2 class="text-lg font-bold text-zinc-900 sm:text-xl dark:text-white">What we bring</h2>
    </div>

<section
    aria-label="Our services"
    class="relative overflow-hidden rounded-[40px] bg-zinc-950 text-white ring-1 ring-white/10 shadow-xl shadow-zinc-900/30"
>
    {{-- Ambient looping background — self-hosted storefront video. LAZY by design:
         it carries no src and never preloads, so the (heavy) file stays OFF the
         critical path and can't hurt LCP or jank the main thread. We attach the
         source and start playback only once the page has fully loaded AND the
         section scrolls within 300px of the viewport. Before that the panel just
         shows its black background, which is fine for a decorative layer. --}}
    <div class="absolute inset-0 overflow-hidden" aria-hidden="true">
        <video
            x-data="{
                started: false,
                start() {
                    if (this.started) { return; }
                    this.started = true;
                    const v = this.$el;
                    const s = document.createElement('source');
                    s.src = v.dataset.src;
                    s.type = 'video/mp4';
                    v.appendChild(s);
                    v.load();
                    v.play().catch(() => {});
                },
                init() {
                    const io = new IntersectionObserver((entries) => {
                        entries.forEach((e) => { if (e.isIntersecting) { this.start(); io.disconnect(); } });
                    }, { rootMargin: '300px' });
                    const arm = () => io.observe(this.$el);
                    if (document.readyState === 'complete') { arm(); }
                    else { window.addEventListener('load', arm, { once: true }); }
                },
            }"
            class="absolute inset-0 h-full w-full object-cover"
            muted
            loop
            playsinline
            preload="none"
            data-src="{{ asset('assets/'.rawurlencode('store front video youtube replacement.mp4')) }}"
        ></video>
    </div>

    {{-- Pure-black overlay so the copy stays readable on every frame of the
         video. Solid 70% black tint plus a sideways gradient that deepens
         toward the left where the headline sits. --}}
    <div class="pointer-events-none absolute inset-0 bg-black/70" aria-hidden="true"></div>
    <div class="pointer-events-none absolute inset-0 bg-gradient-to-r from-black/85 via-black/60 to-black/40" aria-hidden="true"></div>

    <div class="relative z-20 grid gap-10 px-6 py-10 sm:px-10 sm:py-12 lg:grid-cols-2 lg:items-center lg:gap-12 lg:px-14 lg:py-16">

        {{-- Left: section heading + pitch copy --}}
        <div class="max-w-xl">
            <h2 class="text-2xl font-extrabold tracking-tight text-white sm:text-3xl lg:text-[34px]">
                What Rshop has to offer you
            </h2>
            <p class="mt-4 text-base leading-relaxed text-zinc-200 sm:text-lg">
                Access <span class="font-semibold text-blue-400">23+ digital services</span> in one powerful ecosystem including gift cards, eSIMs, mobile top-ups, bill payments, flights, and global stays.
            </p>
            <p class="mt-3 text-base leading-relaxed text-zinc-300 sm:text-lg">
                {{ $siteName }} brings everything together under one roof, making international shopping, connectivity, travel, and everyday digital payments fast, seamless, and stress-free.
            </p>
            <p class="mt-4 text-base font-semibold text-white sm:text-lg">
                One platform. Endless possibilities.<br>
                <span class="text-zinc-200">Built to simplify your life.</span>
            </p>
        </div>

        {{-- Right: actions --}}
        <div class="flex flex-col items-stretch gap-5 sm:items-center">

            {{-- Referral link pill (logged-in) or guest prompt --}}
            @auth
                @if ($referralUrl)
                    <div
                        x-data="{
                            copied: false,
                            async copy() {
                                const url = this.$refs.linkAnchor?.href || this.$refs.linkAnchor?.dataset.url || '';
                                if (! url) { return; }
                                const done = () => { this.copied = true; setTimeout(() => this.copied = false, 1800); };
                                if (navigator.clipboard?.writeText) {
                                    try { await navigator.clipboard.writeText(url); done(); return; }
                                    catch (e) { /* fall through to execCommand */ }
                                }
                                const ta = document.createElement('textarea');
                                ta.value = url;
                                ta.setAttribute('readonly', '');
                                ta.style.position = 'fixed';
                                ta.style.top = '0';
                                ta.style.left = '0';
                                ta.style.opacity = '0';
                                document.body.appendChild(ta);
                                ta.focus();
                                ta.select();
                                ta.setSelectionRange(0, ta.value.length);
                                let ok = false;
                                try { ok = document.execCommand('copy'); } catch (e) {}
                                document.body.removeChild(ta);
                                if (ok) { done(); }
                                else { window.prompt('Copy this referral link manually:', url); }
                            },
                        }"
                        class="w-full sm:w-auto"
                    >
                        {{-- overflow-hidden + max-w-full hard-contain the pill so the
                             Copy chip can never hang past the rounded edge on narrow
                             phones; the URL truncates first. Mobile gets slightly
                             tighter padding/type than sm+. --}}
                        <div class="flex w-full max-w-full items-center gap-1.5 overflow-hidden rounded-[25px] py-1.5 pl-1 pr-1.5 ring-1 ring-white/20 shadow-[inset_0_1px_0_rgba(255,255,255,0.18)] backdrop-blur-2xl backdrop-saturate-150 sm:inline-flex sm:w-auto sm:gap-2 sm:px-2 sm:py-2"
                             style="background: linear-gradient(180deg, rgba(255,255,255,0.08) 0%, rgba(255,255,255,0.03) 100%);"
                        >
                            <a
                                x-ref="linkAnchor"
                                href="{{ $referralUrl }}"
                                data-url="{{ $referralUrl }}"
                                target="_blank"
                                rel="noopener"
                                class="min-w-0 flex-1 truncate pl-2.5 font-mono text-[11px] text-blue-300 underline-offset-4 transition-colors hover:text-blue-200 hover:underline sm:flex-initial sm:pl-3 sm:text-sm"
                            >{{ $referralUrl }}</a>
                            <button
                                type="button"
                                @click="copy()"
                                aria-label="Copy your referral link"
                                class="inline-flex shrink-0 items-center gap-1 rounded-[20px] bg-white px-2.5 py-1.5 text-[11px] font-semibold text-zinc-900 transition-colors hover:bg-zinc-100 sm:gap-1.5 sm:px-3 sm:text-xs"
                            >
                                <svg x-show="! copied" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75" aria-hidden="true">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 17.25v3.375c0 .621-.504 1.125-1.125 1.125h-9.75a1.125 1.125 0 01-1.125-1.125V7.875c0-.621.504-1.125 1.125-1.125H6.75a9.06 9.06 0 011.5.124m7.5 10.376h3.375c.621 0 1.125-.504 1.125-1.125V11.25c0-4.46-3.243-8.161-7.5-8.876a9.06 9.06 0 00-1.5-.124H9.375c-.621 0-1.125.504-1.125 1.125v3.5m7.5 10.375H9.375a1.125 1.125 0 01-1.125-1.125v-9.25"/>
                                </svg>
                                <svg x-show="copied" x-cloak class="h-4 w-4 text-emerald-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5" aria-hidden="true">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5"/>
                                </svg>
                                <span x-text="copied ? 'Copied' : 'Copy'">Copy</span>
                            </button>
                        </div>
                        <p class="mt-2 text-center text-xs text-zinc-400">Share your referral link to earn RCoin</p>
                    </div>
                @else
                    <div class="w-full max-w-sm rounded-[25px] px-4 py-3 text-center ring-1 ring-white/20 shadow-[inset_0_1px_0_rgba(255,255,255,0.18)] backdrop-blur-2xl backdrop-saturate-150"
                         style="background: linear-gradient(180deg, rgba(255,255,255,0.08) 0%, rgba(255,255,255,0.03) 100%);"
                    >
                        <p class="text-sm font-semibold text-white">Set your referral code to start earning</p>
                        <a href="{{ route('dashboard.rewards') }}" wire:navigate class="mt-1 inline-block text-xs font-medium text-blue-300 underline underline-offset-2 hover:text-blue-200">Open Rewards</a>
                    </div>
                @endif
            @else
                <div
                    class="w-full max-w-sm rounded-[25px] px-4 py-3 text-center ring-1 ring-white/20 shadow-[inset_0_1px_0_rgba(255,255,255,0.18)] backdrop-blur-2xl backdrop-saturate-150"
                    style="background: linear-gradient(180deg, rgba(255,255,255,0.08) 0%, rgba(255,255,255,0.03) 100%);"
                >
                    <p class="text-sm font-semibold text-white">Set up your account to start earning RCoin</p>
                    <button
                        type="button"
                        @click="$dispatch('open-auth-modal', { mode: 'register' })"
                        class="mt-1 inline-block text-xs font-medium text-blue-300 underline underline-offset-2 hover:text-blue-200"
                    >Create a free account</button>
                </div>
            @endauth

            {{-- Primary action pair --}}
            <div class="flex flex-wrap items-center justify-center gap-3 sm:justify-start">
                <a
                    href="{{ route('shop.gift-cards') }}"
                    wire:navigate
                    class="inline-flex items-center gap-2 rounded-[12px] border-2 border-white bg-transparent px-5 py-2.5 text-base font-semibold text-white transition-colors hover:bg-white/10"
                >
                    <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M21 11.25v8.25a1.5 1.5 0 01-1.5 1.5H5.25a1.5 1.5 0 01-1.5-1.5v-8.25M12 4.875A2.625 2.625 0 109.375 7.5H12m0-2.625V7.5m0-2.625A2.625 2.625 0 1114.625 7.5H12m0 0V21m-8.625-9.75h18c.621 0 1.125-.504 1.125-1.125v-1.5c0-.621-.504-1.125-1.125-1.125h-18c-.621 0-1.125.504-1.125 1.125v1.5c0 .621.504 1.125 1.125 1.125z"/>
                    </svg>
                    Gift Cards
                </a>
                <a
                    href="{{ route('shop.esims') }}"
                    wire:navigate
                    class="inline-flex items-center gap-2 rounded-[12px] border-2 border-white bg-transparent px-5 py-2.5 text-base font-semibold text-white transition-colors hover:bg-white/10"
                >
                    <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M10.5 1.5H8.25A2.25 2.25 0 006 3.75v16.5a2.25 2.25 0 002.25 2.25h7.5A2.25 2.25 0 0018 20.25V3.75a2.25 2.25 0 00-2.25-2.25H13.5m-3 0V3h3V1.5m-3 0h3m-3 18.75h3"/>
                    </svg>
                    eSIMs
                </a>
            </div>
        </div>

    </div>
</section>
</div>

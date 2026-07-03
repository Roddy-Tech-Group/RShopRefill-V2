@php
    // Earn Points - marketing page for the Rcoin rewards programme. Dark-mode safe:
    // bg-white/bg-blue-50 remap to navy in dark; bg-blue-600 stays blue.
    $rcoinEnabled = (bool) \App\Models\Setting::get('rcoin_enabled', true);
    $ways = [
        ['title' => 'Shop and earn', 'desc' => 'Earn Rcoin automatically on every purchase, from gift cards and eSIMs to top-ups, bills and travel.', 'path' => 'M2.25 3h1.386c.51 0 .955.343 1.087.835l.383 1.437M7.5 14.25a3 3 0 00-3 3h15.75m-12.75-3h11.218c1.121-2.3 2.1-4.684 2.924-7.138a60.114 60.114 0 00-16.536-1.84M7.5 14.25L5.106 5.272M6 20.25a.75.75 0 11-1.5 0 .75.75 0 011.5 0zm12.75 0a.75.75 0 11-1.5 0 .75.75 0 011.5 0z'],
        ['title' => 'Refer your friends', 'desc' => 'Share your referral code. When friends join and shop, you both get rewarded.', 'path' => 'M18 18.72a9.094 9.094 0 003.741-.479 3 3 0 00-4.682-2.72m.94 3.198l.001.031c0 .225-.012.447-.037.666A11.944 11.944 0 0112 21c-2.17 0-4.207-.576-5.963-1.584A6.062 6.062 0 016 18.719m12 0a5.971 5.971 0 00-.941-3.197m0 0A5.995 5.995 0 0012 12.75a5.995 5.995 0 00-5.058 2.772m0 0a3 3 0 00-4.681 2.72 8.986 8.986 0 003.74.477m.94-3.197a5.971 5.971 0 00-.94 3.197M15 6.75a3 3 0 11-6 0 3 3 0 016 0zm6 3a2.25 2.25 0 11-4.5 0 2.25 2.25 0 014.5 0zm-13.5 0a2.25 2.25 0 11-4.5 0 2.25 2.25 0 014.5 0z'],
        ['title' => 'Redeem your Rcoin', 'desc' => 'Turn your Rcoin into wallet credit and spend it on anything we offer. Your rewards, your way.', 'path' => 'M21 11.25v8.25a1.5 1.5 0 01-1.5 1.5H5.25a1.5 1.5 0 01-1.5-1.5v-8.25M12 4.875A2.625 2.625 0 109.375 7.5H12m0-2.625V7.5m0-2.625A2.625 2.625 0 1114.625 7.5H12m0 0V21m-8.625-9.75h18c.621 0 1.125-.504 1.125-1.125v-1.5c0-.621-.504-1.125-1.125-1.125h-18c-.621 0-1.125.504-1.125 1.125v1.5c0 .621.504 1.125 1.125 1.125z'],
    ];
@endphp

<x-layouts.app.header :title="'Earn Points | '.$siteName">

    @unless ($rcoinEnabled)
        {{-- Engine is off. Show a single hero explaining the programme is
             paused and let the page end early so we don't promise rewards
             that won't be paid. Existing balances stay spendable from the
             Rewards dashboard. --}}
        <section class="border-b border-zinc-100 bg-blue-50">
            <div class="mx-auto w-full max-w-[1140px] px-4 py-14 text-center sm:px-6 sm:py-20">
                <span class="inline-flex items-center gap-2 rounded-[5px] bg-amber-100 px-3 py-1.5 text-[11px] font-bold uppercase tracking-[0.14em] text-amber-700">Paused</span>
                <h1 class="mt-5 text-3xl font-bold tracking-tight text-zinc-900 sm:text-4xl lg:text-5xl">Rewards are currently paused</h1>
                <p class="mx-auto mt-3 max-w-xl text-sm leading-relaxed text-zinc-600 sm:text-base">
                    The Rcoin earning programme is temporarily off. Any Rcoin already in your wallet stays yours and can still be used at checkout when the programme resumes.
                </p>
                <div class="mt-7 flex flex-col gap-3 sm:flex-row sm:justify-center">
                    <a href="{{ route('home') }}" class="inline-flex items-center justify-center rounded-[12px] bg-blue-600 px-6 py-3 text-sm font-semibold text-white transition-colors hover:bg-blue-700">Back to home</a>
                </div>
            </div>
        </section>
    @else

    {{-- ── Hero ──────────────────────────────────────────────── --}}
    <section class="border-b border-zinc-100 bg-blue-50">
        <div class="mx-auto w-full max-w-[1140px] px-4 py-14 text-center sm:px-6 sm:py-20">
            <span class="inline-flex items-center gap-2 rounded-[5px] bg-blue-100 px-3 py-1.5 text-[11px] font-bold uppercase tracking-[0.14em] text-blue-700">Rewards</span>
            <h1 class="mt-5 text-3xl font-bold tracking-tight text-zinc-900 sm:text-4xl lg:text-5xl">Earn points every time you shop</h1>
            <p class="mx-auto mt-3 max-w-xl text-sm leading-relaxed text-zinc-600 sm:text-base">
                Every order earns you Rcoin, our rewards currency. Collect it as you shop and redeem it for credit you can spend across the platform.
            </p>
        </div>
    </section>

    {{-- ── How to earn ───────────────────────────────────────── --}}
    <section class="mx-auto w-full max-w-[1140px] px-4 py-14 sm:px-6 sm:py-16">
        <h2 class="text-center text-2xl font-bold tracking-tight text-zinc-900 sm:text-3xl">How it works</h2>
        <p class="mx-auto mt-2 max-w-lg text-center text-sm text-zinc-600">Three simple ways to earn and use your Rcoin.</p>

        <div class="mt-9 grid grid-cols-1 gap-5 sm:grid-cols-3">
            @foreach ($ways as $i => $way)
                <div class="rounded-[12px] bg-white p-6 text-center ring-1 ring-zinc-100">
                    <span class="mx-auto flex h-14 w-14 items-center justify-center rounded-[12px] bg-blue-50 text-blue-600">
                        <svg class="h-7 w-7" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="{{ $way['path'] }}"/></svg>
                    </span>
                    <p class="mt-4 text-xs font-bold uppercase tracking-wider text-blue-600">Step {{ $i + 1 }}</p>
                    <h3 class="mt-1 text-base font-bold text-zinc-900">{{ $way['title'] }}</h3>
                    <p class="mt-1.5 text-sm leading-relaxed text-zinc-600">{{ $way['desc'] }}</p>
                </div>
            @endforeach
        </div>

        {{-- Earn-rate highlight --}}
        <div class="mx-auto mt-8 flex max-w-2xl items-start gap-3 rounded-[12px] bg-blue-50 p-4 ring-1 ring-zinc-100">
            <svg class="mt-0.5 h-5 w-5 shrink-0 text-blue-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M11.25 11.25l.041-.02a.75.75 0 011.063.852l-.708 2.836a.75.75 0 001.063.853l.041-.021M21 12a9 9 0 11-18 0 9 9 0 0118 0zm-9-3.75h.008v.008H12V8.25z"/></svg>
            <div>
                <p class="text-sm font-bold text-blue-700">Earn as you spend</p>
                <p class="mt-1 text-sm leading-relaxed text-zinc-600">You collect Rcoin on every completed order. Your balance and history are always available on your Rewards page.</p>
            </div>
        </div>
    </section>

    {{-- ── CTA ───────────────────────────────────────────────── --}}
    <section class="bg-blue-600">
        <div class="mx-auto w-full max-w-[1140px] px-4 py-16 text-center sm:px-6">
            <h2 class="text-2xl font-bold text-white sm:text-3xl">Start earning today</h2>
            <p class="mx-auto mt-2 max-w-md text-sm leading-relaxed text-blue-100">Shop the products you already love and watch your Rcoin grow.</p>
            <div class="mt-7 flex flex-col gap-3 sm:flex-row sm:justify-center">
                <a href="{{ route('shop.gift-cards') }}" wire:navigate class="inline-flex items-center justify-center gap-2 rounded-[6px] bg-white px-6 py-3 text-sm font-semibold text-blue-700 transition-colors hover:bg-blue-50">
                    Start shopping
                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M8.25 4.5l7.5 7.5-7.5 7.5"/></svg>
                </a>
                <a href="{{ route('dashboard.rewards') }}" wire:navigate class="inline-flex items-center justify-center gap-2 rounded-[6px] border border-white/40 px-6 py-3 text-sm font-semibold text-white transition-colors hover:bg-white/10">
                    View your rewards
                </a>
            </div>
        </div>
    </section>

    @endunless
</x-layouts.app.header>

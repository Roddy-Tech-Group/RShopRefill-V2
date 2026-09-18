@php
    // eSIM Installation Guide. The public, general version of the steps: a
    // plan's own device-specific instructions still live on its order page
    // once bought. Same shell as the other help and policy pages.
    //
    // Menu names below are the common ones. Phone makers rename them between
    // models and software versions, so the page says so rather than implying
    // every phone matches word for word.
    $step = 'flex h-7 w-7 shrink-0 items-center justify-center rounded-full bg-blue-100 text-sm font-bold text-blue-700';
@endphp

<x-layouts.app.header :title="'eSIM Installation Guide | '.$siteName">

    {{-- ── Hero ──────────────────────────────────────────────── --}}
    <section class="border-b border-zinc-100 bg-blue-50">
        <div class="mx-auto w-full max-w-[1140px] px-4 py-14 text-center sm:px-6 sm:py-20">
            <span class="inline-flex items-center gap-2 rounded-[5px] bg-blue-100 px-3 py-1.5 text-[11px] font-bold uppercase tracking-[0.14em] text-blue-700">eSIM</span>
            <h1 class="mt-5 text-3xl font-bold tracking-tight text-zinc-900 sm:text-4xl">eSIM Installation Guide</h1>
            <p class="mx-auto mt-3 max-w-xl text-sm text-zinc-600 sm:text-base">How to install and turn on your travel eSIM on iPhone and Android.</p>
        </div>
    </section>

    <div class="mx-auto w-full max-w-[1140px] px-4 py-12 sm:px-6 sm:py-16">

        {{-- ── Before you start ──────────────────────────────────── --}}
        <section>
            <h2 class="text-xl font-bold tracking-tight text-zinc-900 sm:text-2xl">Before you start</h2>
            <ul class="mt-4 list-disc space-y-2 pl-5 text-sm leading-relaxed text-zinc-600 marker:text-blue-500 sm:text-base">
                <li>Your phone must support eSIM and be unlocked, so it accepts a line from another network.</li>
                <li>Connect to Wi-Fi or another internet connection. The eSIM downloads to your phone during setup.</li>
                <li>Have your QR code or manual setup details ready. You will find them on your order page, in your dashboard and in your delivery email.</li>
                <li>Once installed, keep the eSIM on your phone. If you remove it, the same QR code often cannot be used again.</li>
            </ul>
        </section>

        {{-- ── iPhone ────────────────────────────────────────────── --}}
        <section class="mt-12">
            <h2 class="text-xl font-bold tracking-tight text-zinc-900 sm:text-2xl">Install on iPhone</h2>
            <ol class="mt-4 space-y-3 text-sm leading-relaxed text-zinc-600 sm:text-base">
                <li class="flex gap-3"><span class="{{ $step }}">1</span><span>Open Settings, then Cellular. In some regions this is called Mobile Data or Mobile Service.</span></li>
                <li class="flex gap-3"><span class="{{ $step }}">2</span><span>Tap Add eSIM, then Use QR Code.</span></li>
                <li class="flex gap-3"><span class="{{ $step }}">3</span><span>Scan the QR code from your order. If you cannot scan it, choose Enter Details Manually and type the SM-DP+ address and activation code.</span></li>
                <li class="flex gap-3"><span class="{{ $step }}">4</span><span>When asked, give the new line a label such as Travel, so it is easy to tell apart from your home line.</span></li>
            </ol>
        </section>

        {{-- ── Android ───────────────────────────────────────────── --}}
        <section class="mt-12">
            <h2 class="text-xl font-bold tracking-tight text-zinc-900 sm:text-2xl">Install on Android</h2>
            <p class="mt-3 text-sm leading-relaxed text-zinc-600 sm:text-base">
                Menu names vary between phone makers and software versions. These are the most common paths.
            </p>
            <ul class="mt-4 space-y-3 text-sm leading-relaxed text-zinc-600 sm:text-base">
                <li class="rounded-[12px] bg-zinc-50 p-4 ring-1 ring-zinc-100"><span class="font-semibold text-zinc-900">Samsung Galaxy:</span> Settings, Connections, SIM manager, Add eSIM, then scan the QR code.</li>
                <li class="rounded-[12px] bg-zinc-50 p-4 ring-1 ring-zinc-100"><span class="font-semibold text-zinc-900">Google Pixel:</span> Settings, Network and internet, SIMs, Add SIM, then choose to download a SIM and scan the QR code.</li>
                <li class="rounded-[12px] bg-zinc-50 p-4 ring-1 ring-zinc-100"><span class="font-semibold text-zinc-900">Other Android phones:</span> look in Settings under Network, SIM or Mobile network for an option to add or download an eSIM.</li>
            </ul>
        </section>

        {{-- ── Turn it on ────────────────────────────────────────── --}}
        <section class="mt-12">
            <h2 class="text-xl font-bold tracking-tight text-zinc-900 sm:text-2xl">Turn it on at your destination</h2>
            <ul class="mt-4 list-disc space-y-2 pl-5 text-sm leading-relaxed text-zinc-600 marker:text-blue-500 sm:text-base">
                <li>Choose the eSIM as the line your phone uses for mobile data.</li>
                <li>Turn on data roaming for the eSIM line.</li>
                <li>You can keep your home SIM on for calls and texts, but turn off its data roaming so your home network does not charge you for data.</li>
                <li>Check your plan's validity details: many plans only start once the eSIM connects to a supported network at your destination.</li>
            </ul>
        </section>

        {{-- ── Troubleshooting ───────────────────────────────────── --}}
        <section class="mt-12">
            <h2 class="text-xl font-bold tracking-tight text-zinc-900 sm:text-2xl">If something is not working</h2>
            <ul class="mt-4 list-disc space-y-2 pl-5 text-sm leading-relaxed text-zinc-600 marker:text-blue-500 sm:text-base">
                <li>The QR code will not scan: enter the setup details manually instead.</li>
                <li>Installed but no signal: check that data roaming is on for the eSIM line and that it is selected for mobile data, then turn airplane mode on and off or restart your phone.</li>
            </ul>

            <div class="my-5 flex items-start gap-3 rounded-[12px] bg-zinc-50 p-4 ring-1 ring-zinc-100">
                <svg class="mt-0.5 h-5 w-5 shrink-0 text-blue-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M9.879 7.519c1.171-1.025 3.071-1.025 4.242 0 1.172 1.025 1.172 2.687 0 3.712-.203.179-.43.326-.67.442-.745.361-1.45.999-1.45 1.827v.75M21 12a9 9 0 11-18 0 9 9 0 0118 0zm-9 5.25h.008v.008H12v-.008z"/></svg>
                <div>
                    <p class="text-sm font-bold text-blue-700">Still stuck?</p>
                    <p class="mt-1 text-sm leading-relaxed text-zinc-600">Visit the <a href="{{ route('shop.help') }}" wire:navigate class="font-medium text-blue-600 hover:underline">Help Center</a> or <a href="{{ route('shop.contact') }}" wire:navigate class="font-medium text-blue-600 hover:underline">contact support</a> with your order number.</p>
                </div>
            </div>
        </section>
    </div>

</x-layouts.app.header>

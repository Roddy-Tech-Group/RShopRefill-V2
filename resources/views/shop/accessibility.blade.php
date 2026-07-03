@php
    // Accessibility Statement. Dark-mode safe: bg-white/bg-zinc-50/bg-blue-50 remap
    // to navy in dark; coloured accents (text-blue-700) read in both themes.
    $supportEmail = 'support@rshoprefill.com';
    $lastUpdated = 'May 23, 2026';
@endphp

<x-layouts.app.header :title="'Accessibility Statement | '.$siteName">

    {{-- ── Hero ──────────────────────────────────────────────── --}}
    <section class="border-b border-zinc-100 bg-blue-50">
        <div class="mx-auto w-full max-w-[1140px] px-4 py-14 text-center sm:px-6 sm:py-20">
            <span class="inline-flex items-center gap-2 rounded-[5px] bg-blue-100 px-3 py-1.5 text-[11px] font-bold uppercase tracking-[0.14em] text-blue-700">Accessibility</span>
            <h1 class="mt-5 text-3xl font-bold tracking-tight text-zinc-900 sm:text-4xl">Accessibility Statement</h1>
            <p class="mt-3 text-sm text-zinc-600">Last updated: {{ $lastUpdated }}</p>
        </div>
    </section>

    <div class="mx-auto w-full max-w-[1140px] px-4 py-12 sm:px-6 sm:py-16">

        {{-- Intro --}}
        <p class="text-sm leading-relaxed text-zinc-600 sm:text-base">
            {{ $siteName }} is committed to making our platform usable by as many people as possible, including people with
            disabilities. We aim to meet the Web Content Accessibility Guidelines (WCAG) 2.1 at Level AA, and we keep working
            to improve the experience for everyone.
        </p>

        {{-- ── 1 ─────────────────────────────────────────────────── --}}
        <section class="mt-12">
            <h2 class="text-xl font-bold tracking-tight text-zinc-900 sm:text-2xl">Our commitment</h2>
            <p class="mt-3 text-sm leading-relaxed text-zinc-600 sm:text-base">
                Accessibility is part of how we build. We design and test our pages so that people using keyboards, screen
                readers, screen magnifiers and other assistive technologies can browse, buy and manage their account with
                confidence.
            </p>
        </section>

        {{-- ── 2 ─────────────────────────────────────────────────── --}}
        <section class="mt-12">
            <h2 class="text-xl font-bold tracking-tight text-zinc-900 sm:text-2xl">What we have done</h2>
            <ul class="mt-4 list-disc space-y-2 pl-5 text-sm leading-relaxed text-zinc-600 marker:text-blue-500 sm:text-base">
                <li>Clear, semantic structure with labels that assistive technologies can read.</li>
                <li>Full keyboard navigation with visible focus states on interactive elements.</li>
                <li>Readable colour contrast for text and important controls.</li>
                <li>A light and dark theme, plus a system option, to suit your comfort.</li>
                <li>Respect for the "reduce motion" setting, so animations are minimised when you prefer.</li>
                <li>A responsive layout that works across phones, tablets and desktops.</li>
                <li>Descriptive text alternatives for meaningful images.</li>
            </ul>
        </section>

        {{-- ── 3 ─────────────────────────────────────────────────── --}}
        <section class="mt-12">
            <h2 class="text-xl font-bold tracking-tight text-zinc-900 sm:text-2xl">Ongoing work</h2>
            <p class="mt-3 text-sm leading-relaxed text-zinc-600 sm:text-base">
                Accessibility is an ongoing effort. We test regularly and fix issues as we find them. Some content provided by
                third parties may not yet fully meet our standards, and we work with our partners to improve this over time.
            </p>
        </section>

        {{-- ── 4 ─────────────────────────────────────────────────── --}}
        <section class="mt-12">
            <h2 class="text-xl font-bold tracking-tight text-zinc-900 sm:text-2xl">Need help or found a problem?</h2>
            <p class="mt-3 text-sm leading-relaxed text-zinc-600 sm:text-base">
                If you run into an accessibility barrier, or you need information in a different format, please tell us. We take
                this seriously and will do our best to help.
            </p>

            <div class="my-5 flex items-start gap-3 rounded-[12px] bg-zinc-50 p-4 ring-1 ring-zinc-100">
                <svg class="mt-0.5 h-5 w-5 shrink-0 text-blue-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M21.75 6.75v10.5a2.25 2.25 0 01-2.25 2.25h-15a2.25 2.25 0 01-2.25-2.25V6.75m19.5 0A2.25 2.25 0 0019.5 4.5h-15a2.25 2.25 0 00-2.25 2.25m19.5 0v.243a2.25 2.25 0 01-1.07 1.916l-7.5 4.615a2.25 2.25 0 01-2.36 0L3.32 8.91a2.25 2.25 0 01-1.07-1.916V6.75"/></svg>
                <div>
                    <p class="text-sm font-bold text-blue-700">Contact us about accessibility</p>
                    <p class="mt-1 text-sm leading-relaxed text-zinc-600">Email <a href="mailto:{{ $supportEmail }}" class="font-medium text-blue-600 hover:underline">{{ $supportEmail }}</a> or use our <a href="{{ route('shop.contact') }}" wire:navigate class="font-medium text-blue-600 hover:underline">Contact page</a>. Please describe the page and the problem so we can help quickly.</p>
                </div>
            </div>
        </section>

        <x-legal-footer />
    </div>

</x-layouts.app.header>

@php
    // Cookie Policy — customer-facing legal page. Dark-mode safe: backgrounds use
    // remapped classes (bg-white/bg-zinc-50/bg-blue-50 -> navy in dark) while
    // coloured accents (text-red-600 / text-blue-700) read in both themes.
    $supportEmail = 'support@rshoprefill.com';
    $lastUpdated = 'May 23, 2026';
@endphp

<x-layouts.app.header :title="'Cookie Policy | '.$siteName">

    {{-- ── Hero ──────────────────────────────────────────────── --}}
    <section class="border-b border-zinc-100 bg-blue-50">
        <div class="mx-auto w-full max-w-[1140px] px-4 py-14 text-center sm:px-6 sm:py-20">
            <span class="inline-flex items-center gap-2 rounded-[5px] bg-blue-100 px-3 py-1.5 text-[11px] font-bold uppercase tracking-[0.14em] text-blue-700">Cookies</span>
            <h1 class="mt-5 text-3xl font-bold tracking-tight text-zinc-900 sm:text-4xl">Cookie Policy</h1>
            <p class="mt-3 text-sm text-zinc-600">Last updated: {{ $lastUpdated }}</p>
        </div>
    </section>

    <div class="mx-auto w-full max-w-[1140px] px-4 py-12 sm:px-6 sm:py-16">

        {{-- Intro --}}
        <p class="text-sm leading-relaxed text-zinc-600 sm:text-base">
            This Cookie Policy explains how {{ $siteName }} uses cookies and similar technologies when you use our website and
            app. It should be read together with our
            <a href="{{ route('shop.privacy') }}" wire:navigate class="font-medium text-blue-600 hover:underline">Privacy Policy</a>.
            By using {{ $siteName }}, you agree to our use of cookies as described here.
        </p>

        {{-- On this page --}}
        <nav class="mt-7 rounded-[12px] bg-zinc-50 p-5 ring-1 ring-zinc-100" aria-label="On this page">
            <p class="text-xs font-semibold uppercase tracking-wider text-zinc-500">On this page</p>
            <ol class="mt-3 space-y-2 text-sm">
                <li><a href="#what" class="font-medium text-blue-600 hover:underline">1. What are cookies</a></li>
                <li><a href="#types" class="font-medium text-blue-600 hover:underline">2. Types of cookies we use</a></li>
                <li><a href="#how" class="font-medium text-blue-600 hover:underline">3. How we use cookies</a></li>
                <li><a href="#manage" class="font-medium text-blue-600 hover:underline">4. Managing your cookies</a></li>
                <li><a href="#third-party" class="font-medium text-blue-600 hover:underline">5. Third-party cookies</a></li>
                <li><a href="#contact" class="font-medium text-blue-600 hover:underline">6. Updates and contact</a></li>
            </ol>
        </nav>

        {{-- ── 1. What are cookies ───────────────────────────────── --}}
        <section id="what" class="mt-12 scroll-mt-24">
            <h2 class="text-xl font-bold tracking-tight text-zinc-900 sm:text-2xl">1. What are cookies</h2>
            <p class="mt-3 text-sm leading-relaxed text-zinc-600 sm:text-base">
                Cookies are small text files that a website stores on your device when you visit. We also use similar
                technologies, such as local storage and device identifiers. Together these help the platform work properly,
                remember your choices, keep your account secure and help us improve.
            </p>
        </section>

        {{-- ── 2. Types ──────────────────────────────────────────── --}}
        <section id="types" class="mt-12 scroll-mt-24">
            <h2 class="text-xl font-bold tracking-tight text-zinc-900 sm:text-2xl">2. Types of cookies we use</h2>
            <p class="mt-3 text-sm leading-relaxed text-zinc-600 sm:text-base">
                We use four main groups of cookies. Here is what each one does.
            </p>

            <div class="mt-5 overflow-x-auto rounded-[12px] ring-1 ring-zinc-100">
                <table class="w-full text-left text-sm">
                    <thead class="bg-zinc-50">
                        <tr>
                            <th class="px-4 py-3 font-semibold text-zinc-900">Type</th>
                            <th class="px-4 py-3 font-semibold text-zinc-900">What it does</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-zinc-100">
                        <tr>
                            <td class="px-4 py-3 font-medium text-zinc-900">Essential</td>
                            <td class="px-4 py-3 text-zinc-600">Keep you signed in, secure your session, and run core features like the cart and checkout. These cannot be turned off.</td>
                        </tr>
                        <tr>
                            <td class="px-4 py-3 font-medium text-zinc-900">Preferences</td>
                            <td class="px-4 py-3 text-zinc-600">Remember your country, currency, language and your light or dark theme.</td>
                        </tr>
                        <tr>
                            <td class="px-4 py-3 font-medium text-zinc-900">Analytics</td>
                            <td class="px-4 py-3 text-zinc-600">Help us understand how the platform is used, in aggregate, so we can make it better.</td>
                        </tr>
                        <tr>
                            <td class="px-4 py-3 font-medium text-zinc-900">Security and fraud prevention</td>
                            <td class="px-4 py-3 text-zinc-600">Detect suspicious activity and protect your account, wallet and payments.</td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <div class="my-5 flex items-start gap-3 rounded-[12px] bg-zinc-50 p-4 ring-1 ring-zinc-100">
                <svg class="mt-0.5 h-5 w-5 shrink-0 text-blue-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M11.25 11.25l.041-.02a.75.75 0 011.063.852l-.708 2.836a.75.75 0 001.063.853l.041-.021M21 12a9 9 0 11-18 0 9 9 0 0118 0zm-9-3.75h.008v.008H12V8.25z"/></svg>
                <div>
                    <p class="text-sm font-bold text-blue-700">Essential cookies are always on</p>
                    <p class="mt-1 text-sm leading-relaxed text-zinc-600">Essential and security cookies are required for the platform to work and keep you safe, so they cannot be switched off. You can still manage the other types.</p>
                </div>
            </div>
        </section>

        {{-- ── 3. How we use cookies ─────────────────────────────── --}}
        <section id="how" class="mt-12 scroll-mt-24">
            <h2 class="text-xl font-bold tracking-tight text-zinc-900 sm:text-2xl">3. How we use cookies</h2>
            <ul class="mt-4 list-disc space-y-2 pl-5 text-sm leading-relaxed text-zinc-600 marker:text-blue-500 sm:text-base">
                <li>To keep you signed in and protect your session.</li>
                <li>To remember your preferences, such as country, currency, language and theme.</li>
                <li>To run your cart, checkout and wallet smoothly.</li>
                <li>To detect and prevent fraud and keep your account secure.</li>
                <li>To measure and improve how the platform performs.</li>
            </ul>
        </section>

        {{-- ── 4. Managing cookies ───────────────────────────────── --}}
        <section id="manage" class="mt-12 scroll-mt-24">
            <h2 class="text-xl font-bold tracking-tight text-zinc-900 sm:text-2xl">4. Managing your cookies</h2>
            <p class="mt-3 text-sm leading-relaxed text-zinc-600 sm:text-base">
                You are in control of non-essential cookies. You can manage them in two ways:
            </p>
            <ul class="mt-4 list-disc space-y-2 pl-5 text-sm leading-relaxed text-zinc-600 marker:text-blue-500 sm:text-base">
                <li><span class="font-semibold text-zinc-900">Your browser settings.</span> Most browsers let you block or delete cookies. Check your browser's help pages for how to do this.</li>
                <li><span class="font-semibold text-zinc-900">Our cookie settings.</span> Where available, use the cookie settings link in the footer to adjust your choices.</li>
            </ul>

            <div class="my-5 flex items-start gap-3 rounded-[12px] bg-zinc-50 p-4 ring-1 ring-zinc-100">
                <svg class="mt-0.5 h-5 w-5 shrink-0 text-red-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m9-.75a9 9 0 11-18 0 9 9 0 0118 0zm-9 3.75h.008v.008H12v-.008z"/></svg>
                <div>
                    <p class="text-sm font-bold text-red-600">Blocking essential cookies breaks core features</p>
                    <p class="mt-1 text-sm leading-relaxed text-zinc-600">If you block all cookies, you may not be able to sign in, use your wallet, or complete a checkout. Essential cookies are needed for the platform to work safely.</p>
                </div>
            </div>
        </section>

        {{-- ── 5. Third-party cookies ────────────────────────────── --}}
        <section id="third-party" class="mt-12 scroll-mt-24">
            <h2 class="text-xl font-bold tracking-tight text-zinc-900 sm:text-2xl">5. Third-party cookies</h2>
            <p class="mt-3 text-sm leading-relaxed text-zinc-600 sm:text-base">
                Some trusted partners we rely on, such as analytics, payment and fraud-prevention providers, may set their own
                cookies to deliver their service securely. These partners are only allowed to use cookies for the purposes we
                agree with them.
            </p>

            <div class="my-5 flex items-start gap-3 rounded-[12px] bg-blue-50 p-4 ring-1 ring-zinc-100">
                <svg class="mt-0.5 h-5 w-5 shrink-0 text-blue-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75L11.25 15 15 9.75M21 12c0 1.268-.63 2.39-1.593 3.068a3.745 3.745 0 01-1.043 3.296 3.745 3.745 0 01-3.296 1.043A3.745 3.745 0 0112 21c-1.268 0-2.39-.63-3.068-1.593a3.746 3.746 0 01-3.296-1.043 3.745 3.745 0 01-1.043-3.296A3.745 3.745 0 013 12c0-1.268.63-2.39 1.593-3.068a3.745 3.745 0 011.043-3.296 3.746 3.746 0 013.296-1.043A3.746 3.746 0 0112 3c1.268 0 2.39.63 3.068 1.593a3.746 3.746 0 013.296 1.043 3.746 3.746 0 011.043 3.296A3.745 3.745 0 0121 12z"/></svg>
                <div>
                    <p class="text-sm font-bold text-blue-700">We never sell your data through cookies</p>
                    <p class="mt-1 text-sm leading-relaxed text-zinc-600">Cookies help us run the platform, keep it secure and improve it. We do not use them to sell your personal data to advertisers. See our Privacy Policy for full details.</p>
                </div>
            </div>
        </section>

        {{-- ── 6. Updates & contact ──────────────────────────────── --}}
        <section id="contact" class="mt-12 scroll-mt-24">
            <h2 class="text-xl font-bold tracking-tight text-zinc-900 sm:text-2xl">6. Updates and contact</h2>
            <p class="mt-3 text-sm leading-relaxed text-zinc-600 sm:text-base">
                We may update this Cookie Policy from time to time. The date at the top of this page shows the latest version.
                If you have any questions about how we use cookies, email us at
                <a href="mailto:{{ $supportEmail }}" class="font-medium text-blue-600 hover:underline">{{ $supportEmail }}</a>
                or <a href="{{ route('shop.contact') }}" wire:navigate class="font-medium text-blue-600 hover:underline">contact our team</a>.
            </p>
        </section>
        <x-legal-footer />
    </div>

</x-layouts.app.header>

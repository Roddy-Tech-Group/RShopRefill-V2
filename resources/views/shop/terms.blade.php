@php
    // Terms of Service — customer-facing legal page. Dark-mode safe: backgrounds use
    // remapped classes (bg-white/bg-zinc-50/bg-blue-50 -> navy in dark) while
    // coloured accents (text-red-600 / text-blue-700) read in both themes.
    // Terms / legal contact is a formal channel - kept on the info@ inbox.
    $supportEmail = 'info@rshoprefill.com';
    $lastUpdated = 'May 23, 2026';
@endphp

<x-layouts.app.header :title="'Terms of Service | '.$siteName">

    {{-- ── Hero ──────────────────────────────────────────────── --}}
    <section class="border-b border-zinc-100 bg-blue-50">
        <div class="mx-auto w-full max-w-[1140px] px-4 py-14 text-center sm:px-6 sm:py-20">
            <span class="inline-flex items-center gap-2 rounded-[5px] bg-blue-100 px-3 py-1.5 text-[11px] font-bold uppercase tracking-[0.14em] text-blue-700">Legal</span>
            <h1 class="mt-5 text-3xl font-bold tracking-tight text-zinc-900 sm:text-4xl">Terms of Service</h1>
            <p class="mt-3 text-sm text-zinc-600">Last updated: {{ $lastUpdated }}</p>
        </div>
    </section>

    <div class="mx-auto w-full max-w-[1140px] px-4 py-12 sm:px-6 sm:py-16">

        {{-- Intro --}}
        <p class="text-sm leading-relaxed text-zinc-600 sm:text-base">
            These Terms of Service ("Terms") govern your access to and use of {{ $siteName }}, including our website, app,
            wallet and all products and services we offer. Please read them carefully. By creating an account or making a
            purchase, you agree to these Terms. If you do not agree, please do not use the platform.
        </p>

        {{-- On this page --}}
        <nav class="mt-7 rounded-[12px] bg-zinc-50 p-5 ring-1 ring-zinc-100" aria-label="On this page">
            <p class="text-xs font-semibold uppercase tracking-wider text-zinc-500">On this page</p>
            <ol class="mt-3 grid grid-cols-1 gap-2 text-sm sm:grid-cols-2">
                <li><a href="#acceptance" class="font-medium text-blue-600 hover:underline">1. Acceptance of these Terms</a></li>
                <li><a href="#eligibility" class="font-medium text-blue-600 hover:underline">2. Eligibility and your account</a></li>
                <li><a href="#services" class="font-medium text-blue-600 hover:underline">3. Our products and services</a></li>
                <li><a href="#payments" class="font-medium text-blue-600 hover:underline">4. Wallet, pricing and payments</a></li>
                <li><a href="#orders" class="font-medium text-blue-600 hover:underline">5. Orders, delivery and refunds</a></li>
                <li><a href="#acceptable-use" class="font-medium text-blue-600 hover:underline">6. Acceptable use</a></li>
                <li><a href="#ip" class="font-medium text-blue-600 hover:underline">7. Intellectual property</a></li>
                <li><a href="#third-party" class="font-medium text-blue-600 hover:underline">8. Third-party services</a></li>
                <li><a href="#disclaimers" class="font-medium text-blue-600 hover:underline">9. Disclaimers and liability</a></li>
                <li><a href="#termination" class="font-medium text-blue-600 hover:underline">10. Suspension and termination</a></li>
                <li><a href="#law" class="font-medium text-blue-600 hover:underline">11. Governing law and disputes</a></li>
                <li><a href="#changes" class="font-medium text-blue-600 hover:underline">12. Changes and contact</a></li>
            </ol>
        </nav>

        {{-- ── 1 ─────────────────────────────────────────────────── --}}
        <section id="acceptance" class="mt-12 scroll-mt-24">
            <h2 class="text-xl font-bold tracking-tight text-zinc-900 sm:text-2xl">1. Acceptance of these Terms</h2>
            <p class="mt-3 text-sm leading-relaxed text-zinc-600 sm:text-base">
                By accessing or using {{ $siteName }}, you confirm that you have read, understood and agree to be bound by these
                Terms and by our <a href="{{ route('shop.privacy') }}" wire:navigate class="font-medium text-blue-600 hover:underline">Privacy Policy</a>,
                <a href="{{ route('shop.refund-policy') }}" wire:navigate class="font-medium text-blue-600 hover:underline">Refund and Cancellation Policy</a>
                and <a href="{{ route('shop.cookie-policy') }}" wire:navigate class="font-medium text-blue-600 hover:underline">Cookie Policy</a>, which form part of these Terms.
            </p>
        </section>

        {{-- ── 2 ─────────────────────────────────────────────────── --}}
        <section id="eligibility" class="mt-12 scroll-mt-24">
            <h2 class="text-xl font-bold tracking-tight text-zinc-900 sm:text-2xl">2. Eligibility and your account</h2>
            <ul class="mt-4 list-disc space-y-2 pl-5 text-sm leading-relaxed text-zinc-600 marker:text-blue-500 sm:text-base">
                <li>You must be at least 18 years old, or the age of majority in your country, to use the platform.</li>
                <li>You agree to provide accurate, current and complete information and to keep it up to date.</li>
                <li>You are responsible for keeping your password and transaction PIN confidential and for all activity under your account.</li>
                <li>To use higher-value services, you may be required to verify your identity (KYC). Verification requirements increase with the value and risk of the activity.</li>
                <li>One person should hold one account. We may decline, suspend or remove accounts that breach these Terms.</li>
            </ul>

            <div class="my-5 flex items-start gap-3 rounded-[12px] bg-zinc-50 p-4 ring-1 ring-zinc-100">
                <svg class="mt-0.5 h-5 w-5 shrink-0 text-blue-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M16.5 10.5V6.75a4.5 4.5 0 10-9 0v3.75m-.75 11.25h10.5a2.25 2.25 0 002.25-2.25v-6.75a2.25 2.25 0 00-2.25-2.25H6.75a2.25 2.25 0 00-2.25 2.25v6.75a2.25 2.25 0 002.25 2.25z"/></svg>
                <div>
                    <p class="text-sm font-bold text-blue-700">Keep your account secure</p>
                    <p class="mt-1 text-sm leading-relaxed text-zinc-600">Set a transaction PIN, use a strong password, and never share your codes, password or PIN. We will never ask you for them.</p>
                </div>
            </div>
        </section>

        {{-- ── 3 ─────────────────────────────────────────────────── --}}
        <section id="services" class="mt-12 scroll-mt-24">
            <h2 class="text-xl font-bold tracking-tight text-zinc-900 sm:text-2xl">3. Our products and services</h2>
            <p class="mt-3 text-sm leading-relaxed text-zinc-600 sm:text-base">
                {{ $siteName }} offers digital products and services, including gift cards, eSIMs, mobile top-ups, bill payments,
                and travel such as flights and stays, alongside an in-app wallet. Many products are fulfilled by third-party
                suppliers and networks. Product availability, pricing and features can vary by country and region, and may
                change or be withdrawn at any time.
            </p>
        </section>

        {{-- ── 4 ─────────────────────────────────────────────────── --}}
        <section id="payments" class="mt-12 scroll-mt-24">
            <h2 class="text-xl font-bold tracking-tight text-zinc-900 sm:text-2xl">4. Wallet, pricing and payments</h2>
            <ul class="mt-4 list-disc space-y-2 pl-5 text-sm leading-relaxed text-zinc-600 marker:text-blue-500 sm:text-base">
                <li>Your in-app wallet lets you hold balance and pay for services. Wallet credit is not a bank deposit and does not earn interest.</li>
                <li>You can pay by card, bank transfer, mobile money, crypto or wallet balance, depending on your currency and region.</li>
                <li>Prices are shown before you confirm. Where you pay in a different currency, conversion uses the rate shown at checkout.</li>
                <li>You are responsible for any taxes, fees or charges that apply to your purchase under local law.</li>
                <li>We use automated systems to detect and prevent fraud, and we may decline or hold transactions that appear high-risk.</li>
            </ul>
        </section>

        {{-- ── 5 ─────────────────────────────────────────────────── --}}
        <section id="orders" class="mt-12 scroll-mt-24">
            <h2 class="text-xl font-bold tracking-tight text-zinc-900 sm:text-2xl">5. Orders, delivery and refunds</h2>
            <p class="mt-3 text-sm leading-relaxed text-zinc-600 sm:text-base">
                Most products are digital and delivered instantly to your dashboard and email once payment is confirmed. Because
                of this, many products are final sale. Please double-check your phone number, account number and delivery email
                before you pay.
            </p>

            <div class="my-5 flex items-start gap-3 rounded-[12px] bg-zinc-50 p-4 ring-1 ring-zinc-100">
                <svg class="mt-0.5 h-5 w-5 shrink-0 text-red-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m9-.75a9 9 0 11-18 0 9 9 0 0118 0zm-9 3.75h.008v.008H12v-.008z"/></svg>
                <div>
                    <p class="text-sm font-bold text-red-600">Refunds follow our Refund Policy</p>
                    <p class="mt-1 text-sm leading-relaxed text-zinc-600">Eligibility, final-sale rules and approved-refund handling are set out in full in our <a href="{{ route('shop.refund-policy') }}" wire:navigate class="font-medium text-blue-600 hover:underline">Refund and Cancellation Policy</a>. Approved refunds are issued as wallet credit by default.</p>
                </div>
            </div>
        </section>

        {{-- ── 6 ─────────────────────────────────────────────────── --}}
        <section id="acceptable-use" class="mt-12 scroll-mt-24">
            <h2 class="text-xl font-bold tracking-tight text-zinc-900 sm:text-2xl">6. Acceptable use</h2>
            <p class="mt-3 text-sm leading-relaxed text-zinc-600 sm:text-base">When using {{ $siteName }}, you agree not to:</p>
            <ul class="mt-4 list-disc space-y-2 pl-5 text-sm leading-relaxed text-zinc-600 marker:text-blue-500 sm:text-base">
                <li>Use the platform for fraud, money laundering, terrorist financing or any unlawful purpose.</li>
                <li>Use stolen, unauthorised or another person's payment details.</li>
                <li>Provide false information or impersonate any person or entity.</li>
                <li>Resell, abuse promotions, or exploit pricing or system errors.</li>
                <li>Attempt to access accounts that are not yours, or disrupt, scrape or reverse-engineer the platform.</li>
                <li>Infringe our or any third party's intellectual property or other rights.</li>
            </ul>

            <div class="my-5 flex items-start gap-3 rounded-[12px] bg-zinc-50 p-4 ring-1 ring-zinc-100">
                <svg class="mt-0.5 h-5 w-5 shrink-0 text-red-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m9-.75a9 9 0 11-18 0 9 9 0 0118 0zm-9 3.75h.008v.008H12v-.008z"/></svg>
                <div>
                    <p class="text-sm font-bold text-red-600">Breaching these rules can cost you access</p>
                    <p class="mt-1 text-sm leading-relaxed text-zinc-600">We may suspend or close accounts, hold or reverse funds, and report activity to the authorities where we reasonably suspect misuse or a breach of these Terms.</p>
                </div>
            </div>
        </section>

        {{-- ── 7 ─────────────────────────────────────────────────── --}}
        <section id="ip" class="mt-12 scroll-mt-24">
            <h2 class="text-xl font-bold tracking-tight text-zinc-900 sm:text-2xl">7. Intellectual property</h2>
            <p class="mt-3 text-sm leading-relaxed text-zinc-600 sm:text-base">
                The {{ $siteName }} name, logo, software, design and content are owned by us or our licensors and are protected by
                law. You may use the platform only as permitted by these Terms. Brand names and logos of the products we sell
                belong to their respective owners and are used to identify those products.
            </p>
        </section>

        {{-- ── 8 ─────────────────────────────────────────────────── --}}
        <section id="third-party" class="mt-12 scroll-mt-24">
            <h2 class="text-xl font-bold tracking-tight text-zinc-900 sm:text-2xl">8. Third-party services</h2>
            <p class="mt-3 text-sm leading-relaxed text-zinc-600 sm:text-base">
                Some products are provided or fulfilled by third parties, such as airlines, hotels, telecom networks and
                payment providers. Their products may be subject to their own terms and conditions. We are not responsible for
                the acts, omissions or content of third parties, but we will help you resolve issues wherever we can.
            </p>
        </section>

        {{-- ── 9 ─────────────────────────────────────────────────── --}}
        <section id="disclaimers" class="mt-12 scroll-mt-24">
            <h2 class="text-xl font-bold tracking-tight text-zinc-900 sm:text-2xl">9. Disclaimers and limitation of liability</h2>
            <p class="mt-3 text-sm leading-relaxed text-zinc-600 sm:text-base">
                The platform is provided on an "as is" and "as available" basis. We work hard to keep it reliable and secure,
                but we do not guarantee that it will always be uninterrupted or error-free.
            </p>

            <div class="my-5 flex items-start gap-3 rounded-[12px] bg-zinc-50 p-4 ring-1 ring-zinc-100">
                <svg class="mt-0.5 h-5 w-5 shrink-0 text-red-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m9-.75a9 9 0 11-18 0 9 9 0 0118 0zm-9 3.75h.008v.008H12v-.008z"/></svg>
                <div>
                    <p class="text-sm font-bold text-red-600">Limitation of liability</p>
                    <p class="mt-1 text-sm leading-relaxed text-zinc-600">To the maximum extent permitted by law, {{ $siteName }} is not liable for indirect or consequential losses. Our total liability for any claim relating to a transaction is limited to the amount you paid for that transaction. Nothing in these Terms limits liability that cannot be excluded by law.</p>
                </div>
            </div>

            <p class="text-sm leading-relaxed text-zinc-600 sm:text-base">
                You agree to indemnify {{ $siteName }} against losses arising from your breach of these Terms or your misuse of
                the platform.
            </p>
        </section>

        {{-- ── 10 ────────────────────────────────────────────────── --}}
        <section id="termination" class="mt-12 scroll-mt-24">
            <h2 class="text-xl font-bold tracking-tight text-zinc-900 sm:text-2xl">10. Suspension and termination</h2>
            <p class="mt-3 text-sm leading-relaxed text-zinc-600 sm:text-base">
                We may suspend or close your account, or limit your access, if you breach these Terms, if required by law, or to
                protect the platform and our customers. You may close your account at any time. Some records must be retained
                after closure to meet legal and regulatory obligations, as explained in our Privacy Policy.
            </p>
        </section>

        {{-- ── 11 ────────────────────────────────────────────────── --}}
        <section id="law" class="mt-12 scroll-mt-24">
            <h2 class="text-xl font-bold tracking-tight text-zinc-900 sm:text-2xl">11. Governing law and disputes</h2>
            <p class="mt-3 text-sm leading-relaxed text-zinc-600 sm:text-base">
                These Terms are governed by the laws that apply in our principal place of business, without affecting any
                mandatory consumer-protection rights you have in your home country. We encourage you to contact us first so we
                can try to resolve any issue quickly and fairly before taking any formal action.
            </p>
        </section>

        {{-- ── 12 ────────────────────────────────────────────────── --}}
        <section id="changes" class="mt-12 scroll-mt-24">
            <h2 class="text-xl font-bold tracking-tight text-zinc-900 sm:text-2xl">12. Changes and contact</h2>
            <p class="mt-3 text-sm leading-relaxed text-zinc-600 sm:text-base">
                We may update these Terms from time to time. The date at the top of this page shows the latest version, and your
                continued use of the platform means you accept the updated Terms. If you have any questions, email us at
                <a href="mailto:{{ $supportEmail }}" class="font-medium text-blue-600 hover:underline">{{ $supportEmail }}</a>
                or <a href="{{ route('shop.contact') }}" wire:navigate class="font-medium text-blue-600 hover:underline">contact our team</a>.
            </p>
        </section>
        <x-legal-footer />
    </div>

</x-layouts.app.header>

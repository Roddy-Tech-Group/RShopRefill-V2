@php
    // Privacy Policy — customer-facing legal page. Dark-mode safe: backgrounds use
    // remapped classes (bg-white/bg-zinc-50/bg-blue-50 -> navy in dark) while
    // coloured accents (text-red-600 / text-blue-700) read in both themes.
    // Privacy / DPO contact is a formal legal channel - kept on the info@
    // inbox for the record, not the support@ help queue.
    $supportEmail = 'info@rshoprefill.com';
    $lastUpdated = 'May 23, 2026';
@endphp

<x-layouts.app.header :title="'Privacy Policy | '.$siteName">

    {{-- ── Hero ──────────────────────────────────────────────── --}}
    <section class="border-b border-zinc-100 bg-blue-50">
        <div class="mx-auto w-full max-w-[1140px] px-4 py-14 text-center sm:px-6 sm:py-20">
            <span class="inline-flex items-center gap-2 rounded-[5px] bg-blue-100 px-3 py-1.5 text-[11px] font-bold uppercase tracking-[0.14em] text-blue-700">Privacy</span>
            <h1 class="mt-5 text-3xl font-bold tracking-tight text-zinc-900 sm:text-4xl">Privacy Policy</h1>
            <p class="mt-3 text-sm text-zinc-600">Last updated: {{ $lastUpdated }}</p>
        </div>
    </section>

    <div class="mx-auto w-full max-w-[1140px] px-4 py-12 sm:px-6 sm:py-16">

        {{-- Intro --}}
        <p class="text-sm leading-relaxed text-zinc-600 sm:text-base">
            Your privacy matters to us. This policy explains, in plain language, what information {{ $siteName }} collects, how we
            use it, who we share it with, and the rights you have over your data. We serve customers across Africa and
            internationally, and we work to comply with applicable data-protection laws, including the EU GDPR, the California
            CCPA, the Nigeria Data Protection Act (NDPA) and the Kenya Data Protection Act. By using {{ $siteName }}, you agree to
            this policy.
        </p>

        {{-- On this page --}}
        <nav class="mt-7 rounded-[12px] bg-zinc-50 p-5 ring-1 ring-zinc-100" aria-label="On this page">
            <p class="text-xs font-semibold uppercase tracking-wider text-zinc-500">On this page</p>
            <ol class="mt-3 space-y-2 text-sm">
                <li><a href="#collect" class="font-medium text-blue-600 hover:underline">1. Information we collect</a></li>
                <li><a href="#use" class="font-medium text-blue-600 hover:underline">2. How we use your information</a></li>
                <li><a href="#sharing" class="font-medium text-blue-600 hover:underline">3. Data sharing with third parties</a></li>
                <li><a href="#transfers" class="font-medium text-blue-600 hover:underline">4. Cross-border data transfers</a></li>
                <li><a href="#rights" class="font-medium text-blue-600 hover:underline">5. Your rights and data retention</a></li>
                <li><a href="#dpo" class="font-medium text-blue-600 hover:underline">6. Contact our Data Protection Officer</a></li>
            </ol>
        </nav>

        {{-- ── 1. Information we collect ──────────────────────────── --}}
        <section id="collect" class="mt-12 scroll-mt-24">
            <h2 class="text-xl font-bold tracking-tight text-zinc-900 sm:text-2xl">1. Information we collect</h2>
            <p class="mt-3 text-sm leading-relaxed text-zinc-600 sm:text-base">
                We only collect what we need to run your account, deliver your purchases and keep the platform secure. We group
                it into four categories.
            </p>

            <div class="mt-5 space-y-4">
                <div class="rounded-[12px] bg-zinc-50 p-5 ring-1 ring-zinc-100">
                    <h3 class="text-base font-bold text-zinc-900">Account information</h3>
                    <ul class="mt-2 list-disc space-y-1.5 pl-5 text-sm leading-relaxed text-zinc-600 marker:text-blue-500">
                        <li>Your name, email address and phone number.</li>
                        <li>Your internal app wallet balance and wallet activity.</li>
                        <li>Account preferences and settings.</li>
                    </ul>
                </div>

                <div class="rounded-[12px] bg-zinc-50 p-5 ring-1 ring-zinc-100">
                    <h3 class="text-base font-bold text-zinc-900">Identity verification (KYC)</h3>
                    <ul class="mt-2 list-disc space-y-1.5 pl-5 text-sm leading-relaxed text-zinc-600 marker:text-blue-500">
                        <li>Government-issued identification, such as a national ID card.</li>
                        <li>Passport details, where required for flight bookings.</li>
                        <li>Where the law requires it for certain transactions, such as some African real estate purchases, identifiers like a BVN or NIN.</li>
                    </ul>
                </div>

                <div class="rounded-[12px] bg-zinc-50 p-5 ring-1 ring-zinc-100">
                    <h3 class="text-base font-bold text-zinc-900">Transaction and payment data</h3>
                    <ul class="mt-2 list-disc space-y-1.5 pl-5 text-sm leading-relaxed text-zinc-600 marker:text-blue-500">
                        <li>The payment methods you use, your transaction history and your billing address.</li>
                        <li>Records of purchases, refunds and wallet top-ups.</li>
                    </ul>
                    <div class="mt-4 flex items-start gap-3 rounded-[12px] bg-blue-50 p-4 ring-1 ring-zinc-100">
                        <svg class="mt-0.5 h-5 w-5 shrink-0 text-blue-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M16.5 10.5V6.75a4.5 4.5 0 10-9 0v3.75m-.75 11.25h10.5a2.25 2.25 0 002.25-2.25v-6.75a2.25 2.25 0 00-2.25-2.25H6.75a2.25 2.25 0 00-2.25 2.25v6.75a2.25 2.25 0 002.25 2.25z"/></svg>
                        <div>
                            <p class="text-sm font-bold text-blue-700">We do not store full card numbers</p>
                            <p class="mt-1 text-sm leading-relaxed text-zinc-600">Your complete credit or debit card number is handled directly by regulated payment gateways. We never keep your full card number on our servers.</p>
                        </div>
                    </div>
                </div>

                <div class="rounded-[12px] bg-zinc-50 p-5 ring-1 ring-zinc-100">
                    <h3 class="text-base font-bold text-zinc-900">Technical and location data</h3>
                    <ul class="mt-2 list-disc space-y-1.5 pl-5 text-sm leading-relaxed text-zinc-600 marker:text-blue-500">
                        <li>Your IP address and device identifiers.</li>
                        <li>Approximate geolocation, used to help prevent fraudulent international transactions.</li>
                    </ul>
                </div>
            </div>
        </section>

        {{-- ── 2. How we use it ──────────────────────────────────── --}}
        <section id="use" class="mt-12 scroll-mt-24">
            <h2 class="text-xl font-bold tracking-tight text-zinc-900 sm:text-2xl">2. How we use your information</h2>
            <ul class="mt-4 list-disc space-y-2 pl-5 text-sm leading-relaxed text-zinc-600 marker:text-blue-500 sm:text-base">
                <li>To process your transactions, deliver digital goods such as eSIMs, gift cards, top-ups and bill payments, and manage your internal app wallet.</li>
                <li>To detect, prevent and reduce cross-border fraud using automated security systems.</li>
                <li>To comply with local financial rules and anti-money-laundering (AML) regulations across Africa and internationally.</li>
                <li>To provide customer support and respond to your requests.</li>
                <li>To keep the platform secure, reliable and improving over time.</li>
            </ul>

            <div class="my-5 flex items-start gap-3 rounded-[12px] bg-zinc-50 p-4 ring-1 ring-zinc-100">
                <svg class="mt-0.5 h-5 w-5 shrink-0 text-blue-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75L11.25 15 15 9.75M21 12c0 1.268-.63 2.39-1.593 3.068a3.745 3.745 0 01-1.043 3.296 3.745 3.745 0 01-3.296 1.043A3.745 3.745 0 0112 21c-1.268 0-2.39-.63-3.068-1.593a3.746 3.746 0 01-3.296-1.043 3.745 3.745 0 01-1.043-3.296A3.745 3.745 0 013 12c0-1.268.63-2.39 1.593-3.068a3.745 3.745 0 011.043-3.296 3.746 3.746 0 013.296-1.043A3.746 3.746 0 0112 3c1.268 0 2.39.63 3.068 1.593a3.746 3.746 0 013.296 1.043 3.746 3.746 0 011.043 3.296A3.745 3.745 0 0121 12z"/></svg>
                <div>
                    <p class="text-sm font-bold text-blue-700">Fraud prevention keeps your money safe</p>
                    <p class="mt-1 text-sm leading-relaxed text-zinc-600">Some of your data, such as device and location signals, is used by automated systems to spot and block suspicious activity before it can affect your account or wallet.</p>
                </div>
            </div>
        </section>

        {{-- ── 3. Data sharing ───────────────────────────────────── --}}
        <section id="sharing" class="mt-12 scroll-mt-24">
            <h2 class="text-xl font-bold tracking-tight text-zinc-900 sm:text-2xl">3. Data sharing with third parties</h2>
            <p class="mt-3 text-sm leading-relaxed text-zinc-600 sm:text-base">
                To deliver what you buy, we must share specific, limited information with trusted service providers. We share
                only what is needed to complete your request, and we require these partners to protect your data.
            </p>
            <ul class="mt-4 list-disc space-y-2 pl-5 text-sm leading-relaxed text-zinc-600 marker:text-blue-500 sm:text-base">
                <li><span class="font-semibold text-zinc-900">Airline and hotel partners</span>, to confirm and manage your flight and stay bookings.</li>
                <li><span class="font-semibold text-zinc-900">Telecom and gift-card suppliers</span>, to deliver eSIMs, mobile top-ups, data and gift card codes.</li>
                <li><span class="font-semibold text-zinc-900">Regulated third-party payment gateways</span>, to process your payments securely.</li>
                <li><span class="font-semibold text-zinc-900">Identity-verification and fraud-prevention providers</span>, to confirm who you are and keep accounts safe.</li>
                <li><span class="font-semibold text-zinc-900">Cloud and technology providers</span>, who host and run our platform under strict agreements.</li>
                <li><span class="font-semibold text-zinc-900">Regulators and authorities</span>, when we are legally required to share information.</li>
            </ul>

            <div class="my-5 rounded-[12px] bg-blue-600 p-6">
                <span class="flex h-12 w-12 items-center justify-center rounded-[12px] bg-white/15">
                    <svg class="h-6 w-6 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75L11.25 15 15 9.75M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                </span>
                <p class="mt-4 text-lg font-bold text-white">We never sell your personal data to third-party advertisers.</p>
                <p class="mt-2 text-sm leading-relaxed text-blue-100">Your information is shared only to deliver your purchases, verify your identity, prevent fraud, and meet legal obligations. It is never sold for advertising.</p>
            </div>
        </section>

        {{-- ── 4. Cross-border transfers ─────────────────────────── --}}
        <section id="transfers" class="mt-12 scroll-mt-24">
            <h2 class="text-xl font-bold tracking-tight text-zinc-900 sm:text-2xl">4. Cross-border data transfers</h2>
            <p class="mt-3 text-sm leading-relaxed text-zinc-600 sm:text-base">
                Because we operate internationally, your data may be transferred to, stored and processed on secure cloud
                servers located outside your home country, for example in the United States or the European Union. Wherever your
                data is processed, we protect it with enterprise-grade security and put legal safeguards in place, such as
                standard contractual clauses, to keep your information protected to a consistent standard.
            </p>
        </section>

        {{-- ── 5. Rights & retention ─────────────────────────────── --}}
        <section id="rights" class="mt-12 scroll-mt-24">
            <h2 class="text-xl font-bold tracking-tight text-zinc-900 sm:text-2xl">5. Your rights and data retention</h2>
            <p class="mt-3 text-sm leading-relaxed text-zinc-600 sm:text-base">
                Depending on where you live, you have rights over your personal data. We aim to honour these rights for all our
                customers.
            </p>
            <ul class="mt-4 list-disc space-y-2 pl-5 text-sm leading-relaxed text-zinc-600 marker:text-blue-500 sm:text-base">
                <li><span class="font-semibold text-zinc-900">Access</span> the personal data we hold about you.</li>
                <li><span class="font-semibold text-zinc-900">Correct</span> information that is wrong or out of date.</li>
                <li><span class="font-semibold text-zinc-900">Delete</span> your account and personal data, subject to the exception below.</li>
                <li><span class="font-semibold text-zinc-900">Object to or restrict</span> certain uses of your data.</li>
                <li><span class="font-semibold text-zinc-900">Withdraw consent</span> where we rely on it.</li>
            </ul>

            <div class="my-5 flex items-start gap-3 rounded-[12px] bg-zinc-50 p-4 ring-1 ring-zinc-100">
                <svg class="mt-0.5 h-5 w-5 shrink-0 text-red-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m9-.75a9 9 0 11-18 0 9 9 0 0118 0zm-9 3.75h.008v.008H12v-.008z"/></svg>
                <div>
                    <p class="text-sm font-bold text-red-600">Some records must be kept by law</p>
                    <p class="mt-1 text-sm leading-relaxed text-zinc-600">When you ask us to delete your data, we must still keep certain transaction and identity-verification records for a period set by financial and anti-money-laundering laws. We delete or anonymise this data once that legal period has passed.</p>
                </div>
            </div>

            <p class="text-sm leading-relaxed text-zinc-600 sm:text-base">
                We keep your data only for as long as we need it for the purposes in this policy, or for as long as the law
                requires, whichever is longer.
            </p>
        </section>

        {{-- ── 6. DPO ────────────────────────────────────────────── --}}
        <section id="dpo" class="mt-12 scroll-mt-24">
            <h2 class="text-xl font-bold tracking-tight text-zinc-900 sm:text-2xl">6. Contact our Data Protection Officer</h2>
            <p class="mt-3 text-sm leading-relaxed text-zinc-600 sm:text-base">
                For any privacy question, or to request access to or deletion of your data, contact our Data Protection Officer
                through any of these channels:
            </p>
            <ul class="mt-4 list-disc space-y-2 pl-5 text-sm leading-relaxed text-zinc-600 marker:text-blue-500 sm:text-base">
                <li><span class="font-semibold text-zinc-900">Live Chat</span>, while you are logged in to the app, for a quick response.</li>
                <li><span class="font-semibold text-zinc-900">WhatsApp</span>, providing the email or phone number registered to your account so we can verify you.</li>
                <li><span class="font-semibold text-zinc-900">Email</span>, at <a href="mailto:{{ $supportEmail }}" class="font-medium text-blue-600 hover:underline">{{ $supportEmail }}</a>, which is best for data-deletion requests so there is a clear written record.</li>
            </ul>

            <div class="mt-8 border-t border-zinc-100 pt-8">
                <p class="text-sm leading-relaxed text-zinc-600">
                    We may update this policy from time to time. The date at the top of this page shows the latest version. If
                    you have any questions, please <a href="{{ route('shop.contact') }}" wire:navigate class="font-medium text-blue-600 hover:underline">contact our team</a>.
                </p>
            </div>
        </section>
        <x-legal-footer />
    </div>

</x-layouts.app.header>

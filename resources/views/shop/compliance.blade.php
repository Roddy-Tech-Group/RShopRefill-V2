@php
    // Compliance & Regulatory Framework — public-facing, regulatory-grade page.
    // Dark-mode safe: backgrounds use remapped classes (bg-white/bg-zinc-50/bg-blue-50
    // -> navy in dark) while coloured accents (text-red-600 / text-blue-700) read in
    // both themes.
    $complianceEmail = 'info@rshoprefill.com';
    $lastUpdated = 'May 23, 2026';
@endphp

<x-layouts.app.header :title="'Compliance and Regulatory Framework | '.$siteName">

    {{-- ── Hero ──────────────────────────────────────────────── --}}
    <section class="border-b border-zinc-100 bg-blue-50">
        <div class="mx-auto w-full max-w-[1140px] px-4 py-14 text-center sm:px-6 sm:py-20">
            <span class="inline-flex items-center gap-2 rounded-[5px] bg-blue-100 px-3 py-1.5 text-[11px] font-bold uppercase tracking-[0.14em] text-blue-700">Compliance</span>
            <h1 class="mt-5 text-3xl font-bold tracking-tight text-zinc-900 sm:text-4xl">Compliance and Regulatory Framework</h1>
            <p class="mt-3 text-sm text-zinc-600">Last updated: {{ $lastUpdated }}</p>
        </div>
    </section>

    <div class="mx-auto w-full max-w-[1140px] px-4 py-12 sm:px-6 sm:py-16">

        {{-- Intro --}}
        <p class="text-sm leading-relaxed text-zinc-600 sm:text-base">
            {{ $siteName }} operates a multi-vertical digital commerce and payments platform serving customers across Africa,
            Europe and North America. This framework sets out the governance, controls and standards we apply to meet our
            regulatory obligations, protect our customers and partners, and safeguard the integrity of the financial system.
            Our program is risk-based, independently reviewed, and continuously updated to reflect evolving law and
            international best practice.
        </p>

        {{-- On this page --}}
        <nav class="mt-7 rounded-[12px] bg-zinc-50 p-5 ring-1 ring-zinc-100" aria-label="On this page">
            <p class="text-xs font-semibold uppercase tracking-wider text-zinc-500">On this page</p>
            <ol class="mt-3 space-y-2 text-sm">
                <li><a href="#statement" class="font-medium text-blue-600 hover:underline">1. Compliance statement and commitment</a></li>
                <li><a href="#aml" class="font-medium text-blue-600 hover:underline">2. AML and counter-terrorist financing</a></li>
                <li><a href="#kyc" class="font-medium text-blue-600 hover:underline">3. KYC and identity verification stages</a></li>
                <li><a href="#sanctions" class="font-medium text-blue-600 hover:underline">4. Sanctions and geographic restrictions</a></li>
                <li><a href="#fraud" class="font-medium text-blue-600 hover:underline">5. Fraud prevention and payment integrity</a></li>
                <li><a href="#real-estate" class="font-medium text-blue-600 hover:underline">6. Digital real estate and asset compliance</a></li>
                <li><a href="#contact" class="font-medium text-blue-600 hover:underline">7. Compliance contact for regulators and partners</a></li>
            </ol>
        </nav>

        {{-- ── 1. Statement ──────────────────────────────────────── --}}
        <section id="statement" class="mt-12 scroll-mt-24">
            <h2 class="text-xl font-bold tracking-tight text-zinc-900 sm:text-2xl">1. Compliance statement and commitment</h2>
            <p class="mt-3 text-sm leading-relaxed text-zinc-600 sm:text-base">
                {{ $siteName }} maintains a zero-tolerance policy toward financial crime in all its forms, including money
                laundering, terrorist financing, fraud, bribery, corruption and sanctions evasion. We are committed to
                conducting business lawfully and ethically, and to upholding the highest standards of regulatory integrity in
                every jurisdiction we serve across Africa and globally.
            </p>

            <div class="my-5 rounded-[12px] bg-blue-600 p-6">
                <span class="flex h-12 w-12 items-center justify-center rounded-[12px] bg-white/15">
                    <svg class="h-6 w-6 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75L11.25 15 15 9.75M21 12c0 1.268-.63 2.39-1.593 3.068a3.745 3.745 0 01-1.043 3.296 3.745 3.745 0 01-3.296 1.043A3.745 3.745 0 0112 21c-1.268 0-2.39-.63-3.068-1.593a3.746 3.746 0 01-3.296-1.043 3.745 3.745 0 01-1.043-3.296A3.745 3.745 0 013 12c0-1.268.63-2.39 1.593-3.068a3.745 3.745 0 011.043-3.296 3.746 3.746 0 013.296-1.043A3.746 3.746 0 0112 3c1.268 0 2.39.63 3.068 1.593a3.746 3.746 0 013.296 1.043 3.746 3.746 0 011.043 3.296A3.745 3.745 0 0121 12z"/></svg>
                </span>
                <p class="mt-4 text-lg font-bold text-white">We have zero tolerance for money laundering, terrorist financing and financial crime.</p>
                <p class="mt-2 text-sm leading-relaxed text-blue-100">Compliance is embedded in our governance, our technology and our day-to-day operations, with senior accountability and regular independent review.</p>
            </div>
        </section>

        {{-- ── 2. AML & CTF ──────────────────────────────────────── --}}
        <section id="aml" class="mt-12 scroll-mt-24">
            <h2 class="text-xl font-bold tracking-tight text-zinc-900 sm:text-2xl">2. Anti-money laundering (AML) and counter-terrorist financing (CTF)</h2>
            <p class="mt-3 text-sm leading-relaxed text-zinc-600 sm:text-base">
                We operate a risk-based AML and CTF program aligned with the recommendations of the Financial Action Task Force
                (FATF) and with the laws of the jurisdictions in which we operate. Our framework includes the following
                controls:
            </p>
            <ul class="mt-4 list-disc space-y-2 pl-5 text-sm leading-relaxed text-zinc-600 marker:text-blue-500 sm:text-base">
                <li><span class="font-semibold text-zinc-900">Automated transaction monitoring</span> that screens activity in real time and flags patterns inconsistent with a customer's profile.</li>
                <li><span class="font-semibold text-zinc-900">Anomaly detection</span> for sudden high-volume purchases and other unusual behaviour.</li>
                <li><span class="font-semibold text-zinc-900">Velocity controls</span> on gift card purchases and cross-border utility top-ups, designed to detect structuring and smurfing.</li>
                <li><span class="font-semibold text-zinc-900">Escalation and reporting</span> of suspicious activity to the relevant Financial Intelligence Units, in line with statutory obligations.</li>
                <li><span class="font-semibold text-zinc-900">Record-keeping</span> of transactions and verification data for the periods required by law.</li>
            </ul>

            <div class="my-5 flex items-start gap-3 rounded-[12px] bg-zinc-50 p-4 ring-1 ring-zinc-100">
                <svg class="mt-0.5 h-5 w-5 shrink-0 text-blue-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M3 13.125C3 12.504 3.504 12 4.125 12h2.25c.621 0 1.125.504 1.125 1.125v6.75C7.5 20.496 6.996 21 6.375 21h-2.25A1.125 1.125 0 013 19.875v-6.75zM9.75 8.625c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125v11.25c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 01-1.125-1.125V8.625zM16.5 4.125c0-.621.504-1.125 1.125-1.125h2.25C20.496 3 21 3.504 21 4.125v15.75c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 01-1.125-1.125V4.125z"/></svg>
                <div>
                    <p class="text-sm font-bold text-blue-700">Designed to detect structuring and smurfing</p>
                    <p class="mt-1 text-sm leading-relaxed text-zinc-600">By tracking purchase velocity and cross-border top-up patterns across accounts and devices, our systems are built to identify attempts to break large transactions into smaller ones to avoid detection.</p>
                </div>
            </div>
        </section>

        {{-- ── 3. KYC tiers ──────────────────────────────────────── --}}
        <section id="kyc" class="mt-12 scroll-mt-24">
            <h2 class="text-xl font-bold tracking-tight text-zinc-900 sm:text-2xl">3. Know Your Customer (KYC) and identity verification stages</h2>
            <p class="mt-3 text-sm leading-relaxed text-zinc-600 sm:text-base">
                We apply a tiered, risk-based approach to identity verification. Verification requirements increase
                progressively with the value and risk of the activity, so low-value retail stays simple while higher-value and
                higher-risk activity carries stronger checks.
            </p>

            <div class="mt-5 overflow-x-auto rounded-[12px] ring-1 ring-zinc-100">
                <table class="w-full text-left text-sm">
                    <thead class="bg-zinc-50">
                        <tr>
                            <th class="px-4 py-3 font-semibold text-zinc-900">Tier</th>
                            <th class="px-4 py-3 font-semibold text-zinc-900">Applies to</th>
                            <th class="px-4 py-3 font-semibold text-zinc-900">Requirements</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-zinc-100">
                        <tr>
                            <td class="px-4 py-3 font-medium text-zinc-900">Tier 1<br><span class="text-xs font-normal text-zinc-500">Low-value retail</span></td>
                            <td class="px-4 py-3 text-zinc-600">Mobile top-ups, eSIMs, small purchases.</td>
                            <td class="px-4 py-3 text-zinc-600">Basic account registration: a verified email address and a verified mobile number.</td>
                        </tr>
                        <tr>
                            <td class="px-4 py-3 font-medium text-zinc-900">Tier 2<br><span class="text-xs font-normal text-zinc-500">Medium-value</span></td>
                            <td class="px-4 py-3 text-zinc-600">Flights, hotel stays, large gift cards.</td>
                            <td class="px-4 py-3 text-zinc-600">Full legal name, validation of a government-issued ID, and date of birth.</td>
                        </tr>
                        <tr>
                            <td class="px-4 py-3 font-medium text-zinc-900">Tier 3<br><span class="text-xs font-normal text-zinc-500">High-value</span></td>
                            <td class="px-4 py-3 text-zinc-600">Digital real estate, bulk corporate B2B.</td>
                            <td class="px-4 py-3 text-zinc-600">Advanced KYC and KYB, including official government database checks (for example BVN or NIN in Nigeria, IPRS in Kenya, and SSN or EIN in the United States where applicable) and address verification.</td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <p class="mt-5 text-sm leading-relaxed text-zinc-600 sm:text-base">
                Where a customer or transaction presents a higher risk, we apply Enhanced Due Diligence, which may include
                additional documentation, source-of-funds checks and senior management approval before activity is permitted.
            </p>
        </section>

        {{-- ── 4. Sanctions ──────────────────────────────────────── --}}
        <section id="sanctions" class="mt-12 scroll-mt-24">
            <h2 class="text-xl font-bold tracking-tight text-zinc-900 sm:text-2xl">4. Sanctions and geographic restrictions</h2>
            <p class="mt-3 text-sm leading-relaxed text-zinc-600 sm:text-base">
                We run automated daily screenings of customers and counterparties against global sanctions lists, including
                those maintained by the US Office of Foreign Assets Control (OFAC), the United Nations, the European Union and
                the United Kingdom HM Treasury. Screening is performed at onboarding and on an ongoing basis, and we also screen
                for Politically Exposed Persons (PEPs).
            </p>

            <div class="my-5 flex items-start gap-3 rounded-[12px] bg-zinc-50 p-4 ring-1 ring-zinc-100">
                <svg class="mt-0.5 h-5 w-5 shrink-0 text-red-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m9-.75a9 9 0 11-18 0 9 9 0 0118 0zm-9 3.75h.008v.008H12v-.008z"/></svg>
                <div>
                    <p class="text-sm font-bold text-red-600">No onboarding or payments involving sanctioned territories</p>
                    <p class="mt-1 text-sm leading-relaxed text-zinc-600">We do not onboard users, nor do we facilitate payments to or from, comprehensively sanctioned countries or territories. Accounts or transactions that match a sanctions designation are blocked and reviewed.</p>
                </div>
            </div>
        </section>

        {{-- ── 5. Fraud ──────────────────────────────────────────── --}}
        <section id="fraud" class="mt-12 scroll-mt-24">
            <h2 class="text-xl font-bold tracking-tight text-zinc-900 sm:text-2xl">5. Fraud prevention and payment integrity</h2>
            <p class="mt-3 text-sm leading-relaxed text-zinc-600 sm:text-base">
                We protect our international payment rails with machine-learning fraud-prevention protocols that score activity
                in real time and challenge or block high-risk transactions. Our controls include:
            </p>
            <ul class="mt-4 list-disc space-y-2 pl-5 text-sm leading-relaxed text-zinc-600 marker:text-blue-500 sm:text-base">
                <li><span class="font-semibold text-zinc-900">Device fingerprinting</span> to recognise trusted devices and detect suspicious ones.</li>
                <li><span class="font-semibold text-zinc-900">Card-velocity checks</span> to identify rapid or abnormal payment attempts.</li>
                <li><span class="font-semibold text-zinc-900">3D-Secure enforcement</span> on card payments to confirm cardholder identity.</li>
                <li><span class="font-semibold text-zinc-900">Geolocation and IP risk analysis</span> to detect anomalous cross-border activity.</li>
            </ul>
            <p class="mt-4 text-sm leading-relaxed text-zinc-600 sm:text-base">
                Card payments are processed through regulated, secure gateways. We do not store complete card numbers on our
                servers.
            </p>
        </section>

        {{-- ── 6. Digital real estate ────────────────────────────── --}}
        <section id="real-estate" class="mt-12 scroll-mt-24">
            <h2 class="text-xl font-bold tracking-tight text-zinc-900 sm:text-2xl">6. Digital real estate and asset compliance</h2>
            <p class="mt-3 text-sm leading-relaxed text-zinc-600 sm:text-base">
                Our digital real estate offerings strictly adhere to the property ownership laws and the fractionalization and
                asset-compliance requirements of the jurisdiction in which each physical property resides. Where local law
                requires it, allocations, title and fractional contracts are structured and recorded through licensed local
                entities and qualified professionals.
            </p>
            <p class="mt-3 text-sm leading-relaxed text-zinc-600 sm:text-base">
                We provide clear disclosures to participants and honour any cooling-off periods or investor-protection rules
                mandated in the relevant region. We do not offer these products where doing so would conflict with local law.
            </p>
        </section>

        {{-- ── 7. Compliance contact ─────────────────────────────── --}}
        <section id="contact" class="mt-12 scroll-mt-24">
            <h2 class="text-xl font-bold tracking-tight text-zinc-900 sm:text-2xl">7. Compliance contact for regulators and partners</h2>
            <p class="mt-3 text-sm leading-relaxed text-zinc-600 sm:text-base">
                Banking partners, payment providers, law enforcement, regulators and corporate compliance teams may contact our
                compliance department directly for due-diligence questionnaires, audit requests, regulatory enquiries and
                lawful information requests.
            </p>

            <div class="mt-5 rounded-[12px] bg-zinc-50 p-6 ring-1 ring-zinc-100">
                <p class="text-xs font-semibold uppercase tracking-wider text-zinc-500">Compliance department</p>
                <a href="mailto:{{ $complianceEmail }}" class="mt-2 inline-flex items-center gap-2 text-lg font-bold text-blue-600 hover:underline">
                    <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M21.75 6.75v10.5a2.25 2.25 0 01-2.25 2.25h-15a2.25 2.25 0 01-2.25-2.25V6.75m19.5 0A2.25 2.25 0 0019.5 4.5h-15a2.25 2.25 0 00-2.25 2.25m19.5 0v.243a2.25 2.25 0 01-1.07 1.916l-7.5 4.615a2.25 2.25 0 01-2.36 0L3.32 8.91a2.25 2.25 0 01-1.07-1.916V6.75"/></svg>
                    {{ $complianceEmail }}
                </a>
                <p class="mt-3 text-sm leading-relaxed text-zinc-600">We aim to acknowledge institutional and regulatory enquiries promptly and to cooperate fully with all lawful requests.</p>
            </div>

            <div class="mt-8 border-t border-zinc-100 pt-8">
                <p class="text-sm leading-relaxed text-zinc-600">
                    This framework is reviewed regularly and may be updated to reflect changes in law, regulation and best
                    practice. The date at the top of this page shows the latest version.
                </p>
            </div>
        </section>
        <x-legal-footer />
    </div>

</x-layouts.app.header>

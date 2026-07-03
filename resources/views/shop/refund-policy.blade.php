@php
    // Refund & Cancellation Policy — customer-facing legal page. Dark-mode safe:
    // backgrounds use remapped classes (bg-white/bg-zinc-50/bg-blue-50 -> navy in
    // dark) while coloured accents (text-red-600 / text-blue-700) read in both modes.
    $supportEmail = 'support@rshoprefill.com';
    // The written-record / disputes channel intentionally routes to the
    // info@ inbox, which keeps the formal audit trail for high-value cases.
    $disputesEmail = 'info@rshoprefill.com';
    $lastUpdated = 'May 23, 2026';
@endphp

<x-layouts.app.header :title="'Refund and Cancellation Policy | '.$siteName">

    {{-- ── Hero ──────────────────────────────────────────────── --}}
    <section class="border-b border-zinc-100 bg-blue-50">
        <div class="mx-auto w-full max-w-[1140px] px-4 py-14 text-center sm:px-6 sm:py-20">
            <span class="inline-flex items-center gap-2 rounded-[5px] bg-blue-100 px-3 py-1.5 text-[11px] font-bold uppercase tracking-[0.14em] text-blue-700">Policy</span>
            <h1 class="mt-5 text-3xl font-bold tracking-tight text-zinc-900 sm:text-4xl">Refund and Cancellation Policy</h1>
            <p class="mt-3 text-sm text-zinc-600">Last updated: {{ $lastUpdated }}</p>
        </div>
    </section>

    <div class="mx-auto w-full max-w-[1140px] px-4 py-12 sm:px-6 sm:py-16">

        {{-- Intro --}}
        <p class="text-sm leading-relaxed text-zinc-600 sm:text-base">
            This policy explains how refunds and cancellations work across every service on {{ $siteName }}. We sell digital
            products that are delivered instantly, so please read this page before you buy. By making a purchase you agree
            to the terms below. This policy is part of our Terms of Service.
        </p>

        {{-- Delivery: digital products ship free and arrive instantly. --}}
        <div class="mt-5 grid gap-3 sm:grid-cols-2">
            <div class="flex items-start gap-3 rounded-[12px] bg-emerald-50 p-4 ring-1 ring-emerald-100 dark:bg-emerald-500/10 dark:ring-emerald-500/20">
                <svg class="mt-0.5 h-5 w-5 shrink-0 text-emerald-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75l2.25 2.25 4.5-4.5M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                <div>
                    <p class="text-sm font-bold text-emerald-700">Free delivery, always</p>
                    <p class="mt-1 text-sm leading-relaxed text-zinc-600">Every product is digital, so there are no shipping or delivery fees. The price you see is the price you pay.</p>
                </div>
            </div>
            <div class="flex items-start gap-3 rounded-[12px] bg-blue-50 p-4 ring-1 ring-zinc-100">
                <svg class="mt-0.5 h-5 w-5 shrink-0 text-blue-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M3.75 13.5l10.5-11.25L12 10.5h8.25L9.75 21.75 12 13.5H3.75z"/></svg>
                <div>
                    <p class="text-sm font-bold text-blue-700">Instant delivery</p>
                    <p class="mt-1 text-sm leading-relaxed text-zinc-600">Your code, eSIM or top-up is delivered to your email and dashboard within seconds of your payment being confirmed.</p>
                </div>
            </div>
        </div>

        {{-- On this page --}}
        <nav class="mt-7 rounded-[12px] bg-zinc-50 p-5 ring-1 ring-zinc-100" aria-label="On this page">
            <p class="text-xs font-semibold uppercase tracking-wider text-zinc-500">On this page</p>
            <ol class="mt-3 space-y-2 text-sm">
                <li><a href="#wallet-first" class="font-medium text-blue-600 hover:underline">1. Global wallet-first refund policy</a></li>
                <li><a href="#verticals" class="font-medium text-blue-600 hover:underline">2. Policies by product type</a></li>
                <li><a href="#failed-protection" class="font-medium text-blue-600 hover:underline">3. Automated failed-transaction protection</a></li>
                <li><a href="#support" class="font-medium text-blue-600 hover:underline">4. How to get support</a></li>
            </ol>
        </nav>

        {{-- ── 1. Wallet-first ───────────────────────────────────── --}}
        <section id="wallet-first" class="mt-12 scroll-mt-24">
            <h2 class="text-xl font-bold tracking-tight text-zinc-900 sm:text-2xl">1. Global wallet-first refund policy</h2>
            <p class="mt-3 text-sm leading-relaxed text-zinc-600 sm:text-base">
                When a refund is approved, we issue it as store credit to your {{ $siteName }} App Wallet. This is our default
                method for every approved refund, on every service.
            </p>

            {{-- Info callout --}}
            <div class="my-5 flex items-start gap-3 rounded-[12px] bg-blue-50 p-4 ring-1 ring-zinc-100">
                <svg class="mt-0.5 h-5 w-5 shrink-0 text-blue-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M2.25 8.25h19.5M2.25 9h19.5m-16.5 5.25h6m-6 2.25h3M3.75 19.5h16.5a1.5 1.5 0 001.5-1.5V6a1.5 1.5 0 00-1.5-1.5H3.75A1.5 1.5 0 002.25 6v12a1.5 1.5 0 001.5 1.5z"/></svg>
                <div>
                    <p class="text-sm font-bold text-blue-700">Refunds are instant to your wallet</p>
                    <p class="mt-1 text-sm leading-relaxed text-zinc-600">Approved refunds land in your wallet right away. You can spend that credit immediately on any service we offer, including gift cards, airtime, data, bills, eSIMs and travel.</p>
                </div>
            </div>

            <h3 class="mt-6 text-base font-bold text-zinc-900">Refunds back to your original payment method</h3>
            <p class="mt-2 text-sm leading-relaxed text-zinc-600 sm:text-base">
                A reversal back to your original payment method, such as a credit card, mobile money account or bank transfer,
                is only granted in exceptional circumstances and is at the absolute discretion of management. Where a reversal
                is approved, any third-party processing fees charged by the payment provider may be deducted from the amount
                returned.
            </p>

            {{-- Warning callout --}}
            <div class="my-5 flex items-start gap-3 rounded-[12px] bg-zinc-50 p-4 ring-1 ring-zinc-100">
                <svg class="mt-0.5 h-5 w-5 shrink-0 text-red-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m9-.75a9 9 0 11-18 0 9 9 0 0118 0zm-9 3.75h.008v.008H12v-.008z"/></svg>
                <div>
                    <p class="text-sm font-bold text-red-600">Original-method reversals are not guaranteed</p>
                    <p class="mt-1 text-sm leading-relaxed text-zinc-600">We do not promise refunds back to your card, mobile money or bank. They are granted only at management's discretion and may carry processing fees. Wallet credit remains the standard, fastest option.</p>
                </div>
            </div>
        </section>

        {{-- ── 2. By product type ────────────────────────────────── --}}
        <section id="verticals" class="mt-12 scroll-mt-24">
            <h2 class="text-xl font-bold tracking-tight text-zinc-900 sm:text-2xl">2. Policies by product type</h2>
            <p class="mt-3 text-sm leading-relaxed text-zinc-600 sm:text-base">
                Different products carry different rules. Use this summary, then read the details for the product you bought.
            </p>

            {{-- Summary table --}}
            <div class="mt-5 overflow-x-auto rounded-[12px] ring-1 ring-zinc-100">
                <table class="w-full text-left text-sm">
                    <thead class="bg-zinc-50">
                        <tr>
                            <th class="px-4 py-3 font-semibold text-zinc-900">Product</th>
                            <th class="px-4 py-3 font-semibold text-zinc-900">Refund policy</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-zinc-100">
                        <tr>
                            <td class="px-4 py-3 font-medium text-zinc-900">Gift cards, airtime, data, bills</td>
                            <td class="px-4 py-3 text-zinc-600">No refunds. Final sale once delivered.</td>
                        </tr>
                        <tr>
                            <td class="px-4 py-3 font-medium text-zinc-900">eSIMs</td>
                            <td class="px-4 py-3 text-zinc-600">Wallet refund only if not yet installed or activated.</td>
                        </tr>
                        <tr>
                            <td class="px-4 py-3 font-medium text-zinc-900">Flights and hotel stays</td>
                            <td class="px-4 py-3 text-zinc-600">Subject to the airline or hotel terms. Flat processing fee applies.</td>
                        </tr>
                        <tr>
                            <td class="px-4 py-3 font-medium text-zinc-900">Digital real estate</td>
                            <td class="px-4 py-3 text-zinc-600">Non-refundable once finalized, subject to local cooling-off laws.</td>
                        </tr>
                    </tbody>
                </table>
            </div>

            {{-- Gift cards / airtime / data / bills --}}
            <h3 class="mt-8 text-base font-bold text-zinc-900">Digital gift cards, mobile airtime, data top-ups and bill payments</h3>
            <p class="mt-2 text-sm leading-relaxed text-zinc-600 sm:text-base">
                These products are final sale. Once a gift card code is generated, or once a top-up or bill payment is sent to
                the network, the purchase cannot be cancelled, reversed or refunded. This is because the value is delivered to
                you or to the destination account immediately and cannot be recovered.
            </p>

            <div class="my-5 flex items-start gap-3 rounded-[12px] bg-zinc-50 p-4 ring-1 ring-zinc-100">
                <svg class="mt-0.5 h-5 w-5 shrink-0 text-red-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m9-.75a9 9 0 11-18 0 9 9 0 0118 0zm-9 3.75h.008v.008H12v-.008z"/></svg>
                <div>
                    <p class="text-sm font-bold text-red-600">No refunds, all sales are final</p>
                    <p class="mt-1 text-sm leading-relaxed text-zinc-600">Please double-check your phone number, account number and delivery email before you pay. We cannot refund or recover a top-up sent to the wrong number, or a code delivered to the wrong email, once the transaction is complete.</p>
                </div>
            </div>

            {{-- eSIMs --}}
            <h3 class="mt-8 text-base font-bold text-zinc-900">eSIMs</h3>
            <p class="mt-2 text-sm leading-relaxed text-zinc-600 sm:text-base">
                An eSIM can be refunded to your wallet only if it has not been installed, registered or activated on any
                device. Once the eSIM is installed or activated, it is considered used and is no longer refundable.
            </p>

            <div class="my-5 flex items-start gap-3 rounded-[12px] bg-zinc-50 p-4 ring-1 ring-zinc-100">
                <svg class="mt-0.5 h-5 w-5 shrink-0 text-red-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m9-.75a9 9 0 11-18 0 9 9 0 0118 0zm-9 3.75h.008v.008H12v-.008z"/></svg>
                <div>
                    <p class="text-sm font-bold text-red-600">No refunds after activation or data use</p>
                    <p class="mt-1 text-sm leading-relaxed text-zinc-600">There are zero refunds once any data has been used, or if your device turns out to be incompatible after the eSIM is activated. Please confirm your device supports eSIM before you buy.</p>
                </div>
            </div>

            {{-- Wrong-region purchase --}}
            <div class="my-5 flex items-start gap-3 rounded-[12px] bg-blue-50 p-4 ring-1 ring-zinc-100">
                <svg class="mt-0.5 h-5 w-5 shrink-0 text-blue-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M12 21a9.004 9.004 0 008.716-6.747M12 21a9.004 9.004 0 01-8.716-6.747M12 21c2.485 0 4.5-4.03 4.5-9S14.485 3 12 3m0 18c-2.485 0-4.5-4.03-4.5-9S9.515 3 12 3m0 0a9.004 9.004 0 018.716 6.747M12 3a9.004 9.004 0 00-8.716 6.747M21.75 12H2.25"/></svg>
                <div>
                    <p class="text-sm font-bold text-blue-700">Bought the wrong region?</p>
                    <p class="mt-1 text-sm leading-relaxed text-zinc-600">If you bought an eSIM for the wrong country or region, refunds are processed within 24 to 48 hours after our team confirms the case. Whether a refund is granted still depends on whether the eSIM was installed: an eSIM that has not been installed or activated is refunded to your wallet, while one that has already been installed or used is not refundable.</p>
                </div>
            </div>

            {{-- Flights & stays --}}
            <h3 class="mt-8 text-base font-bold text-zinc-900">Flights and hotel stays</h3>
            <p class="mt-2 text-sm leading-relaxed text-zinc-600 sm:text-base">
                Travel bookings follow a pass-through policy. Cancellations, changes and refunds are strictly subject to the
                terms of the specific airline or hotel for your booking. We can only return what the airline or hotel allows
                under their own rules.
            </p>

            <div class="my-5 flex items-start gap-3 rounded-[12px] bg-blue-50 p-4 ring-1 ring-zinc-100">
                <svg class="mt-0.5 h-5 w-5 shrink-0 text-blue-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M11.25 11.25l.041-.02a.75.75 0 011.063.852l-.708 2.836a.75.75 0 001.063.853l.041-.021M21 12a9 9 0 11-18 0 9 9 0 0118 0zm-9-3.75h.008v.008H12V8.25z"/></svg>
                <div>
                    <p class="text-sm font-bold text-blue-700">A flat processing fee applies</p>
                    <p class="mt-1 text-sm leading-relaxed text-zinc-600">To manage a travel cancellation or change on your behalf, we charge a flat processing fee of $25 (or the equivalent in your local currency). This fee is separate from any charge the airline or hotel applies.</p>
                </div>
            </div>

            {{-- Digital real estate --}}
            <h3 class="mt-8 text-base font-bold text-zinc-900">Digital real estate</h3>
            <p class="mt-2 text-sm leading-relaxed text-zinc-600 sm:text-base">
                Digital real estate purchases are completely non-refundable once the allocation, transaction or fractional
                contract is finalized. The only exception is where a regional law requires a mandatory cooling-off period, in
                which case that legal right applies.
            </p>

            <div class="my-5 flex items-start gap-3 rounded-[12px] bg-zinc-50 p-4 ring-1 ring-zinc-100">
                <svg class="mt-0.5 h-5 w-5 shrink-0 text-red-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m9-.75a9 9 0 11-18 0 9 9 0 0118 0zm-9 3.75h.008v.008H12v-.008z"/></svg>
                <div>
                    <p class="text-sm font-bold text-red-600">Non-refundable once finalized</p>
                    <p class="mt-1 text-sm leading-relaxed text-zinc-600">After your allocation or fractional contract is confirmed, the purchase cannot be refunded, except where a cooling-off period is required by the laws of your region.</p>
                </div>
            </div>
        </section>

        {{-- ── 3. Failed-transaction protection ──────────────────── --}}
        <section id="failed-protection" class="mt-12 scroll-mt-24">
            <h2 class="text-xl font-bold tracking-tight text-zinc-900 sm:text-2xl">3. Automated failed-transaction protection</h2>
            <p class="mt-3 text-sm leading-relaxed text-zinc-600 sm:text-base">
                We built protection directly into the platform so that a failed delivery never costs you money. You never lose
                money on {{ $siteName }}: every payment is either delivered or refunded to your wallet, and our support team is
                available 24/7 if you ever need a human.
            </p>

            <div class="mt-5 rounded-[12px] bg-blue-600 p-6 sm:p-8">
                <span class="flex h-12 w-12 items-center justify-center rounded-[12px] bg-white/15">
                    <svg class="h-6 w-6 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75L11.25 15 15 9.75M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                </span>
                <h3 class="mt-4 text-lg font-bold text-white sm:text-xl">Failed delivery? Automatic refund after a fast system check.</h3>
                <p class="mt-2 text-sm leading-relaxed text-blue-100">
                    If your payment is taken but our delivery system fails to deliver your digital product because of a network
                    timeout, our system detects the failure automatically and issues an instant refund to your wallet, usually
                    within 60 seconds. You do not need to contact us or fill out any form. Your money is returned to your wallet
                    so you can try again right away - and if anything ever looks wrong, our 24/7 support team will make it right.
                </p>
            </div>
        </section>

        {{-- ── 4. Support ────────────────────────────────────────── --}}
        <section id="support" class="mt-12 scroll-mt-24">
            <h2 class="text-xl font-bold tracking-tight text-zinc-900 sm:text-2xl">4. How to get support</h2>
            <p class="mt-3 text-sm leading-relaxed text-zinc-600 sm:text-base">
                Choose the channel that fits your issue. To protect your account, we verify your identity before processing any
                refund.
            </p>

            <div class="mt-5 space-y-4">
                {{-- Live chat --}}
                <div class="flex items-start gap-4 rounded-[12px] bg-white p-5 ring-1 ring-zinc-100">
                    <span class="flex h-11 w-11 shrink-0 items-center justify-center rounded-[12px] bg-blue-50 text-blue-600">
                        <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M2.25 12.76c0 1.6 1.123 2.994 2.707 3.227 1.087.16 2.185.283 3.293.369V21l4.184-4.183a1.14 1.14 0 01.778-.332 48.294 48.294 0 005.83-.498c1.585-.233 2.708-1.626 2.708-3.228V6.741c0-1.602-1.123-2.995-2.707-3.228A48.394 48.394 0 0012 3c-2.392 0-4.744.175-7.043.513C3.373 3.746 2.25 5.14 2.25 6.741v6.018z"/></svg>
                    </span>
                    <div>
                        <p class="text-sm font-bold text-zinc-900">Live Chat <span class="ml-1 rounded-[5px] bg-emerald-50 px-1.5 py-0.5 text-[10px] font-bold uppercase tracking-wide text-emerald-700">24/7</span></p>
                        <p class="mt-1 text-sm leading-relaxed text-zinc-600">The fastest way to resolve a transaction issue, available around the clock. Open Live Chat while you are logged in to the app and our team can see your account and order details securely.</p>
                    </div>
                </div>

                {{-- WhatsApp --}}
                <div class="flex items-start gap-4 rounded-[12px] bg-white p-5 ring-1 ring-zinc-100">
                    <span class="flex h-11 w-11 shrink-0 items-center justify-center rounded-[12px] bg-blue-50 text-blue-600">
                        <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M20.25 8.511c.884.284 1.5 1.128 1.5 2.097v4.286c0 1.136-.847 2.1-1.98 2.193-.34.027-.68.052-1.02.072v3.091l-3-3c-1.354 0-2.694-.055-4.02-.163a2.115 2.115 0 01-.825-.242m9.345-8.334a2.126 2.126 0 00-.476-.095 48.64 48.64 0 00-8.048 0c-1.131.094-1.976 1.057-1.976 2.192v4.286c0 .837.46 1.58 1.155 1.951m9.345-8.334V6.637c0-1.621-1.152-3.026-2.76-3.235A48.455 48.455 0 0011.25 3c-2.115 0-4.198.137-6.24.402-1.608.209-2.76 1.614-2.76 3.235v6.226c0 1.621 1.152 3.026 2.76 3.235.577.075 1.157.14 1.74.194V21l4.155-4.155"/></svg>
                    </span>
                    <div>
                        <p class="text-sm font-bold text-zinc-900">WhatsApp</p>
                        <p class="mt-1 text-sm leading-relaxed text-zinc-600">For security, you must provide the email or phone number registered to your account, plus the Transaction ID of the order in question.</p>
                    </div>
                </div>

                {{-- Email --}}
                <div class="flex items-start gap-4 rounded-[12px] bg-white p-5 ring-1 ring-zinc-100">
                    <span class="flex h-11 w-11 shrink-0 items-center justify-center rounded-[12px] bg-blue-50 text-blue-600">
                        <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M21.75 6.75v10.5a2.25 2.25 0 01-2.25 2.25h-15a2.25 2.25 0 01-2.25-2.25V6.75m19.5 0A2.25 2.25 0 0019.5 4.5h-15a2.25 2.25 0 00-2.25 2.25m19.5 0v.243a2.25 2.25 0 01-1.07 1.916l-7.5 4.615a2.25 2.25 0 01-2.36 0L3.32 8.91a2.25 2.25 0 01-1.07-1.916V6.75"/></svg>
                    </span>
                    <div>
                        <p class="text-sm font-bold text-zinc-900">Email</p>
                        <p class="mt-1 text-sm leading-relaxed text-zinc-600">Best for high-value disputes, travel changes and digital real estate issues, where a written record keeps a clear audit trail. Write to <a href="mailto:{{ $disputesEmail }}" class="font-medium text-blue-600 hover:underline">{{ $disputesEmail }}</a>. For everyday help, email <a href="mailto:{{ $supportEmail }}" class="font-medium text-blue-600 hover:underline">{{ $supportEmail }}</a>.</p>
                    </div>
                </div>
            </div>

            {{-- Verification warning --}}
            <div class="my-5 flex items-start gap-3 rounded-[12px] bg-zinc-50 p-4 ring-1 ring-zinc-100">
                <svg class="mt-0.5 h-5 w-5 shrink-0 text-red-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M16.5 10.5V6.75a4.5 4.5 0 10-9 0v3.75m-.75 11.25h10.5a2.25 2.25 0 002.25-2.25v-6.75a2.25 2.25 0 00-2.25-2.25H6.75a2.25 2.25 0 00-2.25 2.25v6.75a2.25 2.25 0 002.25 2.25z"/></svg>
                <div>
                    <p class="text-sm font-bold text-red-600">We cannot refund without verification</p>
                    <p class="mt-1 text-sm leading-relaxed text-zinc-600">No refund will be processed until we confirm your registered account details and your Transaction ID. Please have them ready to speed things up.</p>
                </div>
            </div>
        </section>

        {{-- Footer note --}}
        <div class="mt-12 border-t border-zinc-100 pt-8">
            <p class="text-sm leading-relaxed text-zinc-600">
                We may update this policy from time to time. The date at the top of this page shows the latest version. If you
                have any questions, please <a href="{{ route('shop.contact') }}" wire:navigate class="font-medium text-blue-600 hover:underline">contact our team</a>.
            </p>
        </div>
        <x-legal-footer />
    </div>

</x-layouts.app.header>

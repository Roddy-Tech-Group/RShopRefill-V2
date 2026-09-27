@php
    // Digital Assets Security. Same shell as the accessibility statement, so
    // the Resources pages read as one set. Dark-mode safe for the same reason.
    //
    // Every protection described here is one the platform actually enforces
    // (device verification, the new-device hold, the PIN lockout, order and
    // download scoping, private KYC storage). A security page that promises
    // more than the code does is worse than no page, so nothing is claimed
    // that is not in the application. The hold length is read from config
    // rather than written out, so changing it cannot leave this page wrong.
    $supportEmail = trim((string) \App\Models\SiteSetting::get('contact.email_support', ''))
        ?: \App\Domain\Communication\Enums\MailDomain::replyTo();
    $holdHours = (int) config('security.device_restriction_hours', 24);
    $lastUpdated = 'September 15, 2026';
@endphp

<x-layouts.app.header :title="'Digital Assets Security | '.$siteName">

    {{-- ── Hero ──────────────────────────────────────────────── --}}
    <section class="bg-[#0c1337] dark:bg-[#0c1a36]">
        <div class="mx-auto w-full max-w-[1140px] px-4 py-14 text-center sm:px-6 sm:py-20">
            <span class="inline-flex items-center gap-2 rounded-[5px] bg-white/10 px-3 py-1.5 text-[11px] font-bold uppercase tracking-[0.14em] text-white">Security</span>
            <h1 class="mt-5 text-3xl font-bold tracking-tight text-white sm:text-4xl">Digital Assets Security</h1>
            <p class="mt-3 text-sm text-white/75">Last updated: {{ $lastUpdated }}</p>
        </div>
    </section>

    <div class="mx-auto w-full max-w-[1140px] px-4 py-12 sm:px-6 sm:py-16">

        {{-- Intro --}}
        <p class="text-sm leading-relaxed text-zinc-600 sm:text-base">
            Gift card codes, eSIM activation details, your wallet balance and your Rcoin all hold real value. This page explains
            how {{ $siteName }} protects them, and what you can do to keep them safe.
        </p>

        {{-- ── 1 ─────────────────────────────────────────────────── --}}
        <section class="mt-12">
            <h2 class="text-xl font-bold tracking-tight text-zinc-900 sm:text-2xl">What we mean by digital assets</h2>
            <ul class="mt-4 list-disc space-y-2 pl-5 text-sm leading-relaxed text-zinc-600 marker:text-blue-500 sm:text-base">
                <li>Gift card codes and PINs delivered to your orders.</li>
                <li>eSIM QR codes and activation details.</li>
                <li>The balance held in your wallet.</li>
                <li>The Rcoin rewards you earn.</li>
            </ul>
        </section>

        {{-- ── 2 ─────────────────────────────────────────────────── --}}
        <section class="mt-12">
            <h2 class="text-xl font-bold tracking-tight text-zinc-900 sm:text-2xl">Your codes stay in your account</h2>
            <p class="mt-3 text-sm leading-relaxed text-zinc-600 sm:text-base">
                Delivered codes and eSIM details appear on your order page and in your dashboard, and a copy is sent to your
                delivery email. An order page only opens for the account that placed the order, and downloading your codes as a
                file requires you to be signed in.
            </p>
        </section>

        {{-- ── 3 ─────────────────────────────────────────────────── --}}
        <section class="mt-12">
            <h2 class="text-xl font-bold tracking-tight text-zinc-900 sm:text-2xl">Protecting your sign-in</h2>
            <p class="mt-3 text-sm leading-relaxed text-zinc-600 sm:text-base">
                When your account is used on a device we have not seen before, we ask you to verify that device and we email you
                an alert. If it was not you, the alert includes a link to report it straight away.
            </p>
            <p class="mt-3 text-sm leading-relaxed text-zinc-600 sm:text-base">
                For the first {{ $holdHours }} hours on a new device, actions that change your account security, such as changing
                or resetting your transaction PIN, and actions that move money out, such as Rcoin withdrawals, are paused.
                Buying and paying are not affected.
            </p>
        </section>

        {{-- ── 4 ─────────────────────────────────────────────────── --}}
        <section class="mt-12">
            <h2 class="text-xl font-bold tracking-tight text-zinc-900 sm:text-2xl">Your transaction PIN</h2>
            <p class="mt-3 text-sm leading-relaxed text-zinc-600 sm:text-base">
                Payments from your wallet balance are authorised with your transaction PIN. Easy-to-guess PINs are not accepted.
                After repeated incorrect attempts the PIN pauses for a short time, and too many incorrect attempts lock it until you
                set a new one with Forgot PIN.
            </p>
        </section>

        {{-- ── 5 ─────────────────────────────────────────────────── --}}
        <section class="mt-12">
            <h2 class="text-xl font-bold tracking-tight text-zinc-900 sm:text-2xl">Identity documents</h2>
            <p class="mt-3 text-sm leading-relaxed text-zinc-600 sm:text-base">
                Documents you upload for identity verification are kept in private storage and cannot be reached from a public web
                address.
            </p>
        </section>

        {{-- ── 6 ─────────────────────────────────────────────────── --}}
        <section class="mt-12">
            <h2 class="text-xl font-bold tracking-tight text-zinc-900 sm:text-2xl">How to keep your assets safe</h2>
            <ul class="mt-4 list-disc space-y-2 pl-5 text-sm leading-relaxed text-zinc-600 marker:text-blue-500 sm:text-base">
                <li>Treat a gift card code like cash: anyone who has it can redeem it.</li>
                <li>Never share your password or transaction PIN. We will never ask you for them.</li>
                <li>Be wary of anyone who asks you to pay them in gift cards. Genuine government agencies, utility companies and employers do not ask for payment in gift cards.</li>
                <li>Use a strong password that you do not use anywhere else, and set a transaction PIN.</li>
                <li>If you receive a new device alert you do not recognise, use the link in that email right away.</li>
                <li>Only send codes to people you trust. A code that someone else has already redeemed generally cannot be refunded.</li>
            </ul>
        </section>

        {{-- ── 7 ─────────────────────────────────────────────────── --}}
        <section class="mt-12">
            <h2 class="text-xl font-bold tracking-tight text-zinc-900 sm:text-2xl">Think something is wrong?</h2>
            <p class="mt-3 text-sm leading-relaxed text-zinc-600 sm:text-base">
                If you think your account or one of your codes has been compromised, contact us straight away. Include your order
                number so we can act quickly.
            </p>

            <div class="my-5 flex items-start gap-3 rounded-[12px] bg-zinc-50 p-4">
                <svg class="mt-0.5 h-5 w-5 shrink-0 text-blue-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75L11.25 15 15 9.75m-3-7.036A11.959 11.959 0 013.598 6 11.99 11.99 0 003 9.749c0 5.592 3.824 10.29 9 11.623 5.176-1.332 9-6.03 9-11.622 0-1.31-.21-2.571-.598-3.751h-.152c-3.196 0-6.1-1.248-8.25-3.285z"/></svg>
                <div>
                    <p class="text-sm font-bold text-blue-700">Report a security concern</p>
                    <p class="mt-1 text-sm leading-relaxed text-zinc-600">Email <a href="mailto:{{ $supportEmail }}" class="font-medium text-blue-600 hover:underline">{{ $supportEmail }}</a> or use our <a href="{{ route('shop.contact') }}" wire:navigate class="font-medium text-blue-600 hover:underline">Contact page</a>.</p>
                </div>
            </div>
        </section>

    </div>

</x-layouts.app.header>

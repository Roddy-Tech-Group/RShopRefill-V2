@php
    // Everything on this page is admin-editable from System Settings (group
    // "contact"). The support address used to be a literal here, which quietly
    // diverged from contact.email_support - editing the setting changed
    // nothing. It reads the setting now, falling back to the one address the
    // mail platform guarantees exists (every outgoing email replies to it).
    $supportEmail = trim((string) \App\Models\SiteSetting::get('contact.email_support', ''))
        ?: \App\Domain\Communication\Enums\MailDomain::replyTo();

    // Display + dialable (tel:/sms:) come from contact.phone_primary; the
    // wa.me digits come from contact.whatsapp_number.
    $supportPhone = \App\Models\SiteSetting::get('contact.phone_primary', '+1 (940) 238-6229');
    $supportPhoneDial = preg_replace('/[^0-9+]/', '', $supportPhone);
    $supportPhoneWa = preg_replace('/[^0-9]/', '', \App\Models\SiteSetting::get('contact.whatsapp_number', '19402386229'));

    // Optional second line. Empty (the default) renders nothing at all rather
    // than an empty row.
    $altPhone = trim((string) \App\Models\SiteSetting::get('contact.phone_secondary', ''));
    $altPhoneDial = $altPhone !== '' ? preg_replace('/[^0-9+]/', '', $altPhone) : '';
    $altPhoneLabel = trim((string) \App\Models\SiteSetting::get('contact.phone_secondary_label', '')) ?: 'Alternate line';

    // Physical address. Stored as free text so an admin can lay it out over
    // several lines; each non-empty line renders as its own row.
    $addressLines = collect(preg_split('/\r\n|\r|\n/', (string) \App\Models\SiteSetting::get('contact.address', '')))
        ->map(fn ($line) => trim($line))
        ->filter()
        ->values();
    $addressMapsUrl = trim((string) \App\Models\SiteSetting::get('contact.address_maps_url', ''));
    // The registered entity heads the address card - "Roddy Technologies LTD"
    // is what a courier or a regulator needs on the envelope, not the brand.
    $legalName = trim((string) \App\Models\SiteSetting::get('site.legal_name', ''));

    $supportHours = trim((string) \App\Models\SiteSetting::get('contact.support_hours', '')) ?: '24/7';

    // Publishing all eight sender mailboxes made the page read as a wall of
    // addresses. Only the two a customer ever needs are listed: compliance,
    // which is the one mailbox with its own obligations and its own inbox, and
    // support, which every other mailbox replies to anyway
    // (MailDomain::replyTo). The rest still send mail; they are simply not
    // advertised as somewhere to look or write.
    $complianceMailbox = \App\Domain\Communication\Enums\MailDomain::Compliance;
    $tp = config('services.trustpilot');
    // A pill with a 2px edge that only changes colour on focus. The textarea
    // below keeps a corner of its own: a pill around six lines of message is a
    // lozenge, and the curve has no line of text to follow.
    $field = 'w-full rounded-full border-2 border-zinc-300 bg-white px-4 py-2 text-sm text-black placeholder:text-zinc-500 outline-none transition-colors focus:border-edge';
    $fieldMultiline = str_replace('rounded-full', 'rounded-[12px]', $field);

    $subjects = [
        'General enquiry',
        'Order not delivered',
        'Issue redeeming gift card',
        'Missing barcode',
        'Refund request / change order',
        'Paid with the wrong coin or network',
        'KYC approval',
        'Out of stock',
        'Flight or stay booking',
        'Brand / product request',
        'Feature request',
        'Other reason',
    ];

    // Pre-fill from `?subject=` query (used by the dashboard "Need help with
    // your order" button, which sends e.g. ?subject=Help with order ORD-AB12).
    // If the value matches a list item exactly we select it; if it carries an
    // order ID we route to "Order not delivered" and seed the order_id +
    // message fields so support has context without the user having to retype.
    $queriedSubject = trim((string) request()->query('subject', ''));
    $defaultSubject = $subjects[0];
    $defaultOrderId = '';
    $defaultMessage = '';
    if ($queriedSubject !== '') {
        if (in_array($queriedSubject, $subjects, true)) {
            $defaultSubject = $queriedSubject;
        } elseif (preg_match('/order\s+([A-Za-z0-9_-]{4,})/i', $queriedSubject, $m)) {
            $defaultSubject = 'Order not delivered';
            $defaultOrderId = $m[1];
            $defaultMessage = "Hi team, I need help with order {$m[1]}. ";
        } else {
            $defaultSubject = 'Other reason';
            $defaultMessage = $queriedSubject;
        }
    }

    $channels = [
        ['label' => 'Email us',      'value' => $supportEmail,        'href' => 'mailto:'.$supportEmail, 'path' => 'M21.75 6.75v10.5a2.25 2.25 0 01-2.25 2.25h-15a2.25 2.25 0 01-2.25-2.25V6.75m19.5 0A2.25 2.25 0 0019.5 4.5h-15a2.25 2.25 0 00-2.25 2.25m19.5 0v.243a2.25 2.25 0 01-1.07 1.916l-7.5 4.615a2.25 2.25 0 01-2.36 0L3.32 8.91a2.25 2.25 0 01-1.07-1.916V6.75'],
        ['label' => 'Live chat',     'value' => 'Available '.$supportHours, 'href' => null,               'path' => 'M12 6v6h4.5m4.5 0a9 9 0 11-18 0 9 9 0 0118 0z'],
        ['label' => 'Email response','value' => 'Within 24 hours',     'href' => null,                    'path' => 'M6.75 3v2.25M17.25 3v2.25M3 18.75V7.5a2.25 2.25 0 012.25-2.25h13.5A2.25 2.25 0 0121 7.5v11.25m-18 0A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75m-18 0v-7.5A2.25 2.25 0 015.25 9h13.5A2.25 2.25 0 0121 11.25v7.5'],
    ];
@endphp

<x-layouts.app.header :title="'Contact Us | '.$siteName">

    {{-- TrustBox bootstrap. Loaded once; the widget below the form renders into it. --}}
    <script type="text/javascript" src="//widget.trustpilot.com/bootstrap/v5/tp.widget.bootstrap.min.js" async></script>

    {{-- ── Hero ──────────────────────────────────────────────── --}}
    <section class="bg-[#0c1337] dark:bg-[#0c1a36]">
        <div class="mx-auto w-full max-w-[1000px] px-4 py-14 text-center sm:px-6 sm:py-20">
            <span class="inline-flex items-center gap-2 rounded-[5px] bg-white/10 px-3 py-1.5 text-[11px] font-bold uppercase tracking-[0.14em] text-white">Contact</span>
            <h1 class="mt-5 text-3xl font-bold tracking-tight text-white sm:text-4xl lg:text-5xl">Get in touch</h1>
            <p class="mx-auto mt-3 max-w-xl text-sm leading-relaxed text-white/75 sm:text-base">
                Questions about an order, your wallet or your account? Send us a message and our team will get back to you, usually within a day.
            </p>
        </div>
    </section>

    {{-- ── Form + channels ───────────────────────────────────── --}}
    <section class="mx-auto w-full max-w-[1140px] px-4 py-12 sm:px-6 sm:py-16">
        <div class="grid grid-cols-1 gap-8 lg:grid-cols-2 lg:gap-10">

            {{-- Form --}}
            <div>
                <div class="rounded-[24px] bg-white p-6 shadow-sm shadow-zinc-900/5 sm:p-8">
                    @if (session('contact_sent'))
                        {{-- Success state --}}
                        <div class="flex flex-col items-center py-10 text-center">
                            <span class="flex h-14 w-14 items-center justify-center rounded-[12px] bg-emerald-100">
                                <svg class="h-7 w-7 text-emerald-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5" aria-hidden="true">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5"/>
                                </svg>
                            </span>
                            <h2 class="mt-4 text-xl font-bold text-zinc-900">Message sent</h2>
                            <p class="mt-1.5 max-w-sm text-sm leading-relaxed text-zinc-600">
                                Thanks for reaching out. We have received your message and will reply to your email within 24 hours.
                            </p>
                            <a href="{{ route('shop.contact') }}" wire:navigate class="mt-6 inline-flex items-center gap-2 rounded-full bg-blue-600 px-5 py-2.5 text-sm font-semibold text-white transition-colors hover:bg-blue-700">
                                Send another message
                            </a>
                        </div>
                    @else
                        <div class="mb-6">
                            <h2 class="text-lg font-bold text-zinc-900">Send us a message</h2>
                            <p class="mt-0.5 text-sm text-zinc-600">We read every message. Fields marked with an asterisk are required.</p>
                        </div>

                        <form method="POST" action="{{ route('contact.send') }}" class="space-y-5">
                            @csrf

                            {{-- Honeypot — hidden from humans, bots tend to fill it. --}}
                            <div class="hidden" aria-hidden="true">
                                <label>Website<input type="text" name="website" tabindex="-1" autocomplete="off"></label>
                            </div>

                            <div class="grid grid-cols-1 gap-5 sm:grid-cols-2">
                                <div>
                                    <label for="name" class="mb-1.5 block text-sm font-medium text-zinc-700">Full name <span class="text-blue-600">*</span></label>
                                    <input id="name" name="name" type="text" required value="{{ old('name', auth()->user()?->name) }}" placeholder="Your name" class="{{ $field }}">
                                    @error('name') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                                </div>
                                <div>
                                    <label for="email" class="mb-1.5 block text-sm font-medium text-zinc-700">Email <span class="text-blue-600">*</span></label>
                                    <input id="email" name="email" type="email" required value="{{ old('email', auth()->user()?->email) }}" placeholder="you@example.com" class="{{ $field }}">
                                    @error('email') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                                </div>
                            </div>

                            <div class="grid grid-cols-1 gap-5 sm:grid-cols-2">
                                {{-- Subject — modern custom dropdown (styled chevron, not the native arrow) --}}
                                <div x-data="{ open: false, subject: @js(old('subject', $defaultSubject)) }" @click.outside="open = false" @keydown.escape="open = false" class="relative">
                                    <label class="mb-1.5 block text-sm font-medium text-zinc-700">Subject</label>
                                    <input type="hidden" name="subject" :value="subject">
                                    <button
                                        type="button"
                                        @click="open = ! open"
                                        :aria-expanded="open.toString()"
                                        class="flex w-full items-center justify-between gap-2 rounded-full border border-zinc-300 bg-white px-3.5 py-2.5 text-left text-sm font-medium text-zinc-900 outline-none transition-colors hover:border-zinc-400 focus:border-blue-500 focus:outline-none focus-visible:ring-2 focus-visible:ring-blue-500/15"
                                    >
                                        <span x-text="subject" class="truncate"></span>
                                        <svg class="h-4 w-4 shrink-0 text-zinc-500 transition-transform duration-150" :class="open && 'rotate-180'" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7"/></svg>
                                    </button>
                                    <div
                                        x-show="open"
                                        x-cloak
                                        x-transition:enter="transition ease-out duration-150"
                                        x-transition:enter-start="opacity-0 -translate-y-1"
                                        x-transition:enter-end="opacity-100 translate-y-0"
                                        class="absolute left-0 right-0 z-20 mt-1.5 max-h-60 overflow-y-auto rounded-[12px] bg-[#eff6ff] p-1 shadow-xl shadow-zinc-900/10"
                                        role="listbox"
                                    >
                                        @foreach ($subjects as $s)
                                            <button
                                                type="button"
                                                @click="subject = @js($s); open = false"
                                                role="option"
                                                class="flex w-full items-center justify-between gap-2 rounded-full px-3 py-2 text-left text-sm font-medium transition-colors"
                                                :class="subject === @js($s) ? 'bg-blue-50 text-blue-700' : 'text-zinc-700 hover:bg-zinc-100'"
                                            >
                                                <span>{{ $s }}</span>
                                                <svg x-show="subject === @js($s)" class="h-4 w-4 shrink-0 text-blue-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5"/></svg>
                                            </button>
                                        @endforeach
                                    </div>
                                    @error('subject') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                                </div>

                                {{-- Order ID (optional) --}}
                                <div>
                                    <label for="order_id" class="mb-1.5 block text-sm font-medium text-zinc-700">Order ID <span class="font-normal text-zinc-500">(optional)</span></label>
                                    <input id="order_id" name="order_id" type="text" value="{{ old('order_id', $defaultOrderId) }}" placeholder="e.g. ORD-AB12CD34" class="{{ $field }}">
                                    @error('order_id') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                                </div>
                            </div>

                            <div>
                                <label for="message" class="mb-1.5 block text-sm font-medium text-zinc-700">Message <span class="text-blue-600">*</span></label>
                                <textarea id="message" name="message" rows="6" required placeholder="How can we help?" class="{{ $fieldMultiline }} resize-y">{{ old('message', $defaultMessage) }}</textarea>
                                @error('message') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                            </div>

                            <x-turnstile-widget action="contact" context="contact" />

                            <div class="flex items-center gap-3 pt-1">
                                <button type="submit" class="inline-flex items-center gap-2 rounded-full bg-blue-600 px-5 py-2.5 text-sm font-semibold text-white shadow-sm shadow-blue-600/25 transition-colors hover:bg-blue-700 focus:outline-none focus-visible:ring-2 focus-visible:ring-blue-500/50">
                                    Send message
                                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M6 12L3.269 3.126A59.768 59.768 0 0121.485 12 59.77 59.77 0 013.27 20.876L5.999 12zm0 0h7.5"/></svg>
                                </button>
                                <p class="text-xs text-zinc-500">We never share your details.</p>
                            </div>
                        </form>
                    @endif
                </div>

                {{-- Registered office. Sits directly under the form fields, as
                     the postal counterpart to the digital channels opposite.
                     Renders only when contact.address is filled in, so the
                     column simply closes up when it is not set. --}}
                @if ($addressLines->isNotEmpty())
                    <div class="mt-6 rounded-[12px] bg-white p-5">
                        <div class="flex items-start gap-4">
                            <span class="flex h-11 w-11 shrink-0 items-center justify-center rounded-[12px] bg-blue-50 text-blue-600">
                                <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75" aria-hidden="true">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M15 10.5a3 3 0 11-6 0 3 3 0 016 0z"/>
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M19.5 10.5c0 7.142-7.5 11.25-7.5 11.25S4.5 17.642 4.5 10.5a7.5 7.5 0 1115 0z"/>
                                </svg>
                            </span>
                            <div class="min-w-0">
                                <p class="text-sm font-bold text-zinc-900">Our address</p>
                                @if ($legalName)
                                    <p class="mt-0.5 text-sm font-semibold text-zinc-700">{{ $legalName }}</p>
                                @endif
                                <address class="mt-1 not-italic text-sm leading-relaxed text-zinc-600">
                                    @foreach ($addressLines as $line)
                                        <span class="block">{{ $line }}</span>
                                    @endforeach
                                </address>
                                @if ($addressMapsUrl !== '')
                                    <a href="{{ $addressMapsUrl }}" target="_blank" rel="noopener noreferrer" class="mt-3 inline-flex items-center gap-1.5 rounded-full bg-blue-50 px-3 py-2 text-xs font-semibold text-blue-700 transition-colors hover:bg-blue-100">
                                        <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.9" aria-hidden="true">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M9 6.75V15m6-6v8.25m.503 3.498l4.875-2.437c.381-.19.622-.58.622-1.006V4.82c0-.836-.88-1.38-1.628-1.006l-3.869 1.934c-.317.159-.69.159-1.006 0L9.503 3.252a1.125 1.125 0 00-1.006 0L3.622 5.689C3.24 5.88 3 6.27 3 6.695V19.18c0 .836.88 1.38 1.628 1.006l3.869-1.934c.317-.159.69-.159 1.006 0l4.994 2.497c.317.158.69.158 1.006 0z"/>
                                        </svg>
                                        View on map
                                    </a>
                                @endif
                            </div>
                        </div>
                    </div>
                @endif

                {{-- Trustpilot review collector - sits under the message form. --}}
                <div class="mt-6 flex justify-center">
                    <div class="inline-flex overflow-hidden rounded-[12px] bg-white shadow-md shadow-zinc-900/20">
                        <div class="trustpilot-widget"
                            data-locale="{{ $tp['locale'] }}"
                            data-template-id="{{ $tp['review_collector_template_id'] }}"
                            data-businessunit-id="{{ $tp['business_unit_id'] }}"
                            data-style-height="52px"
                            data-style-width="240px"
                            data-token="{{ $tp['review_collector_token'] }}">
                            <a href="{{ $tp['profile_url'] }}" target="_blank" rel="noopener" class="text-sm font-semibold text-blue-700 hover:underline">{{ __('reviews.review_fallback') }}</a>
                        </div>
                    </div>
                </div>
            </div>

            {{-- Channels --}}
            <div>
                <div class="space-y-4">
                    @foreach ($channels as $channel)
                        <div class="flex items-start gap-4 rounded-[12px] bg-white p-5">
                            <span class="flex h-11 w-11 shrink-0 items-center justify-center rounded-[12px] bg-blue-50 text-blue-600">
                                <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="{{ $channel['path'] }}"/></svg>
                            </span>
                            <div class="min-w-0">
                                <p class="text-sm font-bold text-zinc-900">{{ $channel['label'] }}</p>
                                @if ($channel['href'])
                                    <a href="{{ $channel['href'] }}" class="mt-0.5 block break-words text-sm font-medium text-blue-600 hover:underline">{{ $channel['value'] }}</a>
                                @else
                                    <p class="mt-0.5 text-sm text-zinc-600">{{ $channel['value'] }}</p>
                                @endif
                            </div>
                        </div>
                    @endforeach

                    {{-- Global support: one number, reachable by call, iMessage and WhatsApp. --}}
                    <div class="flex items-start gap-4 rounded-[12px] bg-white p-5">
                        <span class="flex h-11 w-11 shrink-0 items-center justify-center rounded-[12px] bg-blue-50 text-blue-600">
                            <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M2.25 6.75c0 8.284 6.716 15 15 15h2.25a2.25 2.25 0 002.25-2.25v-1.372c0-.516-.351-.966-.852-1.091l-4.423-1.106c-.44-.11-.902.055-1.173.417l-.97 1.293c-.282.376-.769.542-1.21.38a12.035 12.035 0 01-7.143-7.143c-.162-.441.004-.928.38-1.21l1.293-.97c.363-.271.527-.734.417-1.173L6.963 3.102a1.125 1.125 0 00-1.091-.852H4.5A2.25 2.25 0 002.25 4.5v2.25z"/></svg>
                        </span>
                        <div class="min-w-0">
                            <p class="text-sm font-bold text-zinc-900">Global support</p>
                            <a href="tel:{{ $supportPhoneDial }}" class="mt-0.5 block break-words text-sm font-medium text-blue-600 hover:underline">{{ $supportPhone }}</a>
                            <div class="mt-3 grid grid-cols-3 gap-2">
                                <a href="tel:{{ $supportPhoneDial }}" aria-label="Call global support" class="flex items-center justify-center gap-1.5 rounded-full bg-blue-50 px-3 py-2 text-xs font-semibold text-blue-700 transition-colors hover:bg-blue-100">
                                    <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.9" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M2.25 6.75c0 8.284 6.716 15 15 15h2.25a2.25 2.25 0 002.25-2.25v-1.372c0-.516-.351-.966-.852-1.091l-4.423-1.106c-.44-.11-.902.055-1.173.417l-.97 1.293c-.282.376-.769.542-1.21.38a12.035 12.035 0 01-7.143-7.143c-.162-.441.004-.928.38-1.21l1.293-.97c.363-.271.527-.734.417-1.173L6.963 3.102a1.125 1.125 0 00-1.091-.852H4.5A2.25 2.25 0 002.25 4.5v2.25z"/></svg>
                                    Call
                                </a>
                                <a href="sms:{{ $supportPhoneDial }}" aria-label="iMessage global support" class="flex items-center justify-center gap-1.5 rounded-full bg-blue-50 px-3 py-2 text-xs font-semibold text-blue-700 transition-colors hover:bg-blue-100">
                                    <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.9" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M2.25 12.76c0 1.6 1.123 2.994 2.707 3.227 1.087.16 2.185.283 3.293.369V21l4.184-4.183a1.14 1.14 0 01.778-.332 48.294 48.294 0 005.83-.498c1.585-.233 2.708-1.626 2.708-3.228V6.741c0-1.602-1.123-2.995-2.707-3.228A48.394 48.394 0 0012 3c-2.392 0-4.744.175-7.043.513C3.373 3.746 2.25 5.14 2.25 6.741v6.018z"/></svg>
                                    iMessage
                                </a>
                                <a href="https://wa.me/{{ $supportPhoneWa }}" target="_blank" rel="noopener noreferrer" aria-label="WhatsApp global support" class="flex items-center justify-center gap-1.5 rounded-full bg-emerald-50 px-3 py-2 text-xs font-semibold text-emerald-700 transition-colors hover:bg-emerald-100">
                                    <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-5.421 7.403h-.004a9.87 9.87 0 01-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 01-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 012.893 6.994c-.003 5.45-4.437 9.884-9.885 9.884m8.413-18.297A11.815 11.815 0 0012.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L.057 24l6.305-1.654a11.882 11.882 0 005.683 1.448h.005c6.554 0 11.89-5.335 11.893-11.893a11.821 11.821 0 00-3.48-8.413z"/></svg>
                                    WhatsApp
                                </a>
                            </div>

                            {{-- Second line, when one is configured. Sits inside
                                 the same card so the two numbers read as one
                                 support contact rather than competing channels. --}}
                            @if ($altPhone !== '')
                                <div class="mt-4 pt-3">
                                    <p class="text-xs font-semibold uppercase tracking-wide text-zinc-500">{{ $altPhoneLabel }}</p>
                                    <a href="tel:{{ $altPhoneDial }}" class="mt-0.5 block break-words text-sm font-medium text-blue-600 hover:underline">{{ $altPhone }}</a>
                                </div>
                            @endif
                        </div>
                    </div>

                    {{-- Self-service + socials --}}
                    <div class="rounded-[12px] bg-blue-600 p-6">
                        <p class="text-sm font-bold text-white">Prefer to help yourself?</p>
                        <p class="mt-1 text-xs leading-relaxed text-blue-100">Browse common answers in our Help Center, available any time.</p>
                        <a href="{{ route('shop.help') }}" wire:navigate class="mt-4 inline-flex items-center gap-2 rounded-full bg-white px-4 py-2.5 text-sm font-semibold text-blue-700 transition-colors hover:bg-blue-50">
                            Visit Help Center
                        </a>
                        <div class="mt-5 flex items-center gap-2 pt-5">
                            <a href="{{ \App\Models\SiteSetting::get('social.facebook', 'https://facebook.com/rshoprefills') }}" target="_blank" rel="noopener noreferrer" aria-label="Facebook" class="flex h-9 w-9 items-center justify-center rounded-full bg-white/10 text-white transition-colors hover:bg-white/20">
                                <svg viewBox="0 0 24 24" class="h-4 w-4" fill="currentColor" aria-hidden="true"><path d="M24 12.073c0-6.627-5.373-12-12-12s-12 5.373-12 12c0 5.99 4.388 10.954 10.125 11.854v-8.385H7.078v-3.47h3.047V9.43c0-3.007 1.792-4.669 4.533-4.669 1.312 0 2.686.235 2.686.235v2.953H15.83c-1.491 0-1.956.925-1.956 1.874v2.25h3.328l-.532 3.47h-2.796v8.385C19.612 23.027 24 18.062 24 12.073z"/></svg>
                            </a>
                            <a href="{{ \App\Models\SiteSetting::get('social.x', 'https://x.com/roddytech237') }}" target="_blank" rel="noopener noreferrer" aria-label="X" class="flex h-9 w-9 items-center justify-center rounded-full bg-white/10 text-white transition-colors hover:bg-white/20">
                                <svg viewBox="0 0 24 24" class="h-3.5 w-3.5" fill="currentColor" aria-hidden="true"><path d="M18.244 2.25h3.308l-7.227 8.26 8.502 11.24H16.17l-5.214-6.817L4.99 21.75H1.68l7.73-8.835L1.254 2.25H8.08l4.713 6.231zm-1.161 17.52h1.833L7.084 4.126H5.117L17.083 19.77z"/></svg>
                            </a>
                            <a href="{{ \App\Models\SiteSetting::get('social.instagram', 'https://instagram.com/rshoprefills') }}" target="_blank" rel="noopener noreferrer" aria-label="Instagram" class="flex h-9 w-9 items-center justify-center rounded-full bg-white/10 text-white transition-colors hover:bg-white/20">
                                <svg viewBox="0 0 24 24" class="h-4 w-4" fill="currentColor" aria-hidden="true"><path d="M12 2.163c3.204 0 3.584.012 4.849.07 3.252.148 4.771 1.691 4.919 4.919.058 1.265.07 1.644.07 4.849 0 3.205-.012 3.584-.07 4.849-.149 3.225-1.664 4.771-4.919 4.919-1.266.058-1.644.07-4.849.07-3.204 0-3.584-.012-4.849-.07-3.26-.149-4.771-1.699-4.919-4.92-.058-1.265-.07-1.644-.07-4.849 0-3.204.013-3.583.07-4.849.149-3.227 1.664-4.771 4.919-4.919 1.266-.057 1.645-.069 4.849-.069zM12 0C8.741 0 8.333.014 7.053.072 2.695.272.273 2.69.073 7.052.014 8.333 0 8.741 0 12c0 3.259.014 3.668.072 4.948.2 4.358 2.618 6.78 6.98 6.98C8.333 23.986 8.741 24 12 24c3.259 0 3.668-.014 4.948-.072 4.354-.2 6.782-2.618 6.979-6.98.059-1.28.073-1.689.073-4.948 0-3.259-.014-3.667-.072-4.947-.196-4.354-2.617-6.78-6.979-6.98C15.668.014 15.259 0 12 0zm0 5.838a6.162 6.162 0 100 12.324 6.162 6.162 0 000-12.324zM12 16a4 4 0 110-8 4 4 0 010 8zm6.406-11.845a1.44 1.44 0 100 2.881 1.44 1.44 0 000-2.881z"/></svg>
                            </a>
                        </div>
                    </div>

                    {{-- Discord community widget. Needs the server Widget enabled
                         (Server Settings -> Widget) and `frame-src https://discord.com`
                         in the production CSP, or the iframe is blocked on live. --}}
                    <div class="rounded-[12px] bg-white p-5">
                        <div class="mb-3 flex items-center gap-2.5">
                            <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-[12px] text-white" style="background-color: #5865F2;">
                                <svg class="h-5 w-5" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M20.317 4.3698a19.7913 19.7913 0 00-4.8851-1.5152.0741.0741 0 00-.0785.0371c-.211.3753-.4447.8648-.6083 1.2495-1.8447-.2762-3.68-.2762-5.4868 0-.1636-.3933-.4058-.8742-.6177-1.2495a.077.077 0 00-.0785-.037 19.7363 19.7363 0 00-4.8852 1.515.0699.0699 0 00-.0321.0277C.5334 9.0458-.319 13.5799.0992 18.0578a.0824.0824 0 00.0312.0561c2.0528 1.5076 4.0413 2.4228 5.9929 3.0294a.0777.0777 0 00.0842-.0276c.4616-.6304.8731-1.2952 1.226-1.9942a.076.076 0 00-.0416-.1057c-.6528-.2476-1.2743-.5495-1.8722-.8923a.077.077 0 01-.0076-.1277c.1258-.0943.2517-.1923.3718-.2914a.0743.0743 0 01.0776-.0105c3.9278 1.7933 8.18 1.7933 12.0614 0a.0739.0739 0 01.0785.0095c.1202.099.246.1981.3728.2924a.077.077 0 01-.0066.1276 12.2986 12.2986 0 01-1.873.8914.0766.0766 0 00-.0407.1067c.3604.698.7719 1.3628 1.225 1.9932a.076.076 0 00.0842.0286c1.961-.6067 3.9495-1.5219 6.0023-3.0294a.077.077 0 00.0313-.0552c.5004-5.177-.8382-9.6739-3.5485-13.6604a.061.061 0 00-.0312-.0286zM8.02 15.3312c-1.1825 0-2.1569-1.0857-2.1569-2.419 0-1.3332.9555-2.4189 2.157-2.4189 1.2108 0 2.1757 1.0952 2.1568 2.419 0 1.3332-.9555 2.4189-2.1569 2.4189zm7.9748 0c-1.1825 0-2.1569-1.0857-2.1569-2.419 0-1.3332.9554-2.4189 2.1569-2.4189 1.2108 0 2.1757 1.0952 2.1568 2.419 0 1.3332-.946 2.4189-2.1568 2.4189Z"/></svg>
                            </span>
                            <div>
                                <p class="text-sm font-bold text-zinc-900">Join our Discord</p>
                                <p class="text-xs text-zinc-600">Chat with the team and community in real time.</p>
                            </div>
                        </div>
                        <iframe
                            src="https://discord.com/widget?id=1450688930746990737&theme=dark"
                            title="{{ $siteName }} Discord community"
                            height="500"
                            class="block w-full rounded-[12px]"
                            style="height: 500px;"
                            frameborder="0"
                            allowtransparency="true"
                            sandbox="allow-popups allow-popups-to-escape-sandbox allow-same-origin allow-scripts"
                            loading="lazy"
                        ></iframe>
                    </div>
                </div>
            </div>
        </div>
    </section>

    {{-- ── Emails you receive from us ────────────────────────────
         Two addresses only: support, which every other mailbox replies to,
         and compliance, the one with its own obligations and its own inbox.
         Listing all eight senders turned the page into a wall of addresses
         and invited mail into boxes nobody reads.

         Both are read off MailDomain / the contact settings rather than typed
         here, so neither can drift from the From header the mailer actually
         builds. --}}
    <section class="mx-auto w-full max-w-[1140px] px-4 pb-16 sm:px-6 sm:pb-20">
        <div class="pt-12">
            <h2 class="text-2xl font-bold tracking-tight text-zinc-900 sm:text-3xl">Emails you receive from us</h2>
            <p class="mt-1 max-w-2xl text-sm leading-relaxed text-zinc-600">
                We only ever email you from the addresses below. If you receive a message about your
                account, your wallet or a payment from anywhere else, it did not come from us. Do not
                click its links, and
                <a href="mailto:{{ $supportEmail }}" class="font-semibold text-blue-700 underline underline-offset-4 hover:text-blue-800">report it to {{ $supportEmail }}</a>.
            </p>

            <div class="mt-8 grid grid-cols-1 gap-x-10 gap-y-6 sm:grid-cols-2">
                <div class="flex items-start gap-3">
                    <span class="mt-0.5 flex h-8 w-8 shrink-0 items-center justify-center rounded-[12px] bg-blue-50 text-blue-600">
                        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M21.75 6.75v10.5a2.25 2.25 0 01-2.25 2.25h-15a2.25 2.25 0 01-2.25-2.25V6.75m19.5 0A2.25 2.25 0 0019.5 4.5h-15a2.25 2.25 0 00-2.25 2.25m19.5 0v.243a2.25 2.25 0 01-1.07 1.916l-7.5 4.615a2.25 2.25 0 01-2.36 0L3.32 8.91a2.25 2.25 0 01-1.07-1.916V6.75"/>
                        </svg>
                    </span>
                    <div class="min-w-0">
                        <a href="mailto:{{ $supportEmail }}" class="break-all text-sm font-bold text-blue-700 hover:underline">{{ $supportEmail }}</a>
                        <p class="mt-0.5 text-sm leading-relaxed text-zinc-600">Orders, wallet, payments and anything else. Replies to any email we send reach this inbox.</p>
                    </div>
                </div>

                <div class="flex items-start gap-3">
                    <span class="mt-0.5 flex h-8 w-8 shrink-0 items-center justify-center rounded-[12px] bg-blue-50 text-blue-600">
                        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75L11.25 15 15 9.75M21 12c0 1.268-.63 2.39-1.593 3.068a3.745 3.745 0 01-1.043 3.296 3.745 3.745 0 01-3.296 1.043A3.745 3.745 0 0112 21c-1.268 0-2.39-.63-3.068-1.593a3.746 3.746 0 01-3.296-1.043 3.745 3.745 0 01-1.043-3.296A3.745 3.745 0 013 12c0-1.268.63-2.39 1.593-3.068a3.745 3.745 0 011.043-3.296 3.746 3.746 0 013.296-1.043A3.746 3.746 0 0112 3c1.268 0 2.39.63 3.068 1.593a3.746 3.746 0 013.296 1.043 3.746 3.746 0 011.043 3.296A3.745 3.745 0 0121 12z"/>
                        </svg>
                    </span>
                    <div class="min-w-0">
                        <p class="break-all text-sm font-bold text-zinc-900">{{ $complianceMailbox->senderAddress() }}</p>
                        <p class="mt-0.5 text-sm leading-relaxed text-zinc-600">{{ $complianceMailbox->customerDescription() }}</p>
                    </div>
                </div>
            </div>

            <p class="mt-8 rounded-[12px] bg-zinc-50 px-4 py-3 text-xs leading-relaxed text-zinc-600 ring-1 ring-zinc-100">
                We send some emails from other addresses on this domain, and replying to any of them
                reaches our support team at
                <a href="mailto:{{ $supportEmail }}" class="font-semibold text-blue-700 hover:underline">{{ $supportEmail }}</a>.
                Anything claiming to be us from a different domain is not.
                We will never ask for your password, your transaction PIN or a one-time code by email.
            </p>
        </div>
    </section>

</x-layouts.app.header>

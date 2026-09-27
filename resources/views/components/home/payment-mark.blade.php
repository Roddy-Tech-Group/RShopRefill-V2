{{--
    One payment-method brand mark, drawn inline.

    Inline <svg> rather than <img> on purpose: we own no logo asset for most of
    these, and the blanket dark-mode invert in app.css targets `img[src$=".svg"]`
    only, so inline artwork keeps its brand colours in both themes without
    needing an exception rule per mark. Neutral parts pair `text-zinc-*` with a
    `dark:` variant; brand hues are pinned with arbitrary values, because the
    global dark remaps in app.css rewrite the named `bg-zinc-*` / `text-blue-*`
    tokens.

    MoMo is the one exception, and it is an exception to the FIRST clause only:
    we do own that artwork, so it loads as an <img>. It is a PNG, which the
    dark-mode invert never matches, so it still needs no exception rule.

    The bank / USSD / QR / wallet marks are deliberately GENERIC. The customer
    picks their own bank, short code or wallet on the gateway's page, so naming
    one (a specific bank's USSD code, a specific wallet brand, a country) would
    advertise a rail most customers cannot use.

    $label is null for the marquee's visual clone, whose wrapper is aria-hidden.
--}}
@props(['mark', 'label' => null])

@switch($mark)
    @case('verve')
        <span class="flex items-center gap-1.5">
            <svg class="h-4 w-4 shrink-0" viewBox="0 0 16 16" aria-hidden="true">
                <path d="M8 0 A8 8 0 0 0 8 16 Z" fill="#EE312A"/>
                <path d="M8 0 A8 8 0 0 1 8 16 Z" fill="#F79E1B"/>
            </svg>
            <span class="text-[19px] font-extrabold italic leading-none text-[#00425F] dark:text-[#4FB6DC]">Verve</span>
        </span>
        @break

    @case('amex')
        <span class="rounded-[6px] bg-[#016FD0] px-2.5 py-2 text-[14px] font-extrabold leading-none tracking-wide text-white">AMEX</span>
        @break

    @case('discover')
        <span class="text-[14px] font-extrabold leading-none tracking-wide text-zinc-900 dark:text-white">DISC<span class="text-[#F76E20]">O</span>VER</span>
        @break

    @case('diners')
        <span class="flex items-center gap-2">
            <svg class="h-7 w-7 shrink-0" viewBox="0 0 32 32" aria-hidden="true">
                <circle cx="16" cy="16" r="15" fill="#0079BE"/>
                <circle cx="16" cy="16" r="10" fill="#ffffff"/>
                <rect x="13.5" y="6" width="5" height="20" fill="#0079BE"/>
            </svg>
            <span class="text-[12px] font-bold leading-[1.15] text-zinc-900 dark:text-white">Diners<br>Club</span>
        </span>
        @break

    @case('apple_pay')
        <span class="flex items-center gap-1.5 text-zinc-900 dark:text-white">
            <svg class="h-6 w-6 shrink-0" fill="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path d="M17.05 20.28c-.98.95-2.05.8-3.08.35-1.09-.46-2.09-.48-3.24 0-1.44.62-2.2.44-3.06-.35C2.79 15.25 3.51 7.59 9.05 7.31c1.35.07 2.29.74 3.08.8 1.18-.24 2.31-.93 3.57-.84 1.51.12 2.65.72 3.4 1.8-3.12 1.87-2.38 5.98.48 7.13-.57 1.5-1.31 2.99-2.54 4.09zM12.03 7.25c-.15-2.23 1.66-4.07 3.74-4.25.29 2.58-2.34 4.5-3.74 4.25z"/></svg>
            <span class="text-[22px] font-semibold leading-none tracking-tight">Pay</span>
        </span>
        @break

    @case('mtn_momo')
        {{-- MoMo, the real mark.

             The only <img> in this file, and the exception that proves the
             rule above rather than breaking it. Everything else here is drawn
             inline because we own no artwork for it; we do own this one, and a
             hand-drawn approximation of a brand mark is worse than the mark.

             A PNG specifically, not an SVG: the blanket dark-mode rule in
             app.css inverts img[src$=".svg"], which would turn MTN's yellow
             purple. A PNG never matches that selector, so the file needs no
             exception rule and no .no-dark-invert hatch.

             The art is 79x81 with fully transparent corners, so on its own it
             floated as a bare yellow shape with no edge, while Orange Money
             beside it is a solid block of colour. It gets a filled card of its
             own in #004f70, the MoMo tile, with the logo centred on it. The
             yellow needs a dark ground: on the white chip it was a pale shape
             on a pale field, which is the one thing a payment mark cannot be.

             No text beside it. This mark is the logo alone. Note that the
             assurance strip hides a span that FOLLOWS the mark, so a label
             here would be hidden there and visible in the full payment block,
             which is exactly the kind of quiet difference nobody intends.

             rawurlencode because the filename carries a space. --}}
        <span class="flex h-7 w-7 shrink-0 items-center justify-center bg-[#004f70]">
            {{-- Drawn at the full size of the card rather than inset in it.
                 The file carries its own margin - the ink covers 77% of the
                 canvas width and 67% of its height, with 15px of nothing above
                 it - so an image box smaller than the card renders a mark
                 smaller again, twice over. At the card's own size the visible
                 logo lands at about 21px, which is the margin the artwork was
                 drawn with. --}}
            <img
                src="{{ asset('assets/'.rawurlencode('MTN MOMO.png')) }}"
                alt=""
                class="h-7 w-7 object-contain"
                width="28"
                height="28"
                loading="lazy"
                decoding="async"
            >
        </span>
        @break

    @case('orange_money')
        <span class="flex items-center gap-2">
            <svg class="h-7 w-7 shrink-0" viewBox="0 0 30 30" aria-hidden="true">
                <rect width="30" height="30" fill="#FF7900"/>
                <rect x="4" y="22" width="22" height="4" fill="#ffffff"/>
            </svg>
            <span class="text-[13px] font-bold leading-[1.1] text-zinc-900 dark:text-white">OM</span>
        </span>
        @break

    @case('mpesa')
        <span class="rounded-[8px] bg-[#43B02A] px-2.5 py-2 text-[14px] font-extrabold italic leading-none text-white">M-PESA</span>
        @break

    @case('airtel_money')
        <span class="flex items-center gap-2">
            <svg class="h-7 w-7 shrink-0" viewBox="0 0 28 28" aria-hidden="true">
                <circle cx="14" cy="14" r="14" fill="#E40000"/>
                <path d="M14 5 A9 9 0 0 1 23 14 L19.5 14 A5.5 5.5 0 0 0 14 8.5 Z" fill="#ffffff"/>
            </svg>
            <span class="text-[13px] font-bold leading-[1.1] text-zinc-900 dark:text-white">airtel<br>money</span>
        </span>
        @break

    @case('bank')
        {{-- Bank transfer. No country flag: the rail is offered per settlement
             currency, not per country. --}}
        <span class="flex items-center gap-2 text-zinc-700 dark:text-zinc-200">
            <svg class="h-7 w-7 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                <path d="M3.25 9.5 12 4.25l8.75 5.25"/>
                <path d="M5.5 9.75v8M9.833 9.75v8M14.167 9.75v8M18.5 9.75v8"/>
                <path d="M3 20.25h18"/>
            </svg>
            <span class="text-[12px] font-bold leading-[1.15]">Bank<br>transfer</span>
        </span>
        @break

    @case('bank_online')
        {{-- Pay with bank: the customer signs in to their own internet or mobile
             banking. Drawn as the bank glyph inside a browser frame to separate
             it from a plain transfer, and with no region chip - the rail is
             offered for NGN as well as EUR/GBP. --}}
        <span class="flex items-center gap-2 text-zinc-700 dark:text-zinc-200">
            <svg class="h-7 w-7 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                <rect x="2.5" y="3.75" width="19" height="16.5" rx="2.5"/>
                <path d="M2.5 8.25h19"/>
                <path d="M5.5 5.9h.01M8 5.9h.01"/>
                <path d="M8 15.75 12 13.25l4 2.5"/>
                <path d="M9.25 16v1.75M12 16v1.75M14.75 16v1.75"/>
            </svg>
            <span class="text-[12px] font-bold leading-[1.15]">Pay with<br>bank</span>
        </span>
        @break

    @case('ussd')
        {{-- Generic short-code chip. NOT a specific bank's code (*737#, *894#
             and so on are bank-specific): Flutterwave's hosted USSD page lets
             the customer pick their own bank and issues that bank's code. --}}
        <span class="flex items-center gap-2">
            <svg class="h-7 w-7 shrink-0 text-zinc-700 dark:text-zinc-200" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                <rect x="4.25" y="2.25" width="15.5" height="19.5" rx="3"/>
                <path d="M8.5 7.5h.01M12 7.5h.01M15.5 7.5h.01M8.5 11h.01M12 11h.01M15.5 11h.01M8.5 14.5h.01M12 14.5h.01M15.5 14.5h.01" stroke-width="2.3"/>
                <path d="M10.25 18.5h3.5"/>
            </svg>
            <span class="text-[12px] font-bold leading-none text-zinc-700 dark:text-zinc-200">USSD</span>
        </span>
        @break

    @case('bank_qr')
        <span class="flex items-center gap-2">
            <svg class="h-7 w-7 shrink-0 text-zinc-700 dark:text-zinc-200" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                <rect x="3" y="3" width="7" height="7" rx="2"/>
                <rect x="14" y="3" width="7" height="7" rx="2"/>
                <rect x="3" y="14" width="7" height="7" rx="2"/>
                <path d="M6.5 6.5h.01M17.5 6.5h.01M6.5 17.5h.01" stroke-width="2.3"/>
                <path d="M14 14h3v3h-3z"/>
                <path d="M20.5 14h.01M14 20.5h.01M17.5 20.5h.01M20.5 17.5h.01M20.5 20.5h.01" stroke-width="2.3"/>
            </svg>
            <span class="text-[12px] font-bold leading-none text-zinc-700 dark:text-zinc-200">Bank QR</span>
        </span>
        @break

    @case('mobile_wallet')
        <span class="flex items-center gap-2 text-zinc-700 dark:text-zinc-200">
            <svg class="h-7 w-7 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                <path d="M3 8.75A2.75 2.75 0 0 1 5.75 6H18a3 3 0 0 1 3 3v7.5a3 3 0 0 1-3 3H6a3 3 0 0 1-3-3z"/>
                <path d="M3 8.75V7.5A2.5 2.5 0 0 1 5.5 5h9.75"/>
                <path d="M21 11.5h-3.25a1.75 1.75 0 0 0 0 3.5H21"/>
                <path d="M17.9 13.25h.01" stroke-width="2.3"/>
            </svg>
            <span class="text-[12px] font-bold leading-[1.15]">Mobile<br>wallet</span>
        </span>
        @break

    @default
        {{-- Unknown mark key: render a neutral card glyph plus the label rather
             than a blank 104px hole in the strip. Reachable only if a badge is
             added to PaymentStripBadges without a matching case here, which
             PaymentStripTest guards against. --}}
        <span class="flex items-center gap-2 text-zinc-700 dark:text-zinc-200">
            <svg class="h-6 w-6 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                <rect x="2.5" y="5" width="19" height="14" rx="2.5"/>
                <path d="M2.5 10h19" stroke-linecap="round"/>
            </svg>
            <span class="text-[12px] font-bold leading-[1.15]">{{ $label ?? 'Card' }}</span>
        </span>
@endswitch

{{-- Accessible name for the wordmark/glyph marks. The wrapper deliberately
     carries no `title`: on a role=generic div ARIA prohibits naming, it is
     pointer-only, and where browsers do expose it the badge gets announced
     twice. Null on the marquee's clone, whose wrapper is aria-hidden. --}}
@if ($label !== null)
    <span class="sr-only">{{ $label }}</span>
@endif

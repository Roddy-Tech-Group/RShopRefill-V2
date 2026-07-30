{{--
    One payment-method brand mark, drawn inline.

    Inline <svg> rather than <img> on purpose: we own no logo asset for these,
    and the blanket dark-mode invert in app.css targets `img[src$=".svg"]` only,
    so inline artwork keeps its brand colours in both themes without needing an
    exception rule per mark. Neutral parts pair `text-zinc-*` with a `dark:`
    variant; brand hues are pinned with arbitrary values, because the global dark
    remaps in app.css rewrite the named `bg-zinc-*` / `text-blue-*` tokens.

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

    @case('orange_money')
        <span class="flex items-center gap-2">
            <svg class="h-7 w-7 shrink-0" viewBox="0 0 30 30" aria-hidden="true">
                <rect width="30" height="30" fill="#FF7900"/>
                <rect x="4" y="22" width="22" height="4" fill="#ffffff"/>
            </svg>
            <span class="text-[13px] font-bold leading-[1.1] text-zinc-900 dark:text-white">Orange<br>Money</span>
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
            <svg class="h-7 w-7 shrink-0" viewBox="0 0 34 30" fill="currentColor" aria-hidden="true">
                <path d="M17 1 32 9 2 9 Z"/>
                <rect x="5" y="12" width="4" height="11"/>
                <rect x="12" y="12" width="4" height="11"/>
                <rect x="19" y="12" width="4" height="11"/>
                <rect x="26" y="12" width="4" height="11"/>
                <rect x="2" y="26" width="30" height="4"/>
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
            <svg class="h-7 w-7 shrink-0" viewBox="0 0 28 28" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                <rect x="2" y="4" width="24" height="20" rx="2.5"/>
                <path d="M2 9h24" stroke-linecap="round"/>
                <path d="M14 12 20 15 8 15 Z" fill="currentColor" stroke="none"/>
                <rect x="9.5" y="16.5" width="1.8" height="4" fill="currentColor" stroke="none"/>
                <rect x="13.1" y="16.5" width="1.8" height="4" fill="currentColor" stroke="none"/>
                <rect x="16.7" y="16.5" width="1.8" height="4" fill="currentColor" stroke="none"/>
            </svg>
            <span class="text-[12px] font-bold leading-[1.15]">Pay with<br>bank</span>
        </span>
        @break

    @case('ussd')
        {{-- Generic short-code chip. NOT a specific bank's code (*737#, *894#
             and so on are bank-specific): Flutterwave's hosted USSD page lets
             the customer pick their own bank and issues that bank's code. --}}
        <span class="flex items-center gap-2">
            <span class="rounded-[8px] bg-zinc-900 px-2.5 py-2 font-mono text-[15px] font-bold leading-none text-white dark:bg-white dark:text-zinc-900">*###</span>
            <span class="text-[12px] font-bold leading-none text-zinc-700 dark:text-zinc-200">USSD</span>
        </span>
        @break

    @case('bank_qr')
        <span class="flex items-center gap-2">
            <svg class="h-7 w-7 shrink-0" viewBox="0 0 30 30" aria-hidden="true">
                <rect x="1" y="1" width="11" height="11" rx="2" fill="none" stroke="#00AA4F" stroke-width="2.4"/>
                <rect x="4.5" y="4.5" width="4" height="4" fill="#00AA4F"/>
                <rect x="18" y="1" width="11" height="11" rx="2" fill="none" stroke="#00AA4F" stroke-width="2.4"/>
                <rect x="21.5" y="4.5" width="4" height="4" fill="#00AA4F"/>
                <rect x="1" y="18" width="11" height="11" rx="2" fill="none" stroke="#00AA4F" stroke-width="2.4"/>
                <rect x="4.5" y="21.5" width="4" height="4" fill="#00AA4F"/>
                <rect x="18" y="18" width="4" height="4" fill="#00AA4F"/>
                <rect x="25" y="18" width="4" height="4" fill="#00AA4F"/>
                <rect x="21.5" y="21.5" width="4" height="4" fill="#00AA4F"/>
                <rect x="18" y="25" width="4" height="4" fill="#00AA4F"/>
                <rect x="25" y="25" width="4" height="4" fill="#00AA4F"/>
            </svg>
            <span class="text-[13px] font-bold leading-none text-zinc-900 dark:text-white">Bank QR</span>
        </span>
        @break

    @case('mobile_wallet')
        <span class="flex items-center gap-2 text-zinc-700 dark:text-zinc-200">
            <svg class="h-7 w-7 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" aria-hidden="true">
                <rect x="6" y="2.5" width="12" height="19" rx="2.5"/>
                <path stroke-linecap="round" d="M10.5 18.5h3"/>
                <path stroke-linecap="round" stroke-linejoin="round" d="M9 8.5h6M9 12h4"/>
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

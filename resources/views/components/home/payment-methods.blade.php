{{--
    "Pay your way" strip under the category cards: every payment method the
    storefront accepts in one banner, with a centred triangle notch pointing
    up at the cards to give the strip its shape. Method brand marks only -
    never gateway/provider names on customer surfaces. Logos carry
    no-dark-invert so the coloured artwork survives the global dark-mode SVG
    invert; the crypto coins are already excepted in app.css.
--}}
@php
    $methods = [
        ['label' => 'Visa',         'icon' => 'visa.svg'],
        ['label' => 'Mastercard',   'icon' => 'mastercard.svg'],
        ['label' => 'Mobile Money', 'icon' => 'MOMO.svg'],
        ['label' => 'Bitcoin',      'icon' => 'BTC.svg'],
        ['label' => 'Ethereum',     'icon' => 'ETH.svg'],
        ['label' => 'USDT',         'icon' => 'USDT.svg'],
        ['label' => 'Solana',       'icon' => 'SOLANA.svg'],
        ['label' => 'Litecoin',     'icon' => 'LTC.svg'],
        ['label' => 'BNB',          'icon' => 'BNB.svg'],
    ];
@endphp
<section aria-label="Accepted payment methods" class="relative mt-10">
    {{-- Triangle pointer - notches up toward the category cards, matching the
         strip surface in both themes --}}
    <span aria-hidden="true" class="absolute -top-3 left-1/2 h-0 w-0 -translate-x-1/2 border-x-[14px] border-b-[13px] border-x-transparent border-b-white dark:border-b-black"></span>

    <div class="rounded-[35px] bg-white px-5 py-6 ring-1 ring-zinc-200/60 sm:px-8 dark:bg-black dark:ring-zinc-700/60">
        <p class="text-center text-xs font-bold uppercase tracking-[0.14em] text-zinc-500 dark:text-zinc-400">Pay your way</p>

        <ul class="mt-4 flex flex-wrap items-center justify-center gap-x-6 gap-y-4 sm:gap-x-8">
            @foreach ($methods as $method)
                <li class="flex items-center gap-2" title="{{ $method['label'] }}">
                    <img
                        src="{{ asset('assets/' . rawurlencode($method['icon'])) }}"
                        alt="{{ $method['label'] }}"
                        class="no-dark-invert h-7 w-auto object-contain sm:h-8"
                        loading="lazy"
                    >
                    <span class="hidden text-sm font-semibold text-zinc-700 lg:block dark:text-zinc-200">{{ $method['label'] }}</span>
                </li>
            @endforeach

            {{-- Apple Pay has no logo asset yet - inline glyph + label --}}
            <li class="flex items-center gap-1.5" title="Apple Pay">
                <svg class="h-6 w-6 shrink-0 text-zinc-900 dark:text-white" fill="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path d="M17.05 20.28c-.98.95-2.05.8-3.08.35-1.09-.46-2.09-.48-3.24 0-1.44.62-2.2.44-3.06-.35C2.79 15.25 3.51 7.59 9.05 7.31c1.35.07 2.29.74 3.08.8 1.18-.24 2.31-.93 3.57-.84 1.51.12 2.65.72 3.4 1.8-3.12 1.87-2.38 5.98.48 7.13-.57 1.5-1.31 2.99-2.54 4.09zM12.03 7.25c-.15-2.23 1.66-4.07 3.74-4.25.29 2.58-2.34 4.5-3.74 4.25z"/></svg>
                <span class="text-sm font-semibold text-zinc-900 dark:text-white">Pay</span>
            </li>
        </ul>
    </div>
</section>

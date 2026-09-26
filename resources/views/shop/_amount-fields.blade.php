{{--
    The Amount field with everything that prices it: a gift card's grid of values,
    the other kinds' amount dropdown, the custom amount input, and then either the
    pay currency (gift cards, which hold quantity and the price in their purchase
    card) or the quantity and estimated price fields.

    One copy: the buy panel draws it for top-ups and bills, and a gift card draws it
    in its buy rail, under the purchase card. Reads the product page's variables and
    its root Alpine state (amount, selectedVariantId, customMode, quantity, ttab).
--}}
@php
    // A refill carries its own amount, so buying "two" of it is just a larger
    // amount typed twice. Top-ups drop the quantity field and send 1, which is
    // what the Alpine state already defaults to, so nothing downstream changes.
    $showQuantity = ! $isGiftCard && ! $isTopup;

    // The credit row's columns follow that: Amount + Quantity + Estimated price,
    // or Amount + Estimated price when the quantity field is gone. Amount takes
    // its own row on a phone either way.
    $creditCols = $showQuantity
        ? 'grid-cols-2 sm:grid-cols-[1fr_auto_auto]'
        : 'grid-cols-1 sm:grid-cols-[1fr_auto]';
@endphp

{{-- Amount / Quantity / Estimated price. Mobile: Amount on its own full
     row, Quantity + Estimated price share a 2-col row. sm+: one row. --}}
<div class="grid gap-3 {{ $isGiftCard ? 'max-w-[400px] grid-cols-1' : (! ($isTopup && $topupHasPlans) ? $creditCols : '') }}"
    @if ($isTopup && $topupHasPlans) :class="ttab === 'credit' ? '{{ $creditCols }}' : 'grid-cols-[auto_1fr]'" @endif>

    {{-- Amount field — plain typeable input with $ prefix. Suggested denominations
         render as small clickable chips below so users can one-tap a common value. --}}
    {{-- col-span-full, not col-span-2: without the quantity field the phone grid
         is a single column, and spanning two of them would open an implicit
         second column and pull the row out of shape. --}}
    <div class="{{ $isGiftCard ? '' : 'col-span-full sm:col-span-1' }}" @if ($isTopup && $topupHasPlans) x-show="ttab === 'credit'" x-cloak @endif>
        {{-- A gift card's tiles say what they are on their face, so they carry no
             label; the other kinds' amount field still needs one. --}}
        @unless ($isGiftCard)
            <label class="mb-2 block text-sm font-semibold text-zinc-900">{{ $isTopup ? __('products.detail_credit_amount') : __('products.detail_amount') }}</label>
        @endunless
        @if ($isGiftCard)
            {{-- Gift cards choose from a standing grid, not a dropdown: every
                 value is on the page with what it costs beneath it, largest
                 first. The figure under each value is the cart engine's own
                 unit price (the same call the plan cards use), so a tile can
                 never promise a price the checkout contradicts. Selection is
                 carried by the variant id, which is also what prices it. Small
                 square tiles, four to a row, beside the art. --}}
            @php
                // A card with a handful of values shows them all; the catalogue
                // also carries cards with twenty, and twenty squares is a screen
                // and a half of scrolling on a phone before the button that buys
                // them. Past four, the phone gets the chosen value and its price
                // on one row, and the grid moves into a sheet behind it. Four or
                // fewer is already short enough to leave alone, and no width
                // above sm ever hides the grid.
                $denomCount = $fixedDenoms->count() + ($variable ? 1 : 0);
                $denomFolds = $denomCount > 4;

                // What the summary row calls the chosen value. Built here rather
                // than read off the shared variants payload, which carries no
                // currency: a tile takes the variant's own code where it has one.
                $denomSummaries = [];
                foreach ($fixedDenoms as $denomVariant) {
                    $denomSummaryFace = (float) ($denomVariant->face_value ?? $denomVariant->retail_price);
                    $denomSummaries[$denomVariant->id] = rtrim(rtrim(number_format($denomSummaryFace, 2, '.', ','), '0'), '.')
                        .' '.strtoupper($denomVariant->currency ?: $currency);
                }
            @endphp

            @if ($denomFolds)
            {{-- Closed is the default, so a long card never paints its sheet
                 open on the way in, and the open state is one attribute. Above
                 sm none of this applies and the element is just the div holding
                 the grid, which is why the desktop needs no second copy of the
                 tiles and no Alpine at all. --}}
            <style>
                @media (max-width: 639.98px) {
                    .denom-sheet {
                        position: fixed;
                        left: 0;
                        right: 0;
                        bottom: 0;
                        z-index: 71;
                        max-height: 82vh;
                        overflow-y: auto;
                        overscroll-behavior: contain;
                        -webkit-overflow-scrolling: touch;
                        border-radius: 24px 24px 0 0;
                        background-color: #ffffff;
                        padding: 1rem 1rem max(1.25rem, env(safe-area-inset-bottom));
                        box-shadow: 0 -8px 40px rgba(15, 23, 42, .25);
                        transform: translateY(100%);
                        visibility: hidden;
                        transition: transform .3s cubic-bezier(.22, 1, .36, 1), visibility .3s;
                    }
                    .denom-sheet[data-open="true"] {
                        transform: translateY(0);
                        visibility: visible;
                    }
                    html.dark .denom-sheet {
                        background-color: #101011;
                        box-shadow: 0 -8px 40px rgba(0, 0, 0, .55);
                    }
                }
                @media (prefers-reduced-motion: reduce) {
                    .denom-sheet { transition: none; }
                }
            </style>

                {{-- The chosen value and what it costs, on one row, with the
                     grid one tap away. Phone only: from sm the grid itself is
                     on the page and a row summarising it would be noise. --}}
                <button
                    type="button"
                    @click="denomSheet = true"
                    class="pure-card flex w-full items-center gap-3 rounded-[12px] border border-zinc-200 bg-white px-4 py-3 text-left transition-colors hover:border-blue-300 focus:outline-none focus-visible:ring-2 focus-visible:ring-blue-500/40 sm:hidden dark:border-white/10 dark:bg-white/5"
                    :aria-label="(selectedVariantId ? @js(__('products.detail_change_amount')) : @js(__('products.detail_select_amount')))"
                >
                    <span class="min-w-0 flex-1">
                        <span
                            class="block truncate text-sm font-semibold text-zinc-600 dark:text-zinc-400"
                            {{-- The map is written into the expression rather than
                                 kept on the component: it is built right here from the
                                 same variants the tiles use, and a name that only this
                                 one row reads does not belong in the page state. --}}
                            x-text="customMode
                                ? @js(__('products.detail_custom_amount'))
                                : (@js($denomSummaries)[selectedVariantId] ?? @js(__('products.detail_select_amount')))"
                        >{{ __('products.detail_select_amount') }}</span>

                        {{-- The price only once there is something to price. --}}
                        <span
                            x-show="selectedVariantId"
                            x-cloak
                            x-data="valueFlip()"
                            x-effect="selectedVariantId; amount; selectedCrypto; flash()"
                            class="mt-0.5 block truncate text-lg font-bold tabular-nums text-zinc-900 dark:text-white"
                            x-text="formatUsdIn(selectedCrypto, unitPriceUsd())"
                        ></span>
                    </span>

                    {{-- A pencil, because the row is a thing already chosen that
                         can be chosen again, not a field waiting to be filled. --}}
                    <svg class="h-5 w-5 shrink-0 text-blue-600 dark:text-blue-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M16.862 4.487l1.687-1.688a1.875 1.875 0 112.652 2.652L10.582 16.07a4.5 4.5 0 01-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 011.13-1.897l8.932-8.931zM19.5 14.25v4.5A2.25 2.25 0 0117.25 21H5.25A2.25 2.25 0 013 18.75V6.75A2.25 2.25 0 015.25 4.5h4.5"/>
                    </svg>
                </button>

                {{-- The dimmer behind the sheet, phone only. --}}
                <div
                    x-show="denomSheet"
                    x-cloak
                    x-transition.opacity
                    @click="denomSheet = false"
                    class="fixed inset-0 z-[70] bg-zinc-900/50 backdrop-blur-[2px] sm:hidden"
                    aria-hidden="true"
                ></div>
            @endif

            {{-- The grid. On a phone of a long card it is the sheet: parked below
                 the fold and slid up. From sm every one of those rules is undone
                 and it is simply the grid on the page, which is why the desktop
                 needs no second copy of the tiles. --}}
            <div
                @if ($denomFolds)
                    class="denom-sheet"
                    :data-open="denomSheet ? 'true' : 'false'"
                    x-effect="denomSheet ? window.rshopScrollLock?.lock() : window.rshopScrollLock?.unlock()"
                    @keydown.escape.window="denomSheet = false"
                    role="dialog"
                    aria-modal="true"
                    aria-label="{{ __('products.detail_amount') }}"
                @endif
            >
                @if ($denomFolds)
                    {{-- The sheet's own head: a handle to say it can be dragged
                         away, the heading, and a close for anyone who would
                         rather press than dismiss. All of it gone from sm. --}}
                    <div class="sm:hidden">
                        <span class="mx-auto mb-3 block h-1 w-10 rounded-full bg-zinc-300 dark:bg-white/20" aria-hidden="true"></span>
                        <div class="mb-3 flex items-center justify-between gap-3">
                            <h2 class="text-base font-bold text-zinc-900 dark:text-white">{{ __('products.detail_amount') }}</h2>
                            <button
                                type="button"
                                @click="denomSheet = false"
                                class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-zinc-100 text-zinc-600 transition-colors hover:bg-zinc-200 focus:outline-none focus-visible:ring-2 focus-visible:ring-blue-500/40 dark:bg-white/10 dark:text-zinc-300"
                                aria-label="{{ __('common.close') }}"
                            >
                                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5" aria-hidden="true">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/>
                                </svg>
                            </button>
                        </div>
                    </div>
                @endif

                @include('shop._denomination-tiles')
            </div>
        @else
        <div
            x-data="{ open: false, locked: false }"
            @mouseenter="if (window.matchMedia('(hover: hover)').matches) open = true"
            @mouseleave="if (window.matchMedia('(hover: hover)').matches && ! locked) open = false"
            @click.outside="open = false; locked = false"
            class="relative"
        >
            <button
                type="button"
                @click="open = ! open; locked = open"
                {{-- Grey until it is opened, then the interactive edge, and grey
                     again on a click outside (which @click.outside already
                     clears). Swapped as whole classes rather than through a
                     focus variant: the storefront pins .border-zinc-200 to the
                     shared edge colour at a specificity no variant can reach,
                     so the blue only lands once that class is off the element. --}}
                :class="open
                    ? 'border-edge ring-2 ring-edge/30 dark:border-edge'
                    : 'border-zinc-200 dark:border-white/10'"
                class="pure-card flex h-11 w-full items-center gap-2 rounded-full border-2 bg-[#eff6ff] px-4 text-base font-bold text-zinc-900 transition-colors"
            >
                <span class="font-semibold text-zinc-600">{{ $variable ? $customSymbol : $displaySymbol }}</span>
                <span
                    x-data="valueFlip()"
                    x-effect="selectedVariantId; customMode; flash()"
                    class="flex-1 truncate text-left tabular-nums"
                    :class="amount ? 'text-zinc-900' : 'font-medium text-zinc-500'"
                    x-text="amount ? amount : @js(__('products.detail_select_amount'))"
                >{{ __('products.detail_select_amount') }}</span>
                {{-- Clear the selected denomination. @click.stop so it doesn't toggle the dropdown. --}}
                <span
                    x-show="selectedVariantId || amount"
                    @click.stop="amount = ''; selectedVariantId = null; customMode = false; open = false"
                    @keydown.enter.stop="amount = ''; selectedVariantId = null; customMode = false; open = false"
                    role="button"
                    tabindex="0"
                    aria-label="{{ __('products.detail_clear_amount') }}"
                    class="flex h-5 w-5 shrink-0 items-center justify-center rounded-[12px] bg-zinc-200 transition-colors hover:bg-zinc-300"
                >
                    <img src="{{ asset('assets/' . rawurlencode('x button.webp')) }}" alt="" class="h-3.5 w-3.5 object-contain" loading="lazy">
                </span>
                <svg class="h-4 w-4 shrink-0 text-zinc-600 transition-transform duration-150" :class="{ 'rotate-180': open }" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7" />
                </svg>
            </button>

            <div
                x-show="open"
                x-transition:enter="transition ease-out duration-150"
                x-transition:enter-start="opacity-0 -translate-y-1"
                x-transition:enter-end="opacity-100 translate-y-0"
                style="display:none;"
                class="absolute left-0 right-0 top-full z-20 max-h-[27rem] overflow-y-auto rounded-[12px] border border-zinc-200 bg-[#eff6ff] pure-card p-1 shadow-xl shadow-zinc-900/10 [scrollbar-width:none] [&::-webkit-scrollbar]:hidden"
                role="listbox"
            >
                @if ($isTopup)
                    {{-- Credit selector: airtime-credit denominations only (Data/Bundle
                         are chosen from their cards above). Labeled by FACE VALUE (the
                         $35 the listing advertises and the carrier prints), not the
                         charged price - the charge shows in Estimated price, mirroring
                         the gift-card flow. Selection is carried by the variant id, so
                         the card and the cart still can never disagree. --}}
                    @foreach ($topupPicks as $pick)
                        @if (isset($pick['plan']))
                            @php $plan = $pick['plan']; @endphp
                            <button
                                type="button"
                                @click="amount = {{ $plan['face'] }}; selectedVariantId = {{ $plan['id'] }}; customMode = false; open = false"
                                :class="(! customMode && selectedVariantId === {{ $plan['id'] }}) ? 'bg-blue-50 text-blue-700' : 'text-zinc-800 hover:bg-zinc-200'"
                                class="flex w-full items-center justify-between rounded-full px-3 py-2.5 text-left text-base font-medium tabular-nums transition-colors"
                            >
                                <span class="flex min-w-0 flex-col">
                                    <span class="font-bold">{{ $conv($plan['face']) }}</span>
                                    @php $pickNote = trim((string) ($plan['summary'] ?? '')); @endphp
                                    @if ($pickNote !== '')
                                        <span class="mt-0.5 truncate text-xs font-normal text-zinc-500 dark:text-zinc-400">{{ $pickNote }}</span>
                                    @endif
                                </span>
                            </button>
                        @else
                            {{-- Synthesized round amount served through the custom-amount
                                 variant - this slot has no clean face in the catalog. --}}
                            <button
                                type="button"
                                @click="customMode = true; selectedVariantId = {{ $variable->id }}; amount = {{ $pick['amount'] }}; open = false"
                                :class="(customMode && Number(amount) === {{ $pick['amount'] }}) ? 'bg-blue-50 text-blue-700' : 'text-zinc-800 hover:bg-zinc-200'"
                                class="flex w-full items-center justify-between rounded-full px-3 py-2.5 text-left text-base font-medium tabular-nums transition-colors"
                            >
                                <span class="font-bold">{{ $conv($pick['amount']) }}</span>
                            </button>
                        @endif
                    @endforeach
                    @foreach ($topupCreditPlans as $plan)
                        <button
                            type="button"
                            @click="amount = {{ $plan['face'] }}; selectedVariantId = {{ $plan['id'] }}; customMode = false; open = false"
                            :class="(! customMode && selectedVariantId === {{ $plan['id'] }}) ? 'bg-blue-50 text-blue-700' : 'text-zinc-800 hover:bg-zinc-200'"
                            class="flex w-full items-center justify-between rounded-full px-3 py-2.5 text-left text-base font-medium tabular-nums transition-colors"
                        >
                            <span class="flex min-w-0 flex-col">
                                <span class="font-bold">{{ $conv($plan['face']) }}</span>
                                @php $creditNote = trim((string) ($plan['summary'] ?? '')); @endphp
                                @if ($creditNote !== '')
                                    {{-- The supplier's own description, verbatim. Deliberately NOT
                                         chips derived from dataGB / dataUnlimited: those fields are
                                         populated on plain credit offers too, so rendering them here
                                         would advertise data the carrier does not actually sell. --}}
                                    <span class="mt-0.5 truncate text-xs font-normal text-zinc-500 dark:text-zinc-400">{{ $creditNote }}</span>
                                @endif
                            </span>
                        </button>
                    @endforeach
                    @if ($variable)
                        <button
                            type="button"
                            @click="customMode = true; selectedVariantId = {{ $variable->id }}; amount = ''; open = false"
                            :class="customMode ? 'bg-blue-50 text-blue-700' : 'text-zinc-800 hover:bg-zinc-200'"
                            class="flex w-full items-center justify-between rounded-full {{ $topupPicks->isNotEmpty() ? 'border-t border-zinc-100' : '' }} px-3 py-2.5 text-left text-base font-medium transition-colors"
                        >
                            <span class="font-bold">{{ __('products.detail_custom_amount') }}</span>
                            <span class="text-xs text-zinc-500">{{ $customMoney($customDisplayMin) }} - {{ $customMoney($customDisplayMax) }}</span>
                        </button>
                    @endif
                @else
                @foreach ($fixedDenoms as $i => $v)
                    @php
                        $val  = (float) $v->retail_price;
                        $disp = round($val * $displayRate, 2); // value in the display currency
                    @endphp
                    <button
                        type="button"
                        @click="amount = {{ $disp }}; selectedVariantId = {{ $v->id }}; customMode = false; open = false"
                        :class="(! customMode && Number(amount) === {{ $disp }}) ? 'bg-blue-50 text-blue-700' : 'text-zinc-800 hover:bg-zinc-200'"
                        class="flex w-full items-center justify-between rounded-full px-3 py-2.5 text-left text-base font-medium tabular-nums transition-colors"
                    >
                        <span class="font-bold">{{ $conv($val) }}</span>
                        @if ($i === $fixedDenoms->count() - 1 && $fixedDenoms->count() > 1)
                            <span class="inline-flex items-center rounded-[12px] bg-white/60 px-2.5 py-0.5 text-[10px] font-bold uppercase tracking-wide text-blue-700 ring-1 ring-blue-300/50 shadow-[0_0_10px_rgba(37,99,235,0.45)] backdrop-blur-md dark:bg-blue-950 dark:ring-blue-400/50">{{ __('products.detail_popular') }}</span>
                        @endif
                    </button>
                @endforeach
                @if ($variable)
                    <button
                        type="button"
                        @click="customMode = true; selectedVariantId = {{ $variable->id }}; amount = ''; open = false"
                        :class="customMode ? 'bg-blue-50 text-blue-700' : 'text-zinc-800 hover:bg-zinc-200'"
                        class="flex w-full items-center justify-between rounded-full {{ $fixedDenoms->isNotEmpty() ? 'border-t border-zinc-100' : '' }} px-3 py-2.5 text-left text-base font-medium transition-colors"
                    >
                        <span class="font-bold">{{ __('products.detail_custom_amount') }}</span>
                        <span class="text-xs text-zinc-500">{{ $customMoney($customDisplayMin) }} - {{ $customMoney($customDisplayMax) }}</span>
                    </button>
                @endif
                @endif
            </div>
        </div>
        @endif

        @if ($variable)
            {{-- Custom-amount input. The amount is entered in the variant's own
                 currency ({{ $customCurrency }} for this product). A value outside the
                 min-max range is rejected: the error shows and the buy buttons
                 disable via canAddToCart(). --}}
            <div x-show="customMode" x-transition class="mt-2">
                <div class="relative">
                    <span class="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-base font-semibold text-zinc-600">{{ $customSymbol }}</span>
                    <input
                        type="number"
                        inputmode="decimal"
                        x-model="amount"
                        min="{{ (float) $customDisplayMin }}"
                        max="{{ (float) $customDisplayMax }}"
                        step="any"
                        placeholder="{{ rtrim(rtrim(number_format((float) $customDisplayMin, 2), '0'), '.') }} - {{ rtrim(rtrim(number_format((float) $customDisplayMax, 2), '0'), '.') }}"
                        class="w-full rounded-full border-2 bg-[#eff6ff] py-2.5 pl-10 pr-3 text-base font-bold tabular-nums text-zinc-900 outline-none transition-colors focus:ring-2 focus:ring-blue-500/15 border-zinc-200 dark:border-white/10 focus:border-edge dark:focus:border-edge"
                        :class="(amount !== '' && (Number(amount) < customMin || Number(amount) > customMax)) ? 'border-red-400 focus:border-red-500' : 'border-zinc-200 focus:border-[color:var(--brand)]'"
                    />
                </div>
                <p
                    x-show="amount !== '' && (Number(amount) < customMin || Number(amount) > customMax)"
                    x-cloak
                    class="mt-1.5 text-xs font-medium text-red-600"
                >
                    {{ __('products.detail_amount_out_of_range', ['min' => $customMoney($customDisplayMin), 'max' => $customMoney($customDisplayMax)]) }}
                </p>
            </div>
        @endif

        {{-- A gift card with fixed values only shows every value and its
             price as a tile, so a range line would say nothing new. --}}
        @if ($rangeMin !== null && $rangeMax !== null && ! ($isGiftCard && ! $variable))
            <p class="mt-2.5 text-sm text-zinc-600">
                {{ __('products.detail_range_hint', ['min' => $variable ? $customMoney($rangeMin) : $conv($rangeMin), 'max' => $variable ? $customMoney($rangeMax) : $conv($rangeMax)]) }}
            </p>
        @endif
    </div>

    {{-- Gift cards hold the quantity, the pay currency and the price in their
         purchase card instead: here they are only values. --}}
    @unless ($isGiftCard)
    @if ($showQuantity)
    {{-- Quantity — custom dropdown so it visually matches the Amount + Estimated price fields. --}}
    <div class="sm:min-w-[6rem]">
        <label class="mb-2 block text-sm font-semibold text-zinc-900">{{ __('products.detail_quantity') }}</label>
        <div
            x-data="{ open: false, locked: false }"
            @mouseenter="if (window.matchMedia('(hover: hover)').matches) open = true"
            @mouseleave="if (window.matchMedia('(hover: hover)').matches && ! locked) open = false"
            @click.outside="open = false; locked = false"
            class="relative"
        >
            <button
                type="button"
                @click="open = ! open; locked = open"
                :class="open ? 'border-[color:var(--brand)] ring-2 ring-blue-500/15' : 'border-zinc-200 hover:border-blue-300 dark:border-zinc-700 dark:hover:border-blue-400/40'"
                class="pure-card flex h-[50px] w-full items-center gap-2 rounded-[12px] border bg-[#eff6ff] px-3 text-base font-bold text-zinc-900 transition-colors"
            >
                <span x-data="valueFlip()" x-effect="quantity; flash()" class="flex-1 text-left tabular-nums" x-text="quantity">1</span>
                <svg class="h-4 w-4 shrink-0 text-zinc-600 transition-transform duration-150" :class="{ 'rotate-180': open }" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7" />
                </svg>
            </button>

            <div
                x-show="open"
                x-transition:enter="transition ease-out duration-150"
                x-transition:enter-start="opacity-0 -translate-y-1"
                x-transition:enter-end="opacity-100 translate-y-0"
                style="display:none;"
                class="absolute left-0 right-0 top-full z-20 max-h-60 overflow-y-auto rounded-[12px] border border-zinc-200 bg-[#eff6ff] pure-card p-1 shadow-xl shadow-zinc-900/10 [scrollbar-width:none] [&::-webkit-scrollbar]:hidden"
                role="listbox"
            >
                @for ($n = 1; $n <= 10; $n++)
                    <button
                        type="button"
                        @click="quantity = {{ $n }}; open = false"
                        :class="quantity === {{ $n }} ? 'bg-blue-50 text-blue-700' : 'text-zinc-800 hover:bg-zinc-200'"
                        class="flex w-full items-center justify-center rounded-full px-2 py-2 text-center text-base font-medium tabular-nums transition-colors"
                    >
                        {{ $n }}
                    </button>
                @endfor
            </div>
        </div>
    </div>

    @endif

    {{-- Estimated price — crypto currency selector. Each option shows the equivalent
         in that crypto using a placeholder rate (rate table lives in Alpine state). --}}
    @include('shop._pay-currency-picker')
    @endunless
</div>

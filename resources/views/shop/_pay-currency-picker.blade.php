{{--
    The pay-currency picker: a button showing the running total in the chosen
    currency, opening the list of every currency the customer can pay in. It is
    also the only control that changes `selectedCrypto`, so wherever the price
    is shown, this goes with it. Lifted out of shop/product so the gift card
    purchase card and the top-up / bill panel share one copy.

    Reads the page root's Alpine state (cryptos, selectedCrypto, formatCrypto,
    formatCryptoFor). Pass ['pickerLabel' => false] to drop its own label,
    ['pickerLabelText' => '...'] to word it differently, and ['pickerCodeOnly' => true]
    where the total is already printed beside it, so the button names the currency
    instead of repeating the figure.
--}}
<div class="sm:min-w-[9rem]">
    @if ($pickerLabel ?? true)
    <label class="mb-1.5 block text-xs font-semibold text-zinc-900 dark:text-zinc-200">{{ $pickerLabelText ?? __('products.detail_estimated_price') }}</label>
    @endif
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
            <template x-if="cryptos[selectedCrypto]?.icon">
                <img :src="cryptos[selectedCrypto].icon" :alt="selectedCrypto" class="h-5 w-5 shrink-0 rounded-[12px]">
            </template>
            <template x-if="!cryptos[selectedCrypto]?.icon && cryptos[selectedCrypto]?.flag">
                <img :src="cryptos[selectedCrypto].flag" :alt="selectedCrypto" class="h-5 w-5 shrink-0 rounded-[12px] object-cover ring-1 ring-zinc-200">
            </template>
            <template x-if="!cryptos[selectedCrypto]?.icon && !cryptos[selectedCrypto]?.flag">
                <span class="inline-flex h-5 w-5 shrink-0 items-center justify-center rounded-[12px] text-[10px] font-black text-white" :class="cryptos[selectedCrypto]?.type === 'crypto' ? 'bg-amber-500' : 'bg-emerald-500'" x-text="selectedCrypto.charAt(0)"></span>
            </template>
            @if ($pickerCodeOnly ?? false)
                {{-- The purchase card prints the total large above this button, so
                     here the button names the currency and nothing else. --}}
                <span class="flex-1 truncate text-left" x-text="(cryptos[selectedCrypto]?.name || selectedCrypto) + ' (' + selectedCrypto + ')'">USD</span>
            @else
            <span x-data="valueFlip()" x-effect="selectedVariantId; quantity; selectedCrypto; flash()" class="flex-1 truncate text-left tabular-nums" x-text="formatCrypto()">0.00 USDT</span>
            @endif
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
            class="absolute right-0 top-full z-20 max-h-80 w-56 overflow-y-auto rounded-[12px] border border-zinc-200 bg-[#eff6ff] pure-card p-1 shadow-xl shadow-zinc-900/10 [scrollbar-width:none] [&::-webkit-scrollbar]:hidden"
            role="listbox"
        >
            <template x-for="(meta, code) in cryptos" :key="code">
                <button
                    type="button"
                    @click="selectedCrypto = code; open = false"
                    :class="selectedCrypto === code ? 'bg-blue-50 text-blue-700' : 'text-zinc-800 hover:bg-zinc-200'"
                    class="flex w-full items-center gap-2 rounded-[12px] px-3 py-2.5 text-left text-sm font-medium transition-colors"
                >
                    <template x-if="meta.icon">
                        <img :src="meta.icon" :alt="code" class="h-5 w-5 shrink-0 rounded-[12px]">
                    </template>
                    <template x-if="!meta.icon && meta.flag">
                        <img :src="meta.flag" :alt="code" class="h-5 w-5 shrink-0 rounded-[12px] object-cover ring-1 ring-zinc-200">
                    </template>
                    <template x-if="!meta.icon && !meta.flag">
                        <span class="inline-flex h-5 w-5 shrink-0 items-center justify-center rounded-[12px] text-[10px] font-black text-white" :class="meta.type === 'crypto' ? 'bg-amber-500' : 'bg-emerald-500'" x-text="code.charAt(0)"></span>
                    </template>
                    <span class="flex-1 truncate text-left tabular-nums" x-text="formatCryptoFor(code)"></span>
                    <svg x-show="selectedCrypto === code" class="h-4 w-4 shrink-0 text-blue-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5"/>
                    </svg>
                </button>
            </template>
        </div>
    </div>
</div>

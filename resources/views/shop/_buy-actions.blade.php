{{--
    The buy actions: the points line, the stock notice, and the Add to cart and
    Buy now buttons. One copy, included by the buy panel (top-ups, bills) and by
    the gift card's purchase card under the art.

    Reads the product page's variables ($product, $productBuyable, $isGiftCard)
    and its root Alpine state (rcoinConfig, pointsEarned, canAddToCart, addToCart,
    buyNow, cartState).
--}}
{{-- Points you earn - calculated via backend rates (USD spent x cashback % / Rcoin USD rate). Coin icon is the site favicon. Hidden when the Rcoin engine is off. --}}
<p x-show="rcoinConfig.enabled" class="my-3 flex items-center gap-1.5 text-sm font-semibold text-zinc-700">
    {{ __('products.detail_points_you_earn') }}
    <img src="{{ asset('assets/favicon.ico') }}" alt="{{ __('products.detail_points_icon_alt') }}" class="h-6 w-6 object-contain" loading="lazy">
    <span x-data="valueFlip()" x-effect="selectedVariantId; quantity; flash()" class="inline-block text-zinc-900" x-text="pointsEarned()">0</span>
</p>

{{-- Feature A/B notice: the product stays fully visible but the buy
     controls below are disabled (and the purchase is blocked server-side)
     when out of stock or when its category feature is switched off. --}}
@unless ($productBuyable)
    <div class="mb-3 flex items-start gap-3 rounded-[12px] border px-4 py-3 {{ $product->is_out_of_stock ? 'border-zinc-200 bg-zinc-50 dark:border-zinc-700 dark:bg-white/5' : 'border-amber-200 bg-amber-50 dark:border-amber-500/20 dark:bg-amber-500/10' }}">
        <svg class="mt-0.5 h-5 w-5 shrink-0 {{ $product->is_out_of_stock ? 'text-zinc-400' : 'text-amber-500' }}" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75" aria-hidden="true">
            <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m9-.75a9 9 0 11-18 0 9 9 0 0118 0zm-9 3.75h.008v.008H12v-.008z"/>
        </svg>
        <div>
            <p class="text-sm font-semibold {{ $product->is_out_of_stock ? 'text-zinc-900 dark:text-white' : 'text-amber-900 dark:text-amber-200' }}">{{ $product->is_out_of_stock ? __('products.out_of_stock') : __('products.temporarily_unavailable') }}</p>
            <p class="mt-0.5 text-xs {{ $product->is_out_of_stock ? 'text-zinc-500 dark:text-zinc-400' : 'text-amber-800/80 dark:text-amber-200/70' }}">{{ $product->is_out_of_stock ? __('products.detail_out_of_stock_notice') : __('products.detail_paused_notice') }}</p>
        </div>
    </div>
@endunless

{{-- Add to cart + Buy now, brand blue. Side by side in the wide panel as an
     outline and a filled button; in the gift card's purchase card they stack as
     short pills, a filled primary and a quiet secondary, with a cart mark on
     Add to cart.
     addToCart() pushes the selected variant into the global cart store, which
     drops the nav cart popup open. Buy now also routes to checkout. --}}
<div class="grid {{ $isGiftCard ? 'grid-cols-1' : 'grid-cols-2' }} gap-3">
    {{-- Add to cart — morphs label -> spinner -> checkmark with a success bounce.
         Re-clicks during the spinner/success cue are ignored by addToCart(). --}}
    <button
        type="button"
        @click="addToCart()"
        :disabled="! canAddToCart()"
        :class="cartState === 'success'
            ? 'border-emerald-500 bg-emerald-500 text-white animate-cart-pop'
            : '{{ $isGiftCard ? 'border-blue-600 bg-blue-600 text-white hover:bg-blue-700 hover:border-blue-700' : 'border-blue-600 bg-white text-blue-600 hover:bg-blue-600 hover:text-white' }}'"
        class="relative flex items-center justify-center border-2 font-semibold transition-colors duration-200 focus:outline-none focus-visible:ring-2 focus-visible:ring-blue-500/40 disabled:cursor-not-allowed disabled:opacity-50 {{ $isGiftCard ? 'h-11 rounded-full px-4 text-sm' : 'h-[52px] rounded-full px-4 text-base' }}"
    >
        <span
            x-show="cartState === 'idle'"
            x-transition:enter="transition ease-out duration-150"
            x-transition:enter-start="opacity-0"
            x-transition:leave="transition ease-in duration-100"
            x-transition:leave-end="opacity-0"
            class="absolute inset-0 flex items-center justify-center gap-2"
        >
            @if ($isGiftCard)
                <svg class="h-4 w-4 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                    <path d="M2.25 3h1.386a.75.75 0 0 1 .728.568L4.8 5.25m0 0 1.72 6.88a1.5 1.5 0 0 0 1.456 1.137h7.99a1.5 1.5 0 0 0 1.456-1.137L19.2 5.25H4.8Z"/>
                    <circle cx="8.25" cy="19.5" r="1.25"/>
                    <circle cx="16.5" cy="19.5" r="1.25"/>
                </svg>
            @endif
            {{ __('products.add_to_cart') }}
        </span>

        <span
            x-show="cartState === 'loading'"
            style="display:none;"
            x-transition:enter="transition ease-out duration-150"
            x-transition:enter-start="opacity-0"
            x-transition:leave="transition ease-in duration-100"
            x-transition:leave-end="opacity-0"
            class="absolute inset-0 flex items-center justify-center"
        >
            <svg class="h-5 w-5 animate-spin" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                <circle class="opacity-30" cx="12" cy="12" r="9" stroke="currentColor" stroke-width="3"/>
                <path class="opacity-90" fill="currentColor" d="M12 3a9 9 0 0 1 9 9h-3a6 6 0 0 0-6-6V3z"/>
            </svg>
        </span>

        <span
            x-show="cartState === 'success'"
            style="display:none;"
            x-transition:enter="transition ease-out duration-200"
            x-transition:enter-start="opacity-0 scale-90"
            x-transition:enter-end="opacity-100 scale-100"
            class="absolute inset-0 flex items-center justify-center gap-2"
        >
            <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                <path d="M5 13l4 4L19 7"/>
            </svg>
            {{ __('products.detail_added') }}
        </span>
    </button>
    <button
        type="button"
        @click="buyNow()"
        :disabled="! canAddToCart() || $store.cart.loading"
        class="flex items-center justify-center font-semibold transition-colors focus:outline-none focus-visible:ring-2 focus-visible:ring-blue-500/40 disabled:cursor-not-allowed disabled:opacity-50 {{ $isGiftCard ? 'h-11 rounded-full px-4 text-sm ' : 'h-[52px] rounded-full px-4 text-base ' }}{{ $isGiftCard ? 'bg-zinc-100 text-zinc-900 hover:bg-zinc-200 disabled:hover:bg-zinc-100 dark:bg-white/10 dark:text-white dark:hover:bg-white/15' : 'bg-blue-600 text-white hover:bg-blue-700 disabled:hover:bg-blue-600' }}"
    >
        {{ __('products.buy_now') }}
    </button>
</div>

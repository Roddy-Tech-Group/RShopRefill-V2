{{--
    Where this product can be used: the country it is issued for, with a way to
    switch country through the locale modal. A gift card carries it high, in place
    of the kind badge; the other kinds keep it under their buy panel.
--}}
<div class="{{ $availabilityClass ?? 'mt-4 mb-2' }}">
    <p class="flex items-center gap-2 text-sm text-zinc-700">
        @if (\App\Models\Product::flagUrl($product->country_code))
            <img src="{{ \App\Models\Product::flagUrl($product->country_code) }}" alt="" class="h-4 w-6 shrink-0 rounded-[2px] object-cover ring-1 ring-zinc-200" loading="lazy">
        @endif
        <span>{{ $isTopup ? __('products.detail_works_for_numbers', ['country' => $countryName]) : ($isBill ? __('products.detail_billers_in', ['country' => $countryName]) : __('products.detail_redeemable_in', ['country' => $countryName])) }}</span>
    </p>
    <p class="mt-0.5 text-sm text-zinc-600">
        {{ __('products.detail_not_in_country', ['country' => $countryName]) }}
        <button type="button" @click="$dispatch('open-locale-modal'); localeModalOpen = true" class="font-semibold text-zinc-900 underline underline-offset-2 transition-colors hover:text-blue-700">
            {{ __('products.detail_find_your_country') }}
        </button>
    </p>
</div>

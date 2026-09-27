{{--
    The brand plate: the gift card art, or a branded name tile for a brand with no
    logo, on its coloured panel. One copy, drawn beside the values on a gift card
    page and as the hero on the other kinds. Pass $plateClass for the panel's own
    width, alignment and inset inside its column, and $artWidth for how much of the
    panel the art fills: a gift card wears it small beside its values, with the
    colour barely framing the art; the other kinds keep it as the page's hero.

    keep-light holds the inner plate white in dark mode: bg-[#ffffff] became the
    dashboard card class in the light redesign, and the dashboard mirror of this
    page renders the same file, so without the opt-out the plate repaints to the
    card grey and a dark-linework logo vanishes.
--}}
<div class="{{ $plateClass ?? 'mx-auto w-full max-w-lg p-10 sm:p-14 lg:mr-0' }} pure-card flex items-center justify-center rounded-[24px] bg-[#e8e8f7] dark:border dark:border-[#24364f] dark:bg-[#0c1a36]">
    <div class="relative flex aspect-[8/5] {{ $artWidth ?? 'w-4/5' }} items-center justify-center overflow-hidden rounded-[20px] keep-light bg-[#ffffff] shadow-[0_10px_28px_-8px_rgba(0,0,0,0.25)]">
        @if ($logoSrc)
            <img src="{{ $logoSrc }}" alt="{{ $productLabel }}" class="h-full w-full object-cover" loading="eager" fetchpriority="high">
        @else
            {{-- No logo (e.g. mobile-airtime operators) - a branded name tile. --}}
            <div class="flex h-full w-full items-center justify-center" style="background-color: {{ \App\Models\Product::tileColor($brandKey) }}">
                <span class="px-4 text-center text-2xl font-extrabold leading-tight text-white">{{ $brandName }}</span>
            </div>
        @endif
    </div>
</div>

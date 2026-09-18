{{--
    Per-brand customer review carousel: approved reviews left on delivered
    orders containing this brand, rendered with the shared review card and the
    same carousel controls as the homepage reviews row. Self-guarding: renders
    nothing when the brand has no published reviews. Used on the gift card
    product page and the order confirmation page.
--}}
@props(['brandKey', 'brandName'])

@php
    $brandReviews = $brandKey
        ? \App\Models\Review::published()->ownCollected()->withText()->forBrand($brandKey)->with('user:id,kyc_status')->ordered()->get()
        : collect();
@endphp

@if ($brandReviews->isNotEmpty())
    <section
        aria-label="{{ $brandName }} customer reviews"
        x-data="customerReviewsCarousel()"
        x-init="$nextTick(() => { setup(); })"
        @resize.window.debounce.200ms="setup()"
        x-on:livewire:navigated.window="$nextTick(() => { setup(); })"
    >
        <div x-ref="header" class="mb-4 flex items-center justify-between gap-4">
            <h2 class="text-lg font-bold text-zinc-900 sm:text-xl dark:text-white">What customers say about {{ $brandName }}</h2>
            <div class="flex shrink-0 items-center gap-2">
                <button
                    type="button"
                    @click="prev()"
                    aria-label="Show previous reviews"
                    class="flex h-10 w-10 shrink-0 items-center justify-center rounded-[12px] bg-white text-zinc-700 ring-1 ring-zinc-200 shadow-sm transition-colors duration-150 hover:bg-zinc-50 hover:text-zinc-900 focus:outline-none focus-visible:ring-2 focus-visible:ring-blue-500/40"
                >
                    <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                        <path d="M15 6l-6 6 6 6"/>
                    </svg>
                </button>
                <button
                    type="button"
                    @click="next()"
                    aria-label="Show more reviews"
                    class="flex h-10 w-10 shrink-0 items-center justify-center rounded-[12px] bg-white text-zinc-700 ring-1 ring-zinc-200 shadow-sm transition-colors duration-150 hover:bg-zinc-50 hover:text-zinc-900 focus:outline-none focus-visible:ring-2 focus-visible:ring-blue-500/40"
                >
                    <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                        <path d="M9 6l6 6-6 6"/>
                    </svg>
                </button>
            </div>
        </div>

        {{-- py-3 keeps the card ring/shadow from being clipped by the
             overflow container, same as the other carousels. --}}
        <div
            x-ref="track"
            class="overflow-x-auto overflow-y-hidden py-3 [scrollbar-width:none] [-webkit-overflow-scrolling:touch] [&::-webkit-scrollbar]:hidden"
        >
            <div x-ref="list" class="flex w-max gap-4 sm:gap-5">
                @foreach ($brandReviews as $review)
                    @include('shop._review-card', ['review' => $review])
                @endforeach
            </div>
        </div>
    </section>
@endif

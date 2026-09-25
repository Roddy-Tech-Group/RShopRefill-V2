@php
    // Customer reviews row. Full-bleed width: a Trustpilot review-collector card
    // pinned on the left, then a horizontal scroll of individual review cards.
    // The header arrow advances the scroll one screenful at a time with a custom
    // rAF easing pass (see customerReviewsCarousel in app.js) and loops back at
    // the end.
    //
    // Content source: the `reviews` table, restricted to what our own form
    // collected (Review::ownCollected). Trustpilot and Google host and display
    // their reviews on their own surfaces - we send customers there to leave one
    // rather than re-render their content here.
    // withText: the experience prompt lets a customer leave a score alone, and a
    // card with nothing to say has nothing to show here.
    $reviews = \App\Models\Review::published()->ownCollected()->withText()->with('user:id,kyc_status,avatar_url')->ordered()->get();
    $trustpilotProfileUrl = config('services.trustpilot.profile_url');
    // The Review Collector TrustBox (same widget as /reviews and /contact):
    // Trustpilot's own button, which opens the review form with the business
    // already selected, in place of a plain link to the profile page.
    $tp = config('services.trustpilot');
@endphp
{{-- TrustBox bootstrap: renders the widget div in the collector card below.
     Loaded once, async, so it neither blocks the page nor doubles up when the
     row is rendered more than once. --}}
@once
    <script type="text/javascript" src="//widget.trustpilot.com/bootstrap/v5/tp.widget.bootstrap.min.js" async></script>
@endonce
<section
    data-reveal
    aria-label="{{ __('reviews.heading') }}"
    class="w-full"
    x-data="customerReviewsCarousel()"
    x-init="$nextTick(() => { setup(); })"
    @resize.window.debounce.200ms="setup()"
    x-on:livewire:navigated.window="$nextTick(() => { setup(); })"
>

    {{-- Header aligns with the page content width. --}}
    <div class="mx-auto w-full max-w-[1550px] px-4 sm:px-6 lg:px-8">
        {{-- x-ref="header" sits on the post-padding flex row, so its left edge
             is the actual content column start — that's the line the first
             review card needs to align to in setup(). --}}
        <div x-ref="header" class="mb-4 flex items-center justify-between gap-4">
            <h2 class="text-lg font-bold text-zinc-900 sm:text-xl">{{ __('reviews.heading') }}</h2>
            {{-- Pill + arrows come from the shared row-controls component, which
                 every other row header on the site uses too. The reviews
                 carousel loops rather than stopping at an end, so no disabled
                 bindings are passed; and its arrows stay on the phone layout. --}}
            <x-ui.row-controls
                :href="route('shop.reviews')"
                prev="prev()"
                next="next()"
                :prev-label="__('reviews.row_prev')"
                :next-label="__('reviews.row_next')"
                :arrows-on-mobile="true"
            />
        </div>
    </div>

    {{-- Full-bleed scroll area. --}}
    <div class="relative">

        {{-- Transform-based carousel viewport (overflow-hidden). The inner
             carousel-list ul is translated via translate3d from JS, exactly
             like the home brand rows, so the slide is GPU-composited and feels
             identical across the homepage. py-6 gives the card ring/shadow
             room to breathe so the top and bottom edges of each card aren't
             clipped by the overflow-hidden viewport. --}}
        <div
            x-ref="track"
            class="overflow-x-auto overflow-y-hidden py-6 [scrollbar-width:none] [-webkit-overflow-scrolling:touch] [&::-webkit-scrollbar]:hidden"
        >
            {{-- pl-[...] starts the first card at the same content-column left as
                 the "What our customers say" header (matches its mx-auto max-w-[1550px]
                 px-4/sm:px-6/lg:px-8), while the row still bleeds full-width to the right. --}}
            <div x-ref="list" class="carousel-list flex w-max gap-4 sm:gap-5 pl-[max(1rem,calc((100vw-1550px)/2+1rem))] sm:pl-[max(1.5rem,calc((100vw-1550px)/2+1.5rem))] lg:pl-[max(2rem,calc((100vw-1550px)/2+2rem))]">

                {{-- Review collector, not a score card. This slot used to flip
                     between Trustpilot and Google aggregate scores; those
                     platforms host and display their own reviews, so the card
                     now sends the customer there to leave one instead of
                     re-rendering their content here. --}}
                @if ($trustpilotProfileUrl)
                    <article class="flex w-72 shrink-0 flex-col rounded-[12px] bg-white p-5 shadow-md shadow-zinc-900/[0.06] dark:shadow-none">
                        {{-- No hand-drawn star or wordmark here: the TrustBox below is the only
                             Trustpilot branding on the site, as their guidelines ask. --}}
                        <h3 class="text-lg font-bold leading-tight text-zinc-900">{{ __('reviews.review_us') }}</h3>
                        <p class="mt-2 text-sm leading-relaxed text-zinc-700">{{ __('reviews.collector_body') }}</p>

                        <div class="mt-auto pt-6">
                            <div class="inline-flex overflow-hidden rounded-[12px] bg-white shadow-md shadow-zinc-900/20">
                                <div class="trustpilot-widget"
                                    data-locale="{{ $tp['locale'] }}"
                                    data-template-id="{{ $tp['review_collector_template_id'] }}"
                                    data-businessunit-id="{{ $tp['business_unit_id'] }}"
                                    data-style-height="52px"
                                    data-style-width="240px"
                                    data-token="{{ $tp['review_collector_token'] }}">
                                    <a href="{{ $trustpilotProfileUrl }}" target="_blank" rel="noopener" class="text-sm font-semibold text-blue-700 hover:underline">{{ __('reviews.review_fallback') }}</a>
                                </div>
                            </div>
                        </div>
                    </article>
                @endif

                {{-- Individual review cards (our own, CMS-managed via the reviews
                     table). Shared partial keeps this carousel and the /reviews
                     rows visually identical. --}}
                @foreach ($reviews as $review)
                    @include('shop._review-card', ['review' => $review])
                @endforeach

            </div>
        </div>

    </div>
</section>

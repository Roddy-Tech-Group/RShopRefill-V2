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
@endphp
<section
    data-reveal
    aria-label="What our customers say"
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
            <h2 class="text-lg font-bold text-zinc-900 sm:text-xl">What our customers say</h2>
            {{-- Pill + arrows come from the shared row-controls component, which
                 every other row header on the site uses too. The reviews
                 carousel loops rather than stopping at an end, so no disabled
                 bindings are passed; and its arrows stay on the phone layout. --}}
            <x-ui.row-controls
                :href="route('shop.reviews')"
                prev="prev()"
                next="next()"
                prev-label="Show previous reviews"
                next-label="Show more reviews"
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
                    <article class="flex w-72 shrink-0 flex-col rounded-[12px] bg-[#eff6ff] p-5 ring-1 ring-zinc-200 dark:ring-[#24364f]">
                        <div class="flex items-center gap-1.5">
                            <svg class="h-5 w-5 text-emerald-500" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
                                <path d="M12 .587l3.668 7.568L24 9.423l-6 5.951L19.336 24 12 19.897 4.664 24 6 15.374 0 9.423l8.332-1.268z"/>
                            </svg>
                            <span class="text-base font-bold text-zinc-900">Trustpilot</span>
                        </div>

                        <h3 class="mt-4 text-lg font-bold leading-tight text-zinc-900">Bought from us recently?</h3>
                        <p class="mt-2 text-sm leading-relaxed text-zinc-700">Share your experience on Trustpilot. It takes a minute, and it helps other customers shop with confidence.</p>

                        <div class="mt-auto pt-6">
                            <a
                                href="{{ $trustpilotProfileUrl }}"
                                target="_blank"
                                rel="noopener noreferrer"
                                class="inline-flex h-10 items-center justify-center rounded-[12px] bg-blue-600 px-4 text-sm font-semibold text-white transition-colors hover:bg-blue-700 focus:outline-none focus-visible:ring-2 focus-visible:ring-blue-500/40"
                            >
                                Review us on Trustpilot
                            </a>
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

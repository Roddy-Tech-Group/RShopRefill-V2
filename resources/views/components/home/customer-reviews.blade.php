@php
    // Customer reviews row. Full-bleed width: Trustpilot aggregate card pinned on
    // the left, then a horizontal scroll of individual review cards. The header
    // arrow advances the scroll one screenful at a time with a custom rAF easing
    // pass (see customerReviewsCarousel in app.js) and loops back at the end.
    //
    // Content source: the `reviews` table + `site_settings` (CMS-managed).
    $reviews = \App\Models\Review::published()->with('user:id,kyc_status')->ordered()->get();
    $aggregateRating = (float) \App\Models\SiteSetting::get('reviews.aggregate.rating', 4.4);
    $aggregateCount = (int) \App\Models\SiteSetting::get('reviews.aggregate.count', 0);
    $aggregateSince = (int) \App\Models\SiteSetting::get('reviews.aggregate.since_year', date('Y'));
    $aggregateSource = (string) \App\Models\SiteSetting::get('reviews.aggregate.source', 'Trustpilot');

    // Per-source aggregates computed live from the reviews table so the
    // homepage carousel can show separate Trustpilot + Google score cards.
    // Falls back to the global SiteSetting aggregate when a source has no
    // entries yet (e.g. brand-new Google profile with 0 imported reviews).
    $sourceAggregates = [];
    foreach (['Trustpilot' => '#00b67a', 'Google' => null] as $sourceLabel => $_) {
        $matching = $reviews->filter(fn ($r) => strcasecmp((string) $r->source, $sourceLabel) === 0);
        $sourceAggregates[$sourceLabel] = [
            'count'  => $matching->count(),
            'rating' => $matching->isNotEmpty() ? round($matching->avg('rating'), 1) : $aggregateRating,
        ];
    }
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

                {{-- Single aggregate card that auto-flips between Trustpilot
                     and Google every 5 seconds. Counts are pulled live from
                     the reviews table (admin manages them via the CMS).
                     Hovering the card pauses the auto-rotation. --}}
                @php
                    $aggSources = [
                        [
                            'key' => 'Trustpilot',
                            'count' => $sourceAggregates['Trustpilot']['count'],
                            'rating' => $sourceAggregates['Trustpilot']['rating'],
                        ],
                        [
                            'key' => 'Google',
                            'count' => $sourceAggregates['Google']['count'],
                            'rating' => $sourceAggregates['Google']['rating'],
                        ],
                    ];
                @endphp
                <article
                    x-data="{
                        idx: 0,
                        total: 2,
                        paused: false,
                        timer: null,
                        init() {
                            this.timer = setInterval(() => {
                                if (! this.paused) {
                                    this.idx = (this.idx + 1) % this.total;
                                }
                            }, 5000);
                        },
                        destroy() {
                            if (this.timer) { clearInterval(this.timer); }
                        },
                    }"
                    @mouseenter="paused = true"
                    @mouseleave="paused = false"
                    class="relative flex w-72 shrink-0 flex-col rounded-[12px] bg-[#eff6ff] p-5 ring-1 ring-zinc-200 dark:ring-[#24364f]"
                >
                    @foreach ($aggSources as $i => $agg)
                        @php $isGoogle = $agg['key'] === 'Google'; @endphp
                        <div
                            x-show="idx === {{ $i }}"
                            x-transition:enter="transition-all duration-500 ease-out"
                            x-transition:enter-start="opacity-0 translate-y-1"
                            x-transition:enter-end="opacity-100 translate-y-0"
                            x-transition:leave="transition-all duration-300 ease-in absolute inset-5"
                            x-transition:leave-start="opacity-100"
                            x-transition:leave-end="opacity-0 -translate-y-1"
                            class="flex flex-1 flex-col"
                            @if ($i > 0) x-cloak @endif
                        >
                            <div class="flex items-start justify-between gap-2">
                                <div class="flex items-center gap-1.5">
                                    @if ($isGoogle)
                                        <svg class="h-5 w-5" viewBox="0 0 48 48" aria-hidden="true">
                                            <path fill="#FFC107" d="M43.611 20.083H42V20H24v8h11.303c-1.649 4.657-6.08 8-11.303 8c-6.627 0-12-5.373-12-12s5.373-12 12-12c3.059 0 5.842 1.154 7.961 3.039l5.657-5.657C34.046 6.053 29.268 4 24 4C12.955 4 4 12.955 4 24s8.955 20 20 20s20-8.955 20-20c0-1.341-.138-2.65-.389-3.917"/>
                                            <path fill="#FF3D00" d="m6.306 14.691l6.571 4.819C14.655 15.108 18.961 12 24 12c3.059 0 5.842 1.154 7.961 3.039l5.657-5.657C34.046 6.053 29.268 4 24 4C16.318 4 9.656 8.337 6.306 14.691"/>
                                            <path fill="#4CAF50" d="M24 44c5.166 0 9.86-1.977 13.409-5.192l-6.19-5.238A11.91 11.91 0 0 1 24 36c-5.202 0-9.619-3.317-11.283-7.946l-6.522 5.025C9.505 39.556 16.227 44 24 44"/>
                                            <path fill="#1976D2" d="M43.611 20.083H42V20H24v8h11.303a12.04 12.04 0 0 1-4.087 5.571l.003-.002l6.19 5.238C36.971 39.205 44 34 44 24c0-1.341-.138-2.65-.389-3.917"/>
                                        </svg>
                                    @else
                                        <svg class="h-5 w-5 text-emerald-500" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
                                            <path d="M12 .587l3.668 7.568L24 9.423l-6 5.951L19.336 24 12 19.897 4.664 24 6 15.374 0 9.423l8.332-1.268z"/>
                                        </svg>
                                    @endif
                                    <span class="text-base font-bold text-zinc-900">{{ $agg['key'] }}</span>
                                </div>
                                <p class="text-2xl font-bold leading-none text-zinc-900">
                                    {{ number_format($agg['rating'], 1) }}<span class="text-base font-normal text-zinc-600"> / 5</span>
                                </p>
                            </div>

                            {{-- Brand-appropriate stars, driven by the actual
                                 rating in half-star steps (4.6 renders as 4.5
                                 stars - never a fake full five). --}}
                            @php $starValue = round((float) $agg['rating'] * 2) / 2; @endphp
                            <div class="mt-3 flex gap-0.5">
                                @for ($s = 0; $s < 5; $s++)
                                    @php $fill = ($starValue - $s) >= 1 ? 'full' : (($starValue - $s) >= 0.5 ? 'half' : 'empty'); @endphp
                                    @if ($isGoogle)
                                        <span class="relative h-7 w-7">
                                            <svg class="h-7 w-7 text-zinc-300" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
                                                <path d="M12 .587l3.668 7.568L24 9.423l-6 5.951L19.336 24 12 19.897 4.664 24 6 15.374 0 9.423l8.332-1.268z"/>
                                            </svg>
                                            @if ($fill !== 'empty')
                                                <span class="absolute inset-y-0 left-0 overflow-hidden" style="width: {{ $fill === 'half' ? '50%' : '100%' }};">
                                                    <svg class="h-7 w-7 text-amber-400" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
                                                        <path d="M12 .587l3.668 7.568L24 9.423l-6 5.951L19.336 24 12 19.897 4.664 24 6 15.374 0 9.423l8.332-1.268z"/>
                                                    </svg>
                                                </span>
                                            @endif
                                        </span>
                                    @else
                                        <span class="relative flex h-7 w-7 items-center justify-center overflow-hidden {{ $fill === 'full' ? 'bg-emerald-500' : 'bg-zinc-300' }}">
                                            @if ($fill === 'half')
                                                <span class="absolute inset-y-0 left-0 w-1/2 bg-emerald-500" aria-hidden="true"></span>
                                            @endif
                                            <svg class="relative h-4 w-4 text-white" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
                                                <path d="M12 .587l3.668 7.568L24 9.423l-6 5.951L19.336 24 12 19.897 4.664 24 6 15.374 0 9.423l8.332-1.268z"/>
                                            </svg>
                                        </span>
                                    @endif
                                @endfor
                            </div>

                            <div class="mt-auto pt-6">
                                @if ($agg['count'] > 0)
                                    <p class="text-sm text-zinc-700">{{ number_format($agg['count']) }}+ reviews on {{ $agg['key'] }}</p>
                                @endif
                                <p class="mt-1 text-sm font-semibold text-zinc-900">Trusted since {{ $aggregateSince }}</p>
                            </div>
                        </div>
                    @endforeach

                    {{-- Dot indicators - clickable to manually jump --}}
                    <div class="pointer-events-none absolute inset-x-0 bottom-3 flex justify-center gap-1.5">
                        @foreach ($aggSources as $i => $agg)
                            <button
                                type="button"
                                @click.stop="idx = {{ $i }}"
                                :class="idx === {{ $i }} ? 'bg-zinc-900 w-4' : 'bg-zinc-300'"
                                class="pointer-events-auto h-1.5 rounded-full transition-all duration-200"
                                aria-label="Show {{ $agg['key'] }} reviews"
                            ></button>
                        @endforeach
                    </div>
                </article>

                {{-- Individual review cards (CMS-managed via the reviews table).
                     Shared partial keeps homepage carousel + /reviews marquees
                     visually identical so Trustpilot + Google + curated reviews
                     all blend when sources come online. --}}
                @foreach ($reviews as $review)
                    @include('shop._review-card', ['review' => $review])
                @endforeach

            </div>
        </div>

    </div>
</section>

@php
    // Customer Reviews page. Linked from the footer (Company column).
    //
    // Free-tier Trustpilot only lets us display the Review Collector widget
    // (a CTA that opens Trustpilot for the customer to leave a review). Live
    // review feeds would need a paid plan. Until we upgrade we lean on the
    // curated Review model for the public-facing review cards, and let
    // Trustpilot collect the actual reviews behind the scenes.
    $tp = config('services.trustpilot');
    $google = config('services.google_reviews');
    $reviews = \App\Models\Review::published()->with('user:id,kyc_status')->ordered()->get();
    $aggregate = [
        'rating' => (float) \App\Models\SiteSetting::get('reviews.aggregate.rating', 0),
        'count' => (int) \App\Models\SiteSetting::get('reviews.aggregate.count', 0),
        'since' => (int) \App\Models\SiteSetting::get('reviews.aggregate.since_year', date('Y')),
        'source' => (string) \App\Models\SiteSetting::get('reviews.aggregate.source', 'Trustpilot'),
    ];
@endphp

<x-layouts.app.header :title="'Customer Reviews | '.$siteName">

    {{-- TrustBox bootstrap. Loaded once; the widget div below renders into it. --}}
    <script type="text/javascript" src="//widget.trustpilot.com/bootstrap/v5/tp.widget.bootstrap.min.js" async></script>

    {{-- Hero --}}
    <section class="border-b border-zinc-100 bg-blue-50 dark:bg-[#0c1a36]">
        <div class="mx-auto w-full max-w-[1140px] px-4 py-14 text-center sm:px-6 sm:py-20">
            <span class="inline-flex items-center gap-2 rounded-[5px] bg-blue-100 px-3 py-1.5 text-[11px] font-bold uppercase tracking-[0.14em] text-blue-700">Customer reviews</span>
            <h1 class="mt-5 text-3xl font-bold tracking-tight text-zinc-900 sm:text-4xl lg:text-5xl">What our customers say</h1>
            <p class="mx-auto mt-3 max-w-xl text-sm leading-relaxed text-zinc-600 sm:text-base">
                Real feedback from real customers across {{ $aggregate['source'] }} and our own community. We've been serving customers since {{ $aggregate['since'] }}.
            </p>

            @if ($aggregate['count'] > 0)
                <div class="mx-auto mt-6 inline-flex items-center gap-3 rounded-[12px] bg-white px-4 py-2.5 ring-1 ring-zinc-100">
                    <div class="flex items-center gap-0.5">
                        @for ($i = 1; $i <= 5; $i++)
                            @php $fillPct = max(0, min(100, ($aggregate['rating'] - ($i - 1)) * 100)); @endphp
                            <span class="relative flex h-5 w-5 items-center justify-center overflow-hidden bg-zinc-200">
                                <span class="absolute inset-y-0 left-0 bg-emerald-500" style="width: {{ $fillPct }}%"></span>
                                <svg class="relative h-3 w-3 text-white" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
                                    <path d="M12 .587l3.668 7.568L24 9.423l-6 5.951L19.336 24 12 19.897 4.664 24 6 15.374 0 9.423l8.332-1.268z"/>
                                </svg>
                            </span>
                        @endfor
                    </div>
                    <span class="text-sm font-bold text-zinc-900">{{ number_format($aggregate['rating'], 1) }} / 5</span>
                    <span class="text-sm text-zinc-600">from {{ number_format($aggregate['count']) }}+ reviews</span>
                </div>
            @endif
        </div>
    </section>

    {{-- Trustpilot Review Collector - lets visitors leave a review directly --}}
    <section class="border-b border-zinc-100 bg-white dark:bg-[#0c1a36]">
        <div class="mx-auto w-full max-w-[1140px] px-4 py-10 sm:px-6">
            <div class="rounded-[12px] bg-zinc-50 p-6 ring-1 ring-zinc-100 sm:p-8">
                <div class="flex flex-col items-center gap-4 text-center sm:flex-row sm:text-left">
                    <div class="flex h-12 w-12 shrink-0 items-center justify-center rounded-[12px] bg-emerald-100">
                        <svg class="h-6 w-6 text-emerald-700" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M7.5 8.25h9m-9 3H12m-9.75 1.51c0 1.6 1.123 2.994 2.707 3.227 1.129.166 2.27.293 3.423.379.35.026.67.21.865.501L12 21l2.755-4.133a1.14 1.14 0 01.865-.501 48.172 48.172 0 003.423-.379c1.584-.233 2.707-1.626 2.707-3.228V6.741c0-1.602-1.123-2.995-2.707-3.228A48.394 48.394 0 0012 3c-2.392 0-4.744.175-7.043.513C3.373 3.746 2.25 5.14 2.25 6.741v6.018z"/>
                        </svg>
                    </div>
                    <div class="min-w-0 flex-1">
                        <h2 class="text-lg font-bold text-zinc-900">Bought from us recently?</h2>
                        <p class="mt-1 text-sm text-zinc-600">Take a minute to share your experience on {{ $aggregate['source'] }}. Reviews help other customers make confident choices and helps us improve services for you.</p>
                    </div>
                </div>

                {{-- Review CTAs - Trustpilot + Google side by side (stack on mobile). --}}
                <div class="mt-6 grid items-center gap-4 sm:grid-cols-2">
                    {{-- TrustBox widget - Review Collector --}}
                    <div class="flex justify-center">
                        <div class="inline-flex overflow-hidden rounded-[12px] border border-zinc-200 bg-white shadow-md shadow-zinc-900/20 dark:border-white/10">
                            <div class="trustpilot-widget"
                                data-locale="{{ $tp['locale'] }}"
                                data-template-id="{{ $tp['review_collector_template_id'] }}"
                                data-businessunit-id="{{ $tp['business_unit_id'] }}"
                                data-style-height="52px"
                                data-style-width="240px"
                                data-token="{{ $tp['review_collector_token'] }}">
                                <a href="{{ $tp['profile_url'] }}" target="_blank" rel="noopener" class="text-sm font-semibold text-blue-700 hover:underline">Leave a review on Trustpilot, on the website or Google.</a>
                            </div>
                        </div>
                    </div>

                    {{-- Google CTA - the other major review surface. QR sits beside
                         the button so desktop visitors can scan, mobile can tap. --}}
                    <div class="flex flex-wrap items-center gap-4 rounded-[12px] bg-white p-4 ring-1 ring-zinc-200">
                    <div class="flex min-w-0 flex-1 items-center gap-3">
                        <svg class="h-6 w-6 shrink-0" viewBox="0 0 48 48" aria-hidden="true">
                            <path fill="#FFC107" d="M43.611 20.083H42V20H24v8h11.303c-1.649 4.657-6.08 8-11.303 8c-6.627 0-12-5.373-12-12s5.373-12 12-12c3.059 0 5.842 1.154 7.961 3.039l5.657-5.657C34.046 6.053 29.268 4 24 4C12.955 4 4 12.955 4 24s8.955 20 20 20s20-8.955 20-20c0-1.341-.138-2.65-.389-3.917"/>
                            <path fill="#FF3D00" d="m6.306 14.691l6.571 4.819C14.655 15.108 18.961 12 24 12c3.059 0 5.842 1.154 7.961 3.039l5.657-5.657C34.046 6.053 29.268 4 24 4C16.318 4 9.656 8.337 6.306 14.691"/>
                            <path fill="#4CAF50" d="M24 44c5.166 0 9.86-1.977 13.409-5.192l-6.19-5.238A11.91 11.91 0 0 1 24 36c-5.202 0-9.619-3.317-11.283-7.946l-6.522 5.025C9.505 39.556 16.227 44 24 44"/>
                            <path fill="#1976D2" d="M43.611 20.083H42V20H24v8h11.303a12.04 12.04 0 0 1-4.087 5.571l.003-.002l6.19 5.238C36.971 39.205 44 34 44 24c0-1.341-.138-2.65-.389-3.917"/>
                        </svg>
                        <div class="min-w-0">
                            <p class="text-sm font-semibold text-zinc-900">Or leave a review on Google</p>
                            <p class="text-xs text-zinc-500">Scan the QR with your phone or tap the button.</p>
                        </div>
                    </div>
                    <div class="flex items-center gap-3">
                        <a href="{{ $google['review_url'] }}" target="_blank" rel="noopener" class="inline-flex items-center gap-1.5 rounded-[12px] bg-zinc-900 px-4 py-2 text-xs font-semibold text-white transition-colors hover:bg-zinc-800">
                            Review on Google
                            <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M13.5 6H5.25A2.25 2.25 0 003 8.25v10.5A2.25 2.25 0 005.25 21h10.5A2.25 2.25 0 0018 18.75V10.5m-10.5 6L21 3m0 0h-5.25M21 3v5.25"/></svg>
                        </a>
                        <a href="{{ $google['review_url'] }}" target="_blank" rel="noopener" aria-label="Scan to open the Google review form on your phone" class="hidden shrink-0 rounded-[12px] bg-white p-1 ring-1 ring-zinc-200 transition-shadow hover:shadow-md sm:block">
                            <img src="{{ asset('assets/' . rawurlencode($google['qr_asset'])) }}" alt="" class="h-16 w-16 object-contain">
                        </a>
                    </div>
                </div>
                </div>
            </div>
        </div>
    </section>

    {{-- Reviews carousel - two full-bleed rows scrolling in opposite directions
         so curated entries + future Trustpilot + Google reviews all blend in
         one continuous wall. Pause on hover. CSS-only marquee (no JS). --}}
    @php
        // Split the published reviews across three rows (interleaved so each row
        // mixes sources). Rendered as three independent, manually-scrollable
        // carousels using the shared gift-card scroll controls - no auto-scroll.
        $reviewRows = collect(range(0, 2))
            ->map(fn ($r) => $reviews->values()->filter(fn ($_, $i) => $i % 3 === $r)->values())
            ->filter->isNotEmpty()
            ->values();
    @endphp

    <section class="w-full bg-zinc-50 py-14 sm:py-16 dark:bg-[#0c1a36]">
        <div class="mx-auto mb-8 w-full max-w-[1140px] px-4 sm:px-6">
            <div class="flex flex-wrap items-end justify-between gap-3">
                <div>
                    <h2 class="text-2xl font-bold tracking-tight text-zinc-900 dark:text-white sm:text-3xl">Recent reviews</h2>
                    <p class="mt-1 text-sm text-zinc-600 dark:text-zinc-400">Reviews collected from Google Business, Trustpilot and our system. Scroll each row to read more.</p>
                </div>
                <a href="{{ $tp['profile_url'] }}" target="_blank" rel="noopener" class="inline-flex items-center gap-1.5 text-sm font-semibold text-blue-700 hover:text-blue-800 dark:text-blue-400">
                    View all on {{ $aggregate['source'] }}
                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M13.5 6H5.25A2.25 2.25 0 003 8.25v10.5A2.25 2.25 0 005.25 21h10.5A2.25 2.25 0 0018 18.75V10.5m-10.5 6L21 3m0 0h-5.25M21 3v5.25"/></svg>
                </a>
            </div>
        </div>

        @if ($reviews->isNotEmpty())
            {{-- Three manually-scrollable carousels (gift-card scroll controls). --}}
            <div class="mx-auto w-full max-w-[1140px] space-y-8 px-4 sm:px-6">
                @foreach ($reviewRows as $row)
                    <x-home.brand-row :title="''" view-all-variant="none" :carousel="true" :cols="5">
                        @foreach ($row as $review)
                            @include('shop._review-card', ['review' => $review])
                        @endforeach
                    </x-home.brand-row>
                @endforeach
            </div>
        @else
            <div class="mx-auto max-w-2xl rounded-[12px] bg-white p-10 text-center ring-1 ring-zinc-100 shadow-sm">
                <div class="mx-auto flex h-14 w-14 items-center justify-center rounded-[12px] bg-blue-50 text-blue-600">
                    <svg class="h-7 w-7" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M11.48 3.499a.562.562 0 011.04 0l2.125 5.111a.563.563 0 00.475.345l5.518.442c.499.04.701.663.321.988l-4.204 3.602a.563.563 0 00-.182.557l1.285 5.385a.562.562 0 01-.84.61l-4.725-2.885a.563.563 0 00-.586 0L6.982 20.54a.562.562 0 01-.84-.61l1.285-5.386a.562.562 0 00-.182-.557l-4.204-3.602a.563.563 0 01.321-.988l5.518-.442a.563.563 0 00.475-.345L11.48 3.5z"/></svg>
                </div>
                <h3 class="mt-4 text-base font-bold text-zinc-900">No reviews yet</h3>
                <p class="mx-auto mt-2 max-w-md text-sm text-zinc-600">Be the first to share your experience. Your review helps us improve and helps other customers shop with confidence.</p>
                <a href="{{ $tp['profile_url'] }}" target="_blank" rel="noopener" class="mt-5 inline-flex items-center gap-2 rounded-[12px] bg-blue-600 px-5 py-2.5 text-sm font-semibold text-white transition-colors hover:bg-blue-700">
                    Leave the first review
                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M8.25 4.5l7.5 7.5-7.5 7.5"/></svg>
                </a>
            </div>
        @endif
    </section>


    {{-- Leave a review - our own collector, posting straight into the wall
         above (after approval). These are general, non-product reviews. --}}
    <section class="border-t border-zinc-100 bg-white dark:bg-[#0c1a36]">
        <div class="mx-auto w-full max-w-[640px] px-4 py-14 sm:px-6 sm:py-16">
            <div class="rounded-[12px] bg-zinc-50 p-6 ring-1 ring-zinc-100 sm:p-8">
                @include('shop._review-form')
            </div>
        </div>
    </section>

    {{-- Final CTA - contained rounded banner rather than a full-bleed strip. --}}
    <section class="px-4 py-12 sm:px-6">
        <div class="mx-auto w-full max-w-[1140px] rounded-[12px] bg-blue-600 px-4 py-14 text-center sm:px-6">
            <h2 class="text-2xl font-bold text-white sm:text-3xl">Loved using {{ $siteName }}?</h2>
            <p class="mx-auto mt-2 max-w-md text-sm leading-relaxed text-blue-100">Drop us a review on {{ $aggregate['source'] }} - it takes less than a minute and helps other customers find us.</p>
            <a href="{{ $tp['profile_url'] }}" target="_blank" rel="noopener" class="mt-6 inline-flex items-center justify-center gap-2 rounded-[12px] bg-white px-6 py-3 text-sm font-semibold text-blue-700 transition-colors hover:bg-blue-50">
                Review us on {{ $aggregate['source'] }}
                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M13.5 6H5.25A2.25 2.25 0 003 8.25v10.5A2.25 2.25 0 005.25 21h10.5A2.25 2.25 0 0018 18.75V10.5m-10.5 6L21 3m0 0h-5.25M21 3v5.25"/></svg>
            </a>
        </div>
    </section>

</x-layouts.app.header>

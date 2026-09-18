{{--
    The product's own reviews: the score with how the ratings are spread, then
    the reviews themselves in two columns, ten at a time.

    Same source as every other review surface: approved, customer-collected
    reviews left on delivered orders that contained this brand. It renders
    nothing at all when the brand has none, so a page never shows an empty
    reviews heading.

    Every review is in the HTML from the start, so the page carries them for
    search engines and for anyone without JavaScript; the sort and the "Load
    more" only change which of them are shown and in what order.
--}}
@props(['brandKey', 'brandName'])

@php
    $productReviews = $brandKey
        ? \App\Models\Review::published()->ownCollected()->withText()->forBrand($brandKey)->with('user:id,kyc_status,avatar_url')->ordered()->get()
        : collect();

    $reviewTotal = $productReviews->count();
    $reviewAverage = $reviewTotal ? round((float) $productReviews->avg('rating'), 1) : 0.0;

    // How the ratings are spread, 5 down to 1, as a share of the total.
    $reviewSpread = collect(range(5, 1))->mapWithKeys(function (int $star) use ($productReviews, $reviewTotal) {
        $count = $productReviews->filter(fn ($review) => (int) round((float) $review->rating) === $star)->count();

        return [$star => ['count' => $count, 'share' => $reviewTotal ? round($count / $reviewTotal * 100) : 0]];
    });

    // What the sort needs, per review, in the order they are written out.
    $reviewOrderData = $productReviews->values()->map(fn ($review, $index) => [
        'i' => $index,
        'rating' => (float) $review->rating,
        'at' => optional($review->reviewed_at)->timestamp ?? 0,
    ])->all();

    $reviewSorts = [
        'relevant' => __('products.reviews_sort_relevant'),
        'newest' => __('products.reviews_sort_newest'),
        'highest' => __('products.reviews_sort_highest'),
        'lowest' => __('products.reviews_sort_lowest'),
    ];
@endphp

@if ($reviewTotal > 0)
    <section
        aria-label="{{ __('products.reviews_heading', ['brand' => $brandName]) }}"
        x-data="productReviews(@js($reviewOrderData), @js(['sorts' => $reviewSorts, 'showing' => __('products.reviews_showing', ['shown' => ':shown', 'total' => ':total'])]))"
        class="mt-14"
    >
        {{-- The score, then the spread beside it. --}}
        <div class="flex flex-col gap-6 border-b border-zinc-200 pb-6 sm:flex-row sm:items-center sm:gap-10 dark:border-white/10">
            <div class="shrink-0">
                <div class="flex items-end gap-3">
                    <span class="text-5xl font-bold leading-none tabular-nums text-zinc-900 dark:text-white">{{ number_format($reviewAverage, 1) }}</span>
                    <span class="text-lg font-semibold leading-tight text-zinc-700 dark:text-zinc-300">
                        {{ trans_choice('products.reviews_count', $reviewTotal, ['count' => number_format($reviewTotal)]) }}
                    </span>
                </div>
                <div class="mt-2 flex gap-0.5" aria-hidden="true">
                    @for ($star = 1; $star <= 5; $star++)
                        <svg class="h-6 w-6 {{ $star <= (int) round($reviewAverage) ? 'text-[#2563eb]' : 'text-[#2563eb]/25' }}" viewBox="0 0 24 24" fill="currentColor">
                            <path d="M12 .587l3.668 7.568L24 9.423l-6 5.951L19.336 24 12 19.897 4.664 24 6 15.374 0 9.423l8.332-1.268z"/>
                        </svg>
                    @endfor
                </div>
            </div>

            <div class="min-w-0 flex-1 sm:border-l sm:border-zinc-200 sm:pl-10 dark:sm:border-white/10">
                @foreach ($reviewSpread as $star => $row)
                    <div class="flex items-center gap-3 py-0.5">
                        <span class="w-3 shrink-0 text-sm tabular-nums text-zinc-600 dark:text-zinc-400">{{ $star }}</span>
                        <span class="h-1.5 min-w-0 flex-1 overflow-hidden rounded-full bg-zinc-200 sm:max-w-[240px] dark:bg-white/10">
                            <span class="block h-full rounded-full bg-blue-600 dark:bg-blue-500" style="width: {{ $row['share'] }}%"></span>
                        </span>
                        <span class="sr-only">{{ trans_choice('products.reviews_count', $row['count'], ['count' => number_format($row['count'])]) }}</span>
                    </div>
                @endforeach
            </div>
        </div>

        {{-- How to sort them. The site's own dropdown, not the browser's. --}}
        <div class="mt-6" x-data="{ open: false }" @click.outside="open = false" @keydown.escape="open = false">
            <div class="relative inline-block">
                <button
                    type="button"
                    @click="open = ! open"
                    :aria-expanded="open.toString()"
                    aria-haspopup="listbox"
                    class="flex h-11 items-center gap-2 rounded-[12px] border border-zinc-200 bg-white px-4 text-sm font-semibold text-zinc-900 transition-colors hover:border-blue-300 focus:outline-none focus-visible:ring-2 focus-visible:ring-blue-500/40 dark:border-white/10 dark:bg-white/5 dark:text-white dark:hover:border-blue-400/50"
                >
                    <svg class="h-4 w-4 shrink-0 text-zinc-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                        <path d="M7 4v16m0 0l-3-3m3 3l3-3M17 20V4m0 0l-3 3m3-3l3 3"/>
                    </svg>
                    <span x-text="sorts[sort]">{{ $reviewSorts['relevant'] }}</span>
                    <svg class="h-4 w-4 shrink-0 text-zinc-500 transition-transform duration-150" :class="{ 'rotate-180': open }" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7"/>
                    </svg>
                </button>

                <div
                    x-show="open"
                    x-cloak
                    x-transition:enter="transition ease-out duration-150"
                    x-transition:enter-start="opacity-0 -translate-y-1"
                    x-transition:enter-end="opacity-100 translate-y-0"
                    role="listbox"
                    class="absolute left-0 top-full z-20 mt-1 w-56 rounded-[12px] border border-zinc-200 bg-white p-1 shadow-xl shadow-zinc-900/10 dark:border-white/10 dark:bg-[#1a1a1e]"
                >
                    @foreach ($reviewSorts as $key => $label)
                        <button
                            type="button"
                            role="option"
                            :aria-selected="(sort === '{{ $key }}').toString()"
                            @click="sort = '{{ $key }}'; open = false"
                            :class="sort === '{{ $key }}' ? 'bg-blue-50 text-blue-700 dark:bg-blue-500/15 dark:text-blue-300' : 'text-zinc-800 hover:bg-zinc-100 dark:text-zinc-200 dark:hover:bg-white/10'"
                            class="flex w-full items-center rounded-[12px] px-3 py-2 text-left text-sm font-medium transition-colors"
                        >{{ $label }}</button>
                    @endforeach
                </div>
            </div>
        </div>

        {{-- The reviews themselves, two columns on a wide screen. --}}
        <div class="mt-6 grid gap-x-10 gap-y-8 sm:grid-cols-2">
            @foreach ($productReviews as $index => $review)
                <article
                    x-data="{ expanded: false, clamped: false }"
                    x-init="$nextTick(() => { clamped = $refs.body.scrollHeight > $refs.body.clientHeight + 1 })"
                    x-show="shows({{ $index }})"
                    :style="{ order: place({{ $index }}) }"
                    class="flex min-w-0 gap-3"
                >
                    {{-- Their profile photo if they set one, otherwise the same plain user
                         glyph the account button falls back to. An anonymous review shows
                         the glyph either way: the photo would say whose account it is. --}}
                    <span class="flex h-10 w-10 shrink-0 items-center justify-center overflow-hidden rounded-full bg-zinc-100 text-zinc-500 dark:bg-white/10 dark:text-zinc-300">
                        @if (! $review->is_anonymous && $review->user?->avatar_url)
                            <img src="{{ $review->user->avatar_url }}" alt="" class="h-full w-full object-cover" loading="lazy">
                        @else
                            <flux:icon.user class="h-5 w-5" />
                        @endif
                    </span>
                    <div class="min-w-0 flex-1">
                        <p class="flex items-center gap-1 text-sm font-semibold text-zinc-900 dark:text-white">
                            <span class="truncate">{{ $review->displayName() }}</span>
                            @if (! $review->is_anonymous && $review->user?->isKycVerified())
                                <x-verified-badge size="xs" />
                            @endif
                        </p>

                        <p class="mt-0.5 flex items-center gap-2">
                            <span class="flex gap-0.5" aria-label="{{ __('products.reviews_stars_aria', ['rating' => number_format((float) $review->rating, 1)]) }}">
                                @for ($star = 1; $star <= 5; $star++)
                                    <svg class="h-4 w-4 {{ $star <= (int) round((float) $review->rating) ? 'text-[#2563eb]' : 'text-[#2563eb]/25' }}" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
                                        <path d="M12 .587l3.668 7.568L24 9.423l-6 5.951L19.336 24 12 19.897 4.664 24 6 15.374 0 9.423l8.332-1.268z"/>
                                    </svg>
                                @endfor
                            </span>
                            <span class="text-xs text-zinc-600 dark:text-zinc-400">{{ $review->reviewed_at->format('M j, Y') }}</span>
                        </p>

                        {{-- Clamped to four lines, released inline so the server-rendered
                             clamp never flashes open while Alpine boots. --}}
                        <p
                            x-ref="body"
                            :style="expanded ? '-webkit-line-clamp: unset;' : ''"
                            class="mt-1.5 line-clamp-4 text-sm leading-relaxed text-zinc-700 dark:text-zinc-300"
                        >{{ $review->body }}</p>

                        <button
                            x-cloak
                            x-show="clamped || expanded"
                            type="button"
                            @click="expanded = ! expanded"
                            :aria-expanded="expanded.toString()"
                            class="mt-1 text-xs font-semibold text-zinc-900 underline underline-offset-2 transition-colors hover:text-blue-700 dark:text-white dark:hover:text-blue-300"
                        >
                            <span x-text="expanded ? @js(__('products.reviews_show_less')) : @js(__('products.reviews_show_more'))">{{ __('products.reviews_show_more') }}</span>
                        </button>
                    </div>
                </article>
            @endforeach
        </div>

        {{-- How many are on screen, and the rest a click away. --}}
        <div class="mt-8 flex flex-col items-center gap-3" x-show="total > perPage">
            <p class="text-sm font-semibold text-zinc-700 dark:text-zinc-300" x-text="showingLabel()">
                {{ __('products.reviews_showing', ['shown' => min(10, $reviewTotal), 'total' => number_format($reviewTotal)]) }}
            </p>
            <span class="h-1 w-56 overflow-hidden rounded-full bg-zinc-200 dark:bg-white/10">
                <span class="block h-full rounded-full bg-blue-600 transition-[width] duration-300 dark:bg-blue-500" :style="{ width: progress() }"></span>
            </span>
            <button
                type="button"
                x-show="shown < total"
                @click="more()"
                class="mt-1 rounded-[12px] bg-zinc-100 px-5 py-2.5 text-sm font-semibold text-zinc-900 transition-colors hover:bg-zinc-200 focus:outline-none focus-visible:ring-2 focus-visible:ring-blue-500/40 dark:bg-white/10 dark:text-white dark:hover:bg-white/15"
            >{{ __('products.reviews_load_more') }}</button>
        </div>
    </section>
@endif

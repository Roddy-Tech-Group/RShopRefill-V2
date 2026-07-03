{{--
    Single review card used by the homepage carousel AND the /reviews page
    marquees. Source-agnostic markup: Trustpilot renders the emerald star mark
    + emerald square rating stars, Google renders the multi-colour "G" + gold
    rating stars, anything else (our own "system" reviews) falls back to the
    emerald style. The brand logo sits top-right with the star rating directly
    beneath it, also right-aligned.

    Source labels are case-insensitive ("Trustpilot", "trustpilot", "TRUSTPILOT"
    all match) so admin form options can normalise however editors type them.

    @var \App\Models\Review $review
--}}
@php
    $sourceKey = strtolower(trim((string) $review->source));
    $isGoogle = $sourceKey === 'google';
    // Our own "system" reviews (source set by ReviewController, e.g. "RshopRefills")
    // use the brand mark + blue stars, distinct from Trustpilot's emerald.
    $isSystem = str_contains($sourceKey, 'rshop');

    // Half-star bucketing: 3.5 renders as 3 full + 1 half + 1 empty.
    $r        = max(0, min(5, (float) $review->rating));
    $full     = (int) floor($r);
    $hasHalf  = ($r - $full) >= 0.25 && ($r - $full) < 0.75;
    if (($r - $full) >= 0.75) { $full++; $hasHalf = false; }
    $empty    = 5 - $full - ($hasHalf ? 1 : 0);
@endphp

<article class="flex w-72! shrink-0 flex-col rounded-[14px] dash-shimmer border border-zinc-200 bg-transparent p-5 transition-colors hover:border-green-200 sm:w-80! dark:border-white dark:hover:border-white">

    <div class="flex items-start justify-between gap-3">
        <div class="flex min-w-0 items-start gap-3">
            <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-[12px] bg-zinc-100 text-sm font-bold text-zinc-600 dark:bg-[#26416b] dark:text-zinc-200">{{ $review->initials }}</span>
            <div class="min-w-0 leading-tight">
                <p class="flex items-center gap-1 text-sm font-semibold text-zinc-900 dark:text-white">
                    <span class="truncate">{{ $review->author_name }}</span>
                    @if ($review->user?->isKycVerified())
                        <x-verified-badge size="xs" />
                    @endif
                </p>
                <p class="text-xs text-zinc-600 dark:text-zinc-400">{{ $review->reviewed_at->format('M j, Y') }}</p>
            </div>
        </div>

        {{-- Logo + star rating stacked on the right, stars directly under the logo. --}}
        <div class="flex shrink-0 flex-col items-end gap-1.5">
            <div class="flex items-center gap-1">
                @if ($isGoogle)
                    {{-- Google "G" logo (multi-colour, sourced from the official mark) --}}
                    <svg class="h-4 w-4" viewBox="0 0 48 48" aria-hidden="true">
                        <path fill="#FFC107" d="M43.611 20.083H42V20H24v8h11.303c-1.649 4.657-6.08 8-11.303 8c-6.627 0-12-5.373-12-12s5.373-12 12-12c3.059 0 5.842 1.154 7.961 3.039l5.657-5.657C34.046 6.053 29.268 4 24 4C12.955 4 4 12.955 4 24s8.955 20 20 20s20-8.955 20-20c0-1.341-.138-2.65-.389-3.917"/>
                        <path fill="#FF3D00" d="m6.306 14.691l6.571 4.819C14.655 15.108 18.961 12 24 12c3.059 0 5.842 1.154 7.961 3.039l5.657-5.657C34.046 6.053 29.268 4 24 4C16.318 4 9.656 8.337 6.306 14.691"/>
                        <path fill="#4CAF50" d="M24 44c5.166 0 9.86-1.977 13.409-5.192l-6.19-5.238A11.91 11.91 0 0 1 24 36c-5.202 0-9.619-3.317-11.283-7.946l-6.522 5.025C9.505 39.556 16.227 44 24 44"/>
                        <path fill="#1976D2" d="M43.611 20.083H42V20H24v8h11.303a12.04 12.04 0 0 1-4.087 5.571l.003-.002l6.19 5.238C36.971 39.205 44 34 44 24c0-1.341-.138-2.65-.389-3.917"/>
                    </svg>
                @elseif ($isSystem)
                    {{-- Our own brand mark for system reviews --}}
                    <img src="{{ asset('assets/favicon.ico') }}" alt="" class="h-4 w-4 object-contain" loading="lazy">
                @else
                    {{-- Trustpilot-style emerald star (default for Trustpilot) --}}
                    <svg class="h-3.5 w-3.5 text-emerald-500" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
                        <path d="M12 .587l3.668 7.568L24 9.423l-6 5.951L19.336 24 12 19.897 4.664 24 6 15.374 0 9.423l8.332-1.268z"/>
                    </svg>
                @endif
                <span class="text-[10px] font-bold text-zinc-900 dark:text-white">{{ $review->source }}</span>
            </div>

            <div class="flex gap-0.5" aria-label="{{ number_format($r, 1) }} out of 5 stars">
                @for ($i = 0; $i < $full; $i++)
                    @if ($isGoogle)
                        <svg class="h-4 w-4 text-amber-400" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
                            <path d="M12 .587l3.668 7.568L24 9.423l-6 5.951L19.336 24 12 19.897 4.664 24 6 15.374 0 9.423l8.332-1.268z"/>
                        </svg>
                    @elseif ($isSystem)
                        <svg class="h-4 w-4 text-blue-600 dark:text-blue-400" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
                            <path d="M12 .587l3.668 7.568L24 9.423l-6 5.951L19.336 24 12 19.897 4.664 24 6 15.374 0 9.423l8.332-1.268z"/>
                        </svg>
                    @else
                        <span class="flex h-4 w-4 items-center justify-center bg-emerald-500">
                            <svg class="h-3 w-3 text-white" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
                                <path d="M12 .587l3.668 7.568L24 9.423l-6 5.951L19.336 24 12 19.897 4.664 24 6 15.374 0 9.423l8.332-1.268z"/>
                            </svg>
                        </span>
                    @endif
                @endfor

                @if ($hasHalf)
                    @if ($isGoogle)
                        {{-- Half gold star: SVG with a left-half fill clip --}}
                        <svg class="h-4 w-4 text-amber-400" viewBox="0 0 24 24" aria-hidden="true">
                            <path d="M12 .587l3.668 7.568L24 9.423l-6 5.951L19.336 24 12 19.897 4.664 24 6 15.374 0 9.423l8.332-1.268z" fill="currentColor" fill-opacity="0.25"/>
                            <path d="M12 .587l3.668 7.568L24 9.423l-6 5.951L19.336 24 12 19.897V.587z" fill="currentColor" transform="scale(-1, 1) translate(-24, 0)"/>
                        </svg>
                    @elseif ($isSystem)
                        {{-- Half blue star --}}
                        <svg class="h-4 w-4 text-blue-600 dark:text-blue-400" viewBox="0 0 24 24" aria-hidden="true">
                            <path d="M12 .587l3.668 7.568L24 9.423l-6 5.951L19.336 24 12 19.897 4.664 24 6 15.374 0 9.423l8.332-1.268z" fill="currentColor" fill-opacity="0.25"/>
                            <path d="M12 .587l3.668 7.568L24 9.423l-6 5.951L19.336 24 12 19.897V.587z" fill="currentColor" transform="scale(-1, 1) translate(-24, 0)"/>
                        </svg>
                    @else
                        {{-- Half emerald square: left half emerald, right half neutral grey --}}
                        <span class="relative flex h-4 w-4 overflow-hidden">
                            <span class="absolute inset-y-0 left-0 w-1/2 bg-emerald-500"></span>
                            <span class="absolute inset-y-0 right-0 w-1/2 bg-zinc-300 dark:bg-zinc-600"></span>
                            <svg class="relative z-10 m-auto h-3 w-3 text-white" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
                                <path d="M12 .587l3.668 7.568L24 9.423l-6 5.951L19.336 24 12 19.897 4.664 24 6 15.374 0 9.423l8.332-1.268z"/>
                            </svg>
                        </span>
                    @endif
                @endif

                @for ($i = 0; $i < $empty; $i++)
                    @if ($isGoogle)
                        <svg class="h-4 w-4 text-amber-400/30" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
                            <path d="M12 .587l3.668 7.568L24 9.423l-6 5.951L19.336 24 12 19.897 4.664 24 6 15.374 0 9.423l8.332-1.268z"/>
                        </svg>
                    @elseif ($isSystem)
                        <svg class="h-4 w-4 text-blue-600/20 dark:text-blue-400/20" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
                            <path d="M12 .587l3.668 7.568L24 9.423l-6 5.951L19.336 24 12 19.897 4.664 24 6 15.374 0 9.423l8.332-1.268z"/>
                        </svg>
                    @else
                        <span class="flex h-4 w-4 items-center justify-center bg-zinc-300 dark:bg-zinc-600">
                            <svg class="h-3 w-3 text-white" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
                                <path d="M12 .587l3.668 7.568L24 9.423l-6 5.951L19.336 24 12 19.897 4.664 24 6 15.374 0 9.423l8.332-1.268z"/>
                            </svg>
                        </span>
                    @endif
                @endfor
            </div>
        </div>
    </div>

    <p class="mt-4 line-clamp-5 text-sm leading-relaxed text-zinc-700 dark:text-zinc-300">{{ $review->body }}</p>
</article>

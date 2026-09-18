{{--
    The row-header control cluster: an optional "See more" / "See all" pill
    followed by the carousel's previous / next arrows.

    This markup used to be copy-pasted into every row that had a header
    (the reviews row, the gift-card brand rows, the eSIM explore row). The
    copies drifted - the storefront pill sat at h-10 beside h-8 arrows, and its
    chevrons were drawn a notch smaller - so the same cluster rendered at three
    different sizes depending on the row. It lives here once now, and the rows
    below pass only what actually differs between them: where the pill goes and
    which Alpine methods the arrows call.

    The treatment itself (h-8, rounded-full, white on a zinc ring, the dark
    surface) is the reviews row's, which is the one the design is signed off
    against.

    Usage:
      <x-ui.row-controls
          href="{{ route('shop.reviews') }}"
          label="See more"
          prev="prev()"
          next="next()"
          prev-label="Show previous reviews"
          next-label="Show more reviews"
          :arrows-on-mobile="true"
      />

    Props:
      href            - pill destination; omit for arrows only.
      action          - Alpine expression for a pill that does something rather
                        than going somewhere, e.g. 'more()'. Use href or action,
                        not both; the pill looks the same either way.
      label           - pill text. Defaults to the reviews row's wording, which
                        every row uses, so the pill is the same width sitewide.
      navigate        - add wire:navigate to the pill (SPA nav). Default true.
      arrows          - render the arrows at all. Default true.
      prev / next     - Alpine expressions the arrows run, e.g. 'prev()' or
                        'nudge(-1)'. Omit both to render the pill alone.
      prevDisabled /  - Alpine expressions bound to :disabled, e.g. '! canPrev'.
      nextDisabled      Omit where the row has no reachable end (the reviews
                        carousel loops, so it never disables).
      prevLabel /     - aria-labels for the arrows. These name the row, so
      nextLabel         screen readers can tell two carousels apart.
      arrowsOnMobile  - keep the arrows below sm. The storefront rows hide them
                        because the track is swipeable there; the reviews row
                        shows them. Default false.
--}}
@props([
    'href' => null,
    'action' => null,
    'label' => 'See more',
    'navigate' => true,
    'arrows' => true,
    'prev' => null,
    'next' => null,
    'prevDisabled' => null,
    'nextDisabled' => null,
    'prevLabel' => 'Previous',
    'nextLabel' => 'Next',
    'arrowsOnMobile' => false,
])

@php
    $pillClasses = 'flex h-8 shrink-0 items-center rounded-full bg-white px-4 text-sm font-semibold text-zinc-700 ring-1 ring-zinc-200 shadow-sm transition-colors duration-150 hover:bg-zinc-50 hover:text-zinc-900 focus:outline-none focus-visible:ring-2 focus-visible:ring-blue-500/40 dark:bg-[#1d3252] dark:text-zinc-200 dark:ring-zinc-700/60 dark:hover:bg-[#26416b]';

    // The arrows are the same control as the pill, squared off to h-8 w-8. The
    // display utility is the only thing that varies: the storefront rows keep
    // them off the phone layout, where the track is swiped instead.
    $arrowDisplay = $arrowsOnMobile ? 'flex' : 'hidden sm:flex';
    $arrowClasses = $arrowDisplay.' h-8 w-8 shrink-0 items-center justify-center rounded-full bg-white text-zinc-700 ring-1 ring-zinc-200 shadow-sm transition-colors duration-150 hover:bg-zinc-50 hover:text-zinc-900 focus:outline-none focus-visible:ring-2 focus-visible:ring-blue-500/40 disabled:opacity-30 disabled:cursor-not-allowed dark:bg-[#1d3252] dark:text-zinc-200 dark:ring-zinc-700/60 dark:hover:bg-[#26416b]';

    $showArrows = $arrows && ($prev || $next);
@endphp

<div {{ $attributes->class('flex shrink-0 items-center gap-2') }}>
    {{-- Slot first: rows with a control the pill cannot express (the dashboard
         brand rows use a round "+" link) pass it here and leave href unset. --}}
    {{ $slot }}

    @if ($href)
        <a href="{{ $href }}" @if ($navigate) wire:navigate @endif class="{{ $pillClasses }}">
            {{ $label }}
        </a>
    @elseif ($action)
        <button type="button" x-on:click="{!! $action !!}" class="{{ $pillClasses }}">
            {{ $label }}
        </button>
    @endif

    @if ($showArrows)
        <button
            type="button"
            x-on:click="{!! $prev !!}"
            @if ($prevDisabled) :disabled="{!! $prevDisabled !!}" @endif
            aria-label="{{ $prevLabel }}"
            class="{{ $arrowClasses }}"
        >
            <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                <path d="M15 6l-6 6 6 6"/>
            </svg>
        </button>
        <button
            type="button"
            x-on:click="{!! $next !!}"
            @if ($nextDisabled) :disabled="{!! $nextDisabled !!}" @endif
            aria-label="{{ $nextLabel }}"
            class="{{ $arrowClasses }}"
        >
            <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                <path d="M9 6l6 6-6 6"/>
            </svg>
        </button>
    @endif
</div>

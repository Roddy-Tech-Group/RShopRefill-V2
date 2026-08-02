{{--
    "Payment Methods We Accept" row: every payment method the storefront accepts,
    drifting continuously right to left under the heading.

    The marks are bare - no card, no plate - so the row reads as a quiet band of
    logos rather than another grid of tiles competing with the gift-card rows
    below it.

    The heading is the row's left edge. The track bleeds to the RIGHT only
    (mr-[calc(50%-50vw)]), so marks enter off-screen at the viewport edge and
    travel left until they clip out exactly where the heading starts. That
    arithmetic is the same one brand-row's mx- bleed uses, applied to one side:
    50% of the container's content width minus 50vw is precisely the distance
    from the content edge to the viewport edge.

    The badge list (and the rule that a badge may only claim a rail checkout
    really accepts) lives in PaymentStripBadges. Marks are inline SVG where we
    own no logo asset - the blanket dark-mode invert in app.css targets
    `img[src$=".svg"]` only, so inline artwork keeps its brand colours without
    needing an exception rule per mark.
--}}
@php
    $methods = \App\Domain\Payment\Support\PaymentStripBadges::all();

    // 3s per mark, so the drift keeps the same cadence no matter how many coins
    // the crypto sync has added. A fixed duration would silently speed the whole
    // row up every time a badge joined the list. Floored so the fixed rails alone
    // (no coins configured) still read as a slow drift rather than a dash.
    $marqueeDuration = max(45, count($methods) * 3);
@endphp

<section aria-label="Payment methods we accept" class="mt-12" x-data="{ paused: false }">

    {{-- Header: same shape as the brand rows - title left, controls right. --}}
    <div class="mb-4 flex items-end justify-between gap-4">
        <h2 class="min-w-0 text-lg font-bold text-zinc-900 sm:text-xl dark:text-white">Payment Methods We Accept</h2>

        {{-- WCAG 2.2.2: auto-scrolling content that runs for more than five
             seconds needs a way to stop it, and hover alone leaves out keyboard
             and touch users entirely. This button is also what makes the row
             keyboard-reachable at all - the marks themselves are decorative and
             not focusable. Styled as the brand rows' control buttons. --}}
        <button
            type="button"
            x-on:click="paused = ! paused"
            x-bind:aria-pressed="paused ? 'true' : 'false'"
            x-bind:aria-label="paused ? 'Resume the payment methods animation' : 'Pause the payment methods animation'"
            class="flex h-10 w-10 shrink-0 items-center justify-center rounded-[12px] bg-white text-zinc-700 shadow-sm ring-1 ring-zinc-200 transition-colors duration-150 hover:bg-zinc-50 hover:text-zinc-900 focus:outline-none focus-visible:ring-2 focus-visible:ring-blue-500/40 dark:bg-[#1d3252] dark:text-zinc-200 dark:ring-zinc-700/60 dark:hover:bg-[#26416b]"
        >
            <svg x-show="! paused" class="h-4 w-4" fill="currentColor" viewBox="0 0 12 12" aria-hidden="true"><rect x="2" y="1.5" width="3" height="9" rx="1"/><rect x="7" y="1.5" width="3" height="9" rx="1"/></svg>
            <svg x-show="paused" x-cloak class="h-4 w-4" fill="currentColor" viewBox="0 0 12 12" aria-hidden="true"><path d="M3 1.6 10.2 6 3 10.4Z"/></svg>
        </button>
    </div>

    {{-- The marquee CLIPS rather than scrolls: an animated translateX inside an
         overflow-x-auto box keeps rewriting the scroll range, which fights a
         touch drag and can never settle. That clip is also what lands the left
         cut-off on the heading. Under prefers-reduced-motion the animation stops,
         the clone is hidden and this becomes a normal scrollable row instead
         (see app.css). --}}
    <div
        class="pay-strip mr-[calc(50%-50vw)]"
        style="--pay-strip-duration: {{ $marqueeDuration }}s"
        x-bind:class="paused && 'pay-strip-paused'"
    >
        <div class="pay-strip-track flex w-max items-center">
            {{-- Two passes: the first is the real list, the second a visual clone
                 hidden from assistive tech, so the seamless loop reads as one set
                 of methods rather than a duplicated one. Each mark carries its own
                 trailing margin (never a flex `gap`): the loop translates exactly
                 -50%, which only equals one full copy when the spacing belongs to
                 the items themselves. --}}
            @foreach ([false, true] as $isClone)
                @foreach ($methods as $method)
                    <div
                        class="mr-7 flex h-[52px] w-[104px] shrink-0 items-center justify-center sm:mr-9"
                        @if ($isClone) aria-hidden="true" @endif
                    >
                        @isset($method['img'])
                            <img
                                src="{{ asset('assets/' . rawurlencode($method['img'])) }}"
                                alt="{{ $isClone ? '' : $method['label'] }}"
                                @class([
                                    'h-8 w-auto max-w-full object-contain',
                                    // Monochrome black glyphs need the global
                                    // dark invert; colour brand marks must opt
                                    // out of it or they flatten to white.
                                    'brightness-0 dark:invert' => $method['mono'] ?? false,
                                    'no-dark-invert' => ! ($method['mono'] ?? false),
                                ])
                                loading="lazy"
                            >
                        @else
                            <x-home.payment-mark :mark="$method['svg']" :label="$isClone ? null : $method['label']" />
                        @endisset
                    </div>
                @endforeach
            @endforeach
        </div>
    </div>
</section>

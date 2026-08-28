{{--
    "Payment Methods We Accept": every payment method the storefront accepts,
    laid out as a static pyramid - narrow row on top, widening to the base.

    It used to be a marquee that drifted right to left. That is gone: with no
    animation there is nothing to pause, so the WCAG 2.2.2 pause control and the
    aria-hidden clone pass went with it, and the row no longer bleeds to the
    viewport edge. Everything is centred inside the panel instead.

    The marks are bare - no card, no plate - so the block reads as a quiet field
    of logos rather than another grid of tiles competing with the gift-card rows
    below it.

    The badge list (and the rule that a badge may only claim a rail checkout
    really accepts) lives in PaymentStripBadges. Marks are inline SVG where we
    own no logo asset - the blanket dark-mode invert in app.css targets
    `img[src$=".svg"]` only, so inline artwork keeps its brand colours without
    needing an exception rule per mark.
--}}
@php
    $methods = \App\Domain\Payment\Support\PaymentStripBadges::all();

    // Split the marks into rows that widen toward the base: 3, then 5, then 7,
    // and so on. Computed rather than hardcoded because the crypto sync adds
    // coins to this list - a fixed 3/5/7/8 would break the shape the first time
    // a badge joined. The base row absorbs whatever is left over, and a trailing
    // row of one is folded back into the row above it so the pyramid never ends
    // on a lone mark.
    $paymentRows = [];
    $offset = 0;
    $size = 3;
    $total = count($methods);

    while ($offset < $total) {
        $take = min($size, $total - $offset);
        $paymentRows[] = array_slice($methods, $offset, $take);
        $offset += $take;
        $size += 2;
    }

    // The last row takes whatever is left over, which can leave it NARROWER
    // than the row above it - 17 marks split 3/5/7/2, which is not a pyramid.
    // Fold any short base back into the row above until the shape widens all
    // the way down. 17 becomes 3/5/9; 23 stays 3/5/7/8.
    while (count($paymentRows) > 1
        && count($paymentRows[count($paymentRows) - 1]) < count($paymentRows[count($paymentRows) - 2])) {
        $tail = array_pop($paymentRows);
        $paymentRows[count($paymentRows) - 1] = array_merge(
            $paymentRows[count($paymentRows) - 1],
            $tail
        );
    }
@endphp

<section aria-label="Payment methods we accept">

    <h2 class="text-center text-lg font-bold text-zinc-900 sm:text-xl dark:text-white">Payment Methods We Accept</h2>

    <div class="mt-7 flex flex-col items-center gap-5 sm:gap-7">
        @foreach ($paymentRows as $row)
            <div class="flex flex-wrap items-center justify-center gap-x-7 gap-y-5 sm:gap-x-10">
                @foreach ($row as $method)
                    <div class="flex h-[52px] w-[104px] shrink-0 items-center justify-center">
                        @isset($method['img'])
                            <img
                                src="{{ asset('assets/' . rawurlencode($method['img'])) }}"
                                alt="{{ $method['label'] }}"
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
                            <x-home.payment-mark :mark="$method['svg']" :label="$method['label']" />
                        @endisset
                    </div>
                @endforeach
            </div>
        @endforeach
    </div>
</section>

{{--
    Assurance strip: three promises, then the payment methods that back them.

    Sits directly under the four category cards, so the order reads: here is
    what we sell, here is what you get, here is how you pay.

    The payment marks come from PaymentStripBadges, the same source checkout
    uses, so a badge can only appear if that rail is genuinely payable. A
    hardcoded row would keep advertising a coin after it was retired, which is
    a promise the checkout would then break.

    Marks are capped so the row stays one line on a laptop; the count that was
    dropped is announced to screen readers rather than silently hidden.
--}}
@php
    $methods = \App\Domain\Payment\Support\PaymentStripBadges::all();

    // One row, not a pyramid: this is a reassurance line under the cards, not
    // the full "everything we accept" block further down the page.
    //
    // Picked BY METHOD rather than by taking the first N, and in the order the
    // sentence above reads: mobile money, wallet, cards, crypto. Taking the
    // first nine off the top filled every slot with cards and pushed crypto
    // out entirely, so the row contradicted the line it sits under.
    //
    // Still sourced from PaymentStripBadges, so a rail that stops being
    // payable disappears from here too; only the ORDER and the per-method
    // count are decided here.
    $pick = function (string $method, int $limit) use ($methods) {
        return array_slice(
            array_values(array_filter($methods, fn ($m) => ($m['method'] ?? null) === $method)),
            0,
            $limit
        );
    };

    $shown = [
        ...$pick('mobile_money', 2),   // MoMo, Orange Money
        ...$pick('apple_pay', 1),
        ...$pick('card', 2),           // the two networks nearly everyone holds
        ...$pick('crypto', 4),
    ];

    $extra = max(0, count($methods) - count($shown));

    // Stroke-only marks on a 24x24 grid, matching the category row's idiom.
    $promises = [
        [
            'title' => __('home.assurance_instant_title'),
            'path'  => 'M13 2.5 4.5 13.5H11l-1 8 8.5-11H12z',
        ],
        [
            'title' => __('home.assurance_privacy_title'),
            'path'  => 'M12 3 4.5 6v5.5c0 4.4 3.2 8.1 7.5 9 4.3-.9 7.5-4.6 7.5-9V6zM9.2 12.2l2 2 3.6-3.8',
        ],
        [
            'title' => __('home.assurance_support_title'),
            'path'  => 'M12 3a9 9 0 0 0-9 9v4.5A2.5 2.5 0 0 0 5.5 19H7v-6H5.2M12 3a9 9 0 0 1 9 9v4.5A2.5 2.5 0 0 1 18.5 19H17v-6h1.8',
        ],
    ];
@endphp

{{-- No card of its own. This sits INSIDE the trust panel, which is already a
     surface, so a second panel here would be a box in a box - the same reason
     the trust signals above it are a plain row rather than four tiles. --}}
<section aria-label="{{ __('home.assurance_label') }}" class="mt-10">
    <div>

        {{-- Promises. Centred and wrapping, so three items never orphan one
             onto a line of its own at an awkward width. --}}
        <ul class="flex flex-wrap items-center justify-center gap-x-10 gap-y-4">
            @foreach ($promises as $promise)
                <li class="flex items-center gap-2.5">
                    {{-- 24px at stroke 2.4, up from 20px at 1.75.

                         These are the only marks on the panel that carry a
                         promise rather than a brand, and at the old weight they
                         read as hairlines beside 15px text with real weight in
                         it, so the line looked like text with decoration rather
                         than three claims. The stroke is scaled by more than the
                         box on purpose: growing the box alone thins a stroke
                         visually, because the same width covers a larger shape.

                         Blue, the same pair as the counts above the rule. Green
                         made these three read as status - three things having
                         passed - rather than as three claims about the service,
                         and it was the only green on the panel. --}}
                    <svg class="h-6 w-6 shrink-0 text-blue-600 dark:text-blue-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                        <path d="{{ $promise['path'] }}" />
                    </svg>
                    <span class="text-[15px] text-zinc-900 dark:text-white">{{ $promise['title'] }}</span>
                </li>
            @endforeach
        </ul>

        @if (! empty($shown))
            {{-- Payment line. The label and the marks sit on one row from sm up
                 and stack on a phone, where a nine-mark row beside text would
                 shrink the marks past recognition. --}}
            <div class="mt-6 flex flex-col items-center gap-x-6 gap-y-4 sm:flex-row sm:justify-center">
                <p class="text-[15px] text-zinc-900 dark:text-white">
                    {{ __('home.assurance_pay_with') }}
                </p>

                <ul class="flex flex-wrap items-center justify-center gap-1.5">
                    @foreach ($shown as $method)
                        @php $isCoin = ($method['method'] ?? null) === 'crypto'; @endphp
                        <li
                            {{-- Logos only. Several marks pair an icon with a wordmark
                                 beside it, which is right in the full payment block but
                                 noisy in a single reassurance row. The variant hides a
                                 span that FOLLOWS the mark, so icon+text marks lose the
                                 text while marks that ARE a wordmark keep theirs.

                                 It matches an img as well as an svg. It used to name
                                 svg alone, which was true only for as long as every
                                 mark was drawn inline: the first file-backed mark with
                                 a label beside it kept its text, blew past this 48px
                                 chip and overlapped its neighbour.

                                 Coins wear no chip: they are already circular badges with
                                 their own colour, and a white square behind a round coin
                                 reads as a mistake. Card and wallet marks do need the
                                 chip, because their artwork assumes a light ground. --}}
                            @class([
                                'flex h-9 items-center justify-center [&_:is(svg,img)~span]:hidden',
                                'w-12 rounded-[8px] bg-[#ffffff] dark:bg-white/10' => ! $isCoin,
                                'w-9' => $isCoin,
                            ])
                            title="{{ $method['label'] }}"
                        >
                            @isset($method['img'])
                                {{-- A file-backed mark. Monochrome glyphs need the
                                     global dark invert; colour brand marks must opt
                                     out of it or they flatten to white. --}}
                                <img
                                    src="{{ asset('assets/'.rawurlencode($method['img'])) }}"
                                    alt="{{ $method['label'] }}"
                                    class="{{ $isCoin ? 'h-7 w-7' : 'h-5 w-auto max-w-[2.25rem]' }} object-contain {{ ($method['mono'] ?? false) ? 'brightness-0 dark:invert' : 'no-dark-invert' }}"
                                    loading="lazy"
                                >
                            @else
                                <x-home.payment-mark :mark="$method['svg']" :label="$method['label']" />
                            @endisset
                        </li>
                    @endforeach

                    @if ($extra > 0)
                        {{-- Announced, not hidden: the row is capped for layout,
                             and a silent cap would read as "these are all of
                             them" when it is not. --}}
                        <li class="flex h-9 items-center rounded-[6px] bg-[#ffffff] px-2.5 text-[12px] font-semibold text-blue-600 dark:bg-white/10 dark:text-blue-400">
                            {{ __('home.assurance_more_methods', ['count' => $extra]) }}
                        </li>
                    @endif
                </ul>
            </div>
        @endif
    </div>
</section>

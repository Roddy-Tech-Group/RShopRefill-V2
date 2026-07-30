{{--
    "Pay your way" strip under the category cards: every payment method the
    storefront accepts, scrolling as a marquee so the full list fits on one line
    instead of wrapping into a tall block. The centred triangle notch points up
    at the cards to give the strip its shape.

    The badge list (and the rule that a badge may only claim a rail checkout
    really accepts) lives in PaymentStripBadges. Marks are inline SVG where we
    own no logo asset - the blanket dark-mode invert in app.css targets
    `img[src$=".svg"]` only, so inline artwork keeps its brand colours without
    needing an exception rule per mark.

    Surfaces use .pay-strip-card / .pay-strip-notch rather than
    `bg-white dark:bg-black`: app.css globally remaps `.dark .bg-white` to
    #2a2a2a (#363636 in soft dark) with higher specificity than Tailwind's
    `dark:` variant, so `dark:bg-black` never applied and the notch ended up
    pure black against a grey panel. Those two classes pin both to the same
    value in every theme tier.
--}}
@php
    $methods = \App\Domain\Payment\Support\PaymentStripBadges::all();
@endphp

<section aria-label="Accepted payment methods" class="relative mt-10">
    {{-- Triangle pointer - notches up toward the category cards. --}}
    <span aria-hidden="true" class="pay-strip-notch absolute -top-3 left-1/2 h-0 w-0 -translate-x-1/2 border-x-[14px] border-b-[13px] border-x-transparent"></span>

    <div
        x-data="{ paused: false }"
        class="pay-strip-card overflow-hidden rounded-[35px] py-6 ring-1 ring-zinc-200/60 dark:ring-zinc-700/60"
    >
        <div class="flex items-center justify-center gap-2 px-5 sm:px-8">
            <p class="text-xs font-bold uppercase tracking-[0.14em] text-zinc-500 dark:text-zinc-400">Pay your way</p>

            {{-- WCAG 2.2.2: auto-scrolling content that runs for more than five
                 seconds needs a way to stop it, and hover alone leaves out
                 keyboard and touch users entirely. This button is also what
                 makes the strip keyboard-reachable at all - the badges
                 themselves are decorative and not focusable. --}}
            <button
                type="button"
                x-on:click="paused = ! paused"
                x-bind:aria-pressed="paused ? 'true' : 'false'"
                x-bind:aria-label="paused ? 'Resume the payment methods animation' : 'Pause the payment methods animation'"
                class="inline-flex h-5 w-5 items-center justify-center rounded-full text-zinc-400 transition-colors hover:text-zinc-700 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-blue-500/50 dark:hover:text-zinc-200"
            >
                <svg x-show="! paused" class="h-3 w-3" fill="currentColor" viewBox="0 0 12 12" aria-hidden="true"><rect x="2" y="1.5" width="3" height="9" rx="1"/><rect x="7" y="1.5" width="3" height="9" rx="1"/></svg>
                <svg x-show="paused" x-cloak class="h-3 w-3" fill="currentColor" viewBox="0 0 12 12" aria-hidden="true"><path d="M3 1.6 10.2 6 3 10.4Z"/></svg>
            </button>
        </div>

        {{-- The marquee clips rather than scrolls: an animated translateX inside
             an overflow-x-auto box keeps rewriting the scroll range, which fights
             a touch drag and can never settle. Under prefers-reduced-motion the
             animation stops, the clone is hidden and this becomes a normal
             scrollable row instead (see app.css). --}}
        <div class="pay-strip mt-5" x-bind:class="paused && 'pay-strip-paused'">
            <div class="pay-strip-track flex w-max items-center">
                {{-- Two passes: the first is the real list, the second a visual
                     clone hidden from assistive tech, so the seamless loop reads
                     as one set of methods rather than a duplicated one. Each
                     badge carries its own trailing margin (never a flex `gap`):
                     the loop translates exactly -50%, which only equals one full
                     copy when the spacing belongs to the items themselves. --}}
                @foreach ([false, true] as $isClone)
                    @foreach ($methods as $method)
                        <div
                            class="flex h-[52px] w-[104px] shrink-0 items-center justify-center mr-7 sm:mr-9"
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
    </div>
</section>

{{--
    Homepage FAQ. Heading on the left, accordion on the right from lg; stacked
    below that.

    Questions come from the `faqs` table, so the admin FAQ editor is the one
    place they are written. A hardcoded list here would drift from the /faq
    page the moment either was edited, and there would be no way to change the
    homepage copy without a deploy.

    Scoped to the General topic: the other topics are support material (PIN
    resets, disputes) that belong on the FAQ page, not on a shop front.

    One item open at a time. `open` holds an id rather than a boolean per row,
    so opening one closes the last without any bookkeeping.

    Cached per locale. The cached rows are already-translated strings, so one
    shared key served whichever language happened to render first to every
    visitor for the next 30 minutes.
--}}
@props([
    // Which FAQ topics to pull, in the order given. Defaults to the homepage's
    // General set, so existing callers are unchanged. A catalog page passes the
    // topics that answer what a shopper on THAT page is about to ask.
    'topics' => ['General'],
    // The heading's id, so two of these on one page cannot collide.
    'headingId' => 'home-faq-heading',
    // The section's own top margin. A prop so a page that wants the FAQ to run
    // straight on from the block above it can pass '' without changing the
    // spacing every other caller relies on.
    'topMargin' => 'mt-12 lg:mt-16',
    // How many questions to show. The homepage wants a short list; a page whose
    // whole job is answering questions can carry its full set.
    'limit' => 10,
    // Optional. A width in px for the panel to START at, so it can line up with
    // the section above it and widen to its full 1550 as the reader scrolls it
    // into view. Left null the panel is simply full width, which is how every
    // existing caller behaves.
    'growFrom' => null,
])

@php
    $faqTopics = array_values((array) $topics);
    $faqLimit = max(1, (int) $limit);

    $faqs = \Illuminate\Support\Facades\Cache::remember(
        'home:faqs:v2:'.md5(implode('|', $faqTopics).'|'.$faqLimit).':'.app()->getLocale(),
        now()->addMinutes(30),
        fn () => \App\Models\Faq::query()
            ->withTranslations()
            ->where('is_published', true)
            ->whereIn('topic', $faqTopics)
            ->orderBy('sort_order')
            ->limit($faqLimit)
            ->get(['id', 'question', 'answer', 'topic'])
            // Topic order follows the order they were asked for, not the table's.
            ->sortBy(fn ($f) => array_search($f->topic, $faqTopics, true))
            ->values()
            ->map(fn ($f) => ['id' => $f->id, 'q' => $f->question, 'a' => $f->answer])
            ->all()
    );
@endphp

@if (! empty($faqs))
    {{-- Breaks out of the page column to match the footer: .full-bleed spans the
         window (minus the measured scrollbar), and the thin padding below is the
         footer band's, so the two slabs share one edge. --}}
    <section
        aria-labelledby="{{ $headingId }}"
        class="full-bleed {{ $topMargin }} px-2 sm:px-3 lg:px-4"
    >
        {{-- Surfaces here follow the project rule (on #f4f6f5 use #ffffff, on
             #ffffff use #f4f6f5), but note WHICH link of the chain this is.
             The section above is `full-bleed`: it spans the window, so this
             panel is not a card sitting on the page, it IS the page surface
             across that band. It therefore stays #ffffff like the page, and
             the question cards on it take #f4f6f5. Painting the band grey
             makes it read as the page background having changed colour, which
             is the trap this note exists to stop.

             Same container as the rest of the page: max-w-[1550px] centred
             with the standard gutters. Without it the panel ran to whatever
             width its parent happened to be, so on a 2560px screen it sat
             pinned left at ~984px while every row above it centred at 1486. --}}
        <div
            @if ($growFrom)
                {{-- Scroll-linked width, symmetric. The panel enters at the width of
                     whatever sits above it, opens to 1550 over a full viewport of
                     scrolling, holds there while it is being read, then closes back
                     to its starting width over another viewport as it leaves past
                     the top. So the two sections read as one column that opens out
                     and folds away again, rather than a narrow block followed by a
                     permanently wide one. Slow on purpose: the move should be
                     watchable, not a snap you notice only in hindsight.

                     Width is set inline, which beats the max-w-[1550px] class, so
                     the class stays the resting value for anyone without JS. The
                     scroll handler is rAF-coalesced and passive, and honours
                     prefers-reduced-motion by leaving the panel at full width. --}}
                x-data="{
                    from: {{ (int) $growFrom }},
                    to: 1550,
                    raf: null,
                    onScroll: null,
                    init() {
                        if (window.matchMedia('(prefers-reduced-motion: reduce)').matches) { return; }
                        this.apply();
                        this.onScroll = () => {
                            if (this.raf) { return; }
                            this.raf = requestAnimationFrame(() => { this.raf = null; this.apply(); });
                        };
                        window.addEventListener('scroll', this.onScroll, { passive: true });
                        window.addEventListener('resize', this.onScroll, { passive: true });
                    },
                    apply() {
                        const r = this.$el.getBoundingClientRect();
                        const vh = window.innerHeight;

                        // A FULL viewport of scrolling to open, and another to close.
                        // At half that the move was over before it registered as
                        // movement; spread this wide you watch it happen.
                        const span = Math.max(1, vh);

                        // Two independent ramps, and the SMALLER one wins. `opening`
                        // runs 0 to 1 as the panel's top rises from the foot of the
                        // viewport; `closing` runs 1 to 0 as its bottom reaches the
                        // top. Taking the minimum gives one curve that opens on the
                        // way in, sits at full width for the whole middle of the
                        // scroll, and folds back on the way out, without either end
                        // needing to know about the other.
                        const opening = (vh - r.top) / span;
                        const closing = r.bottom / span;
                        const t = Math.min(1, Math.max(0, Math.min(opening, closing)));

                        // Smoothstep. A straight ramp starts and stops abruptly at
                        // both ends; this eases in and out, so the panel creeps open,
                        // moves through the middle, and settles rather than snapping.
                        const p = t * t * (3 - 2 * t);

                        this.$el.style.maxWidth = Math.round(this.from + (this.to - this.from) * p) + 'px';
                    },
                    destroy() {
                        window.removeEventListener('scroll', this.onScroll);
                        window.removeEventListener('resize', this.onScroll);
                    },
                }"
            @endif
            {{-- pure-card is the project's opt-in for the grainy dark surface:
                 `.dark .pure-card` in app.css paints the ash tone AND the grain
                 texture, the same one the sidebar and the cards wear. It carries
                 no light-mode rule, so #ffffff above is untouched. Replaces a
                 plain `dark:bg-[var(--ash)]`, which had the colour but none of
                 the texture, so this panel was the one flat slab in dark. --}}
            class="pure-card mx-auto w-full max-w-[1550px] rounded-[20px] bg-[#ffffff] px-5 py-10 transition-[max-width] duration-300 ease-out sm:px-8 sm:py-14 lg:px-10"
        >
        <div class="grid grid-cols-1 gap-8 lg:grid-cols-12 lg:gap-12">

            {{-- Heading column. Sticky from lg so the title stays with the list
                 while a long accordion scrolls past it. --}}
            <div class="lg:col-span-5">
                <div class="lg:sticky lg:top-24">
                    <h2 id="{{ $headingId }}" class="text-[30px] leading-[1.1] tracking-tight text-zinc-900 sm:text-[38px] dark:text-white">
                        {{ __('home.faq_heading') }}
                    </h2>
                    <p class="mt-3 text-base text-zinc-600 dark:text-zinc-400">
                        {!! __('home.faq_subheading', [
                            'link' => '<a href="'.route('shop.faq').'" wire:navigate class="font-semibold text-zinc-900 underline underline-offset-4 transition-colors hover:text-blue-700 dark:text-white dark:hover:text-blue-300">'.e(__('home.faq_link_label')).'</a>',
                        ]) !!}
                    </p>
                </div>
            </div>

            {{-- Accordion --}}
            {{-- The first question starts open, so the panel shows an answer
                 rather than a stack of closed rows. --}}
            <div x-data="{ open: {{ $faqs[0]['id'] }} }" class="lg:col-span-7">
                @foreach ($faqs as $faq)
                    <div class="mt-2.5 first:mt-0 overflow-hidden rounded-[12px] bg-[#f4f6f5] px-4 dark:bg-white/[0.06]">
                        <h3>
                            <button
                                type="button"
                                @click="open = open === {{ $faq['id'] }} ? null : {{ $faq['id'] }}"
                                :aria-expanded="(open === {{ $faq['id'] }}).toString()"
                                aria-controls="faq-panel-{{ $faq['id'] }}"
                                class="flex w-full items-center justify-between gap-6 py-5 text-left transition-colors hover:text-blue-700 focus:outline-none focus-visible:ring-2 focus-visible:ring-blue-500/40 dark:hover:text-blue-300"
                            >
                                <span class="text-[17px] font-bold leading-snug text-zinc-900 dark:text-white">{{ $faq['q'] }}</span>

                                {{-- One chevron that rotates, rather than swapping
                                     two icons: nothing to mismatch, and it animates. --}}
                                <svg
                                    class="h-5 w-5 shrink-0 text-zinc-500 transition-transform duration-200 motion-reduce:transition-none dark:text-zinc-400"
                                    :class="open === {{ $faq['id'] }} && '-rotate-180'"
                                    fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"
                                    stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"
                                >
                                    <path d="M6 9l6 6 6-6" />
                                </svg>
                            </button>
                        </h3>

                        <div
                            id="faq-panel-{{ $faq['id'] }}"
                            x-show="open === {{ $faq['id'] }}"
                            x-collapse
                            @unless ($loop->first) x-cloak @endunless
                        >
                            <p class="pb-5 pr-8 text-[15px] leading-relaxed text-zinc-600 dark:text-zinc-300">
                                {{ $faq['a'] }}
                            </p>
                        </div>
                    </div>
                @endforeach
            </div>
        </div>
        </div>
    </section>

    {{-- FAQPage structured data. Built from the same rows the accordion renders,
         so the markup and what search engines are told can never disagree. --}}
    <script type="application/ld+json">
        {!! json_encode([
            '@context' => 'https://schema.org',
            '@type' => 'FAQPage',
            'mainEntity' => array_map(fn ($f) => [
                '@type' => 'Question',
                'name' => $f['q'],
                'acceptedAnswer' => ['@type' => 'Answer', 'text' => $f['a']],
            ], $faqs),
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) !!}
    </script>
@endif

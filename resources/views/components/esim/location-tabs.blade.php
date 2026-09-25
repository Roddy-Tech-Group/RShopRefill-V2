@php
    // eSIM location tabs (Popular / Local / Regional / Global / All) with icons,
    // spread evenly across the full width. Relies on Alpine `locTab` in the parent
    // scope. Shared by the eSIM landing and the country store so they're identical.
    $tabs = [
        'popular'  => ['label' => __('esim.tab_popular'),  'icon' => '<path stroke-linecap="round" stroke-linejoin="round" d="M15.59 14.37a6 6 0 01-5.84 7.38v-4.8m5.84-2.58a14.98 14.98 0 006.16-12.12A14.98 14.98 0 009.631 8.41m5.96 5.96a14.926 14.926 0 01-5.841 2.58m-.119-8.54a6 6 0 00-7.381 5.84h4.8m2.581-5.84a14.927 14.927 0 00-2.58 5.84m2.699 2.7c-.103.021-.207.041-.311.06a15.09 15.09 0 01-2.448-2.448 14.9 14.9 0 01.06-.312m-2.24 2.39a4.493 4.493 0 00-1.757 4.306 4.493 4.493 0 004.306-1.758M16.5 9a1.5 1.5 0 11-3 0 1.5 1.5 0 013 0z"/>'],
        'local'    => ['label' => __('esim.tab_local'),    'icon' => '<path stroke-linecap="round" stroke-linejoin="round" d="M15 10.5a3 3 0 11-6 0 3 3 0 016 0z"/><path stroke-linecap="round" stroke-linejoin="round" d="M19.5 10.5c0 7.142-7.5 11.25-7.5 11.25S4.5 17.642 4.5 10.5a7.5 7.5 0 1115 0z"/>'],
        'regional' => ['label' => __('esim.tab_regional'), 'icon' => '<path stroke-linecap="round" stroke-linejoin="round" d="M9 6.75V15m6-6v8.25m.503 3.498l4.875-2.437c.381-.19.622-.58.622-1.006V4.82c0-.836-.88-1.38-1.628-1.006l-3.869 1.934c-.317.159-.69.159-1.006 0L9.503 3.252a1.125 1.125 0 00-1.006 0L3.622 5.689C3.24 5.88 3 6.27 3 6.695V19.18c0 .836.88 1.38 1.628 1.006l3.869-1.934c.317-.159.69-.159 1.006 0l4.994 2.497c.317.158.69.158 1.006 0z"/>'],
        'global'   => ['label' => __('esim.tab_global'),   'icon' => '<path stroke-linecap="round" stroke-linejoin="round" d="M12 21a9 9 0 100-18 9 9 0 000 18zm0 0c2.5 0 4.5-4.03 4.5-9S14.5 3 12 3 7.5 7.03 7.5 12s2 9 4.5 9zM3.6 9h16.8M3.6 15h16.8"/>'],
        'unlimited' => ['label' => __('esim.tab_unlimited'), 'icon' => ''],
    ];
@endphp

{{-- One segmented control: a white track holding the four tabs, with a single
     dark thumb that SLIDES to whichever is chosen rather than each pill turning
     its own fill on and off.

     The thumb is one absolutely positioned span; move() measures the selected
     button and writes its width and offset, so the travel is a transform and
     costs nothing to animate. Alpine's locTab still owns which tab is chosen,
     and the tabs carry aria-selected, so the control stays a real tablist for
     anything reading the page rather than looking at it.

     Colour lives in the style block, not in a :class string: two rules, one per
     theme, cannot tie the way stacked dark: variants can. --}}
<style>
    .esim-tab[aria-selected="true"] { color: #ffffff; }
    /* app.css carries `html:not(.dark) body.storefront .text-zinc-700 { color:
       #4a5a72 }`, which is more specific than the rule above and was painting
       the chosen tab's label slate on its own navy thumb. Matching that scope
       here puts the white back. */
    html:not(.dark) body.storefront .esim-tab[aria-selected="true"] { color: #ffffff; }
    html.dark .esim-tab[aria-selected="true"] { color: #0c1337; }
    .esim-tab:not([aria-selected="true"]):hover { color: #0c1337; opacity: 0.7; }
    html.dark .esim-tab:not([aria-selected="true"]):hover { color: #ffffff; opacity: 1; }
    html.dark .esim-tab:not([aria-selected="true"]):hover { color: #ffffff; }
    @media (prefers-reduced-motion: reduce) { .esim-thumb { transition: none; } }
</style>
<div
    x-data="{
        move() {
            const track = this.$refs.track;
            const thumb = this.$refs.thumb;
            // Unquoted attribute value on purpose: nested quotes inside an x-data
            // attribute collapse when it is rendered, which is what silently broke
            // this and left the thumb at zero width. CSS takes an identifier bare.
            const active = track?.querySelector('[aria-selected=true]');
            if (! track || ! thumb || ! active) { return; }
            thumb.style.width = active.offsetWidth + 'px';
            thumb.style.transform = 'translateX(' + active.offsetLeft + 'px)';
            this.reveal(track, active);
        },

        /*
         * Keep the chosen tab in view. On a phone the control is capped at the
         * screen and scrolls, so Unlimited sits off the right edge: choosing it
         * from a deep link, or landing on a page whose scope is already set,
         * would slide the thumb somewhere nobody can see.
         *
         * scrollTo on the track, not scrollIntoView on the tab: the latter
         * scrolls every scrollable ancestor, so on a sticky control it drags
         * the whole page as well.
         */
        reveal(track, active) {
            const pad = 8;
            const left = active.offsetLeft;
            const right = left + active.offsetWidth;

            if (left < track.scrollLeft) {
                track.scrollTo({ left: Math.max(0, left - pad), behavior: 'smooth' });
            } else if (right > track.scrollLeft + track.clientWidth) {
                track.scrollTo({ left: right - track.clientWidth + pad, behavior: 'smooth' });
            }
        },
    }"
    x-ref="track"
    x-init="$nextTick(() => move())"
    {{-- Runs whenever the chosen tab changes, after Alpine has written the new
         aria-selected, which is what move() measures. --}}
    x-effect="locTab; $nextTick(() => move())"
    @resize.window.debounce.100ms="move()"
    x-on:livewire:navigated.window="$nextTick(() => move())"
    {{-- w-max so the control hugs its tabs, max-w-full so it can never be wider
         than the screen: on a phone the five tabs run to about 500px, and
         without the cap the pill pushed past the viewport and took the page's
         horizontal scroll with it. Capped, its own overflow-x-auto scrolls the
         tabs inside the pill instead, and reveal() keeps the chosen one in
         view.

         Overflow is auto at every width now. It used to go visible from sm,
         which was safe only while w-max made the box fit its content by
         definition; with the cap, a tablet-width screen can be narrower than
         the tabs, and visible overflow there would spill them outside the
         white track. --}}
    class="relative mx-auto flex w-max max-w-full items-center gap-1 overflow-x-auto rounded-full bg-[#ffffff] p-1 [scrollbar-width:none] [&::-webkit-scrollbar]:hidden dark:bg-white/5"
    role="tablist"
>
    <span
        x-ref="thumb"
        aria-hidden="true"
        class="esim-thumb pointer-events-none absolute bottom-1 left-0 top-1 w-0 rounded-full bg-[#0c1337] transition-[transform,width] duration-300 ease-out dark:bg-[#fdfdfd]"
    ></span>

    @foreach ($tabs as $key => $tab)
        <button
            type="button"
            @click="locTab = '{{ $key }}'"
            role="tab"
            :aria-selected="(locTab === '{{ $key }}').toString()"
            aria-selected="{{ $loop->first ? 'true' : 'false' }}"
            class="esim-tab relative z-10 shrink-0 rounded-full px-5 py-2.5 text-[16px] text-[#0c1337] transition-colors focus:outline-none dark:text-zinc-200"
        >
            {{ $tab['label'] }}
        </button>
    @endforeach
</div>

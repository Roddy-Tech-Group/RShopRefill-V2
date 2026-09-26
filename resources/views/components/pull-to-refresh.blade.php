{{-- Native-style pull-to-refresh for the installed PWA. Standalone mode
     disables the browser's own pull-to-refresh, so we provide our own: pull
     down at the top of the page past the threshold and release to refresh,
     with a glassy spinner that follows the pull. Disabled on the normal web
     (the browser already does this) and while a modal has the scroll locked
     (body becomes position:fixed).

     Two things make it feel like the real thing rather than a reload button.

     The spinner says when it is armed: under the threshold it is a quiet
     grey and nothing will happen, and the moment a release would fire it
     darkens and snaps up a size. Pulling is a gesture with no click to commit
     it, so the control has to answer while the finger is still down. Greys
     rather than a colour, because the wheel is the platform's own shape and
     a brand colour on it reads as a different control.

     And the refresh keeps the page on screen. A hard reload drops the
     document the instant it commits, so the customer watched a white screen
     with the platform's own grey spinner on it until the server answered.
     Livewire fetches the page and swaps it in instead, which leaves our
     spinner up and the content underneath it the whole way. Livewire keeps a
     cache for back and forward, and a refresh that served a cached copy would
     be a refresh that refreshed nothing, so that case falls back to the hard
     reload it always did. --}}
<div id="rshop-ptr" aria-hidden="true">
    <div class="rshop-ptr-circle">
        {{-- Twelve spokes round a hub, each one fainter than the last. The
             fade is the whole trick: it gives the wheel a head, so even a
             still frame reads as already turning, and a step per spoke then
             turns it the way the eye expects rather than sliding it.

             Drawn rather than pulled in as an image so it takes currentColor
             and follows the state of the pull, and so there is no second
             request between letting go and seeing that something happened. --}}
        <svg class="rshop-ptr-spin" viewBox="0 0 24 24" fill="none" aria-hidden="true">
            <g class="rshop-ptr-spokes" stroke="currentColor" stroke-width="2.3" stroke-linecap="round">
            <line x1="12" y1="3.4" x2="12" y2="7.6" transform="rotate(0 12 12)" opacity="1"/>
            <line x1="12" y1="3.4" x2="12" y2="7.6" transform="rotate(30 12 12)" opacity="0.928"/>
            <line x1="12" y1="3.4" x2="12" y2="7.6" transform="rotate(60 12 12)" opacity="0.856"/>
            <line x1="12" y1="3.4" x2="12" y2="7.6" transform="rotate(90 12 12)" opacity="0.784"/>
            <line x1="12" y1="3.4" x2="12" y2="7.6" transform="rotate(120 12 12)" opacity="0.712"/>
            <line x1="12" y1="3.4" x2="12" y2="7.6" transform="rotate(150 12 12)" opacity="0.64"/>
            <line x1="12" y1="3.4" x2="12" y2="7.6" transform="rotate(180 12 12)" opacity="0.568"/>
            <line x1="12" y1="3.4" x2="12" y2="7.6" transform="rotate(210 12 12)" opacity="0.496"/>
            <line x1="12" y1="3.4" x2="12" y2="7.6" transform="rotate(240 12 12)" opacity="0.424"/>
            <line x1="12" y1="3.4" x2="12" y2="7.6" transform="rotate(270 12 12)" opacity="0.352"/>
            <line x1="12" y1="3.4" x2="12" y2="7.6" transform="rotate(300 12 12)" opacity="0.28"/>
            <line x1="12" y1="3.4" x2="12" y2="7.6" transform="rotate(330 12 12)" opacity="0.208"/>
            </g>
        </svg>
    </div>
</div>

<style>
    #rshop-ptr {
        position: fixed;
        top: max(0px, env(safe-area-inset-top));
        left: 50%;
        z-index: 95;
        transform: translateX(-50%) translateY(-56px);
        opacity: 0;
        pointer-events: none;
        will-change: transform, opacity;
    }
    #rshop-ptr.rshop-ptr-animate { transition: transform .45s cubic-bezier(.22,1,.36,1), opacity .3s ease; }
    .rshop-ptr-circle {
        display: flex;
        height: 40px;
        width: 40px;
        align-items: center;
        justify-content: center;
        border-radius: 9999px;
        /* Grey until the pull is far enough to do anything. */
        color: #94a3b8;
        background: rgba(255, 255, 255, .55);
        box-shadow: 0 6px 18px rgba(15, 23, 42, .18);
        border: 1px solid rgba(255, 255, 255, .5);
        backdrop-filter: blur(14px) saturate(160%);
        -webkit-backdrop-filter: blur(14px) saturate(160%);
        transition: color .18s ease, transform .18s cubic-bezier(.22, 1.6, .36, 1), box-shadow .18s ease;
    }
    /* Armed: let go now and it refreshes. The size change is the part that
       reads without looking straight at it. */
    #rshop-ptr.rshop-ptr-ready .rshop-ptr-circle,
    #rshop-ptr.rshop-ptr-refreshing .rshop-ptr-circle {
        /* Darker, not a different colour. The wheel is the platform's own
           shape and a brand hue painted on it reads as a different control. */
        color: #475569;
        transform: scale(1.12);
        box-shadow: 0 8px 22px rgba(15, 23, 42, .26);
    }
    html.dark .rshop-ptr-circle {
        color: #64748b;
        background: rgba(12, 26, 54, .55);
        border-color: rgba(255, 255, 255, .12);
        box-shadow: 0 6px 18px rgba(0, 0, 0, .35);
    }
    html.dark #rshop-ptr.rshop-ptr-ready .rshop-ptr-circle,
    html.dark #rshop-ptr.rshop-ptr-refreshing .rshop-ptr-circle {
        color: #ffffff;
        box-shadow: 0 8px 22px rgba(0, 0, 0, .45);
    }
    .rshop-ptr-spin { height: 21px; width: 21px; }

    /* A step per spoke, not a glide. Twelve spokes turned smoothly read as a
       blur; turned one spoke at a time, each step lands a lit spoke exactly
       where the last one was, which is what the eye recognises as a spinner. */
    #rshop-ptr.rshop-ptr-refreshing .rshop-ptr-spokes {
        transform-origin: 50% 50%;
        animation: rshop-ptr-rotate .9s steps(12, end) infinite;
    }
    @keyframes rshop-ptr-rotate { to { transform: rotate(360deg); } }
    @media (prefers-reduced-motion: reduce) {
        #rshop-ptr.rshop-ptr-refreshing .rshop-ptr-spin { animation-duration: 1.4s; }
        .rshop-ptr-circle { transition: color .18s ease; }
        #rshop-ptr.rshop-ptr-ready .rshop-ptr-circle,
        #rshop-ptr.rshop-ptr-refreshing .rshop-ptr-circle { transform: none; }
    }
</style>

<script>
    (function () {
        if (window.__rshopPtr) { return; }
        const standalone = window.matchMedia('(display-mode: standalone)').matches
            || window.navigator.standalone === true;
        if (! standalone) { return; } // normal browsers have native pull-to-refresh
        window.__rshopPtr = true;

        const THRESHOLD = 64;   // px of pull needed to trigger a refresh
        const REST_Y = 12;      // resting offset of the spinner while refreshing
        const HIDDEN_Y = -56;   // parked above the viewport
        const DAMP = 0.5;       // resistance so the pull feels elastic
        const MAX = 96;

        let startY = 0, pulling = false, pull = 0, refreshing = false, raf = null, nextPx = 0;

        // The phone dashboard scrolls inside its rounded panel, not the window,
        // so "at the top" has to be read off the panel there; anywhere else the
        // document scrolls as usual.
        const scroller = () => {
            const panel = document.querySelector('.dash-mobile-panel');
            if (panel && getComputedStyle(panel).overflowY === 'auto') { return panel; }
            return document.scrollingElement || document.documentElement;
        };
        const isLocked = () => document.body.style.position === 'fixed';
        const el = () => document.getElementById('rshop-ptr');

        // A drag that starts inside an open dialog is the user reaching for a
        // field, not asking for a refresh - reloading there would throw away a
        // half-finished form (or a live payment session). Same for a drag while
        // the on-screen keyboard is up: that gesture belongs to the field.
        // These back up isLocked() for dialogs that don't take the scroll lock.
        const inDialog = (node) => !! (node && typeof node.closest === 'function'
            && node.closest('[role="dialog"], [aria-modal="true"]'));
        const isTyping = () => {
            const a = document.activeElement;
            return !! a && (a.tagName === 'INPUT' || a.tagName === 'TEXTAREA'
                || a.tagName === 'SELECT' || a.isContentEditable);
        };

        // Batch DOM writes to one per animation frame so the spinner tracks the
        // finger buttery-smooth even on rapid touchmove bursts (no layout thrash).
        function render(px) {
            nextPx = px;
            if (raf) { return; }
            raf = requestAnimationFrame(() => {
                raf = null;
                const e = el();
                if (! e) { return; }
                e.classList.remove('rshop-ptr-animate');
                e.classList.toggle('rshop-ptr-ready', nextPx >= THRESHOLD);
                e.style.transform = 'translateX(-50%) translateY(' + (HIDDEN_Y + nextPx) + 'px)';
                e.style.opacity = Math.min(nextPx / THRESHOLD, 1).toFixed(3);

                // The wheel turns with the pull, a spoke at a time, so the
                // gesture drives the same motion the refresh will continue.
                // A full pull to the threshold turns it a third of the way
                // round, which is enough to see without looking straight at it.
                const spokes = e.querySelector('.rshop-ptr-spokes');
                if (spokes) {
                    const travelled = Math.min(nextPx / THRESHOLD, 1);
                    const step = Math.round(travelled * 4) * 30;
                    spokes.style.transform = 'rotate(' + step + 'deg)';
                }
            });
        }

        function cancelRaf() {
            if (raf) { cancelAnimationFrame(raf); raf = null; }
        }

        function snapBack() {
            cancelRaf();
            const e = el();
            if (! e) { return; }
            e.classList.add('rshop-ptr-animate');
            e.classList.remove('rshop-ptr-ready', 'rshop-ptr-refreshing');

            // Back to its start, so the next pull turns it from there.
            const spokes = e.querySelector('.rshop-ptr-spokes');
            if (spokes) { spokes.style.transform = ''; }
            e.style.transform = 'translateX(-50%) translateY(' + HIDDEN_Y + 'px)';
            e.style.opacity = '0';
        }

        /**
         * Fetch the page and swap it in, so the one on screen stays there and
         * the spinner keeps spinning over it. Falls back to the hard reload
         * whenever that cannot be trusted to actually refresh: Livewire is not
         * up yet, or it would answer from the cache it keeps for back and
         * forward, which would refresh nothing at all.
         */
        function refresh() {
            const hard = () => window.location.reload();

            if (! window.Livewire || typeof window.Livewire.navigate !== 'function') {
                hard();

                return;
            }

            document.addEventListener('livewire:navigate', function (ev) {
                if (ev.detail && ev.detail.cached) {
                    ev.preventDefault();
                    hard();
                }
            }, { once: true });

            // Whatever happens to the swap, the spinner does not stay up
            // forever: it leaves when the new page lands, and on a timer if
            // the navigation never reports back.
            const done = () => { refreshing = false; snapBack(); };
            document.addEventListener('livewire:navigated', done, { once: true });
            setTimeout(done, 8000);

            try {
                window.Livewire.navigate(window.location.href);
            } catch (_) {
                hard();
            }
        }

        window.addEventListener('touchstart', (ev) => {
            if (refreshing || isLocked() || isTyping() || inDialog(ev.target)
                || scroller().scrollTop > 0 || ev.touches.length !== 1) {
                pulling = false;
                return;
            }
            startY = ev.touches[0].clientY;
            pulling = true;
            pull = 0;
        }, { passive: true });

        window.addEventListener('touchmove', (ev) => {
            if (! pulling) { return; }
            const delta = ev.touches[0].clientY - startY;
            if (delta <= 0 || scroller().scrollTop > 0 || isLocked() || isTyping()) {
                pulling = false;
                snapBack();
                return;
            }
            pull = Math.min(delta * DAMP, MAX);
            render(pull);
            if (pull > 6 && ev.cancelable) { ev.preventDefault(); } // kill rubber-band
        }, { passive: false });

        window.addEventListener('touchend', () => {
            if (! pulling) { return; }
            pulling = false;
            if (pull >= THRESHOLD) {
                refreshing = true;
                cancelRaf();
                const e = el();
                if (e) {
                    // Hand the wheel back to the stylesheet, which spins it: an
                    // inline rotation left from the pull would outrank the
                    // animation and hold it still.
                    const spokes = e.querySelector('.rshop-ptr-spokes');
                    if (spokes) { spokes.style.transform = ''; }

                    e.classList.add('rshop-ptr-animate', 'rshop-ptr-refreshing');
                    e.style.transform = 'translateX(-50%) translateY(' + REST_Y + 'px)';
                    e.style.opacity = '1';
                }
                setTimeout(refresh, 450);
            } else {
                snapBack();
            }
        });
    })();
</script>

{{-- Native-style pull-to-refresh for the installed PWA. Standalone mode
     disables the browser's own pull-to-refresh, so we provide our own: pull
     down at the top of the page past the threshold and release to reload, with
     a glassy animated spinner that follows the pull. Disabled on the normal
     web (the browser already does this) and while a modal has the scroll
     locked (body becomes position:fixed). --}}
<div id="rshop-ptr" aria-hidden="true">
    <div class="rshop-ptr-circle">
        <svg class="rshop-ptr-spin" viewBox="0 0 24 24" fill="none" aria-hidden="true">
            <circle cx="12" cy="12" r="9" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" stroke-dasharray="44" stroke-dashoffset="12"/>
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
        color: #2563eb;
        background: rgba(255, 255, 255, .55);
        box-shadow: 0 6px 18px rgba(15, 23, 42, .18);
        border: 1px solid rgba(255, 255, 255, .5);
        backdrop-filter: blur(14px) saturate(160%);
        -webkit-backdrop-filter: blur(14px) saturate(160%);
    }
    html.dark .rshop-ptr-circle {
        color: #60a5fa;
        background: rgba(12, 26, 54, .55);
        border-color: rgba(255, 255, 255, .12);
        box-shadow: 0 6px 18px rgba(0, 0, 0, .35);
    }
    .rshop-ptr-spin { height: 21px; width: 21px; }
    #rshop-ptr.rshop-ptr-refreshing .rshop-ptr-spin { animation: rshop-ptr-rotate .75s linear infinite; }
    @keyframes rshop-ptr-rotate { to { transform: rotate(360deg); } }
    @media (prefers-reduced-motion: reduce) {
        #rshop-ptr.rshop-ptr-refreshing .rshop-ptr-spin { animation-duration: 1.4s; }
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

        const scroller = () => document.scrollingElement || document.documentElement;
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
                e.style.transform = 'translateX(-50%) translateY(' + (HIDDEN_Y + nextPx) + 'px)';
                e.style.opacity = Math.min(nextPx / THRESHOLD, 1).toFixed(3);
                const s = e.querySelector('.rshop-ptr-spin');
                if (s) { s.style.transform = 'rotate(' + (nextPx * 3.2) + 'deg)'; }
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
            e.style.transform = 'translateX(-50%) translateY(' + HIDDEN_Y + 'px)';
            e.style.opacity = '0';
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
                    e.classList.add('rshop-ptr-animate', 'rshop-ptr-refreshing');
                    e.style.transform = 'translateX(-50%) translateY(' + REST_Y + 'px)';
                    e.style.opacity = '1';
                }
                setTimeout(() => window.location.reload(), 450);
            } else {
                snapBack();
            }
        });
    })();
</script>

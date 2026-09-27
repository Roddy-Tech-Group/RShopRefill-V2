{{-- Swipe a bottom sheet down to close it.

     A sheet that slides up from the bottom of a phone is asking to be pushed
     back down, and every one of ours could only be dismissed by finding the
     close button or the strip of backdrop above it. The handle at the top of
     each sheet already says "drag me" and meant nothing.

     Wire a sheet up from its own Alpine scope:

         x-init="window.rshopSheetSwipe?.($el, () => open = false)"

     The element is the sheet itself; the callback is whatever that sheet
     already does to close. Nothing here decides what closing means, so a
     sheet that also clears a selection or releases a scroll lock keeps doing
     it through the same path as the close button. --}}
<script>
    (function () {
        if (window.rshopSheetSwipe) { return; }

        // How far down before a release closes rather than snaps back, and the
        // flick that closes regardless of distance. The distance is a share of
        // the sheet's own height, so a tall sheet asks for a longer push than a
        // short one, which is what the hand expects.
        const CLOSE_FRACTION = 0.28;
        const CLOSE_PX = 96;
        const FLICK_VELOCITY = 0.55; // px per ms

        window.rshopSheetSwipe = function (el, close) {
            if (! el || typeof close !== 'function' || el.dataset.sheetSwipe === 'on') { return; }
            el.dataset.sheetSwipe = 'on';

            let startY = 0;
            let startedAt = 0;
            let delta = 0;
            let dragging = false;

            // The sheet's own scroller has to be at the top before a downward
            // drag means "close": inside a scrolled list the same gesture is
            // the customer reading, and stealing it would make the sheet
            // impossible to scroll back up through.
            const atTop = () => el.scrollTop <= 0;

            // A drag that starts on a control is that control's. Buttons,
            // links and fields inside the sheet keep their own gestures, and a
            // horizontal carousel in there is not a dismissal either.
            const fromControl = (node) => !! (node && typeof node.closest === 'function'
                && node.closest('button, a, input, select, textarea, [role="button"], [data-no-sheet-swipe]'));

            const move = (px) => {
                el.style.transition = 'none';
                el.style.transform = px > 0 ? 'translateY(' + px + 'px)' : '';
            };

            const release = () => {
                el.style.transition = '';
                el.style.transform = '';
            };

            el.addEventListener('touchstart', (ev) => {
                if (ev.touches.length !== 1 || ! atTop() || fromControl(ev.target)) {
                    dragging = false;

                    return;
                }
                startY = ev.touches[0].clientY;
                startedAt = Date.now();
                delta = 0;
                dragging = true;
            }, { passive: true });

            el.addEventListener('touchmove', (ev) => {
                if (! dragging) { return; }

                delta = ev.touches[0].clientY - startY;

                // Dragging up is the sheet's own scroll, so hand it back.
                if (delta <= 0) {
                    if (delta < -4) { dragging = false; move(0); }

                    return;
                }

                // Past a few pixels this is a drag, not a tap, and the sheet
                // must not also scroll underneath it.
                if (delta > 4 && ev.cancelable) { ev.preventDefault(); }

                // Resistance, so the sheet never outruns the finger.
                move(delta * 0.9);
            }, { passive: false });

            const end = () => {
                if (! dragging) { return; }
                dragging = false;

                const travelled = delta;
                const velocity = travelled / Math.max(1, Date.now() - startedAt);
                const far = travelled > Math.max(CLOSE_PX, el.offsetHeight * CLOSE_FRACTION);

                // The transform goes either way: on a close so the sheet's own
                // leave transition can run from where it sits, and on a snap
                // back so it returns under its own easing.
                release();

                if (far || (travelled > 24 && velocity > FLICK_VELOCITY)) {
                    close();
                }
            };

            el.addEventListener('touchend', end);
            el.addEventListener('touchcancel', end);
        };
    })();
</script>

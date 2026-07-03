// Flatpickr — modern date picker used by admin forms (e.g. coupon expiry).
// Exposed on window so Alpine x-init expressions can call flatpickr() directly.
import flatpickr from 'flatpickr';
import 'flatpickr/dist/flatpickr.css';

window.flatpickr = flatpickr;

// Web Push notification manager — registers window.RshopPush for
// subscribe/unsubscribe from the Settings page and admin bell.
import './push-manager';

/**
 * Build a custom dropdown (trigger button + styled options panel) to replace one
 * of Flatpickr's native month/year selectors so they match the admin's other
 * custom dropdowns. The panel is appended to <body> and fixed-positioned so
 * Flatpickr's `overflow:hidden` month strip can't clip it.
 *
 * @param {Array<{value:number,label:string}>} items
 * @param {number} current  currently selected value
 * @param {(value:number)=>void} onPick
 * @returns {{btn:HTMLElement, setValue:(v:number)=>void, cleanup:()=>void}}
 */
function buildFlatpickrDropdown(items, current, onPick) {
    const btn = document.createElement('button');
    btn.type = 'button';
    btn.className = 'flatpickr-modernSelect-btn';
    const label = document.createElement('span');
    label.textContent = items.find((i) => i.value === current)?.label ?? '';
    btn.appendChild(label);
    btn.insertAdjacentHTML('beforeend', '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="flatpickr-modernSelect-chev"><path stroke-linecap="round" stroke-linejoin="round" d="M6 9l6 6 6-6"/></svg>');

    const panel = document.createElement('div');
    panel.className = 'flatpickr-modernSelect-panel';
    const mark = (v) => panel.querySelectorAll('.flatpickr-modernSelect-opt').forEach((o) => o.classList.toggle('is-selected', Number(o.dataset.value) === v));
    const hide = () => panel.classList.remove('is-open');

    items.forEach((it) => {
        const opt = document.createElement('button');
        opt.type = 'button';
        opt.className = 'flatpickr-modernSelect-opt';
        opt.dataset.value = String(it.value);
        opt.textContent = it.label;
        if (it.value === current) opt.classList.add('is-selected');
        opt.addEventListener('click', (e) => {
            e.stopPropagation();
            onPick(it.value);
            label.textContent = it.label;
            mark(it.value);
            hide();
        });
        panel.appendChild(opt);
    });
    document.body.appendChild(panel);

    const show = () => {
        const r = btn.getBoundingClientRect();
        panel.style.top = `${r.bottom + 4}px`;
        panel.style.left = `${r.left + r.width / 2}px`;
        panel.classList.add('is-open');
        panel.querySelector('.is-selected')?.scrollIntoView({ block: 'nearest' });
    };
    btn.addEventListener('click', (e) => {
        e.stopPropagation();
        panel.classList.contains('is-open') ? hide() : show();
    });
    document.addEventListener('click', hide);

    return {
        btn,
        setValue: (v) => {
            const it = items.find((i) => i.value === v);
            if (it) label.textContent = it.label;
            mark(v);
        },
        cleanup: () => {
            document.removeEventListener('click', hide);
            panel.remove();
        },
    };
}

/** Swap Flatpickr's native month <select> and numeric year stepper for custom dropdowns. */
function mountFlatpickrDropdowns(fp, range = 10) {
    if (fp.__modernMounted) return;
    fp.__modernMounted = true;
    const controllers = [];

    const monthSelect = fp.monthsDropdownContainer || fp.calendarContainer.querySelector('.flatpickr-monthDropdown-months');
    if (monthSelect) {
        monthSelect.style.display = 'none';
        const months = fp.l10n.months.longhand.map((label, value) => ({ value, label }));
        const monthCtl = buildFlatpickrDropdown(months, fp.currentMonth, (m) => fp.changeMonth(m - fp.currentMonth));
        monthSelect.parentNode.insertBefore(monthCtl.btn, monthSelect.nextSibling);
        fp.__monthCtl = monthCtl;
        controllers.push(monthCtl);
    }

    const yearWrapper = fp.currentYearElement?.closest('.numInputWrapper');
    if (yearWrapper) {
        yearWrapper.style.display = 'none';
        const base = new Date().getFullYear();
        const years = [];
        for (let y = base; y <= base + range; y++) years.push({ value: y, label: String(y) });
        const yearCtl = buildFlatpickrDropdown(years, fp.currentYear, (y) => fp.changeYear(y));
        yearWrapper.parentNode.insertBefore(yearCtl.btn, yearWrapper.nextSibling);
        fp.__yearCtl = yearCtl;
        controllers.push(yearCtl);
    }

    fp.__cleanupDropdowns = () => controllers.forEach((c) => c.cleanup());
}

function syncFlatpickrDropdowns(fp) {
    fp.__monthCtl?.setValue(fp.currentMonth);
    fp.__yearCtl?.setValue(fp.currentYear);
}

/**
 * Flatpickr for an expiry date field: date-only, future dates only, with custom
 * month + year dropdowns. `onSelect` receives the chosen Date (or null cleared).
 */
window.initExpiryFlatpickr = function (el, onSelect) {
    return window.flatpickr(el, {
        minDate: 'today',
        dateFormat: 'M j, Y',
        monthSelectorType: 'dropdown',
        disableMobile: true,
        onChange: (dates) => onSelect(dates[0] || null),
        onReady: (_s, _d, fp) => mountFlatpickrDropdowns(fp),
        onYearChange: (_s, _d, fp) => syncFlatpickrDropdowns(fp),
        onMonthChange: (_s, _d, fp) => syncFlatpickrDropdowns(fp),
        onDestroy: (_s, _d, fp) => fp.__cleanupDropdowns?.(),
    });
};

/**
 * Looping typewriter for the dashboard greeting: types a phrase, holds, deletes,
 * then moves to the next phrase and repeats. Used via:
 *   x-data="typewriterGreeting(['Hi Roddy', 'Welcome', 'Your shop is ready'])"
 * with <span x-text="display"></span>.
 */
window.typewriterGreeting = function (phrases, { typeMs = 80, deleteMs = 40, holdMs = 10000, pauseMs = 400 } = {}) {
    return {
        phrases,
        display: '',
        _i: 0,
        _c: 0,
        _timer: null,
        init() {
            this._type();
        },
        _type() {
            const word = this.phrases[this._i] ?? '';
            if (this._c <= word.length) {
                this.display = word.slice(0, this._c);
                this._c++;
                this._timer = setTimeout(() => this._type(), typeMs);
            } else {
                this._timer = setTimeout(() => this._delete(), holdMs);
            }
        },
        _delete() {
            const word = this.phrases[this._i] ?? '';
            if (this._c > 0) {
                this._c--;
                this.display = word.slice(0, this._c);
                this._timer = setTimeout(() => this._delete(), deleteMs);
            } else {
                this._i = (this._i + 1) % this.phrases.length;
                this._timer = setTimeout(() => this._type(), pauseMs);
            }
        },
        destroy() {
            clearTimeout(this._timer);
        },
    };
};

/**
 * Entrance + scroll-reveal animations with GSAP.
 *
 * GSAP is loaded via dynamic import so the page still works if the package
 * isn't installed yet. Run `npm i gsap` to enable animations.
 */
async function initAnimations() {
    let gsap, ScrollTrigger;
    try {
        ({ default: gsap } = await import('gsap'));
        ({ ScrollTrigger } = await import('gsap/ScrollTrigger'));
        const { MotionPathPlugin } = await import('gsap/MotionPathPlugin');
        gsap.registerPlugin(ScrollTrigger, MotionPathPlugin);
    } catch (err) {
        console.warn('[animations] gsap not available — skipping animations. Run `npm i gsap`.');
        return;
    }

    // Tear down any existing scroll triggers so they don't pile up after navigation.
    ScrollTrigger.getAll().forEach((t) => t.kill());

    // ----- Hero entrance timeline (on load) -----
    // Pure slide-up; no opacity fade so the hero content is always readable
    // even if the user hits the page mid-animation.
    if (document.querySelector('[data-anim="hero-headline"]')) {
        const heroTL = gsap.timeline({ defaults: { ease: 'power3.out', duration: 0.8 } });
        heroTL
            .from('[data-anim="hero-headline"]', { y: 24 })
            .from('[data-anim="hero-subtitle"]', { y: 18 }, '-=0.55')
            .from('[data-anim="hero-ctas"]',     { y: 18 }, '-=0.55')
            .from('[data-anim="hero-banner"]',   { y: 24 }, '-=0.5');
    }

    // Scroll-triggered reveals intentionally removed: sections render in place
    // with no fade-up on scroll (the reveal read like the page was "refreshing"
    // each section as you scrolled). The hero load animation above is kept.

    // Animated inline-SVG illustrations (hero chips, 404, empty cart).
    initIllos(gsap);
}

/* ------------------------------------------------------------------ *
 * Animated inline-SVG illustrations. The SVGs ship inline in the HTML
 * (zero image requests, instant paint); GSAP only enhances them and
 * honours prefers-reduced-motion. Drive any one via <div data-illo="gift">.
 * Each entry: targets (for cleanup), set (initial state), build (entrance
 * timeline), idle (looping ambient tweens). Selectors are namespaced per
 * illustration, so several can share a page.
 * ------------------------------------------------------------------ */
const ILLOS = {
    notFound: {
        set: (g) => g.set('.i404-char', { y: -150, opacity: 0 }),
        build: (tl) => tl.to('.i404-char', { y: 0, opacity: 1, duration: 0.85, ease: 'bounce.out', stagger: 0.16 }),
        idle: (g) => [
            g.to('.i404-char', { y: -6, duration: 1.5, yoyo: true, repeat: -1, ease: 'sine.inOut', stagger: 0.25 }),
            g.timeline({ repeat: -1, repeatDelay: 2.4 }).to('.i404-eye', { scaleY: 0.15, transformOrigin: '50% 60%', duration: 0.07, yoyo: true, repeat: 1 }),
            g.to('.i404-pupil', { x: -4, duration: 1.2, yoyo: true, repeat: -1, repeatDelay: 0.8, ease: 'sine.inOut' }),
        ],
    },
    globe: {
        set: (g) => { g.set('#igc-globe', { scale: 0, transformOrigin: '50% 50%' }); g.set('#igc-pin', { y: -140, opacity: 0 }); g.set('#igc-plane', { opacity: 0 }); },
        build: (tl) => tl.to('#igc-globe', { scale: 1, duration: 0.7, ease: 'back.out(1.6)' }).to('#igc-pin', { y: 0, opacity: 1, duration: 0.8, ease: 'bounce.out' }, '-=0.2').to('#igc-plane', { opacity: 1, duration: 0.3 }),
        idle: (g) => [
            g.to('#igc-plane', { motionPath: { path: '#igc-orbit', align: '#igc-orbit', alignOrigin: [0.5, 0.5], autoRotate: true }, duration: 7, repeat: -1, ease: 'none' }),
            g.to('#igc-pin', { y: -5, duration: 1.6, yoyo: true, repeat: -1, ease: 'sine.inOut' }),
        ],
    },
    gift: {
        set: (g) => { g.set('#igift-all', { scale: 0, transformOrigin: '50% 100%' }); g.set('#igift-tag', { rotation: -24, svgOrigin: '104 75' }); },
        build: (tl) => tl.to('#igift-all', { scale: 1, duration: 0.7, ease: 'back.out(1.7)' }).to('#igift-all', { y: -14, scaleY: 1.05, scaleX: 0.96, duration: 0.22, ease: 'power2.out', yoyo: true, repeat: 1 }, '+=0.1').to('#igift-tag', { rotation: 0, duration: 1.6, ease: 'elastic.out(1,0.3)' }, '-=0.3'),
        idle: (g) => [
            g.to('#igift-all', { y: -5, duration: 1.8, yoyo: true, repeat: -1, ease: 'sine.inOut' }),
            g.to('#igift-tag', { rotation: 7, duration: 1.4, yoyo: true, repeat: -1, ease: 'sine.inOut' }),
            g.to('.igift-spark', { opacity: 0.25, duration: 0.9, yoyo: true, repeat: -1, stagger: 0.3 }),
        ],
    },
    search: {
        set: (g) => { g.set('.isr-bag', { y: 26, opacity: 0 }); g.set('#isr-glass', { x: 70, y: -70, rotation: 18, opacity: 0, svgOrigin: '118 118' }); },
        build: (tl) => tl.to('.isr-bag', { y: 0, opacity: 1, duration: 0.6, ease: 'back.out(1.8)', stagger: 0.14 }).to('#isr-glass', { x: 0, y: 0, rotation: 0, opacity: 1, duration: 0.7, ease: 'power3.out' }, '-=0.2').to('#isr-glass', { x: -30, duration: 0.9, ease: 'sine.inOut', yoyo: true, repeat: 3 }, '+=0.2'),
        idle: (g) => [
            g.to('#isr-glass', { y: -5, rotation: 3, duration: 1.7, yoyo: true, repeat: -1, ease: 'sine.inOut' }),
            g.to('#isr-glint', { opacity: 0.15, duration: 0.8, yoyo: true, repeat: -1, ease: 'sine.inOut' }),
        ],
    },
    shield: {
        set: (g) => { g.set('#ishield-check', { strokeDasharray: 90, strokeDashoffset: 90 }); g.set('#ishield-shield', { scale: 0, transformOrigin: '50% 50%' }); g.set('#ishield-user', { scale: 0, transformOrigin: '50% 50%' }); },
        build: (tl) => tl.to('#ishield-shield', { scale: 1, duration: 0.65, ease: 'back.out(1.7)' }).to('#ishield-check', { strokeDashoffset: 0, duration: 0.55, ease: 'power2.out' }).to('#ishield-user', { scale: 1, duration: 0.6, ease: 'back.out(2)' }, '-=0.15'),
        idle: (g) => [
            g.to('#ishield-shield', { scale: 1.025, transformOrigin: '50% 50%', duration: 1.6, yoyo: true, repeat: -1, ease: 'sine.inOut' }),
            g.to('#ishield-user', { y: -4, duration: 1.8, yoyo: true, repeat: -1, ease: 'sine.inOut' }),
            g.to('.ishield-spark', { opacity: 0.25, duration: 0.9, yoyo: true, repeat: -1, stagger: 0.3 }),
        ],
    },
    mobile: {
        set: (g) => {
            g.set('#imob-phone', { scale: 0, transformOrigin: '50% 100%' });
            g.set('.imob-ui', { opacity: 0, y: 6 });
            g.set('#imob-gift', { x: 64, y: -46, rotation: 18, opacity: 0, svgOrigin: '142 86' });
            g.set('.imob-trail', { scaleX: 0, opacity: 0, transformOrigin: '100% 50%' });
            g.set('.imob-star', { scale: 0, transformOrigin: '50% 50%' });
        },
        build: (tl) => tl.to('#imob-phone', { scale: 1, duration: 0.65, ease: 'back.out(1.7)' })
            .to('.imob-ui', { opacity: 1, y: 0, duration: 0.4, stagger: 0.08, ease: 'power2.out' }, '-=0.15')
            .to('#imob-gift', { x: 0, y: 0, rotation: 0, opacity: 1, duration: 0.7, ease: 'power3.out' }, '-=0.3')
            .to('.imob-trail', { scaleX: 1, opacity: 1, duration: 0.25, stagger: 0.05 }, '<+0.1')
            .to('.imob-trail', { opacity: 0, duration: 0.35 }, '+=0.1')
            .to('.imob-star', { scale: 1, duration: 0.5, ease: 'back.out(2.5)', stagger: 0.1 }, '-=0.3'),
        idle: (g) => [
            g.to('#imob-gift', { y: -4, rotation: 3, duration: 1.7, yoyo: true, repeat: -1, ease: 'sine.inOut' }),
            g.to('.imob-star', { opacity: 0.35, duration: 0.9, yoyo: true, repeat: -1, ease: 'sine.inOut', stagger: 0.25 }),
        ],
    },
    emptyCart: {
        set: (g) => { g.set('#ec-cart', { x: 230, opacity: 0 }); g.set('.ec-bag', { scale: 0, transformOrigin: '50% 100%' }); g.set('#ec-bubble', { scale: 0, transformOrigin: '50% 100%' }); g.set('#ec-tumbleweed', { x: 170 }); },
        build: (tl) => tl.to('#ec-cart', { x: 0, opacity: 1, duration: 1.1, ease: 'power2.out' }).to('#ec-wheel-1, #ec-wheel-2', { rotation: -520, transformOrigin: '50% 50%', duration: 1.1, ease: 'power2.out' }, '<').to('#ec-tumbleweed', { x: 0, rotation: -680, transformOrigin: '50% 50%', duration: 1.5, ease: 'power2.out' }, 0.15).to('.ec-bag', { scale: 1, duration: 0.5, stagger: 0.12, ease: 'back.out(2.2)' }, '-=0.55').to('#ec-bubble', { scale: 1, duration: 0.8, ease: 'elastic.out(1, 0.45)' }, '-=0.15'),
        idle: (g) => [
            g.to('#ec-bubble', { y: -7, duration: 1.8, yoyo: true, repeat: -1, ease: 'sine.inOut' }),
            g.to('#ec-cactus', { rotation: 2.2, transformOrigin: '50% 100%', duration: 2.4, yoyo: true, repeat: -1, ease: 'sine.inOut' }),
            g.to('#ec-tumbleweed', { rotation: '-=14', duration: 2.2, yoyo: true, repeat: -1, ease: 'sine.inOut' }),
            g.to('.ec-bag', { y: -3, duration: 1.6, yoyo: true, repeat: -1, ease: 'sine.inOut', stagger: 0.2 }),
        ],
    },
    cardFan: {
        set: () => {},
        build: (tl) => tl.from('.gc-card', { scale: 0, opacity: 0, rotation: '-=18', transformOrigin: '50% 50%', duration: 0.7, ease: 'back.out(1.6)', stagger: 0.09 }),
        idle: (g) => [
            g.to('.gc-card', { y: -5, duration: 1.7, yoyo: true, repeat: -1, ease: 'sine.inOut', stagger: 0.18 }),
            g.to('.gc-spark', { opacity: 0.25, duration: 0.9, yoyo: true, repeat: -1, stagger: 0.3 }),
        ],
    },
    payWeb: {
        // The connecting links are drawn on via stroke-dash; payment dots then run
        // along each link path. Queries are scoped to el so instances are isolated.
        set: (g, el) => {
            el.querySelectorAll('.ipay-link').forEach((p) => {
                const len = p.getTotalLength();
                p.style.strokeDasharray = len;
                p.style.strokeDashoffset = len;
            });
            g.set('#ipay-hub', { scale: 0, transformOrigin: '50% 50%' });
            g.set('.ipay-node', { scale: 0, transformOrigin: '50% 50%' });
            g.set('.ipay-dot', { opacity: 0 });
        },
        build: (tl) => tl.to('#ipay-hub', { scale: 1, duration: 0.6, ease: 'back.out(1.8)' })
            .to('.ipay-link', { strokeDashoffset: 0, duration: 0.5, stagger: 0.06, ease: 'power1.out' }, '-=0.1')
            .to('.ipay-node', { scale: 1, duration: 0.5, ease: 'back.out(1.8)', stagger: 0.06 }, '-=0.35'),
        idle: (g, el) => {
            const links = Array.from(el.querySelectorAll('.ipay-link'));
            const dots = Array.from(el.querySelectorAll('.ipay-dot'));
            const tweens = links.map((p, i) => g.fromTo(dots[i], { opacity: 0 }, { opacity: 1, motionPath: { path: p, start: 1, end: 0 }, duration: 1.4, repeat: -1, repeatDelay: 0.6, delay: i * 0.18, ease: 'none' }));
            tweens.push(g.to('#ipay-hub', { scale: 1.04, duration: 1.5, yoyo: true, repeat: -1, ease: 'sine.inOut' }));
            tweens.push(g.to('.ipay-node', { y: -3, duration: 1.8, yoyo: true, repeat: -1, ease: 'sine.inOut', stagger: 0.12 }));
            return tweens;
        },
    },
    payout: {
        set: (g) => {
            g.set('#isx-card', { y: 46, opacity: 0 });
            g.set('#isx-medal', { scale: 0, transformOrigin: '50% 50%' });
            g.set('#isx-check', { strokeDasharray: 52, strokeDashoffset: 52 });
            g.set('.isx-line-l', { scaleX: 0, transformOrigin: '100% 50%' });
            g.set('.isx-line-r', { scaleX: 0, transformOrigin: '0% 50%' });
            g.set('.isx-dot', { scale: 0, transformOrigin: '50% 50%' });
        },
        build: (tl) => tl.to('#isx-card', { y: 0, opacity: 1, duration: 0.7, ease: 'power3.out' })
            .to('#isx-medal', { scale: 1, duration: 0.55, ease: 'back.out(2)' }, '-=0.25')
            .to('#isx-check', { strokeDashoffset: 0, duration: 0.45, ease: 'power2.out' })
            .to('.isx-line-l, .isx-line-r', { scaleX: 1, duration: 0.4, ease: 'power3.out', stagger: 0.05 }, '-=0.2')
            .to('.isx-dot', { scale: 1, duration: 0.4, ease: 'back.out(2)', stagger: 0.08 }, '-=0.2'),
        idle: (g) => [
            // Pulse the medal + shimmer the speed lines, but DON'T float the card:
            // it holds the <text>, and continuously sub-pixel-transforming text makes
            // the letters shimmer/shake. Keeping the card still keeps the text crisp.
            g.to('#isx-medal', { scale: 1.04, duration: 1.5, yoyo: true, repeat: -1, ease: 'sine.inOut' }),
            g.to('.isx-line-l, .isx-line-r', { opacity: 0.5, duration: 1, yoyo: true, repeat: -1, ease: 'sine.inOut', stagger: 0.15 }),
        ],
    },
};

function mountIllo(gsap, el) {
    const def = ILLOS[el.dataset.illo];
    if (! def || el.__illoMounted) {
        return;
    }
    el.__illoMounted = true;

    let ctx;
    // Entrance + idle. gsap.context scopes every selector to THIS element, so the
    // same illustration can appear more than once on a page (e.g. emptyCart in the
    // nav popup AND on the cart page) without instances colliding. The idle loop is
    // added through the context too so it stays scoped + killable.
    const run = (speed) => {
        def.set(gsap, el);
        const tl = gsap.timeline({
            onComplete: () => ctx && ctx.add(() => def.idle(gsap, el)),
        });
        tl.timeScale(speed || 1);
        def.build(tl, gsap, el);
    };
    ctx = gsap.context(() => run(1), el);

    // Replay from the start on hover, sped up so it feels snappy/reactive.
    el.addEventListener('mouseenter', () => {
        ctx.revert();
        ctx.add(() => run(2.2));
    });
}

function initIllos(gsap) {
    if (window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
        return;
    }
    // Play each illustration's entrance the moment it scrolls into view, so the
    // animation is visible on its own (no hover required). Above-the-fold ones
    // are intersecting on load and play immediately.
    const io = new IntersectionObserver((entries) => {
        entries.forEach((entry) => {
            if (entry.isIntersecting) {
                io.unobserve(entry.target);
                mountIllo(gsap, entry.target);
            }
        });
    }, { rootMargin: '0px 0px -8% 0px' });

    document.querySelectorAll('[data-illo]').forEach((el) => {
        if (! el.__illoMounted) {
            io.observe(el);
        }
    });
}

/**
 * Lottie loader. Mounts any [data-lottie="/path.json"] container as an animation.
 *
 * Usage in blade:
 *   <div data-lottie="{{ asset('lottie/empty-cart.json') }}"
 *        data-lottie-loop="true"
 *        data-lottie-autoplay="true"
 *        class="h-40 w-40"></div>
 *
 * Drops the lottie-web import as a dynamic import so the page still works if
 * the package isn't installed yet. Run `npm i lottie-web` to enable.
 */
async function initLottie() {
    const targets = document.querySelectorAll('[data-lottie]:not([data-lottie-mounted])');
    if (!targets.length) return;

    let lottie;
    try {
        ({ default: lottie } = await import('lottie-web'));
    } catch (err) {
        console.warn('[lottie] lottie-web not available — skipping. Run `npm i lottie-web`.');
        return;
    }

    targets.forEach((el) => {
        try {
            lottie.loadAnimation({
                container: el,
                renderer: 'svg',
                loop: el.dataset.lottieLoop !== 'false',
                autoplay: el.dataset.lottieAutoplay !== 'false',
                path: el.dataset.lottie,
            });
            el.setAttribute('data-lottie-mounted', 'true');
        } catch (err) {
            console.warn('[lottie] failed to mount', el.dataset.lottie, err);
        }
    });
}

// First load (handle both pre- and post-DOMContentLoaded states)
function bootAll() {
    initAnimations();
    initLottie();
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', bootAll);
} else {
    bootAll();
}

// Re-run on Livewire SPA navigation so animations replay on the new page.
document.addEventListener('livewire:navigated', bootAll);

/**
 * Shared locale Alpine component used by both the storefront and customer-dashboard
 * layouts. Owns: locale modal open state, country (name + flag + ISO code), language,
 * and currency (code + symbol). Persists to localStorage so the user's preferences
 * survive page navigation. When the user changes country or currency AND they're on
 * a shop page that supports those URL filters (currently /gift-cards), it pushes
 * `?country=` and `?currency=` and reloads via Livewire's wire:navigate so the page
 * re-renders with the filter applied.
 */
/**
 * Global cart store. Talks to the session-authenticated web cart routes
 * (/cart, /cart/items) which wrap the backend CartManager. The nav cart popup
 * and the product page both read/drive this single store.
 */
/**
 * Global popup auto-close on page scroll.
 *
 * Most dropdowns in the project already listen to `keydown.escape.window` to
 * close — by dispatching a synthetic Escape on window scroll, we close every
 * dropdown / search panel / form select that uses the standard pattern with a
 * single 8-line snippet, no per-component changes needed. Only fires for
 * window scroll (page-level) — element-level scrolls inside dropdowns don't
 * bubble to window, so users can still scroll long result lists freely.
 *
 * Also dispatches a custom `app-page-scroll` event for components that don't
 * use the Escape pattern but still want to react.
 */
(function autoCloseOnScroll() {
    let t;
    const escape = new KeyboardEvent('keydown', { key: 'Escape', bubbles: true });
    // On mobile, focusing an input opens the on-screen keyboard, which fires a
    // scroll event. We must NOT dispatch the synthetic Escape in that case -
    // it would close the auth modal (and any open dialog) the instant the user
    // taps a field. Only auto-close dropdowns on genuine page scrolls.
    const isEditing = () => {
        const el = document.activeElement;
        return !! el && (el.tagName === 'INPUT' || el.tagName === 'TEXTAREA' || el.tagName === 'SELECT' || el.isContentEditable);
    };
    window.addEventListener('scroll', () => {
        clearTimeout(t);
        t = setTimeout(() => {
            // Skip the close-Escape when:
            //  1. a field is focused (mobile keyboard opening fires a scroll), or
            //  2. a modal has locked body scroll (rshopScrollLock sets
            //     body.position:fixed) - while locked the page isn't really
            //     scrolling, so any scroll event is spurious (rubber-band /
            //     keyboard) and would otherwise close the open modal.
            if (isEditing() || document.body.style.position === 'fixed') { return; }
            window.dispatchEvent(escape);
            window.dispatchEvent(new CustomEvent('app-page-scroll'));
        }, 60);
    }, { passive: true });
})();

document.addEventListener('alpine:init', () => {
    // Active wallet index — shared so the desktop wallet card and the mobile
    // wallet carousel always show the same wallet (synced live, no page reload).
    window.Alpine.store('wallet', { active: 0 });

    // Admin sidebar collapse state — persisted to localStorage so the layout
    // remembers between page loads. The CSS rules that respond to the class
    // live next to the sidebar markup in resources/views/components/layouts/admin.blade.php.
    window.Alpine.store('adminSidebar', {
        collapsed: false,
        init() {
            try { this.collapsed = localStorage.getItem('admin.sidebar.collapsed') === '1'; } catch (e) {}
            this._sync();
        },
        toggle() {
            this.collapsed = ! this.collapsed;
            try { localStorage.setItem('admin.sidebar.collapsed', this.collapsed ? '1' : '0'); } catch (e) {}
            this._sync();
        },
        _sync() {
            document.documentElement.classList.toggle('admin-sidebar-collapsed', this.collapsed);
        },
    });
    window.Alpine.store('adminSidebar').init();

    // Customer dashboard sidebar collapse — mirrors the adminSidebar store
    // so both shells get the same UX (glass toggle, icon-only rail, hover
    // popups). Separate localStorage key so the two layouts remember
    // independently. CSS rules live in resources/views/components/layouts/dashboard.blade.php.
    window.Alpine.store('dashboardSidebar', {
        collapsed: false,
        init() {
            try { this.collapsed = localStorage.getItem('dashboard.sidebar.collapsed') === '1'; } catch (e) {}
            this._sync();
        },
        toggle() {
            this.collapsed = ! this.collapsed;
            try { localStorage.setItem('dashboard.sidebar.collapsed', this.collapsed ? '1' : '0'); } catch (e) {}
            this._sync();
        },
        _sync() {
            document.documentElement.classList.toggle('dashboard-sidebar-collapsed', this.collapsed);
        },
    });
    window.Alpine.store('dashboardSidebar').init();

    /**
     * Customer-dashboard preferences. Persisted in localStorage so each customer's
     * choice survives navigation and reloads. Hooked up via the Customize button's
     * dropdown (overview.blade.php) — readers throughout the dashboard check
     * `$store.dashPrefs.hideBalance` and the `.compact` class on <html>.
     */
    window.Alpine.store('dashPrefs', {
        hideBalance: false,
        compactMode: false,

        init() {
            try {
                this.hideBalance = localStorage.getItem('dash.hideBalance') === 'true';
                this.compactMode = localStorage.getItem('dash.compactMode') === 'true';
            } catch (_) { /* localStorage blocked — defaults stand */ }
            // Apply compact-mode class to <html> before first paint of the
            // dashboard so the layout doesn't flicker from comfy → compact.
            document.documentElement.classList.toggle('compact', this.compactMode);
        },

        setHideBalance(value) {
            this.hideBalance = !! value;
            try { localStorage.setItem('dash.hideBalance', this.hideBalance ? 'true' : 'false'); } catch (_) {}
        },

        setCompactMode(value) {
            this.compactMode = !! value;
            try { localStorage.setItem('dash.compactMode', this.compactMode ? 'true' : 'false'); } catch (_) {}
            document.documentElement.classList.toggle('compact', this.compactMode);
        },
    });
    // Stores aren't auto-init'd in Alpine 3 — call it ourselves.
    window.Alpine.store('dashPrefs').init();

    window.Alpine.store('cart', {
        items: [],
        count: 0,
        subtotal: 0,         // cart subtotal in the customer's display currency
        subtotalUsd: 0,      // same subtotal in USD — the settlement base
        currency: 'USD',
        currencySymbol: '$',
        rate: 1,
        open: false,         // nav popup visibility
        loading: false,
        hydrated: false,     // true once the first /cart/data fetch resolves

        _csrf() {
            return document.querySelector('meta[name="csrf-token"]')?.content || '';
        },

        // The customer's chosen display currency, written to localStorage by the
        // locale modal. Sent to the cart endpoints so the server converts prices.
        _currency() {
            try {
                return localStorage.getItem('locale.currency') || 'USD';
            } catch (_) {
                return 'USD';
            }
        },

        _url(path) {
            const sep = path.includes('?') ? '&' : '?';
            return path + sep + 'currency=' + encodeURIComponent(this._currency());
        },

        _apply(data) {
            this.items = data.items || [];
            this.count = data.count || 0;
            this.subtotal = data.subtotal || 0;
            this.subtotalUsd = data.subtotal_usd || 0;
            this.currency = data.currency || 'USD';
            this.currencySymbol = data.currency_symbol || '$';
            this.rate = data.rate || 1;
            this.estimated_rcoin_reward = data.estimated_rcoin_reward || 0;
        },

        // True when the display currency differs from USD — only then does the UI
        // show the USD figure as a secondary anchor.
        get showUsd() {
            return this.currency !== 'USD';
        },

        // Human unit label for a cart line: face value plus what the item IS.
        // "$2.50 eSIM", "$25.00 card", "$10.00 top-up" - an eSIM must never
        // read as "card".
        unitLabel(item) {
            if (!item || !item.face_label) {
                return '';
            }
            const suffix = {
                'esims': ' eSIM',
                'mobile-airtime': ' top-up',
                'bill-payments': ' bill payment',
            }[item.category_slug] ?? ' card';
            return item.face_label + suffix;
        },

        // Format an amount already in the customer's display currency.
        pay(value) {
            const n = Number(value || 0);
            if (this.currency === 'USD') {
                return this.usd(n);
            }
            return this.currencySymbol + ' ' + n.toLocaleString('en-US', { maximumFractionDigits: 2 });
        },

        // Format a USD amount.
        usd(value) {
            return '$' + Number(value || 0).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
        },

        // Pull current cart state (called once on page load). Signals checkout
        // context so the server only KEEPS ephemeral "Buy now" items while the
        // shopper is on a checkout page; reading the cart from anywhere else
        // purges them (see CartWebController::show).
        async refresh() {
            try {
                const onCheckout = window.location.pathname.includes('checkout');
                const res = await fetch(this._url('/cart/data') + (onCheckout ? '&ctx=checkout' : ''), {
                    headers: { Accept: 'application/json' },
                    credentials: 'same-origin',
                });
                if (res.ok) {
                    this._apply(await res.json());
                }
            } catch (_) { /* offline / ignore */ } finally {
                this.hydrated = true;
            }
        },

        // Add a variant to the cart, then drop the nav popup open. `buyNow`
        // tags the item as ephemeral: it rides through to checkout but is
        // purged the moment the shopper reads the cart from anywhere else, so
        // an abandoned Buy now never lingers in the persistent cart.
        async add(variantId, quantity = 1, requestedValue = null, metadata = null, buyNow = false) {
            this.loading = true;
            try {
                const body = { product_variant_id: variantId, quantity };
                if (requestedValue) {
                    body.requested_value = requestedValue;
                }
                // Free-form per-item context (recipient phone for top-ups,
                // delivery email for gift cards, etc.) — server stores it
                // on cart_items.metadata_snapshot and copies it onto the
                // order item at checkout so fulfilment can act on it.
                if (metadata && typeof metadata === 'object') {
                    body.metadata = metadata;
                }
                if (buyNow) {
                    body.buy_now = true;
                }
                const res = await fetch(this._url('/cart/items'), {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        Accept: 'application/json',
                        'X-CSRF-TOKEN': this._csrf(),
                    },
                    credentials: 'same-origin',
                    body: JSON.stringify(body),
                });
                if (res.ok) {
                    this._apply(await res.json());
                    this.open = true;
                    return true;
                }
                return false;
            } catch (_) {
                return false;
            } finally {
                this.loading = false;
            }
        },

        // Recompute the derived totals locally from the line items so the +/-
        // steppers update instantly; the server response then reconciles any
        // quantity-based pricing. Unit prices are per-unit, so line = unit x qty.
        _recompute() {
            let count = 0;
            let subtotal = 0;
            let subtotalUsd = 0;
            for (const it of this.items) {
                const q = Number(it.quantity) || 0;
                it.line_total = (Number(it.unit_price) || 0) * q;
                it.line_total_usd = (Number(it.unit_price_usd) || 0) * q;
                count += q;
                subtotal += it.line_total;
                subtotalUsd += it.line_total_usd;
            }
            this.count = count;
            this.subtotal = subtotal;
            this.subtotalUsd = subtotalUsd;
        },

        // Optimistic quantity change: the UI updates on the same tick, and the
        // server PATCH is debounced per item so a burst of +/- taps sends one
        // request. Reaching 0 removes the line (matches the server).
        setQty(itemId, quantity) {
            quantity = Math.floor(Number(quantity));
            if (! Number.isFinite(quantity)) { return; }

            const item = this.items.find((i) => i.id === itemId);
            if (! item) { return; }

            if (quantity <= 0) {
                this.remove(itemId);
                return;
            }

            item.quantity = quantity;
            this._recompute();

            this._qtyTimers = this._qtyTimers || {};
            clearTimeout(this._qtyTimers[itemId]);
            this._qtyTimers[itemId] = setTimeout(() => this._syncQty(itemId), 300);
        },

        async _syncQty(itemId) {
            const item = this.items.find((i) => i.id === itemId);
            if (! item) { return; }
            try {
                const res = await fetch(this._url('/cart/items/' + itemId), {
                    method: 'PATCH',
                    headers: {
                        'Content-Type': 'application/json',
                        Accept: 'application/json',
                        'X-CSRF-TOKEN': this._csrf(),
                    },
                    credentials: 'same-origin',
                    body: JSON.stringify({ quantity: item.quantity }),
                });
                if (res.ok) {
                    this._apply(await res.json());
                } else {
                    this.refresh(); // server rejected — fall back to its truth
                }
            } catch (_) {
                this.refresh();
            }
        },

        async remove(itemId) {
            // Optimistic removal so the line disappears instantly.
            this.items = this.items.filter((i) => i.id !== itemId);
            this._recompute();
            try {
                const res = await fetch(this._url('/cart/items/' + itemId), {
                    method: 'DELETE',
                    headers: { Accept: 'application/json', 'X-CSRF-TOKEN': this._csrf() },
                    credentials: 'same-origin',
                });
                if (res.ok) {
                    this._apply(await res.json());
                } else {
                    this.refresh();
                }
            } catch (_) {
                this.refresh();
            }
        },
    });

    // Hydrate once on load.
    window.Alpine.store('cart').refresh();

    // Re-price the cart when the customer switches currency in the locale modal.
    window.addEventListener('currency-changed', () => window.Alpine.store('cart').refresh());
});

/**
 * Open Google OAuth in a centred popup window instead of full-page redirect.
 * The popup lands on /auth/popup-complete after sign-in (success or failure);
 * that page postMessages back here and closes itself. We listen for the
 * message — on success we navigate to the dashboard, on cancel we just stay
 * on the current page. Popup-blocker fallback: if window.open returns null,
 * we navigate to the URL in the current tab so the user can still sign in.
 */
window.rshopOpenGoogleOAuth = function (href) {
    const w = 500, h = 650;
    const dualLeft  = (window.screenLeft !== undefined ? window.screenLeft : window.screenX) || 0;
    const dualTop   = (window.screenTop  !== undefined ? window.screenTop  : window.screenY) || 0;
    const winWidth  = window.innerWidth  || document.documentElement.clientWidth  || screen.width;
    const winHeight = window.innerHeight || document.documentElement.clientHeight || screen.height;
    const left = dualLeft + (winWidth  - w) / 2;
    const top  = dualTop  + (winHeight - h) / 2;
    const popup = window.open(href, 'rshop-google-oauth', `width=${w},height=${h},left=${left},top=${top},resizable=yes,scrollbars=yes`);
    if (! popup) {
        // Popup blocked - fall back to a normal redirect so the user can still sign in.
        window.location.href = href;
        return;
    }
    popup.focus();
    const onMessage = (event) => {
        if (event.origin !== window.location.origin) { return; }
        const data = event.data;
        if (! data || data.source !== 'rshop-google-oauth') { return; }
        window.removeEventListener('message', onMessage);
        if (data.status === 'success') {
            // Match the original full-redirect destination.
            window.location.href = '/dashboard';
        }
        // status === 'cancelled' leaves the user on the current page.
    };
    window.addEventListener('message', onMessage);
};

/**
 * Live brand search dropdown for the storefront nav. Hits /api/search/brands?q=
 * with a small debounce, renders a dropdown of matching brands while the user
 * types. Pressing Enter submits the form to /gift-cards?q= for the full
 * results page.
 */
window.navBrandSearch = function () {
    return {
        query: '',
        results: [],
        open: false,
        loading: false,
        _debounce: null,
        _aborter: null,

        onInput() {
            const q = this.query.trim();
            if (q.length < 2) {
                this.results = [];
                this.open = false;
                this.loading = false;
                if (this._aborter) this._aborter.abort();
                return;
            }
            this.open = true;
            this.loading = true;
            clearTimeout(this._debounce);
            this._debounce = setTimeout(() => this.fetchResults(q), 200);
        },

        async fetchResults(q) {
            // Abort any in-flight request so we don't race-condition stale results.
            if (this._aborter) this._aborter.abort();
            this._aborter = new AbortController();

            try {
                const res = await fetch('/api/search/brands?q=' + encodeURIComponent(q), {
                    signal: this._aborter.signal,
                    headers: { 'Accept': 'application/json' },
                });
                if (! res.ok) {
                    this.results = [];
                    this.loading = false;
                    return;
                }
                this.results = await res.json();
            } catch (err) {
                if (err.name === 'AbortError') return; // expected when typing fast
                this.results = [];
            } finally {
                this.loading = false;
            }
        },

        clear() {
            this.query = '';
            this.results = [];
            this.open = false;
            this.loading = false;
            this.$refs.search.focus();
        },
    };
};

/**
 * Dashboard command palette (CTRL/CMD + K). Combines the static "Most used"
 * account links with live product search — typing 2+ characters hits the same
 * /api/search/brands?q= endpoint the storefront nav search uses, so the palette
 * searches the whole catalogue, not just dashboard navigation.
 */
window.dashboardSearch = function () {
    return {
        open: false,
        query: '',
        results: [],
        loading: false,
        _debounce: null,
        _aborter: null,
        activeTab: 'Overview',
        tabs: ['Overview', 'Orders', 'Wallet', 'Transactions', 'Profile', 'Settings'],

        /** True once the user has typed enough to trigger a product search. */
        get searching() {
            return this.query.trim().length >= 2;
        },

        onInput() {
            const q = this.query.trim();
            if (q.length < 2) {
                this.results = [];
                this.loading = false;
                if (this._aborter) this._aborter.abort();
                return;
            }
            this.loading = true;
            clearTimeout(this._debounce);
            this._debounce = setTimeout(() => this.fetchResults(q), 200);
        },

        async fetchResults(q) {
            // Abort any in-flight request so stale results never overwrite fresh ones.
            if (this._aborter) this._aborter.abort();
            this._aborter = new AbortController();

            try {
                const res = await fetch('/api/search/brands?q=' + encodeURIComponent(q), {
                    signal: this._aborter.signal,
                    headers: { Accept: 'application/json' },
                });
                this.results = res.ok ? await res.json() : [];
            } catch (err) {
                if (err.name !== 'AbortError') this.results = [];
            } finally {
                this.loading = false;
            }
        },
    };
};

/**
 * Customer-reviews carousel. The header arrow advances the horizontal scroll
 * by one screenful of whole cards using a custom requestAnimationFrame easing
 * pass (cubic ease-in-out), so the motion is consistently smooth across every
 * browser, and loops back to the start once the end is reached. It also tracks
 * SPA navigation so the section can hold a skeleton while the next page loads.
 *
 *   x-data="customerReviewsCarousel()"  with x-ref="track" on the scroll row.
 */
window.customerReviewsCarousel = function () {
    return {
        cardW: 288,
        gap: 20,
        timer: null,

        // Measure the card width + content-column padding so the arrow steps by
        // whole cards and the first card lines up with the header title. Native
        // overflow scrolling drives manual swipes; play() auto-advances on a timer.
        setup() {
            const viewport = this.$refs.track;
            const list     = this.$refs.list;
            if (! viewport || ! list) return;

            const card   = list.querySelector('article');
            this.cardW   = card ? card.offsetWidth : 288;
            this.gap     = window.innerWidth >= 640 ? 20 : 16;
            // First-card alignment with the header is handled in CSS now (the
            // list's responsive pl-[...] matches the content column), so there's
            // no measured padding here — that measurement raced the reveal
            // animation and could land the first card off the header line.
            list.style.transform = '';
        },

        // Arrow: advance a couple of cards (one on mobile) with smooth scrolling,
        // looping back to the start once the end is reached. Touch swiping is
        // handled natively by the scroll container.
        next() {
            const viewport = this.$refs.track;
            if (! viewport) return;

            if (viewport.scrollLeft + viewport.clientWidth >= viewport.scrollWidth - 4) {
                viewport.scrollTo({ left: 0, behavior: 'smooth' });
                return;
            }

            const cardsPerStep = window.innerWidth >= 640 ? 2 : 1;
            viewport.scrollBy({ left: cardsPerStep * (this.cardW + this.gap), behavior: 'smooth' });
        },

        // Auto-advance on a timer (slideshow). Paused while the user hovers or
        // touches the row so they can read; resumed afterwards. Skips ticks while
        // the tab is hidden.
        play() {
            this.stop();
            this.timer = setInterval(() => {
                if (! document.hidden) { this.next(); }
            }, 4500);
        },
        stop() {
            if (this.timer) { clearInterval(this.timer); this.timer = null; }
        },
        destroy() {
            this.stop();
        },
    };
};

/**
 * Pointer-tracked 3D tilt for gift-card tiles. On mousemove the card rotates
 * toward the pointer, lifts, and shows a soft light glare; on mouseleave it
 * eases flat. Reduced-motion users get no tilt. Pair with the .card-3d /
 * .card-3d-glare CSS and a .card-3d-scene ancestor (which supplies perspective).
 *
 *   x-data="cardTilt()"  on the .card-3d element, with @mousemove / @mouseleave.
 */
window.cardTilt = function (max = 9) {
    return {
        _reduced() {
            return window.matchMedia('(prefers-reduced-motion: reduce)').matches;
        },

        tilt(e) {
            if (this._reduced()) return;
            const el = this.$el;
            const r = el.getBoundingClientRect();
            const px = (e.clientX - r.left) / r.width;   // 0..1 across
            const py = (e.clientY - r.top) / r.height;   // 0..1 down

            el.style.setProperty('--tilt-ry', ((px - 0.5) * 2 * max).toFixed(2) + 'deg');
            el.style.setProperty('--tilt-rx', (-(py - 0.5) * 2 * max).toFixed(2) + 'deg');
            el.style.setProperty('--tilt-lift', '-6px');
            el.style.setProperty('--tilt-scale', '1.03');
            el.style.setProperty('--glare-x', (px * 100).toFixed(1) + '%');
            el.style.setProperty('--glare-y', (py * 100).toFixed(1) + '%');
            el.classList.add('is-tilting');
        },

        reset() {
            const el = this.$el;
            el.classList.remove('is-tilting');
            el.style.setProperty('--tilt-rx', '0deg');
            el.style.setProperty('--tilt-ry', '0deg');
            el.style.setProperty('--tilt-lift', '0px');
            el.style.setProperty('--tilt-scale', '1');
        },
    };
};

/**
 * Dynamic value flipper — re-fires a quick roll-and-fade (.value-flip / the
 * valueFlip keyframe in app.css) whenever a displayed number changes. Used on
 * the product + checkout readouts: denomination, quantity, estimated price,
 * points, totals. `ready` is a closure flag (NOT reactive Alpine data, so
 * reading it inside x-effect can't re-trigger the effect) — it skips the
 * initial render so values only animate on a real change.
 *
 *   x-data="valueFlip()"  x-effect="<deps>; flash()"  on the element with x-text.
 */
window.valueFlip = function () {
    let ready = false;
    return {
        flash() {
            if (! ready) {
                ready = true;
                return;
            }
            const el = this.$el;
            el.classList.remove('value-flip');
            void el.offsetWidth; // force a reflow so the animation restarts
            el.classList.add('value-flip');
        },
    };
};

window.storefrontLocale = function () {
    // Pages that read country/currency from the URL. The listing and every brand-level
    // detail page (e.g. /gift-cards/apple) honour ?country=XX so flipping the locale
    // modal reloads with the new filters applied. The dashboard mirrors the storefront:
    // the overview (region-locked popular products) and the whole /dashboard/shop section
    // must re-source on a region switch too, otherwise the catalog stays on the old region.
    const SHOP_PATH_PREFIXES = ['/gift-cards', '/dashboard/shop'];

    // Paths where switching country must reload so the region lock re-applies and the
    // catalog re-sources: the homepage, the gift-cards section, the dashboard overview,
    // and the dashboard shop section.
    const isShopPath = (path) => {
        return path === '/'
            || path === '/dashboard'
            || SHOP_PATH_PREFIXES.some((p) => path === p || path.startsWith(p + '/'));
    };

    // Checkout renders its rate, crypto prices and fee math server-side, so a
    // currency flip must re-render the whole page - a store-only refresh leaves
    // the totals half in the old currency. Always a HARD reload: the gateway
    // script and payment state don't survive an SPA body swap.
    const isCheckoutPath = (path) => {
        return path === '/checkout' || path === '/dashboard/shop/checkout';
    };

    const read = (key, fallback) => {
        try {
            const v = localStorage.getItem('locale.' + key);
            return v === null ? fallback : v;
        } catch (_) {
            return fallback;
        }
    };

    return {
        localeModalOpen: false,

        country:        read('country',        'United States'),
        countryFlag:    read('countryFlag',    '🇺🇸'),
        countryCode:    read('countryCode',    'US'),
        language:       read('language',       'English'),
        currency:       read('currency',       'USD'),
        currencySymbol: read('currencySymbol', '$'),

        activeCategory: 'Gift Cards',

        init() {
            const save = (key) => (val) => {
                try { localStorage.setItem('locale.' + key, val ?? ''); } catch (_) {}
            };

            // Persist each piece independently.
            this.$watch('country',        save('country'));
            this.$watch('countryFlag',    save('countryFlag'));
            this.$watch('countryCode',    save('countryCode'));
            this.$watch('language',       save('language'));
            this.$watch('currency',       save('currency'));
            this.$watch('currencySymbol', save('currencySymbol'));

            // Region (country) is the catalog lock, so switching it must apply on EVERY
            // page of the site — not just shop pages — so the destination always
            // re-sources for the new region (ResolveRegion reads ?country= on any route
            // and sets the region cookie). Currency only needs a reload where prices are
            // server-rendered (shop + checkout); elsewhere the cart re-prices client-side
            // via the currency-changed event.
            //
            // A country pick mutates countryCode AND currency at once, firing two
            // watchers; we debounce into a SINGLE navigation, otherwise the two racing
            // Livewire.navigate calls sometimes leave the switch unapplied until a manual
            // refresh. Debounced => one click reliably switches.
            let reloadTimer = null;
            const navigateLocale = () => {
                clearTimeout(reloadTimer);
                reloadTimer = setTimeout(() => {
                    const checkout = isCheckoutPath(window.location.pathname);
                    const url = new URL(window.location.href);
                    if (this.countryCode) url.searchParams.set('country', this.countryCode);
                    else url.searchParams.delete('country');

                    if (this.currency) url.searchParams.set('currency', this.currency);
                    else url.searchParams.delete('currency');

                    // Checkout hard-reloads (payment scripts + server-side rate math);
                    // everywhere else uses Livewire's fast SPA navigation when available.
                    if (!checkout && window.Livewire && typeof window.Livewire.navigate === 'function') {
                        window.Livewire.navigate(url.toString());
                    } else {
                        window.location.href = url.toString();
                    }
                }, 60);
            };

            // Region: apply on every page.
            this.$watch('countryCode', navigateLocale);

            // Currency: reload only where prices render server-side; always re-price the
            // cart store client-side.
            this.$watch('currency', () => {
                const path = window.location.pathname;
                if (isShopPath(path) || isCheckoutPath(path)) navigateLocale();
                window.dispatchEvent(new CustomEvent('currency-changed'));
            });

            // NOTE: the locale modal dispatches `language-changed` itself when a
            // language is picked (works regardless of which Alpine scope the modal
            // is mounted in - storefront vs dashboard). We intentionally do NOT
            // dispatch it from this watcher to avoid a double trigger / double reload.
        },
    };
};

/**
 * Smooth SPA page transitions. On every Livewire navigation the new page's main
 * content slides in from the right (see `.page-enter` in app.css), giving the
 * app-like feel of the menu modal opening rather than a hard reload. We restart
 * the CSS animation by removing the class, forcing a reflow, then re-adding it.
 */
document.addEventListener('livewire:navigated', () => {
    document.querySelectorAll('[data-page-content]').forEach((el) => {
        el.classList.remove('page-enter');
        void el.offsetWidth; // reflow so the animation replays on every navigation
        el.classList.add('page-enter');
        // Drop the class once it finishes so no transform lingers on the content.
        el.addEventListener('animationend', () => el.classList.remove('page-enter'), { once: true });
    });
});

/**
 * Mobile-instant navigation. Livewire prefetches `wire:navigate.hover` links on
 * mouseenter, but touch devices never fire that — so a tap pays the full server
 * round-trip. We synthesize a mouseenter on touchstart so the destination page is
 * fetched while the finger is still down; by the time the tap completes, the page
 * is cached and the navigation is instant.
 */
document.addEventListener('touchstart', (e) => {
    const link = e.target.closest && e.target.closest('a[wire\\:navigate\\.hover]');
    if (link) {
        link.dispatchEvent(new MouseEvent('mouseenter', { bubbles: true }));
    }
}, { passive: true, capture: true });

/**
 * Modern dropdown select used by the KYC form (and reusable elsewhere). Replaces a
 * native <select> with a styled button + panel. Carries its value on a sibling
 * hidden <input> so it still submits with the form. Pass `searchable: true` to add
 * a filter box (used for the long country list).
 *
 *   x-data="kycSelect(options, initialValue, searchable)"
 *   where options is [{ value, label }, ...]
 */
window.kycSelect = function (options = [], initial = '', searchable = false) {
    return {
        open: false,
        value: initial,
        query: '',
        options: options,
        searchable: searchable,

        get filtered() {
            if (!this.searchable || !this.query.trim()) {
                return this.options;
            }
            const q = this.query.trim().toLowerCase();
            return this.options.filter((o) => o.label.toLowerCase().includes(q));
        },

        get selectedLabel() {
            const match = this.options.find((o) => o.value === this.value);
            return match ? match.label : '';
        },

        toggle() {
            this.open = !this.open;
            if (this.open && this.searchable) {
                this.$nextTick(() => this.$refs.search && this.$refs.search.focus());
            }
        },

        pick(value) {
            this.value = value;
            this.open = false;
            this.query = '';
        },
    };
};

/**
 * Calendar date picker used by the KYC form for date of birth. Renders a month grid
 * with month + year stepping (single chevron steps a month, double steps a year).
 * Future dates are disabled. The chosen date is written as an ISO yyyy-mm-dd string
 * to a sibling hidden <input> so it submits with the form.
 *
 *   x-data="kycDatePicker(initialIsoDate)"
 */
window.kycDatePicker = function (initial = '') {
    const today = new Date();
    const months = ['January', 'February', 'March', 'April', 'May', 'June',
        'July', 'August', 'September', 'October', 'November', 'December'];
    // Default the calendar to ~20 years ago — a sensible starting point for a birth date.
    const start = initial ? new Date(initial + 'T00:00:00') : new Date(today.getFullYear() - 20, today.getMonth(), 1);

    return {
        open: false,
        value: initial,
        viewYear: start.getFullYear(),
        viewMonth: start.getMonth(),
        months: months,
        weekdays: ['Su', 'Mo', 'Tu', 'We', 'Th', 'Fr', 'Sa'],

        get monthLabel() {
            return this.months[this.viewMonth] + ' ' + this.viewYear;
        },

        // Leading nulls pad the grid so day 1 lands under the right weekday.
        get cells() {
            const firstWeekday = new Date(this.viewYear, this.viewMonth, 1).getDay();
            const daysInMonth = new Date(this.viewYear, this.viewMonth + 1, 0).getDate();
            const arr = [];
            for (let i = 0; i < firstWeekday; i++) {
                arr.push(null);
            }
            for (let d = 1; d <= daysInMonth; d++) {
                arr.push(d);
            }
            return arr;
        },

        get displayValue() {
            if (!this.value) {
                return '';
            }
            const d = new Date(this.value + 'T00:00:00');
            return d.toLocaleDateString('en-GB', { day: 'numeric', month: 'short', year: 'numeric' });
        },

        shiftMonth(delta) {
            let m = this.viewMonth + delta;
            let y = this.viewYear;
            while (m < 0) { m += 12; y -= 1; }
            while (m > 11) { m -= 12; y += 1; }
            this.viewMonth = m;
            this.viewYear = y;
        },

        shiftYear(delta) {
            this.viewYear += delta;
        },

        isSelected(day) {
            if (!day || !this.value) {
                return false;
            }
            const s = new Date(this.value + 'T00:00:00');
            return s.getFullYear() === this.viewYear
                && s.getMonth() === this.viewMonth
                && s.getDate() === day;
        },

        isFuture(day) {
            if (!day) {
                return false;
            }
            const cellEnd = new Date(this.viewYear, this.viewMonth, day, 23, 59, 59);
            return cellEnd > new Date();
        },

        pick(day) {
            if (!day || this.isFuture(day)) {
                return;
            }
            const mm = String(this.viewMonth + 1).padStart(2, '0');
            const dd = String(day).padStart(2, '0');
            this.value = this.viewYear + '-' + mm + '-' + dd;
            this.open = false;
        },
    };
};

/*
 * Keyboard navigation for every custom dropdown / menu on the site.
 *
 * The whole storefront + admin use one markup convention: a `.relative`
 * wrapper holding a `.absolute` popup panel whose options are real <a>/<button>
 * elements. This single listener drives them all — no per-dropdown wiring:
 *   - Arrow Down / Up move focus between the open panel's options.
 *   - Enter activates the focused option natively (a focused <a> follows its
 *     href; a focused <button> fires its @click), so no Enter handling here.
 *   - It only acts when focus sits on a control inside an open dropdown, so it
 *     never hijacks arrow keys elsewhere on the page.
 */
(function () {
    const isVisible = (el) => !! (el && el.offsetParent !== null);

    // Resolve the open popup panel relative to the currently focused element.
    function panelFor(el) {
        // Focus is already inside an open panel (on an option or the search box).
        const within = el.closest('.absolute');
        if (within && isVisible(within) && within.querySelector('a[href],button')) {
            return within;
        }
        // Focus is on the toggle — the panel is a visible `.absolute` element
        // inside the same `.relative` wrapper. Require >= 2 options so a stray
        // absolutely-positioned badge/icon is never mistaken for a menu.
        const wrapper = el.closest('.relative');
        if (! wrapper) {
            return null;
        }
        return [...wrapper.querySelectorAll('.absolute')].find(
            (p) => isVisible(p) && p.querySelectorAll('a[href],button:not([disabled])').length >= 2
        ) || null;
    }

    document.addEventListener('keydown', (e) => {
        if (e.key !== 'ArrowDown' && e.key !== 'ArrowUp') {
            return;
        }
        const active = document.activeElement;
        if (! active || ! ['A', 'BUTTON', 'INPUT'].includes(active.tagName)) {
            return;
        }
        const panel = panelFor(active);
        if (! panel) {
            return;
        }
        const items = [...panel.querySelectorAll('a[href],button:not([disabled])')].filter(isVisible);
        if (! items.length) {
            return;
        }
        e.preventDefault();
        const i = items.indexOf(active);
        const next = e.key === 'ArrowDown'
            ? (i < 0 ? 0 : (i + 1) % items.length)
            : (i <= 0 ? items.length - 1 : i - 1);
        items[next].focus();
    });
})();

/**
 * Admin dashboard Trends chart — smooth sales/cost area chart powered by
 * ApexCharts, lazy-imported so the bundle stays light on pages that don't
 * use it. Series accepts `[{ date, sales, cost }, …]`. The card header has a
 * Sales / Cost / Both dropdown driving client-side series visibility.
 *
 *   x-data="salesCostChart(@js($salesCostSeries))"
 */
/**
 * Admin dashboard Best Selling Countries world map — shades countries by
 * sales volume. Lazy-imports jsvectormap + its world-merc data file on
 * mount; persists its own dropdown state. Accepts:
 *   - countries: { ISO2: usdTotal, … } (Country mode)
 *   - regions:   { Continent: usdTotal, … } (Region mode)
 *   - codeToContinent: { ISO2: 'Africa' | 'Asia' | ... }
 *
 *   x-data="bestSellingCountriesMap({
 *     countries: @js($countriesByCode),
 *     regions: @js($salesByRegion),
 *     codeToContinent: @js($codeToContinent),
 *   })"
 */
window.bestSellingCountriesMap = function (payload) {
    // codeToContinent is reference data (ISO -> continent); never changes per
    // request, so it stays as a const captured at init. The countries/regions
    // payloads DO change when the Period or Product filter is clicked - they
    // live on `this` so updateData() can mutate them without re-initializing
    // the map (the rendered SVG would be lost on a re-init).
    const CODE_TO_CONTINENT = payload.codeToContinent || {};

    return {
        map: null,
        countries: payload.countries || {},
        regions: payload.regions || {},
        view: 'country',  // 'country' | 'region'
        continent: 'all', // 'all' | 'Africa' | 'Asia' | 'Europe' | 'North America' | 'South America' | 'Oceania'

        async init() {
            // jsvectormap's map data files are side-effect scripts that call
            // `jsVectorMap.addMap(...)` against a GLOBAL constructor — they
            // don't import it. So we have to publish the constructor first,
            // THEN load the map data, or the map silently never registers
            // and the canvas paints empty. Order matters here.
            const { default: JsVectorMap } = await import('jsvectormap');
            await import('jsvectormap/dist/jsvectormap.css');
            window.jsVectorMap = JsVectorMap;
            await import('jsvectormap/dist/maps/world-merc.js');

            const el = this.$refs.map;
            if (!el) { return; }
            el.innerHTML = '';

            const dark = document.documentElement.classList.contains('dark');
            const bg = dark ? '#26416b' : '#e5e7eb';
            const stroke = dark ? '#34507a' : '#cbd5e1';

            // Initial fill, kept so _refresh() can un-shade countries that
            // drop out when a filter narrows the window.
            this._baseFill = bg;

            // Cursor affordance: grab on idle, grabbing during a drag pan.
            el.style.cursor = 'grab';
            el.addEventListener('mousedown', () => { el.style.cursor = 'grabbing'; });
            window.addEventListener('mouseup', () => { el.style.cursor = 'grab'; });

            this.map = new JsVectorMap({
                selector: el,
                map: 'world_merc',
                backgroundColor: 'transparent',
                // Interactive zoom + pan — wheel zoom, +/- buttons, click-drag pan.
                zoomOnScroll: true,
                zoomOnScrollSpeed: 3,
                zoomButtons: true,
                zoomMax: 8,
                zoomMin: 1,
                draggable: true,
                regionStyle: {
                    initial: { fill: bg, fillOpacity: 1, stroke, strokeWidth: 0.5 },
                    // Light-green hover highlight (Tailwind green-300) so the
                    // pointer-tracked country reads clearly against the blue
                    // shading of buyer regions and the grey of non-buyers.
                    hover:   { fill: '#86efac', fillOpacity: 1, cursor: 'pointer' },
                },
                series: {
                    regions: [{
                        scale: this._scale(),
                        values: this._values(),
                        // Linear normalisation — polynomial breaks on small
                        // datasets (single-buyer = NaN = black fill).
                        normalizeFunction: 'linear',
                        attribute: 'fill',
                    }],
                },
                onRegionTooltipShow: (event, tooltip, code) => {
                    const value = this._tooltipValue(code);
                    const name  = tooltip.text();
                    tooltip.text(value !== null
                        ? `${name}: USD ${value.toFixed(2)}`
                        : name, true);
                },
            });

            // Seed the shaded-codes memory with the initial paint so the first
            // filter change can reset countries that drop out.
            this._lastShaded = Object.keys(this._values());

            this.$watch('view', () => this._refresh());
            this.$watch('continent', () => this._refresh());
        },

        destroy() {
            if (this.map && typeof this.map.destroy === 'function') { this.map.destroy(); }
            this.map = null;
        },

        setView(v) { this.view = v; },
        setContinent(c) { this.continent = c; },

        // No sales in the selected window — drives the client-side empty
        // overlay; the map SVG stays mounted (wire:ignore) so a later filter
        // with data re-shades it in place instead of facing a dead canvas.
        isEmpty() {
            return Object.keys(this.countries || {}).length === 0;
        },

        // Called from the dashboard's Livewire layer (via the
        // map-data-updated browser event) when the Period or Product filter
        // changes. Swaps in the new aggregates and re-shades the SVG in
        // place - the map stays interactive throughout.
        //
        // Livewire 3 wraps named params in different detail shapes depending
        // on how the event is consumed: sometimes `{ params: [{ ... }] }`,
        // sometimes the params spread directly onto detail. We accept both.
        updateData(detail) {
            if (! detail) return;
            const payload = Array.isArray(detail?.params)
                ? (detail.params[0] || {})
                : detail;
            this.countries = payload.countries || {};
            this.regions   = payload.regions   || {};
            this._refresh();
        },

        // True when the country code belongs to the currently-selected
        // continent scope. Global ('all') passes everything.
        _inScope(code) {
            if (this.continent === 'all') { return true; }
            return CODE_TO_CONTINENT[code] === this.continent;
        },

        _scale() {
            // jsvectormap's Series only understands an ORDINAL scale: an
            // object of named keys -> colors, with values mapping each region
            // to one of those keys. (An array scale + numeric values makes
            // every lookup return undefined, which SVG paints BLACK - the
            // "black country" bug.) Four brand-blue intensity tiers; _values()
            // buckets each country's USD total into one of them.
            return { t1: '#93c5fd', t2: '#60a5fa', t3: '#3b82f6', t4: '#1d4ed8' };
        },

        // Numeric USD totals per country for the current view + continent
        // scope. The tooltip reads these directly.
        _numericValues() {
            if (this.view === 'country') {
                const out = {};
                Object.keys(this.countries).forEach((cc) => {
                    if (this._inScope(cc)) { out[cc] = this.countries[cc]; }
                });
                return out;
            }
            // Region mode: every country in the same continent gets the
            // continent's aggregate value. When scoped to one continent,
            // only paint countries within it.
            const out = {};
            Object.keys(CODE_TO_CONTINENT).forEach((cc) => {
                if (!this._inScope(cc)) { return; }
                const region = CODE_TO_CONTINENT[cc];
                if (this.regions[region] !== undefined) { out[cc] = this.regions[region]; }
            });
            return out;
        },

        // Bucket the numeric totals into the ordinal tier keys the scale
        // understands: top seller gets the deepest blue, others scale down.
        _values() {
            const nums = this._numericValues();
            const max = Math.max(0, ...Object.values(nums));
            const out = {};
            Object.keys(nums).forEach((cc) => {
                const ratio = max > 0 ? nums[cc] / max : 0;
                out[cc] = ratio > 0.75 ? 't4' : (ratio > 0.5 ? 't3' : (ratio > 0.25 ? 't2' : 't1'));
            });
            return out;
        },

        _tooltipValue(code) {
            if (!this._inScope(code)) { return null; }
            if (this.view === 'country') { return this.countries[code] ?? null; }
            const region = CODE_TO_CONTINENT[code];
            return region ? (this.regions[region] ?? null) : null;
        },

        _refresh() {
            if (!this.map) { return; }
            const series = this.map.series.regions[0];
            const tiers = this._values();

            // setValues() only paints the codes it is given - countries shaded
            // by the previous filter would keep their old blue, so reset any
            // that dropped out of the new window back to the base fill first.
            const reset = {};
            (this._lastShaded || []).forEach((cc) => {
                if (!(cc in tiers)) { reset[cc] = this._baseFill; }
            });
            series.setAttributes(reset);

            series.setValues(tiers);
            this._lastShaded = Object.keys(tiers);
        },
    };
};

/**
 * Admin dashboard New Users bar chart — monthly registrations rendered as
 * a smooth rounded-bar chart by ApexCharts. Same lazy-import pattern as
 * salesCostChart. Series accepts `[{ label, value }, …]`.
 *
 *   x-data="newUsersChart(@js($newUsersSeries))"
 */
window.newUsersChart = function (series) {
    return {
        chart: null,
        series: series || [],

        async init() {
            const { default: ApexCharts } = await import('apexcharts');
            if (this.chart) { this.chart.destroy(); this.chart = null; }
            if (this.$refs.canvas) { this.$refs.canvas.innerHTML = ''; }
            this.chart = new ApexCharts(this.$refs.canvas, this._options());
            await this.chart.render();
        },

        destroy() {
            if (this.chart) { this.chart.destroy(); this.chart = null; }
            if (this.$refs.canvas) { this.$refs.canvas.innerHTML = ''; }
        },

        _options() {
            const dark = document.documentElement.classList.contains('dark');
            const gridColor = dark ? 'rgba(255,255,255,0.08)' : '#e5e7eb';
            const textColor = dark ? '#94a3b8' : '#52525b';
            const tooltipBg = dark ? 'rgba(38, 65, 107, 0.95)' : 'rgba(255,255,255,0.96)';
            const tooltipText = dark ? '#ffffff' : '#0f172a';

            return {
                chart: {
                    type: 'bar',
                    height: 320,
                    toolbar: { show: false },
                    fontFamily: 'inherit',
                    background: 'transparent',
                    animations: { speed: 450, easing: 'easeout' },
                    parentHeightOffset: 0,
                },
                series: [{ name: 'New users', data: this.series.map((p) => p.value) }],
                colors: ['#2563eb'], // brand blue-600
                plotOptions: {
                    bar: {
                        borderRadius: 10,
                        borderRadiusApplication: 'end',
                        columnWidth: '38%',
                        distributed: false,
                    },
                },
                fill: {
                    type: 'gradient',
                    gradient: {
                        type: 'vertical',
                        shadeIntensity: 1,
                        gradientToColors: ['#3b82f6'],
                        opacityFrom: 1,
                        opacityTo: 0.85,
                        stops: [0, 100],
                    },
                },
                stroke: { show: false, width: 0 },
                xaxis: {
                    categories: this.series.map((p) => p.label),
                    labels: { style: { colors: textColor, fontSize: '11px', fontWeight: 500 } },
                    axisBorder: { show: false },
                    axisTicks: { show: false },
                },
                yaxis: {
                    labels: {
                        style: { colors: textColor, fontSize: '11px', fontWeight: 500 },
                        formatter: (v) => Number(v).toFixed(0),
                    },
                },
                grid: {
                    borderColor: gridColor,
                    strokeDashArray: 4,
                    xaxis: { lines: { show: false } },
                    yaxis: { lines: { show: true } },
                    padding: { left: 0, right: 8 },
                },
                dataLabels: { enabled: false },
                legend: { show: false },
                tooltip: {
                    theme: dark ? 'dark' : 'light',
                    custom: ({ series, dataPointIndex, w }) => {
                        const label = w.globals.labels?.[dataPointIndex] ?? '';
                        const value = series[0]?.[dataPointIndex] ?? 0;
                        return `
                            <div style="padding:10px 14px;border-radius:12px;background:${tooltipBg};backdrop-filter:blur(8px);box-shadow:0 8px 24px -8px rgba(0,0,0,0.35);color:${tooltipText};">
                                <div style="font-size:11px;font-weight:500;opacity:0.7;margin-bottom:4px;">${label}</div>
                                <div style="display:flex;align-items:center;gap:8px;">
                                    <span style="height:8px;width:8px;border-radius:9999px;background:#2563eb;display:inline-block;"></span>
                                    <span style="font-size:12px;font-weight:600;">${value} new user${value === 1 ? '' : 's'}</span>
                                </div>
                            </div>`;
                    },
                },
            };
        },
    };
};

/**
 * Glassmorphism tooltip renderer shared by the admin dashboard's Trends chart
 * (salesCostChart) and the /admin/reports chart (reportChart). Builds an
 * ApexCharts `tooltip.custom` HTML string that uses backdrop-blur + low-alpha
 * background + an inset white border for the "frosted glass" look.
 *
 * Returns a closure ApexCharts can call directly. Theme-aware: pass the
 * `dark` boolean (typically `document.documentElement.classList.contains('dark')`)
 * so colours adapt.
 */
function buildGlassTooltipRenderer(dark) {
    // Inverted glass: dark mode shows a WHITE-tinted frosted panel; light mode
    // shows a DARK-tinted frosted panel. Both keep light text so legibility
    // stays consistent regardless of the chart area behind the tooltip.
    const panelBg = dark ? 'rgba(255, 255, 255, 0.10)' : 'rgba(15, 23, 42, 0.22)';
    const panelBorder = dark ? 'rgba(255, 255, 255, 0.22)' : 'rgba(15, 23, 42, 0.28)';
    const insetHighlight = dark ? 'rgba(255, 255, 255, 0.25)' : 'rgba(255, 255, 255, 0.18)';
    const labelColor = 'rgba(255, 255, 255, 0.72)';
    const valueColor = '#ffffff';
    const dateColor = 'rgba(255, 255, 255, 0.55)';

    return ({ series, dataPointIndex, w }) => {
        // Apex stores datetime-xaxis labels as raw ms-since-epoch strings (e.g.
        // "1779753600000"). Try categoryLabels / labels first, then detect a
        // numeric timestamp and humanise it, then fall back to seriesX.
        let dateLabel = w.globals.categoryLabels?.[dataPointIndex]
            ?? w.globals.labels?.[dataPointIndex]
            ?? '';

        if (dateLabel && /^\d{10,}$/.test(String(dateLabel))) {
            try {
                dateLabel = new Date(Number(dateLabel)).toLocaleDateString(undefined, { month: 'short', day: 'numeric', year: 'numeric' });
            } catch (e) { /* keep raw label */ }
        }

        if (!dateLabel && w.globals.seriesX?.[0]?.[dataPointIndex]) {
            const ts = w.globals.seriesX[0][dataPointIndex];
            try {
                dateLabel = new Date(ts).toLocaleDateString(undefined, { month: 'short', day: 'numeric', year: 'numeric' });
            } catch (e) { /* swallow — header just won't show */ }
        }

        const rows = w.globals.seriesNames.map((name, idx) => {
            const colour = w.globals.colors[idx];
            const value = series[idx]?.[dataPointIndex];
            if (value === undefined || value === null) { return ''; }
            // Halo behind the dot via `box-shadow` so we can recolour per-series
            // without an extra wrapping element. `${colour}33` = colour @ 20% alpha.
            return `
                <div style="display:flex;align-items:center;gap:7px;padding:1px 0;">
                    <span style="height:6px;width:6px;border-radius:9999px;background:${colour};box-shadow:0 0 0 2px ${colour}30;display:inline-block;flex-shrink:0;"></span>
                    <span style="font-size:10px;font-weight:500;color:${labelColor};letter-spacing:0.01em;">${name}</span>
                    <span style="font-size:11px;font-weight:700;color:${valueColor};margin-left:10px;font-variant-numeric:tabular-nums;letter-spacing:-0.01em;">USD ${Number(value).toFixed(2)}</span>
                </div>`;
        }).join('');

        const header = dateLabel
            ? `<p style="margin:0 0 4px;font-size:9px;font-weight:700;text-transform:uppercase;letter-spacing:0.08em;color:${dateColor};">${dateLabel}</p>`
            : '';

        return `<div style="
            padding:7px 9px;
            border-radius:10px;
            background:${panelBg};
            backdrop-filter:blur(16px) saturate(180%);
            -webkit-backdrop-filter:blur(16px) saturate(180%);
            border:1px solid ${panelBorder};
            box-shadow:
                0 8px 20px rgba(0, 0, 0, 0.32),
                inset 0 1px 0 ${insetHighlight};
        ">${header}${rows}</div>`;
    };
}

window.salesCostChart = function (series) {
    const SALES_COLOR = '#34d399'; // emerald-400
    const COST_COLOR  = '#60a5fa'; // blue-400

    return {
        chart: null,
        mode: 'both',            // 'both' | 'sales' | 'cost'
        series: series || [],
        salesColor: SALES_COLOR,
        costColor: COST_COLOR,

        async init() {
            const { default: ApexCharts } = await import('apexcharts');
            // Defensive: if Livewire's wire:navigate re-mounts this card an
            // older ApexCharts SVG may still be sitting inside $refs.canvas.
            // Clear it before mounting a new one so we never stack charts.
            if (this.chart) { this.chart.destroy(); this.chart = null; }
            if (this.$refs.canvas) { this.$refs.canvas.innerHTML = ''; }
            this.chart = new ApexCharts(this.$refs.canvas, this._options());
            await this.chart.render();
            this.$watch('mode', () => this._update());
        },

        destroy() {
            if (this.chart) { this.chart.destroy(); this.chart = null; }
            if (this.$refs.canvas) { this.$refs.canvas.innerHTML = ''; }
        },

        setMode(mode) { this.mode = mode; },

        modeLabel() {
            return this.mode === 'sales' ? 'Sales' : (this.mode === 'cost' ? 'Cost' : 'Sales / Cost');
        },

        // No data in the selected window — drives the client-side empty
        // overlay so the canvas can stay in the DOM (wire:ignore) across
        // filter changes instead of being swapped out by a server re-render.
        isEmpty() {
            return !this.series
                || this.series.length === 0
                || this.series.every((p) => !Number(p.sales) && !Number(p.cost));
        },

        // Called via the trends-data-updated browser event when the period
        // filter changes server-side. Swaps the series into the live chart -
        // no page reload, no re-init. Livewire 3 wraps named params in
        // different detail shapes depending on the consumer; accept both.
        updateData(detail) {
            if (! detail) return;
            const payload = Array.isArray(detail?.params)
                ? (detail.params[0] || {})
                : detail;
            this.series = payload.series || [];
            if (! this.chart) return; // init() will pick the series up when it lands

            this.chart.updateSeries([
                { name: 'Sales', data: this.series.map((p) => [new Date(p.date).getTime(), p.sales]) },
                { name: 'Cost',  data: this.series.map((p) => [new Date(p.date).getTime(), p.cost])  },
            ]);

            // updateSeries resets per-series visibility, so a "Sales only" or
            // "Cost only" selection would silently revert to both after a period
            // change. Re-apply the active mode so the filter sticks.
            this._update();
        },

        _update() {
            if (! this.chart) return;
            const wanted = this.mode === 'both' ? ['Sales', 'Cost'] : (this.mode === 'sales' ? ['Sales'] : ['Cost']);
            ['Sales', 'Cost'].forEach((name) => {
                if (wanted.includes(name)) { this.chart.showSeries(name); }
                else                        { this.chart.hideSeries(name); }
            });
        },

        _options() {
            const dark = document.documentElement.classList.contains('dark');
            const gridColor = dark ? 'rgba(255,255,255,0.08)' : '#e5e7eb';
            const textColor = dark ? '#94a3b8' : '#52525b';
            const renderGlassTooltip = buildGlassTooltipRenderer(dark);

            return {
                chart: {
                    type: 'area',
                    height: 320,
                    toolbar: { show: false },
                    zoom: { enabled: false },
                    fontFamily: 'inherit',
                    background: 'transparent',
                    animations: { speed: 450, easing: 'easeout' },
                    parentHeightOffset: 0,
                },
                series: [
                    { name: 'Sales', data: this.series.map((p) => [new Date(p.date).getTime(), p.sales]) },
                    { name: 'Cost',  data: this.series.map((p) => [new Date(p.date).getTime(), p.cost])  },
                ],
                colors: [SALES_COLOR, COST_COLOR],
                stroke: { curve: 'smooth', width: 2.5, lineCap: 'round' },
                fill: {
                    type: 'gradient',
                    gradient: { shadeIntensity: 1, opacityFrom: 0.30, opacityTo: 0.0, stops: [0, 90, 100] },
                },
                xaxis: {
                    type: 'datetime',
                    labels: {
                        style: { colors: textColor, fontSize: '11px', fontWeight: 500 },
                        // The series is daily buckets, but with a short data
                        // window Apex zooms to hour-level ticks whose default
                        // format is HH:mm — every label reads "00:00". Override
                        // hour/minute so sub-day ticks still print the day.
                        datetimeFormatter: { day: 'MMM dd, ddd', hour: 'MMM dd', minute: 'MMM dd' },
                    },
                    axisBorder: { show: false },
                    axisTicks: { show: false },
                    crosshairs: {
                        show: true,
                        width: 1,
                        position: 'back',
                        stroke: { color: dark ? '#475569' : '#94a3b8', width: 1, dashArray: 0 },
                    },
                    tooltip: { enabled: false },
                },
                yaxis: {
                    labels: {
                        style: { colors: textColor, fontSize: '11px', fontWeight: 500 },
                        formatter: (v) => 'USD ' + Number(v).toFixed(2),
                    },
                },
                grid: {
                    borderColor: gridColor,
                    strokeDashArray: 4,
                    xaxis: { lines: { show: false } },
                    yaxis: { lines: { show: true } },
                    padding: { left: 0, right: 12, top: 0, bottom: 0 },
                },
                dataLabels: { enabled: false },
                legend: { show: false },
                tooltip: {
                    theme: dark ? 'dark' : 'light',
                    shared: true,
                    intersect: false,
                    followCursor: false,
                    fixed: { enabled: false },
                    marker: { show: true },
                    style: { fontSize: '12px' },
                    x: { format: 'MMM dd, yyyy' },
                    y: { formatter: (v) => 'USD ' + Number(v).toFixed(2) },
                    custom: renderGlassTooltip,
                },
                markers: {
                    size: 0,
                    strokeWidth: 2,
                    strokeColors: dark ? '#1d3252' : '#ffffff',
                    hover: { size: 6, sizeOffset: 2 },
                },
            };
        },
    };
};

/**
 * Global confirm-modal wiring. Two pieces:
 *
 * 1. `confirmModal()` — Alpine factory bound to <x-confirm-modal />, owns the
 *    open/cancel/confirm state and replays the original action when confirmed.
 * 2. A capture-phase listener for forms / buttons carrying `data-confirm="..."`
 *    that preempts the action and fires a `confirm-show` event the modal picks
 *    up. Marking the element with `data-confirmed="true"` lets the replayed
 *    submit/click pass straight through without re-triggering the modal.
 *
 * Why capture-phase and `stopImmediatePropagation`: third-party listeners (e.g.
 * Livewire/Volt) already attach to submit/click in the bubble phase. We must
 * intercept BEFORE them and silence the chain so the network request never
 * fires until the admin/user actually confirms.
 *
 *   <form data-confirm="Delete row?" data-confirm-tone="danger" ...>
 *   <button data-confirm="Continue?" @click="..."> ...
 */
window.confirmModal = function () {
    return {
        isOpen: false,
        title: 'Are you sure?',
        message: '',
        confirmText: 'Confirm',
        cancelText: 'Cancel',
        tone: 'danger',       // 'danger' | 'warning' | 'primary' | 'success'
        target: null,         // element to submit/click on confirm

        open(detail) {
            this.title = detail.title || 'Are you sure?';
            this.message = detail.message || '';
            this.confirmText = detail.confirmText || 'Confirm';
            this.cancelText = detail.cancelText || 'Cancel';
            this.tone = detail.tone || 'danger';
            this.target = detail.target || null;
            this.isOpen = true;

            // Auto-focus the confirm button so Enter completes the action and
            // Escape (bound on the panel) cancels — keyboard-only flow stays cheap.
            this.$nextTick(() => this.$refs.confirmBtn?.focus());
        },

        cancel() {
            this.isOpen = false;
            this.target = null;
        },

        confirm() {
            const el = this.target;
            this.isOpen = false;
            this.target = null;

            if (!el) { return; }

            // Replay the original action. `data-confirmed` shortcircuits our own
            // listener so the submit/click goes through this time.
            el.dataset.confirmed = 'true';

            if (el.tagName === 'FORM') {
                // .submit() bypasses any onsubmit handler — that's fine because
                // we already gated it; the form's normal action runs.
                el.submit();
            } else {
                el.click();
            }
        },
    };
};

/**
 * /admin/reports chart. Reuses the `series` shape coming out of
 * DashboardMetricsQuery::getReportSeries — one row per bucket carrying
 * `date / sales_usd / cost_usd`. Supports two chart types selected by the
 * page's Bar / Line toggle:
 *
 *   - line: smooth area chart (same look as the dashboard's Trends widget)
 *   - bar:  vertical columns, sales + cost side by side
 *
 *   x-data="reportChart(@js($this->series), @js($chartType))"
 *
 * The Volt component re-mounts the wrapper via `wire:key` whenever the
 * chart type / granularity / category changes, so init() always runs against
 * a fresh DOM node — no need for an in-place rebuild hook.
 */
window.reportChart = function (series, chartType) {
    const SALES_COLOR = '#34d399'; // emerald-400
    const COST_COLOR = '#60a5fa';  // blue-400

    return {
        chart: null,
        series: series || [],
        chartType: chartType || 'line',

        async init() {
            if (!this.series.length || !this.$refs.canvas) { return; }
            const { default: ApexCharts } = await import('apexcharts');
            this.$refs.canvas.innerHTML = '';
            this.chart = new ApexCharts(this.$refs.canvas, this._options());
            await this.chart.render();
        },

        destroy() {
            if (this.chart) { this.chart.destroy(); this.chart = null; }
            if (this.$refs.canvas) { this.$refs.canvas.innerHTML = ''; }
        },

        _options() {
            const dark = document.documentElement.classList.contains('dark');
            const gridColor = dark ? 'rgba(255,255,255,0.08)' : '#e5e7eb';
            const textColor = dark ? '#94a3b8' : '#52525b';
            const isBar = this.chartType === 'bar';
            const renderGlassTooltip = buildGlassTooltipRenderer(dark);

            const base = {
                chart: {
                    type: isBar ? 'bar' : 'area',
                    height: 320,
                    toolbar: { show: false },
                    zoom: { enabled: false },
                    fontFamily: 'inherit',
                    background: 'transparent',
                    animations: { speed: 450, easing: 'easeout' },
                    parentHeightOffset: 0,
                },
                series: [
                    { name: 'Sales', data: this.series.map((p) => [new Date(p.date).getTime(), p.sales_usd]) },
                    { name: 'Cost', data: this.series.map((p) => [new Date(p.date).getTime(), p.cost_usd]) },
                ],
                colors: [SALES_COLOR, COST_COLOR],
                xaxis: {
                    type: 'datetime',
                    labels: {
                        style: { colors: textColor, fontSize: '11px', fontWeight: 500 },
                        // Same fix as the dashboard Trends chart: short data
                        // windows make Apex pick hour-level ticks, whose
                        // default HH:mm format prints "00:00" everywhere.
                        datetimeFormatter: { day: 'MMM dd, ddd', hour: 'MMM dd', minute: 'MMM dd' },
                    },
                    axisBorder: { show: false },
                    axisTicks: { show: false },
                    tooltip: { enabled: false },
                },
                yaxis: {
                    labels: {
                        style: { colors: textColor, fontSize: '11px', fontWeight: 500 },
                        formatter: (v) => 'USD ' + Number(v).toFixed(2),
                    },
                },
                grid: {
                    borderColor: gridColor,
                    strokeDashArray: 4,
                    xaxis: { lines: { show: false } },
                    yaxis: { lines: { show: true } },
                    padding: { left: 0, right: 12, top: 0, bottom: 0 },
                },
                dataLabels: { enabled: false },
                legend: { show: false },
                tooltip: {
                    theme: dark ? 'dark' : 'light',
                    shared: true,
                    intersect: false,
                    x: { format: 'MMM dd, yyyy' },
                    y: { formatter: (v) => 'USD ' + Number(v).toFixed(2) },
                    custom: renderGlassTooltip,
                },
            };

            if (isBar) {
                return {
                    ...base,
                    plotOptions: {
                        bar: { borderRadius: 6, borderRadiusApplication: 'end', columnWidth: '55%' },
                    },
                    stroke: { show: false, width: 0 },
                };
            }

            return {
                ...base,
                stroke: { curve: 'smooth', width: 2.5, lineCap: 'round' },
                fill: {
                    type: 'gradient',
                    gradient: { shadeIntensity: 1, opacityFrom: 0.30, opacityTo: 0.0, stops: [0, 90, 100] },
                },
                markers: {
                    size: 0,
                    strokeWidth: 2,
                    strokeColors: dark ? '#1d3252' : '#ffffff',
                    hover: { size: 6, sizeOffset: 2 },
                },
            };
        },
    };
};

(function installConfirmDispatcher() {
    if (typeof window === 'undefined' || typeof document === 'undefined') { return; }

    function dispatch(target, source) {
        window.dispatchEvent(new CustomEvent('confirm-show', {
            detail: {
                title: source.dataset.confirmTitle || 'Are you sure?',
                message: source.dataset.confirm,
                confirmText: source.dataset.confirmText || 'Confirm',
                cancelText: source.dataset.confirmCancel || 'Cancel',
                tone: source.dataset.confirmTone || 'danger',
                target,
            },
        }));
    }

    document.addEventListener('submit', (event) => {
        const form = event.target;
        if (!(form instanceof HTMLFormElement)) { return; }
        if (!form.dataset.confirm) { return; }
        if (form.dataset.confirmed === 'true') {
            // Replayed by the modal — clear the flag and let the submit through.
            delete form.dataset.confirmed;
            return;
        }
        event.preventDefault();
        event.stopImmediatePropagation();
        dispatch(form, form);
    }, true);

    document.addEventListener('click', (event) => {
        const btn = event.target.closest('[data-confirm]');
        if (!btn) { return; }
        // Skip submit buttons — the form's submit handler above owns those, so
        // we don't double-prompt when the button lives inside a confirmed form.
        if (btn.tagName === 'BUTTON' && btn.type === 'submit') { return; }
        if (btn.tagName === 'FORM') { return; } // handled by the submit listener
        if (btn.dataset.confirmed === 'true') {
            delete btn.dataset.confirmed;
            return;
        }
        event.preventDefault();
        event.stopImmediatePropagation();
        dispatch(btn, btn);
    }, true);
})();

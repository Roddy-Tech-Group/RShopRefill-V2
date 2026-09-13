{{--
    Site translation engine.

    Applies the MACHINE languages from config/localization.php - the ones the
    app has no lang/{code} directory for - by driving a browser translate
    widget. The list it can reach is exactly the list the picker offers
    (App\Support\Languages), so a visitor can never land in a language the
    picker cannot show back to them.

    It deliberately does NOTHING for a server language. English and French are
    rendered by Laravel from real translation files, so for those visitors this
    partial loads no third-party script, makes no third-party request, and
    leaves the page exactly as the server sent it. That is most traffic.

    Two entry points:

      1. Auto-detect - on a first load with no stored choice, the browser
         language picks one of the offered languages, or English if it names
         none of them.
      2. Manual - the locale modal stages a language and its Save posts the
         matching SERVER locale while setting the cookie this engine reads, so
         one reload applies both halves.

    The widget's own banner and toolbar are hidden; the locale modal is the
    only language control a customer ever sees.
--}}

<style>
    /* Hide the injected translate banner/toolbar - we drive everything from the locale modal. */
    .goog-te-banner-frame.skiptranslate,
    .goog-te-gadget,
    .goog-te-gadget-icon,
    #goog-gt-tt,
    .goog-te-balloon-frame,
    #google_translate_element { display: none !important; }

    .goog-text-highlight { background: none !important; box-shadow: none !important; }

    /* The widget pushes <body> down with an inline `top`; keep our layout flush. */
    body { top: 0 !important; position: static !important; }
    iframe.skiptranslate { visibility: hidden !important; height: 0 !important; border: 0 !important; }
</style>

{{-- Hidden mount point for the translate widget. --}}
<div id="google_translate_element" aria-hidden="true"></div>

<script>
(function () {
    'use strict';

    // ── The offered languages, resolved server-side from one config ──────────
    // ENGINE holds ONLY machine languages: a name in here means the widget owns
    // it. English and French are absent because Laravel renders them, and that
    // absence is what stops this file from machine-translating a page that was
    // already translated properly.
    var ENGINE        = @json(\App\Support\Languages::engineMap());
    var SERVER_LOCALE = @json(\App\Support\Languages::serverLocaleMap());
    var DETECT        = @json(\App\Support\Languages::detectionMap());
    var OFFERED       = @json(\App\Support\Languages::names());
    var DEFAULT_NAME  = @json(\App\Support\Languages::defaultName());

    // What Laravel actually rendered this response in. The widget is told this
    // as the source language, so it is truthful even if the session locale and
    // the stored language ever disagree.
    var PAGE_LOCALE   = @json(app()->getLocale());

    var STORAGE_KEY = 'locale.language';

    function isOffered(name) { return !!name && OFFERED.indexOf(name) !== -1; }
    function engineCode(name) { return ENGINE[name] || null; }

    function readStored() {
        try { return localStorage.getItem(STORAGE_KEY); } catch (e) { return null; }
    }
    function writeStored(name) {
        try { localStorage.setItem(STORAGE_KEY, name); } catch (e) {}
    }

    // ── The widget's cookie ───────────────────────────────────────────────────
    // Written on the bare host and both dotted forms because the widget reads
    // whichever the deployment happens to produce.
    function googHosts() {
        var host = location.hostname;
        var hosts = [null];
        if (host.indexOf('.') > -1 && !/^\d+(\.\d+)+$/.test(host)) {
            hosts.push(host, '.' + host);
        }
        return hosts;
    }
    function setGoogTrans(value) {
        googHosts().forEach(function (domain) {
            document.cookie = 'googtrans=' + value + ';path=/' + (domain ? ';domain=' + domain : '');
        });
    }
    function clearGoogTrans() {
        var expire = ';expires=Thu, 01 Jan 1970 00:00:00 GMT;path=/';
        googHosts().forEach(function (domain) {
            document.cookie = 'googtrans=' + expire + (domain ? ';domain=' + domain : '');
        });
    }

    // ── Auto-detect, limited to what the picker offers ────────────────────────
    // An unrecognised tag falls back to the default rather than to the widget's
    // nearest match: being silently machine-translated into a language you
    // cannot then un-pick is worse than being shown English.
    function detectName() {
        var nav = (navigator.language || navigator.userLanguage || '').toLowerCase();
        if (!nav) { return DEFAULT_NAME; }
        if (DETECT[nav]) { return DETECT[nav]; }
        return DETECT[nav.split('-')[0]] || DEFAULT_NAME;
    }

    // ── Resolve what is in force ──────────────────────────────────────────────
    var stored = readStored();

    // A preference from before the list was trimmed is retired here rather than
    // honoured. Without this a visitor stored as, say, Hindi keeps getting a
    // Hindi page while the picker shows ten languages, none of them checked.
    if (stored && !isOffered(stored)) {
        stored = null;
        clearGoogTrans();
    }

    if (!stored) {
        stored = detectName();
        writeStored(stored);
    }

    var activeCode = engineCode(stored);

    // ── Server languages: nothing to do, and nothing to load ──────────────────
    if (!activeCode) {
        clearGoogTrans();
    } else {
        // Source is what the server rendered, target is the stored choice.
        setGoogTrans('/' + PAGE_LOCALE + '/' + activeCode);
    }

    // ── Public hook for the locale modal ──────────────────────────────────────
    // The modal's Save posts the server locale AND sets this cookie, so the one
    // reload that POST already causes comes back in the right language by both
    // mechanisms. The source here is the locale the POST is about to install,
    // not the one currently rendered.
    window.rshopSetTranslateCookie = function (name) {
        if (!isOffered(name)) { return; }
        var code = engineCode(name);
        if (!code) { clearGoogTrans(); return; }
        setGoogTrans('/' + (SERVER_LOCALE[name] || PAGE_LOCALE) + '/' + code);
    };

    // Direct application, for anything that changes language outside the modal.
    // Reloads so the page comes back already translated rather than visibly
    // re-translating in place.
    window.addEventListener('language-changed', function (e) {
        var name = (e && e.detail) ? e.detail : readStored();
        if (!isOffered(name)) { return; }
        writeStored(name);
        window.rshopSetTranslateCookie(name);
        window.location.reload();
    });

    // Everything below drives the widget. A server language needs none of it,
    // so this is where most visitors stop: no preconnect, no script, no
    // third-party request at all.
    if (!activeCode) { return; }

    // ── Widget bootstrap ──────────────────────────────────────────────────────
    function initElement() {
        if (!(window.google && window.google.translate && window.google.translate.TranslateElement)) {
            return false;
        }
        new google.translate.TranslateElement({
            // The language the page is genuinely in, not a hardcoded 'en'.
            pageLanguage: PAGE_LOCALE,
            autoDisplay: false,
            layout: google.translate.TranslateElement.InlineLayout.SIMPLE
        }, 'google_translate_element');
        return true;
    }

    window.googleTranslateElementInit = function () { initElement(); };

    function loadWidget() {
        if (document.getElementById('translate-engine-script')) { return; }

        // Warm DNS and TLS only for the visitors who will actually use it.
        var pre = document.createElement('link');
        pre.rel = 'preconnect';
        pre.href = 'https://translate.googleapis.com';
        pre.crossOrigin = '';
        document.head.appendChild(pre);

        var s = document.createElement('script');
        s.id = 'translate-engine-script';
        s.src = 'https://translate.google.com/translate_a/element.js?cb=googleTranslateElementInit';
        s.async = true;
        document.head.appendChild(s);
    }
    loadWidget();

    // ── Re-apply after SPA navigation ─────────────────────────────────────────
    // Livewire swaps the body, which takes the widget's mount point and its
    // injected <select> with it. Re-creating the element re-scans the new
    // document; the old code instead polled 80 times at 60ms for a <select>
    // that navigation had already destroyed, so it burned 4.8 seconds per
    // page swap and usually found nothing.
    function reapply(attempts) {
        var combo = document.querySelector('select.goog-te-combo');

        if (combo) {
            if (combo.value !== activeCode) {
                combo.value = activeCode;
                combo.dispatchEvent(new Event('change'));
            }
            return;
        }

        // No select: the widget is gone with the old body. Rebuild it, which
        // re-translates from the cookie on its own.
        if (initElement()) { return; }

        // Script still in flight on a very early navigation. Bounded, and an
        // order of magnitude shorter than what it replaces.
        if (attempts > 0) {
            setTimeout(function () { reapply(attempts - 1); }, 100);
        }
    }

    window.addEventListener('livewire:navigated', function () { reapply(10); });
})();
</script>

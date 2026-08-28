{{--
    Site translation engine.

    Drives whole-page translation for the 88 languages offered in the locale modal
    (resources/views/components/nav/locale-modal.blade.php). Two entry points:

      1. Auto-detect — on a visitor's first load (no stored choice) we read the
         browser language and translate to it automatically.
      2. Manual — picking a language in the locale modal sets `locale.language`
         in localStorage and dispatches `language-changed`; we re-translate live.

    The choice is persisted in the `googtrans` cookie so it survives full reloads,
    and re-applied on `livewire:navigated` so SPA page swaps stay translated.

    The third-party translate widget's banner/toolbar is hidden via CSS; only our
    own modal picker is ever shown to the customer.
--}}

<style>
    /* Hide the injected translate banner/toolbar — we drive everything from the locale modal. */
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
    // Locale-modal display name -> translate engine language code.
    var LANG_MAP = {
        'English': 'en', 'Mandarin Chinese': 'zh-CN', 'Hindi': 'hi', 'Spanish': 'es', 'French': 'fr',
        'Standard Arabic': 'ar', 'Bengali': 'bn', 'Russian': 'ru', 'Portuguese': 'pt', 'Indonesian': 'id',
        'Urdu': 'ur', 'German': 'de', 'Japanese': 'ja', 'Swahili': 'sw', 'Marathi': 'mr', 'Telugu': 'te',
        'Turkish': 'tr', 'Tamil': 'ta', 'Cantonese': 'zh-TW', 'Vietnamese': 'vi', 'Korean': 'ko',
        'Italian': 'it', 'Hausa': 'ha', 'Thai': 'th', 'Gujarati': 'gu', 'Persian': 'fa', 'Polish': 'pl',
        'Pashto': 'ps', 'Kannada': 'kn', 'Malayalam': 'ml', 'Sundanese': 'su', 'Hebrew': 'iw',
        'Burmese': 'my', 'Amharic': 'am', 'Oromo': 'om', 'Yoruba': 'yo', 'Igbo': 'ig', 'Ukrainian': 'uk',
        'Dutch': 'nl', 'Romanian': 'ro', 'Filipino': 'tl', 'Greek': 'el', 'Czech': 'cs', 'Swedish': 'sv',
        'Hungarian': 'hu', 'Serbian': 'sr', 'Croatian': 'hr', 'Bulgarian': 'bg', 'Danish': 'da',
        'Finnish': 'fi', 'Norwegian': 'no', 'Slovak': 'sk', 'Afrikaans': 'af', 'Albanian': 'sq',
        'Armenian': 'hy', 'Azerbaijani': 'az', 'Basque': 'eu', 'Belarusian': 'be', 'Bosnian': 'bs',
        'Catalan': 'ca', 'Estonian': 'et', 'Galician': 'gl', 'Georgian': 'ka', 'Hawaiian': 'haw',
        'Icelandic': 'is', 'Irish': 'ga', 'Kazakh': 'kk', 'Khmer': 'km', 'Lao': 'lo', 'Latvian': 'lv',
        'Lithuanian': 'lt', 'Macedonian': 'mk', 'Malay': 'ms', 'Maltese': 'mt', 'Maori': 'mi',
        'Mongolian': 'mn', 'Nepali': 'ne', 'Punjabi': 'pa', 'Sinhala': 'si', 'Slovenian': 'sl',
        'Somali': 'so', 'Tajik': 'tg', 'Turkmen': 'tk', 'Uzbek': 'uz', 'Welsh': 'cy', 'Xhosa': 'xh',
        'Zulu': 'zu', 'Esperanto': 'eo'
    };

    // Browser language code -> our display name (for auto-detect). Built from LANG_MAP,
    // plus a few aliases the browser may report.
    var CODE_TO_NAME = {};
    Object.keys(LANG_MAP).forEach(function (name) {
        var code = LANG_MAP[name].toLowerCase();
        if (!(code in CODE_TO_NAME)) { CODE_TO_NAME[code] = name; }
    });
    var ALIASES = {
        'zh': 'Mandarin Chinese', 'zh-cn': 'Mandarin Chinese', 'zh-hans': 'Mandarin Chinese',
        'zh-tw': 'Cantonese', 'zh-hant': 'Cantonese', 'yue': 'Cantonese',
        'he': 'Hebrew', 'pt-br': 'Portuguese', 'pt-pt': 'Portuguese', 'fil': 'Filipino',
        'nb': 'Norwegian', 'nn': 'Norwegian', 'in': 'Indonesian'
    };

    var STORAGE_KEY = 'locale.language';

    function nameToCode(name) { return LANG_MAP[name] || 'en'; }

    function detectName() {
        var nav = (navigator.language || navigator.userLanguage || 'en').toLowerCase();
        if (ALIASES[nav]) { return ALIASES[nav]; }
        if (CODE_TO_NAME[nav]) { return CODE_TO_NAME[nav]; }
        var primary = nav.split('-')[0];
        return ALIASES[primary] || CODE_TO_NAME[primary] || 'English';
    }

    function readStored() {
        try { return localStorage.getItem(STORAGE_KEY); } catch (e) { return null; }
    }
    function writeStored(name) {
        try { localStorage.setItem(STORAGE_KEY, name); } catch (e) {}
    }

    function setGoogTrans(value) {
        var host = location.hostname;
        document.cookie = 'googtrans=' + value + ';path=/';
        if (host.indexOf('.') > -1 && !/^\d+(\.\d+)+$/.test(host)) {
            document.cookie = 'googtrans=' + value + ';path=/;domain=' + host;
            document.cookie = 'googtrans=' + value + ';path=/;domain=.' + host;
        }
    }
    function clearGoogTrans() {
        var expire = ';expires=Thu, 01 Jan 1970 00:00:00 GMT;path=/';
        var host = location.hostname;
        document.cookie = 'googtrans=' + expire;
        if (host.indexOf('.') > -1 && !/^\d+(\.\d+)+$/.test(host)) {
            document.cookie = 'googtrans=' + expire.replace(';path=/', ';path=/;domain=' + host);
            document.cookie = 'googtrans=' + expire.replace(';path=/', ';path=/;domain=.' + host);
        }
    }

    // Drive the (hidden) widget select in place so we don't need a full reload.
    function triggerCombo(code, attempts) {
        var combo = document.querySelector('select.goog-te-combo');
        if (combo) {
            combo.value = code;
            combo.dispatchEvent(new Event('change'));
            return;
        }
        if (attempts > 0) {
            setTimeout(function () { triggerCombo(code, attempts - 1); }, 60);
        }
    }

    // Apply a language by its display name. Both paths set the cookie and reload:
    // the page then comes back already translated on load (fast + flicker-free),
    // which is far smoother than the in-place re-translate. English clears the
    // cookie so the reload shows the original source.
    function applyByName(name) {
        // Persist here too (not just via the Alpine locale store) so the choice
        // survives the reload no matter which layout/scope triggered the change -
        // the dashboard's locale store sits in a different Alpine scope than the
        // storefront's, and was not reliably saving before the reload.
        writeStored(name);
        var code = nameToCode(name);
        if (code === 'en') {
            clearGoogTrans();
        } else {
            setGoogTrans('/en/' + code);
        }
        window.location.reload();
    }
    // The locale modal stages a language and applies it on Save. It needs the
    // cookie written without the immediate reload applyByName() does, so the
    // one reload the Save already causes is what shows the translation.
    window.rshopSetTranslateCookie = function (name) {
        var code = nameToCode(name);
        if (code === "en") { clearGoogTrans(); } else { setGoogTrans("/en/" + code); }
    };

    window.__applyStoredLanguage = function () {
        var name = readStored();
        if (name && nameToCode(name) !== 'en') {
            triggerCombo(nameToCode(name), 80);
        }
    };

    // ── First-load setup (runs before Alpine reads localStorage) ──────────────
    var stored = readStored();
    if (!stored) {
        // No explicit choice yet: auto-detect from the browser and remember it so
        // the locale modal reflects the detected language.
        stored = detectName();
        writeStored(stored);
    }
    var initialCode = nameToCode(stored);
    if (initialCode !== 'en') {
        // Seed the cookie so the widget auto-translates as soon as it initialises.
        setGoogTrans('/en/' + initialCode);
    }

    // ── Translate widget bootstrap ────────────────────────────────────────────
    window.googleTranslateElementInit = function () {
        new google.translate.TranslateElement({
            pageLanguage: 'en',
            autoDisplay: false,
            layout: google.translate.TranslateElement.InlineLayout.SIMPLE
        }, 'google_translate_element');
        window.__applyStoredLanguage();
    };

    function loadWidget() {
        if (document.getElementById('translate-engine-script')) { return; }
        var s = document.createElement('script');
        s.id = 'translate-engine-script';
        s.src = 'https://translate.google.com/translate_a/element.js?cb=googleTranslateElementInit';
        s.async = true;
        document.head.appendChild(s);
    }
    loadWidget();

    // ── Manual switching from the locale modal ────────────────────────────────
    window.addEventListener('language-changed', function (e) {
        var name = (e && e.detail) ? e.detail : readStored();
        if (name) { applyByName(name); }
    });

    // ── Keep translation applied after SPA navigation ─────────────────────────
    window.addEventListener('livewire:navigated', function () {
        var name = readStored();
        if (name && nameToCode(name) !== 'en') {
            triggerCombo(nameToCode(name), 80);
        }
    });
})();
</script>

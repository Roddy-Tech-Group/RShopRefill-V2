{{--
    Country & language modal — custom Alpine dropdowns with search.
    State lives on the wrapper x-data: localeModalOpen, country, countryFlag, countryCode, language, currency, currencySymbol.
    countryCode comes from config/countries.php and lets the gift-cards filter map full country names to ISO codes.
--}}
@php
    $countryCodes = config('countries.codes', []);

    /*
     * Only show countries that currently have at least one active product in
     * the synced catalog. Cached 10 min so this modal (rendered in every page
     * layout) doesn't re-query on every request. The cache key is bumped any
     * time a catalog sync runs (see CatalogSyncService).
     */
    $availableCountryCodes = \Illuminate\Support\Facades\Cache::remember(
        'locale-modal:available-country-codes',
        now()->addMinutes(10),
        fn () => \App\Models\Product::query()
            ->where('is_active', true)
            ->whereNotNull('country_code')
            ->distinct()
            ->pluck('country_code')
            ->map(fn ($c) => strtoupper((string) $c))
            ->all()
    );
    $availableCountryCodes = array_flip($availableCountryCodes);

    $countries = [
        'Afghanistan' => '🇦🇫', 'Albania' => '🇦🇱', 'Algeria' => '🇩🇿', 'Andorra' => '🇦🇩', 'Angola' => '🇦🇴', 'Antigua and Barbuda' => '🇦🇬',
        'Argentina' => '🇦🇷', 'Armenia' => '🇦🇲', 'Australia' => '🇦🇺', 'Austria' => '🇦🇹', 'Azerbaijan' => '🇦🇿', 'Bahamas' => '🇧🇸',
        'Bahrain' => '🇧🇭', 'Bangladesh' => '🇧🇩', 'Barbados' => '🇧🇧', 'Belarus' => '🇧🇾', 'Belgium' => '🇧🇪', 'Belize' => '🇧🇿',
        'Benin' => '🇧🇯', 'Bhutan' => '🇧🇹', 'Bolivia' => '🇧🇴', 'Bosnia and Herzegovina' => '🇧🇦', 'Botswana' => '🇧🇼', 'Brazil' => '🇧🇷',
        'Brunei' => '🇧🇳', 'Bulgaria' => '🇧🇬', 'Burkina Faso' => '🇧🇫', 'Burundi' => '🇧🇮', 'Cambodia' => '🇰🇭', 'Cameroon' => '🇨🇲',
        'Canada' => '🇨🇦', 'Cape Verde' => '🇨🇻', 'Central African Republic' => '🇨🇫', 'Chad' => '🇹🇩', 'Chile' => '🇨🇱', 'China' => '🇨🇳',
        'Colombia' => '🇨🇴', 'Comoros' => '🇰🇲', 'Congo (Brazzaville)' => '🇨🇬', 'Congo (Kinshasa)' => '🇨🇩', 'Costa Rica' => '🇨🇷', 'Côte d\'Ivoire' => '🇨🇮',
        'Croatia' => '🇭🇷', 'Cuba' => '🇨🇺', 'Cyprus' => '🇨🇾', 'Czechia' => '🇨🇿', 'Denmark' => '🇩🇰', 'Djibouti' => '🇩🇯',
        'Dominica' => '🇩🇲', 'Dominican Republic' => '🇩🇴', 'Ecuador' => '🇪🇨', 'Egypt' => '🇪🇬', 'El Salvador' => '🇸🇻', 'Equatorial Guinea' => '🇬🇶',
        'Eritrea' => '🇪🇷', 'Estonia' => '🇪🇪', 'Eswatini' => '🇸🇿', 'Ethiopia' => '🇪🇹', 'Fiji' => '🇫🇯', 'Finland' => '🇫🇮',
        'France' => '🇫🇷', 'Gabon' => '🇬🇦', 'Gambia' => '🇬🇲', 'Georgia' => '🇬🇪', 'Germany' => '🇩🇪', 'Ghana' => '🇬🇭',
        'Greece' => '🇬🇷', 'Grenada' => '🇬🇩', 'Guatemala' => '🇬🇹', 'Guinea' => '🇬🇳', 'Guinea-Bissau' => '🇬🇼', 'Guyana' => '🇬🇾',
        'Haiti' => '🇭🇹', 'Honduras' => '🇭🇳', 'Hungary' => '🇭🇺', 'Iceland' => '🇮🇸', 'India' => '🇮🇳', 'Indonesia' => '🇮🇩',
        'Iran' => '🇮🇷', 'Iraq' => '🇮🇶', 'Ireland' => '🇮🇪', 'Israel' => '🇮🇱', 'Italy' => '🇮🇹', 'Jamaica' => '🇯🇲',
        'Japan' => '🇯🇵', 'Jordan' => '🇯🇴', 'Kazakhstan' => '🇰🇿', 'Kenya' => '🇰🇪', 'Kiribati' => '🇰🇮', 'Kuwait' => '🇰🇼',
        'Kyrgyzstan' => '🇰🇬', 'Laos' => '🇱🇦', 'Latvia' => '🇱🇻', 'Lebanon' => '🇱🇧', 'Lesotho' => '🇱🇸', 'Liberia' => '🇱🇷',
        'Libya' => '🇱🇾', 'Liechtenstein' => '🇱🇮', 'Lithuania' => '🇱🇹', 'Luxembourg' => '🇱🇺', 'Madagascar' => '🇲🇬', 'Malawi' => '🇲🇼',
        'Malaysia' => '🇲🇾', 'Maldives' => '🇲🇻', 'Mali' => '🇲🇱', 'Malta' => '🇲🇹', 'Marshall Islands' => '🇲🇭', 'Mauritania' => '🇲🇷',
        'Mauritius' => '🇲🇺', 'Mexico' => '🇲🇽', 'Micronesia' => '🇫🇲', 'Moldova' => '🇲🇩', 'Monaco' => '🇲🇨', 'Mongolia' => '🇲🇳',
        'Montenegro' => '🇲🇪', 'Morocco' => '🇲🇦', 'Mozambique' => '🇲🇿', 'Myanmar' => '🇲🇲', 'Namibia' => '🇳🇦', 'Nauru' => '🇳🇷',
        'Nepal' => '🇳🇵', 'Netherlands' => '🇳🇱', 'New Zealand' => '🇳🇿', 'Nicaragua' => '🇳🇮', 'Niger' => '🇳🇪', 'Nigeria' => '🇳🇬',
        'North Korea' => '🇰🇵', 'North Macedonia' => '🇲🇰', 'Norway' => '🇳🇴', 'Oman' => '🇴🇲', 'Pakistan' => '🇵🇰', 'Palau' => '🇵🇼',
        'Palestine' => '🇵🇸', 'Panama' => '🇵🇦', 'Papua New Guinea' => '🇵🇬', 'Paraguay' => '🇵🇾', 'Peru' => '🇵🇪', 'Philippines' => '🇵🇭',
        'Poland' => '🇵🇱', 'Portugal' => '🇵🇹', 'Qatar' => '🇶🇦', 'Romania' => '🇷🇴', 'Russia' => '🇷🇺', 'Rwanda' => '🇷🇼',
        'Saint Kitts and Nevis' => '🇰🇳', 'Saint Lucia' => '🇱🇨', 'Saint Vincent and the Grenadines' => '🇻🇨', 'Samoa' => '🇼🇸', 'San Marino' => '🇸🇲', 'São Tomé and Príncipe' => '🇸🇹',
        'Saudi Arabia' => '🇸🇦', 'Senegal' => '🇸🇳', 'Serbia' => '🇷🇸', 'Seychelles' => '🇸🇨', 'Sierra Leone' => '🇸🇱', 'Singapore' => '🇸🇬',
        'Slovakia' => '🇸🇰', 'Slovenia' => '🇸🇮', 'Solomon Islands' => '🇸🇧', 'Somalia' => '🇸🇴', 'South Africa' => '🇿🇦', 'South Korea' => '🇰🇷',
        'South Sudan' => '🇸🇸', 'Spain' => '🇪🇸', 'Sri Lanka' => '🇱🇰', 'Sudan' => '🇸🇩', 'Suriname' => '🇸🇷', 'Sweden' => '🇸🇪',
        'Switzerland' => '🇨🇭', 'Syria' => '🇸🇾', 'Taiwan' => '🇹🇼', 'Tajikistan' => '🇹🇯', 'Tanzania' => '🇹🇿', 'Thailand' => '🇹🇭',
        'Timor-Leste' => '🇹🇱', 'Togo' => '🇹🇬', 'Tonga' => '🇹🇴', 'Trinidad and Tobago' => '🇹🇹', 'Tunisia' => '🇹🇳', 'Turkey' => '🇹🇷',
        'Turkmenistan' => '🇹🇲', 'Tuvalu' => '🇹🇻', 'Uganda' => '🇺🇬', 'Ukraine' => '🇺🇦', 'United Arab Emirates' => '🇦🇪', 'United Kingdom' => '🇬🇧',
        'United States' => '🇺🇸', 'Uruguay' => '🇺🇾', 'Uzbekistan' => '🇺🇿', 'Vanuatu' => '🇻🇺', 'Vatican City' => '🇻🇦', 'Venezuela' => '🇻🇪',
        'Vietnam' => '🇻🇳', 'Yemen' => '🇾🇪', 'Zambia' => '🇿🇲', 'Zimbabwe' => '🇿🇼',
    ];

    // Drop any country that has no stock so the dropdown matches the catalog.
    // If the catalog query ever returns empty (fresh install, sync hasn't run yet)
    // we keep the full list so the modal is never blank.
    if (! empty($availableCountryCodes)) {
        $countries = array_filter(
            $countries,
            fn ($name) => isset($availableCountryCodes[strtoupper((string) ($countryCodes[$name] ?? ''))]),
            ARRAY_FILTER_USE_KEY
        );
    }

    // The offered languages, resolved from config/localization.php through
    // App\Support\Languages. NOT a list of its own: a second copy here is how
    // the picker and the old translate widget drifted apart, leaving a visitor
    // auto-detected into one of that widget's 88 with no row to un-pick it.
    //
    // These are DISPLAY names; the locale each one posts is the config's
    // business, not this view's. Every one of them has its own lang directory,
    // so picking a language is a page render, not a repaint.
    $languages = \App\Support\Languages::names();
    $languageLocales = \App\Support\Languages::localeMap();
    $currentLanguage = \App\Support\Languages::nameFor(app()->getLocale())
        ?? \App\Support\Languages::defaultName();
    $currencies = [
        ['code' => 'USD', 'symbol' => '$',   'name' => 'United States Dollar'],
        ['code' => 'EUR', 'symbol' => '€',   'name' => 'Euro'],
        ['code' => 'GBP', 'symbol' => '£',   'name' => 'British Pound'],
        ['code' => 'NGN', 'symbol' => '₦',   'name' => 'Nigerian Naira'],
        ['code' => 'XAF', 'symbol' => 'XAF ','name' => 'Central African CFA Franc'],
        ['code' => 'ZAR', 'symbol' => 'R',   'name' => 'South African Rand'],
        ['code' => 'KES', 'symbol' => 'KSh', 'name' => 'Kenyan Shilling'],
        ['code' => 'GHS', 'symbol' => '₵',   'name' => 'Ghanaian Cedi'],
        ['code' => 'EGP', 'symbol' => 'E£',  'name' => 'Egyptian Pound'],
        ['code' => 'MAD', 'symbol' => 'DH',  'name' => 'Moroccan Dirham'],
        ['code' => 'CAD', 'symbol' => 'CA$', 'name' => 'Canadian Dollar'],
        ['code' => 'AUD', 'symbol' => 'A$',  'name' => 'Australian Dollar'],
        ['code' => 'JPY', 'symbol' => '¥',   'name' => 'Japanese Yen'],
        ['code' => 'CNY', 'symbol' => '¥',   'name' => 'Chinese Yuan'],
        ['code' => 'INR', 'symbol' => '₹',   'name' => 'Indian Rupee'],
        ['code' => 'BRL', 'symbol' => 'R$',  'name' => 'Brazilian Real'],
        ['code' => 'AED', 'symbol' => 'AED', 'name' => 'UAE Dirham'],
        ['code' => 'SAR', 'symbol' => 'SAR', 'name' => 'Saudi Riyal'],
        ['code' => 'TRY', 'symbol' => '₺',   'name' => 'Turkish Lira'],
        ['code' => 'CHF', 'symbol' => 'Fr',  'name' => 'Swiss Franc'],
    ];

    // Picking a country drives the currency — there is no separate currency picker.
    // Country ISO -> currency ISO, and currency ISO -> display symbol.
    $countryCurrencyMap = config('country_currency.map', []);
    $currencySymbols = [];
    foreach (array_unique(array_values($countryCurrencyMap)) as $cur) {
        $currencySymbols[$cur] = \App\Models\Product::currencySymbol($cur);
    }
@endphp

{{-- Backdrop - sits BELOW the nav (z-40 < nav z-50) so the nav's glassmorphism stays visible over it --}}
<div
    x-show="localeModalOpen"
    x-transition:enter="transition-opacity ease-out duration-500"
    x-transition:enter-start="opacity-0"
    x-transition:enter-end="opacity-100"
    x-transition:leave="transition-opacity ease-in duration-500"
    x-transition:leave-start="opacity-100"
    x-transition:leave-end="opacity-0"
    @click="localeModalOpen = false"
    style="display:none;"
    class="fixed inset-0 z-40 bg-zinc-900/40"
></div>

{{-- Preferences sheet.

     One card, four panes: a root list of the three settings, and a chooser for
     each. Panes rather than three inline dropdowns - a country list is ~200 rows
     and an overlay that long inside a centred modal has nowhere to go, so it
     used to scroll inside its own box while the card stayed put. A pane owns the
     whole card, which is the pattern the reference uses.

     `pane` lives here, not on the shared locale scope: it is view state, and
     resetting it belongs to closing the modal, not to switching country.

     A chooser slides in from the right. The card is overflow-hidden, so the
     off-screen start is clipped rather than widening the sheet mid-transition.

     Height: a chooser's list shows six rows and scrolls the rest. Six because
     the sheet then reads as a list you scan rather than a page you scroll. The
     cap is 18.5rem: six 3rem rows (py-3 either side of a 24px mark) plus the
     card's own 0.5rem of padding. Written as a single value rather than a
     calc() because Tailwind does not emit a rule for an arbitrary value
     containing arithmetic - the class lands in the markup and no CSS behind
     it, which caps nothing. Rows are 3rem in BOTH lists: the currency chip
     was 28px against the country flag's 24px, which made "six rows" two
     different heights.

     The list is not flex-1: it hugs its content, so a search narrowed to two
     matches leaves a two-row card rather than a six-row box with a hole in it.
     min-h-0 stays, so a short viewport can still shrink it past the cap.

     Page scroll is already frozen while this is open - layouts/app/header holds
     the reference-counted rshopScrollLock for localeModalOpen. The only scroll
     inside is a chooser's own list, which is why the card is height-capped. --}}
<div
    x-data="{
        pane: 'root',

        /*
         * Staged selection. Every pane writes here, never to the live locale
         * scope, and the root list previews these - so what you see before
         * pressing Save is exactly what Save will apply, and closing the sheet
         * throws the lot away.
         */
        draftCountry: '',
        draftCountryFlag: '',
        draftCountryCode: '',
        draftCurrency: '',
        draftCurrencySymbol: '',
        /*
         * The staged LANGUAGE, as a display name.
         *
         * Applying it takes both halves of Save: the form posts the SERVER
         * locale this language needs (English and French have real lang files;
         * every other offered language is painted over an English page), and
         * the translate engine's cookie is set alongside it. The live choice
         * is mirrored in localStorage under 'locale.language' because the
         * engine reads it before Alpine exists.
         */
        draftLanguage: @js($currentLanguage),

        /** The live language, for the row label when the sheet is closed. */
        language: @js($currentLanguage),

        /** Display name -> the locale that renders it. */
        languageLocales: @js($languageLocales),

        /** Only these can be staged, stored or posted. */
        offeredLanguages: @js($languages),

        localeFor(name) {
            return this.languageLocales[name] ?? @js(config('localization.default', 'en'));
        },

        /*
         * A stored language that is no longer offered falls back rather than
         * being staged: it has no row in the list, so honouring it would show
         * a sheet where nothing is checked and Save could not change anything.
         */
        readLanguage() {
            try {
                const stored = localStorage.getItem('locale.language');
                return this.offeredLanguages.includes(stored) ? stored : @js($currentLanguage);
            } catch (e) {
                return @js($currentLanguage);
            }
        },

        /*
         * Seed the drafts from what is actually in force. Runs on close as
         * well as on first render, so an abandoned edit never survives into
         * the next open.
         */
        syncDrafts() {
            this.draftCountry        = this.country;
            this.draftCountryFlag    = this.countryFlag;
            this.draftCountryCode    = this.countryCode;
            this.draftCurrency       = this.currency;
            this.draftCurrencySymbol = this.currencySymbol;
            this.language            = this.readLanguage();
            this.draftLanguage       = this.language;
        },

        get dirty() {
            return this.draftCountryCode !== this.countryCode
                || this.draftCurrency    !== this.currency
                || this.draftLanguage    !== this.language;
        },

        /*
         * Save. The form posts the language to locale.update; country and
         * currency ride along beside it.
         *
         * They are written straight to localStorage rather than assigned to
         * the shared scope, because those assignments fire storefrontLocale's
         * watchers, which schedule a Livewire navigation - and that would race
         * the POST this submit is about to make. The document URL is rewritten
         * for the same reason: the controller redirects back() to the Referer,
         * which is this URL, and ResolveRegion reads ?country= off it while
         * ResolveDisplayCurrency reads ?display_currency= - display_currency,
         * not currency: ?currency= is the gift-cards listing's own catalogue
         * FILTER, and the modal's choice converts prices, it never hides
         * products. So one
         * submit applies all three with a single page load.
         */
        commit(event) {
            if (! this.dirty) {
                event.preventDefault();
                this.localeModalOpen = false;
                return;
            }

            // Language is server state: the hidden field above carries it
            // and the POST's redirect renders the next page translated. The
            // localStorage copy is only what the nav pill paints from before
            // Alpine has a server value, so it is mirrored, never authoritative.
            try {
                localStorage.setItem('locale.language', this.draftLanguage);
            } catch (e) {}

            try {
                localStorage.setItem('locale.country',        this.draftCountry);
                localStorage.setItem('locale.countryFlag',    this.draftCountryFlag);
                localStorage.setItem('locale.countryCode',    this.draftCountryCode);
                localStorage.setItem('locale.currency',       this.draftCurrency);
                localStorage.setItem('locale.currencySymbol', this.draftCurrencySymbol);
            } catch (e) {}

            try {
                const url = new URL(window.location.href);
                if (this.draftCountryCode) url.searchParams.set('country', this.draftCountryCode);
                if (this.draftCurrency) url.searchParams.set('display_currency', this.draftCurrency);
                window.history.replaceState({}, '', url.toString());
            } catch (e) {}
        },

        get paneTitle() {
            return { root: @js(__('common.preferences')), country: @js(__('common.country')), language: @js(__('common.language')), currency: @js(__('common.payment_currency')) }[this.pane];
        },

        /*
         * Picking a country sets all five locale fields, currency included -
         * country is what drives it. Kept as one method rather than five
         * assignments inline on the button, so the currency can never be left
         * behind when the country moves.
         *
         * Draft only - nothing is in force until Save. These keys live on THIS
         * x-data, so Alpine's merged scope writes them here rather than
         * assigning through to the shared storefrontLocale() scope, whose
         * watchers would persist and navigate immediately.
         */
        pickCountry(name, flag, codes, currencyByCode, symbolByCurrency) {
            const code = (codes[name] || '').toUpperCase();

            this.draftCountry = name;
            this.draftCountryFlag = flag;
            this.draftCountryCode = code;
            this.draftCurrency = currencyByCode[code] || 'USD';
            this.draftCurrencySymbol = symbolByCurrency[this.draftCurrency] || this.draftCurrency;
            this.pane = 'root';
        },
    }"
    {{-- Closing discards the draft: reopening always starts from what is
         actually in force, never from a half-finished edit. --}}
    x-effect="if (! localeModalOpen) { pane = 'root'; syncDrafts(); }"
    x-show="localeModalOpen"
    x-transition:enter="transition-opacity ease-out duration-300"
    x-transition:enter-start="opacity-0"
    x-transition:enter-end="opacity-100"
    x-transition:leave="transition-opacity ease-in duration-200"
    x-transition:leave-start="opacity-100"
    x-transition:leave-end="opacity-0"
    @click.self="localeModalOpen = false"
    {{-- Escape steps back out of a chooser first, and only closes the
         sheet from the root pane. --}}
    @keydown.escape.window="pane === 'root' ? localeModalOpen = false : pane = 'root'"
    style="display:none;"
    class="fixed inset-0 z-[60] flex items-center justify-center p-4"
    role="dialog"
    aria-modal="true"
    aria-labelledby="locale-modal-title"
>
    <div
        x-show="localeModalOpen"
        x-transition:enter="transition duration-[600ms] ease-[cubic-bezier(0.16,1,0.3,1)]"
        x-transition:enter-start="opacity-0 translate-y-24 scale-[0.97]"
        x-transition:enter-end="opacity-100 translate-y-0 scale-100"
        x-transition:leave="transition duration-200 ease-in"
        x-transition:leave-start="opacity-100 translate-y-0 scale-100"
        x-transition:leave-end="opacity-0 translate-y-8 scale-[0.98]"
        class="pointer-events-auto relative flex max-h-[90vh] w-full max-w-lg flex-col overflow-hidden rounded-[20px] bg-[#f4f6f5] p-6 shadow-2xl shadow-zinc-900/20 dark:bg-[#0c1a36] dark:ring-1 dark:ring-white/10"
    >
        {{-- Header. The back arrow appears only in a chooser, and the title
             names whatever is being chosen. --}}
        <div class="mb-5 flex items-center gap-3">
            <button
                type="button"
                x-show="pane !== 'root'"
                @click="pane = 'root'"
                class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full text-zinc-900 transition-colors hover:bg-[#dee3e1] focus:outline-none focus-visible:ring-2 focus-visible:ring-blue-500/40 dark:text-white dark:hover:bg-white/10"
                aria-label="{{ __('common.back') }}"
            >
                <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M15 19l-7-7 7-7" />
                </svg>
            </button>

            <h2 id="locale-modal-title" class="flex-1 text-center text-lg font-bold text-zinc-900 dark:text-white" x-text="paneTitle">{{ __('common.preferences') }}</h2>

            <button
                type="button"
                @click="localeModalOpen = false"
                class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full text-zinc-900 transition-colors hover:bg-[#dee3e1] focus:outline-none focus-visible:ring-2 focus-visible:ring-blue-500/40 dark:text-white dark:hover:bg-white/10"
                aria-label="{{ __('common.close') }}"
            >
                <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
                </svg>
            </button>
        </div>

        {{-- One form around every pane, with Save as its submit button.

             Save posts the LANGUAGE, which is server state - so that half
             still works the way it did before this sheet existed: a real POST
             with a CSRF token, no fetch, no JSON. Country and currency are
             client state and are written by commit() just before the submit
             leaves. One button, one page load, three preferences - which are
             still three independent values: picking a language changes no
             country or currency, and vice versa. --}}
        <form
            method="POST"
            action="{{ route('locale.update') }}"
            @submit="commit($event)"
            {{-- One cell, every pane in it: an outgoing pane overlaps the
                 incoming one instead of stacking under it, so the card cannot
                 lunge to the height of both mid-transition. --}}
            class="grid min-h-0 flex-1 grid-cols-1 grid-rows-1"
        >
            @csrf
            {{-- The locale the staged language renders in. Every offered
                 language has its own lang/{code} directory, so this is the
                 whole of a language change: the POST installs the locale and
                 its redirect renders the next page already translated.

                 LocaleController validates this against localization.supported,
                 so only a locale the app genuinely ships can ever land. --}}
            <input type="hidden" name="locale" :value="localeFor(draftLanguage)" value="{{ $languageLocales[$currentLanguage] ?? config('localization.default', 'en') }}">

        {{-- Root: the three settings, previewing the DRAFT rather than what is
             in force - the sheet has to show what Save is about to do. --}}
        <div x-show="pane === 'root'" class="col-start-1 row-start-1 flex flex-col gap-4">

            <div>
                <span class="mb-1.5 block text-[13px] font-medium text-zinc-600 dark:text-zinc-400">{{ __('common.country') }}</span>
                <button type="button" @click="pane = 'country'" class="flex w-full items-center gap-3 rounded-[12px] bg-[#ffffff] px-4 py-3.5 text-base font-medium text-zinc-900 ring-1 ring-[#dee3e1] transition-colors hover:ring-[#c9d0cd] focus:outline-none focus-visible:ring-2 focus-visible:ring-blue-500/40 dark:bg-white/5 dark:text-white dark:ring-white/10">
                    <img :src="'https://flagcdn.com/w40/' + (draftCountryCode || 'us').toLowerCase() + '.png'" alt="" class="h-6 w-6 shrink-0 rounded-full object-cover ring-1 ring-black/10 dark:ring-white/20">
                    <span class="flex-1 text-left" x-text="draftCountry">United States</span>
                    <svg class="h-4 w-4 shrink-0 text-zinc-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7" /></svg>
                </button>
            </div>

            {{-- Language.

                 Shares this sheet with country and currency, but is NOT tied to
                 them: it posts to locale.update on its own, and picking a
                 language changes no country or currency state. Sharing a panel
                 is presentation; the preferences stay independent. --}}
            <div>
                <span class="mb-1.5 block text-[13px] font-medium text-zinc-600 dark:text-zinc-400">{{ __('common.language') }}</span>
                <button type="button" @click="pane = 'language'" class="flex w-full items-center gap-3 rounded-[12px] bg-[#ffffff] px-4 py-3.5 text-base font-medium text-zinc-900 ring-1 ring-[#dee3e1] transition-colors hover:ring-[#c9d0cd] focus:outline-none focus-visible:ring-2 focus-visible:ring-blue-500/40 dark:bg-white/5 dark:text-white dark:ring-white/10">
                    <span class="flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-[#f4f6f5] text-[11px] font-bold text-zinc-900 dark:bg-white/10 dark:text-white" x-text="draftLanguage.slice(0, 2).toUpperCase()">EN</span>
                    <span class="flex-1 truncate text-left" x-text="draftLanguage">English</span>
                    <svg class="h-4 w-4 shrink-0 text-zinc-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7" /></svg>
                </button>
            </div>

            <div>
                <span class="mb-1.5 block text-[13px] font-medium text-zinc-600 dark:text-zinc-400">{{ __('common.payment_currency') }}</span>
                <button type="button" @click="pane = 'currency'" class="flex w-full items-center gap-3 rounded-[12px] bg-[#ffffff] px-4 py-3.5 text-base font-medium text-zinc-900 ring-1 ring-[#dee3e1] transition-colors hover:ring-[#c9d0cd] focus:outline-none focus-visible:ring-2 focus-visible:ring-blue-500/40 dark:bg-white/5 dark:text-white dark:ring-white/10">
                    <span class="flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-[#dee3e1] text-[13px] font-bold text-zinc-900 dark:bg-white/10 dark:text-white" x-text="draftCurrencySymbol">$</span>
                    <span class="flex-1 text-left" x-text="draftCurrency">USD</span>
                    <svg class="h-4 w-4 shrink-0 text-zinc-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7" /></svg>
                </button>
            </div>

            {{-- Nothing is in force until this is pressed. With no draft
                 changes commit() cancels the submit and just closes, so Save
                 never costs a page load it did not need to. --}}
            <button
                type="submit"
                class="mt-2 w-full rounded-full bg-zinc-900 px-5 py-3.5 text-sm font-bold text-white transition-colors hover:bg-zinc-800 focus:outline-none focus-visible:ring-2 focus-visible:ring-blue-500/40 dark:bg-white dark:text-zinc-900 dark:hover:bg-zinc-200"
            >
                {{ __('common.save') }}
            </button>
        </div>

        {{-- Country chooser.

             Flag images, not emoji: emoji flags render as two-letter blocks on
             Windows, which is most of this catalogue looking broken.

             Selection is a checkmark, not a filled row - on a ~200 row list you
             are scrolling, a filled row reads as hover. --}}
        <div
            x-show="pane === 'country'"
            x-transition:enter="transition duration-300 ease-[cubic-bezier(0.16,1,0.3,1)]"
            x-transition:enter-start="opacity-0 translate-x-full"
            x-transition:enter-end="opacity-100 translate-x-0"
            x-transition:leave="transition duration-150 ease-in"
            x-transition:leave-start="opacity-100 translate-x-0"
            x-transition:leave-end="opacity-0 translate-x-full"
            x-data="{ search: '', options: @js($countries), codes: @js($countryCodes), currencyByCode: @js($countryCurrencyMap), symbolByCurrency: @js($currencySymbols) }"
            class="col-start-1 row-start-1 flex min-h-0 flex-col"
        >
            <div class="relative mb-3">
                <span class="pointer-events-none absolute left-4 top-1/2 -translate-y-1/2 text-zinc-500">
                    <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" />
                    </svg>
                </span>
                {{-- Enter would otherwise submit the form this pane sits in, saving a
                     half-made choice from the search box. --}}
                <input x-model="search" @keydown.enter.prevent type="text" placeholder="{{ __('common.search') }}" aria-label="{{ __('common.country') }}" class="w-full rounded-[12px] bg-[#ffffff] py-3 pl-11 pr-4 text-base text-zinc-900 outline-none ring-1 ring-[#dee3e1] placeholder:text-zinc-500 focus:ring-2 focus:ring-blue-500/40 dark:bg-white/5 dark:text-white dark:ring-white/10">
            </div>

            <div class="min-h-0 max-h-[18.5rem] overflow-y-auto rounded-[12px] bg-[#ffffff] p-1 ring-1 ring-[#dee3e1] dark:bg-white/5 dark:ring-white/10">
                <template x-for="entry in Object.entries(options).filter((e) => e[0].toLowerCase().includes(search.toLowerCase()))" :key="entry[0]">
                    <button
                        type="button"
                        @click="pickCountry(entry[0], entry[1], codes, currencyByCode, symbolByCurrency); search = ''"
                        class="flex w-full items-center gap-3 rounded-[10px] px-3 py-3 text-left text-base text-zinc-900 transition-colors hover:bg-[#f4f6f5] dark:text-white dark:hover:bg-white/10"
                    >
                        <img :src="'https://flagcdn.com/w40/' + (codes[entry[0]] || '').toLowerCase() + '.png'" alt="" class="h-6 w-6 shrink-0 rounded-full object-cover ring-1 ring-black/10 dark:ring-white/20">
                        <span class="flex-1 truncate" :class="draftCountry === entry[0] ? 'font-semibold' : ''" x-text="entry[0]"></span>
                        <template x-if="draftCountry === entry[0]">
                            <svg class="h-5 w-5 shrink-0 text-zinc-900 dark:text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5" aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5" />
                            </svg>
                        </template>
                    </button>
                </template>
            </div>
        </div>

        {{-- Language chooser.

             x-nav.language-switcher stages draftLanguage; the form's Save button
             posts it, and it carries its own search field.

             Sharing this sheet with country and currency is presentation only.
             Picking a language changes no country or currency value. --}}
        <div
            x-show="pane === 'language'"
            x-transition:enter="transition duration-300 ease-[cubic-bezier(0.16,1,0.3,1)]"
            x-transition:enter-start="opacity-0 translate-x-full"
            x-transition:enter-end="opacity-100 translate-x-0"
            x-transition:leave="transition duration-150 ease-in"
            x-transition:leave-start="opacity-100 translate-x-0"
            x-transition:leave-end="opacity-0 translate-x-full"
            class="col-start-1 row-start-1 flex min-h-0 flex-col"
        >
            {{-- No wrapper here on purpose. The switcher already renders its
                 own search field and its own bordered, scrolling panel, so
                 boxing it again drew a second identical ring around the list
                 and nested two 18.5rem scroll areas inside each other. The
                 country pane above builds its search and list as siblings for
                 the same reason. --}}
            <x-nav.language-switcher :languages="$languages" />
        </div>

        {{-- Payment currency chooser.

             Country still drives the currency by default - pickCountry() sets
             it. This pane only overrides that default, so the two never disagree
             unless the visitor says so. --}}
        <div
            x-show="pane === 'currency'"
            x-transition:enter="transition duration-300 ease-[cubic-bezier(0.16,1,0.3,1)]"
            x-transition:enter-start="opacity-0 translate-x-full"
            x-transition:enter-end="opacity-100 translate-x-0"
            x-transition:leave="transition duration-150 ease-in"
            x-transition:leave-start="opacity-100 translate-x-0"
            x-transition:leave-end="opacity-0 translate-x-full"
            x-data="{ search: '', options: @js($currencies) }"
            class="col-start-1 row-start-1 flex min-h-0 flex-col"
        >
            <div class="relative mb-3">
                <span class="pointer-events-none absolute left-4 top-1/2 -translate-y-1/2 text-zinc-500">
                    <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" />
                    </svg>
                </span>
                {{-- Enter would otherwise submit the form this pane sits in, saving a
                     half-made choice from the search box. --}}
                <input x-model="search" @keydown.enter.prevent type="text" placeholder="{{ __('common.search') }}" aria-label="{{ __('common.payment_currency') }}" class="w-full rounded-[12px] bg-[#ffffff] py-3 pl-11 pr-4 text-base text-zinc-900 outline-none ring-1 ring-[#dee3e1] placeholder:text-zinc-500 focus:ring-2 focus:ring-blue-500/40 dark:bg-white/5 dark:text-white dark:ring-white/10">
            </div>

            <div class="min-h-0 max-h-[18.5rem] overflow-y-auto rounded-[12px] bg-[#ffffff] p-1 ring-1 ring-[#dee3e1] dark:bg-white/5 dark:ring-white/10">
                <template x-for="c in options.filter((c) => (c.code + ' ' + c.name).toLowerCase().includes(search.toLowerCase()))" :key="c.code">
                    <button
                        type="button"
                        @click="draftCurrency = c.code; draftCurrencySymbol = c.symbol; pane = 'root'; search = ''"
                        class="flex w-full items-center gap-3 rounded-[10px] px-3 py-3 text-left text-base text-zinc-900 transition-colors hover:bg-[#f4f6f5] dark:text-white dark:hover:bg-white/10"
                    >
                        <span class="flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-[#f4f6f5] text-[11px] font-bold dark:bg-white/10" x-text="c.symbol"></span>
                        <span class="flex-1 truncate" :class="draftCurrency === c.code ? 'font-semibold' : ''"><span x-text="c.code"></span> - <span x-text="c.name"></span></span>
                        <template x-if="draftCurrency === c.code">
                            <svg class="h-5 w-5 shrink-0 text-zinc-900 dark:text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5" aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5" />
                            </svg>
                        </template>
                    </button>
                </template>
            </div>
        </div>
        </form>
    </div>
</div>

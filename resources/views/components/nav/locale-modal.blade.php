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

    $languages = [
        'English', 'Mandarin Chinese', 'Hindi', 'Spanish', 'French', 'Standard Arabic', 'Bengali', 'Russian', 'Portuguese', 'Indonesian',
        'Urdu', 'German', 'Japanese', 'Swahili', 'Marathi', 'Telugu', 'Turkish', 'Tamil', 'Cantonese', 'Vietnamese',
        'Korean', 'Italian', 'Hausa', 'Thai', 'Gujarati', 'Persian', 'Polish', 'Pashto', 'Kannada', 'Malayalam',
        'Sundanese', 'Hebrew', 'Burmese', 'Amharic', 'Oromo', 'Yoruba', 'Igbo', 'Ukrainian', 'Dutch', 'Romanian',
        'Filipino', 'Greek', 'Czech', 'Swedish', 'Hungarian', 'Serbian', 'Croatian', 'Bulgarian', 'Danish', 'Finnish',
        'Norwegian', 'Slovak', 'Afrikaans', 'Albanian', 'Armenian', 'Azerbaijani', 'Basque', 'Belarusian', 'Bosnian', 'Catalan',
        'Estonian', 'Galician', 'Georgian', 'Hawaiian', 'Icelandic', 'Irish', 'Kazakh', 'Khmer', 'Lao', 'Latvian',
        'Lithuanian', 'Macedonian', 'Malay', 'Maltese', 'Maori', 'Mongolian', 'Nepali', 'Punjabi', 'Sinhala', 'Slovenian',
        'Somali', 'Tajik', 'Turkmen', 'Uzbek', 'Welsh', 'Xhosa', 'Zulu', 'Esperanto',
    ];
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

{{-- Backdrop — sits BELOW the nav (z-40 < nav z-50) so the nav's glassmorphism stays visible over it --}}
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

{{-- Card container — sits ABOVE the nav (z-[60] > nav z-50) so the modal floats on top.
     The outer wrapper handles visibility; the inner card runs the slide-up sheet animation. --}}
<div
    x-show="localeModalOpen"
    x-transition:enter="transition-opacity ease-out duration-300"
    x-transition:enter-start="opacity-0"
    x-transition:enter-end="opacity-100"
    x-transition:leave="transition-opacity ease-in duration-200"
    x-transition:leave-start="opacity-100"
    x-transition:leave-end="opacity-0"
    @click.self="localeModalOpen = false"
    style="display:none;"
    class="fixed inset-0 z-[60] flex items-center justify-center p-4"
    role="dialog"
    aria-modal="true"
    aria-labelledby="locale-modal-title"
>
    {{-- Card — app-style slide-up sheet entrance, mirroring the auth pages. --}}
    <div
        x-show="localeModalOpen"
        x-transition:enter="transition duration-[600ms] ease-[cubic-bezier(0.16,1,0.3,1)]"
        x-transition:enter-start="opacity-0 translate-y-24 scale-[0.97]"
        x-transition:enter-end="opacity-100 translate-y-0 scale-100"
        x-transition:leave="transition duration-200 ease-in"
        x-transition:leave-start="opacity-100 translate-y-0 scale-100"
        x-transition:leave-end="opacity-0 translate-y-8 scale-[0.98]"
        class="pointer-events-auto relative w-full max-w-2xl rounded-[12px] bg-[#eff6ff] ring-1 ring-zinc-200 shadow-2xl shadow-zinc-900/25 px-[15px] pt-[30px] pb-[30px] dark:ring-[#24364f]"
    >
        {{-- Close button (positioned outside the card's top-right corner) --}}
        <x-close-button @click="localeModalOpen = false" class="absolute -top-3 -right-3 z-10 shadow-lg shadow-zinc-900/20" />

        {{-- Header --}}
        <h2 id="locale-modal-title" class="mb-5 text-lg font-bold text-zinc-900 dark:text-white">Country and language</h2>

        {{-- Body. The modal stays short (two pickers + Save); each picker's
             dropdown is an absolute overlay, so opening one never makes the
             modal taller - the ONLY thing that scrolls is the option list
             inside the open dropdown, not the whole modal. --}}
        <div class="flex flex-col gap-6">

            {{-- Country picker --}}
            <div
                x-data="{ open: false, search: '', options: @js($countries), codes: @js($countryCodes), currencyByCode: @js($countryCurrencyMap), symbolByCurrency: @js($currencySymbols) }"
                @click.outside="open = false"
                class="relative"
            >
                <label class="mb-1.5 block text-[13px] font-medium text-zinc-600">Country</label>

                <button
                    type="button"
                    @click="open = !open; if (open && window.matchMedia('(hover: hover)').matches) $nextTick(() => $refs.search.focus())"
                    :aria-expanded="open.toString()"
                    aria-haspopup="listbox"
                    :class="open ? 'border-blue-500 ring-2 ring-blue-500/15' : 'border-zinc-300 hover:border-zinc-400'"
                    class="flex w-full items-center gap-2 rounded-[12px] border bg-[#eff6ff] px-3 py-2.5 text-base font-medium text-zinc-900 outline-none transition-colors dark:text-white"
                >
                    <img :src="'https://flagcdn.com/w40/' + (countryCode || 'us').toLowerCase() + '.png'" alt="" class="h-3.5 w-5 shrink-0 rounded-[2px] object-cover ring-1 ring-zinc-200">
                    <span class="flex-1 text-left" x-text="country">United States</span>
                    <svg class="h-4 w-4 text-zinc-600 transition-transform duration-150" :class="{ 'rotate-180': open }" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7" />
                    </svg>
                </button>

                <div
                    x-show="open"
                    x-transition:enter="transition ease-out duration-150"
                    x-transition:enter-start="opacity-0 -translate-y-1"
                    x-transition:enter-end="opacity-100 translate-y-0"
                    x-transition:leave="transition ease-in duration-100"
                    x-transition:leave-start="opacity-100 translate-y-0"
                    x-transition:leave-end="opacity-0 -translate-y-1"
                    style="display:none;"
                    class="absolute left-0 right-0 top-full z-20 mt-2 overflow-hidden rounded-[12px] border border-zinc-200 bg-[#eff6ff] shadow-xl shadow-zinc-900/15 dark:border-zinc-700"
                    role="listbox"
                >
                    {{-- Search --}}
                    <div class="border-b border-zinc-100 p-2">
                        <div class="relative">
                            <svg class="pointer-events-none absolute left-2.5 top-1/2 h-4 w-4 -translate-y-1/2 text-zinc-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" />
                            </svg>
                            <input
                                x-ref="search"
                                x-model="search"
                                type="text"
                                placeholder="Search countries"
                                aria-label="Search countries"
                                class="w-full rounded-[15px] border border-zinc-200 bg-zinc-50 py-2 pl-8 pr-3 text-base text-zinc-800 placeholder:text-zinc-600 outline-none transition-colors focus:border-blue-500 focus:bg-white focus:ring-2 focus:ring-blue-500/15"
                            />
                        </div>
                    </div>

                    {{-- Options - capped at ~8 rows so the dropdown never
                         spills past the modal; the list itself scrolls. --}}
                    <div class="max-h-80 overflow-y-auto overscroll-contain p-1">
                        <template x-for="[name, flag] in Object.entries(options).filter(([n]) => n.toLowerCase().includes(search.toLowerCase()))" :key="name">
                            <button
                                type="button"
                                role="option"
                                :aria-selected="country === name ? 'true' : 'false'"
                                @click="country = name; countryFlag = flag; countryCode = codes[name] || ''; currency = (currencyByCode[(codes[name] || '').toUpperCase()] || 'USD'); currencySymbol = (symbolByCurrency[currency] || currency); open = false; search = ''"
                                :class="country === name ? 'bg-blue-50 text-blue-700' : 'text-zinc-700 hover:bg-zinc-50'"
                                class="flex w-full items-center gap-2.5 rounded-[12px] px-3 py-2 text-left text-base font-medium transition-colors"
                            >
                                <img :src="'https://flagcdn.com/w40/' + (codes[name] || 'us').toLowerCase() + '.png'" alt="" loading="lazy" decoding="async" class="h-3.5 w-5 shrink-0 rounded-[2px] object-cover ring-1 ring-zinc-200">
                                <span class="flex-1" x-text="name"></span>
                                <svg x-show="country === name" class="h-4 w-4 shrink-0 text-blue-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5" aria-hidden="true">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5" />
                                </svg>
                            </button>
                        </template>
                        <div
                            x-show="Object.entries(options).filter(([n]) => n.toLowerCase().includes(search.toLowerCase())).length === 0"
                            class="px-3 py-6 text-center text-base text-zinc-600"
                        >
                            No matches
                        </div>
                    </div>
                </div>
            </div>

            {{-- Language picker --}}
            <div
                x-data="{ open: false, search: '', options: @js($languages) }"
                @click.outside="open = false"
                class="relative"
            >
                <label class="mb-1.5 flex items-center gap-1.5 text-[13px] font-medium text-zinc-600">
                    <img src="{{ asset('assets/' . rawurlencode('global svg.svg')) }}" alt="" class="h-3.5 w-3.5 opacity-70" />
                    Language
                </label>

                <button
                    type="button"
                    @click="open = !open; if (open && window.matchMedia('(hover: hover)').matches) $nextTick(() => $refs.search.focus())"
                    :aria-expanded="open.toString()"
                    aria-haspopup="listbox"
                    :class="open ? 'border-blue-500 ring-2 ring-blue-500/15' : 'border-zinc-300 hover:border-zinc-400'"
                    class="flex w-full items-center gap-2 rounded-[12px] border bg-[#eff6ff] px-3 py-2.5 text-base font-medium text-zinc-900 outline-none transition-colors dark:text-white"
                >
                    <span class="flex-1 text-left" x-text="language">English</span>
                    <svg class="h-4 w-4 text-zinc-600 transition-transform duration-150" :class="{ 'rotate-180': open }" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7" />
                    </svg>
                </button>

                <div
                    x-show="open"
                    x-transition:enter="transition ease-out duration-150"
                    x-transition:enter-start="opacity-0 -translate-y-1"
                    x-transition:enter-end="opacity-100 translate-y-0"
                    x-transition:leave="transition ease-in duration-100"
                    x-transition:leave-start="opacity-100 translate-y-0"
                    x-transition:leave-end="opacity-0 -translate-y-1"
                    style="display:none;"
                    class="absolute left-0 right-0 top-full z-20 mt-2 overflow-hidden rounded-[12px] border border-zinc-200 bg-[#eff6ff] shadow-xl shadow-zinc-900/15 dark:border-zinc-700"
                    role="listbox"
                >
                    {{-- Search --}}
                    <div class="border-b border-zinc-100 p-2">
                        <div class="relative">
                            <svg class="pointer-events-none absolute left-2.5 top-1/2 h-4 w-4 -translate-y-1/2 text-zinc-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" />
                            </svg>
                            <input
                                x-ref="search"
                                x-model="search"
                                type="text"
                                placeholder="Search languages"
                                aria-label="Search languages"
                                class="w-full rounded-[15px] border border-zinc-200 bg-zinc-50 py-2 pl-8 pr-3 text-base text-zinc-800 placeholder:text-zinc-600 outline-none transition-colors focus:border-blue-500 focus:bg-white focus:ring-2 focus:ring-blue-500/15"
                            />
                        </div>
                    </div>

                    {{-- Options - capped at ~6 rows for a compact mobile-friendly
                         list; the list itself scrolls for the rest. --}}
                    <div class="max-h-60 overflow-y-auto overscroll-contain p-1">
                        <template x-for="lang in options.filter(l => l.toLowerCase().includes(search.toLowerCase()))" :key="lang">
                            <button
                                type="button"
                                role="option"
                                :aria-selected="language === lang ? 'true' : 'false'"
                                @click="language = lang; open = false; search = ''; window.dispatchEvent(new CustomEvent('language-changed', { detail: lang }))"
                                :class="language === lang ? 'bg-blue-50 text-blue-700' : 'text-zinc-700 hover:bg-zinc-50'"
                                class="flex w-full items-center rounded-[12px] px-3 py-2 text-left text-base font-medium transition-colors"
                            >
                                <span class="flex-1" x-text="lang"></span>
                                <svg x-show="language === lang" class="h-4 w-4 shrink-0 text-blue-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5" aria-hidden="true">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5" />
                                </svg>
                            </button>
                        </template>
                        <div
                            x-show="options.filter(l => l.toLowerCase().includes(search.toLowerCase())).length === 0"
                            class="px-3 py-6 text-center text-base text-zinc-600"
                        >
                            No matches
                        </div>
                    </div>
                </div>
            </div>

        </div>

        {{-- Save --}}
        <button
            type="button"
            @click="localeModalOpen = false"
            class="mt-7 w-full rounded-[12px] bg-blue-600 px-4 py-3 text-base font-semibold text-white transition-colors duration-150 hover:bg-blue-700 focus:outline-none focus-visible:ring-2 focus-visible:ring-blue-500/50"
        >
            Save
        </button>
    </div>
</div>

@php
    use App\Domain\Cart\Services\CartPricingService;
    use App\Models\CurrencyRate;
    use App\Models\Product;

    /**
     * @var \App\Models\Product $product  The country-specific representative Product for this brand.
     * @var string $brandKey  Raw Zendit brand key.
     */

    $brandName = Product::brandDisplayName($brandKey);
    $logoSrc   = Product::brandLogoUrl($brandKey, $product->logo_url);

    // This page is shared across catalog categories — gift cards and mobile
    // top-ups both render here. Derive the customer-facing noun + routes from
    // the product's category so a top-up never reads as a "gift card".
    $categorySlug = $product->category?->slug ?? 'gift-cards';
    $isTopup      = $categorySlug === 'mobile-airtime';
    $isBill       = $categorySlug === 'bill-payments';
    $isGiftCard   = $categorySlug === 'gift-cards';

    // What the card is FOR, keyed off its own subcategory. The supplier sends a
    // company blurb ("X is a multinational technology company...") which says
    // nothing about why anyone would buy the card, and reads the same on every
    // brand; it keeps its place in the long-form copy further down the page.
    $purposeKey = match ($product->subcategory?->slug) {
        'digital-apps', 'gaming-entertainment', 'electronics', 'imtu-internet' => 'products.detail_purpose_digital',
        'food-beverage', 'restaurant', 'supermarket' => 'products.detail_purpose_food',
        'travel-experience' => 'products.detail_purpose_travel',
        'dating' => 'products.detail_purpose_dating',
        'clothing-accessories', 'home-garden', 'sports-outdoors', 'health-beauty', 'auto-moto' => 'products.detail_purpose_retail',
        default => 'products.detail_purpose_general',
    };

    [$kindTitle, $listingRoute, $detailRoute] = match ($categorySlug) {
        'mobile-airtime' => [__('products.detail_kind_topup'), 'shop.topups', 'shop.topup'],
        'bill-payments'  => [__('products.detail_kind_bill'), 'shop.bills', 'shop.bill'],
        default          => [__('products.detail_kind_gift_card'), 'shop.gift-cards', 'shop.brand'],
    };

    // Copy that names the product is written as three whole sentences (gift
    // card, top-up, bill payment) rather than one sentence with a noun spliced
    // in: word order and gender change between languages ("Acme gift card",
    // "Carte cadeau Acme"). This picks the version for this page's category.
    $kindText = fn (string $giftCard, string $topup, string $bill, array $replace = []) => __(
        $isTopup ? $topup : ($isBill ? $bill : $giftCard),
        $replace
    );
    $productLabel = $kindText('products.detail_name_gift_card', 'products.detail_name_topup', 'products.detail_name_bill', ['brand' => $brandName]);
    $pageTitle    = $kindText('products.detail_title_gift_card', 'products.detail_title_topup', 'products.detail_title_bill', ['brand' => $brandName]);

    // Inside the dashboard chrome, swap to the dashboard.shop.* equivalents so
    // the "see more" + similar-brand links keep the user in the dashboard.
    $inDashboard = request()->is('dashboard/shop*') && auth()->check();

    if ($inDashboard) {
        $listingRoute = 'dashboard.'.$listingRoute;
        $detailRoute  = 'dashboard.'.$detailRoute;
    }

    $variants    = $product->variants;
    $fixedDenoms = $variants->where('is_variable', false)->sortBy('retail_price')->values();
    $variable    = $variants->where('is_variable', true)->first();

    $currency = $product->currency_code ?: 'USD';

    $symbols = [
        'USD' => '$', 'EUR' => '€', 'GBP' => '£', 'NGN' => '₦', 'XAF' => 'XAF ', 'ZAR' => 'R',
        'KES' => 'KSh', 'GHS' => '₵', 'EGP' => 'E£', 'MAD' => 'DH', 'CAD' => 'CA$', 'AUD' => 'A$',
        'JPY' => '¥', 'CNY' => '¥', 'INR' => '₹', 'BRL' => 'R$', 'AED' => 'AED', 'SAR' => 'SAR',
        'TRY' => '₺', 'CHF' => 'Fr', 'MXN' => 'MX$', 'KRW' => '₩', 'SGD' => 'S$', 'HKD' => 'HK$',
        'TWD' => 'NT$', 'THB' => '฿', 'IDR' => 'Rp', 'PHP' => '₱', 'VND' => '₫', 'MYR' => 'RM',
    ];
    $sym = fn (string $code) => $symbols[strtoupper($code)] ?? ($code . ' ');

    $flag = function (?string $code) {
        if (! $code || strlen($code) !== 2) {
            return '';
        }
        $code = strtoupper($code);
        return mb_chr(0x1F1E6 + ord($code[0]) - ord('A')) . mb_chr(0x1F1E6 + ord($code[1]) - ord('A'));
    };

    $countryNames = array_flip(config('countries.codes', [])); // ISO → name
    $countryName  = $countryNames[strtoupper($product->country_code)] ?? $product->country_code;

    // Every country this brand has an active product in — i.e. the full set of
    // regions the card can be redeemed in. Drives the "redeemable in" chips.
    $brandCountries = Product::query()
        ->where('brand_key', $brandKey)
        ->where('is_active', true)
        ->whereNotNull('country_code')
        ->distinct()
        ->orderByRaw("country_code = '" . addslashes($product->country_code) . "' DESC")
        ->orderBy('country_code')
        ->pluck('country_code');

    // Primary redemption URL — a curated override (config/brand_urls.php), else
    // the first link inside Zendit's redemption instructions — so the customer
    // can jump straight to the brand to redeem right after buying.
    $redeemUrl = config("brand_urls.urls.{$brandKey}");
    if (! $redeemUrl && $product->redeem_instructions
        && preg_match('~https?://[^\s<>"\'\)]+~i', $product->redeem_instructions, $m)) {
        $redeemUrl = $m[0];
    }

    // Brand colour: config override → Zendit-synced colour → brand blue accent fallback.
    $brandCardColor = Product::brandColor($brandKey, $product->brand_color);
    $brandColor = $brandCardColor ?: '#2563eb';
    $hasStock   = $variants->isNotEmpty();
    // Feature A/B: can a customer buy this product right now? Category feature ON and
    // not admin-flagged out of stock. ($hasStock separately covers "no available variant".)
    $categoryEnabled = $product->isCategoryEnabled();
    $productBuyable  = $categoryEnabled && ! $product->is_out_of_stock;
    $redemptionVideo = data_get($product->metadata, 'redemption_video');

    // Pricing — the Estimated price is the actual payable amount: provider cost
    // + markup (CartPricingService), converted into the selected currency.
    $pricing = app(CartPricingService::class);

    // Country-locked denomination range for the placeholder text ("$15 - 500").
    $availableVariants = $variants->where('is_available', true);

    if (($product->category?->slug ?? '') === 'mobile-airtime') {
        // Topups: the range speaks FACE VALUES in the operator's own currency,
        // merged with the custom-amount bounds - the same language as the
        // /topups listing and the face-labeled amount selector. This line once
        // rendered USD engine prices behind the operator's symbol
        // ("Between ₦0.28 and ₦78.56" on MTN NG); the real charge belongs in
        // Estimated price, never here.
        $faceRange = $availableVariants->where('is_variable', false)
            ->map(fn ($v) => (float) ($v->face_value ?? $v->retail_price))
            ->filter(fn ($v) => $v > 0);
        $rangeMin = collect([$faceRange->min(), $variable?->min_amount])->filter(fn ($v) => $v !== null)->map(fn ($v) => (float) $v)->min();
        $rangeMax = collect([$faceRange->max(), $variable?->max_amount])->filter(fn ($v) => $v !== null)->map(fn ($v) => (float) $v)->max();
    } else {
        // Priced through CartPricingService, not read off retail_price: the raw
        // column is the pre-markup base, so the hint used to advertise a floor
        // ("Between $35.00 and $55.00") that the plan cards themselves contradicted.
        $pricedRange = $availableVariants
            ->map(function ($v) use ($pricing, $product) {
                $v->setRelation('product', $product);

                return round((float) $pricing->calculatePricing($v, 1)['unit_price_snapshot'], 2);
            })
            ->filter();
        $rangeMin = $pricedRange->min();
        $rangeMax = $pricedRange->max();
        if ((! $rangeMin || ! $rangeMax) && $variable) {
            $rangeMin = $variable->min_amount;
            $rangeMax = $variable->max_amount;
        }
    }
    // Same conversion as the tile label that led here - see DisplayCurrency.
    [$rangeMinDisplay, $rangeCurrency] = \App\Support\DisplayCurrency::convert((float) $rangeMin, $currency);
    [$rangeMaxDisplay] = \App\Support\DisplayCurrency::convert((float) $rangeMax, $currency);
    $rangeText = ($rangeMin !== null && $rangeMax !== null)
        ? $sym($rangeCurrency) . rtrim(rtrim(number_format($rangeMinDisplay, 2), '0'), '.')
            . ' - '
            . rtrim(rtrim(number_format($rangeMaxDisplay, 2), '0'), '.')
        : null;

    // All active currency rates (fiat + crypto) from the admin-managed `currency_rates`
    // table. `rate_per_usd` is the EXACT admin value — including USD (e.g. 1.04), which
    // acts as the platform spread/fee. It is applied as-is to compute the payable total.
    // A fiat code's flag derives from its first two letters (USD -> US, GBP -> GB,
    // EUR -> EU); a few non-country codes are mapped explicitly.
    $currencyFlagOverrides = ['XAF' => 'CM', 'XOF' => 'SN', 'XCD' => 'AG'];
    $currencyFlag = function (string $code) use ($currencyFlagOverrides) {
        $code = strtoupper($code);

        return Product::flagUrl($currencyFlagOverrides[$code] ?? substr($code, 0, 2));
    };

    $cryptoRatesForJs = CurrencyRate::active()
        ->orderBy('type')
        ->orderBy('sort_order')
        ->orderBy('code')
        ->get(['code', 'name', 'type', 'rate_per_usd', 'icon_path'])
        ->map(fn ($r) => [
            'code'    => $r->code,
            'name'    => $r->name,
            'type'    => $r->type,
            'perUsd'  => (float) $r->rate_per_usd,
            // More decimals for tiny BTC-style rates, fewer for fiat.
            'decimals' => $r->rate_per_usd < 0.01 ? 8 : ($r->rate_per_usd < 1 ? 4 : 2),
            // Only crypto uses an icon image; fiat always falls through to its
            // country flag below (an icon_path on a fiat row is ignored).
            'icon'    => ($r->type === 'crypto' && $r->icon_path) ? asset('assets/' . $r->icon_path) : null,
            // Fiat currencies show a country flag instead of the letter circle.
            'flag'    => $r->type === 'fiat' ? $currencyFlag($r->code) : null,
        ])
        ->values();

    // Denominations are shown at their native face value, in the CARD'S OWN
    // currency (a UK card is GBP, a US card is USD). The face value is intrinsic
    // to the card and is never converted; only the price the customer pays is.
    $displayRate = 1.0;
    $displayCurrencyCode = $currency;
    // The customer's chosen display currency, when it prices this pair -
    // $conv and the per-denomination 'disp' values convert with it.
    $chosenDisplay = \App\Support\DisplayCurrency::code();
    if ($chosenDisplay && $chosenDisplay !== strtoupper((string) $currency)) {
        $chosenRate = \App\Support\DisplayCurrency::rate(strtoupper((string) $currency), $chosenDisplay);
        if ($chosenRate !== null && $chosenRate > 0) {
            $displayRate = $chosenRate;
            $displayCurrencyCode = $chosenDisplay;
        }
    }
    $displaySymbol = Product::currencySymbol($displayCurrencyCode);
    $conv = fn ($v) => $displaySymbol . number_format((float) $v * $displayRate, 2);

    // A variable product's amount is entered in the VARIANT's own currency (e.g. a
    // utility top-up priced in XOF), not USD. These format the custom-amount UI in it.
    $customCurrency = $variable ? strtoupper((string) ($variable->currency ?: $currency)) : 'USD';
    $customSymbol   = $sym($customCurrency);
    $customMoney    = fn ($v) => $customSymbol . rtrim(rtrim(number_format((float) $v, 2), '0'), '.');

    // Makes bare URLs in Zendit's redemption/terms HTML clickable so customers can jump
    // straight to the brand's redeem page. Existing <a> tags are kept (and forced to
    // open in a new tab); only plain-text URLs between tags get wrapped.
    $linkify = function (?string $html): string {
        if (! $html) {
            return '';
        }
        $parts = preg_split('~(<a\b[^>]*>.*?</a>)~is', $html, -1, PREG_SPLIT_DELIM_CAPTURE);
        $out = '';
        foreach ($parts as $part) {
            if (preg_match('~^<a\b~i', $part)) {
                if (! preg_match('~\btarget=~i', $part)) {
                    $part = preg_replace('~^<a\b~i', '<a target="_blank" rel="noopener noreferrer"', $part);
                }
                $out .= $part;

                continue;
            }
            $out .= preg_replace(
                '~(https?://[^\s<>"\']+)~i',
                '<a href="$1" target="_blank" rel="noopener noreferrer">$1</a>',
                $part
            );
        }

        return $out;
    };

    // The Estimated price selector defaults to the country's currency
    // (config/country_currency.php) when that currency is configured in Rate
    // Management; otherwise USD.
    $countryCurrency = strtoupper(config('country_currency.map.' . strtoupper($product->country_code), 'USD'));
    $defaultCrypto = $cryptoRatesForJs->firstWhere('code', $countryCurrency)
        ? $countryCurrency
        : ($cryptoRatesForJs->firstWhere('code', 'USD') ? 'USD' : ($cryptoRatesForJs->first()['code'] ?? 'USDT'));

    // $pricing is resolved earlier (the denomination range needs it too).
    $markup = $pricing->markupDescriptor($product);

    // A variable amount is typed in the variant's own currency; this rate
    // converts that entered face amount to USD before markup is applied.
    $customRate = $variable
        ? (float) (CurrencyRate::where('code', $customCurrency)->value('rate_per_usd') ?: 1.0)
        : 1.0;

    // What the custom input ADVERTISES as its minimum. For NGN/XAF-class
    // topups this is the ₦500 presentation floor - the supplier accepts less
    // (Zendit sells ₦5 of airtime) but advertising that reads as a joke.
    $customDisplayMin = ($isTopup && $variable)
        ? \App\Domain\Catalog\Support\TopupQuickPicks::presentationMin((float) $variable->min_amount, $customRate)
        : ($variable ? (float) $variable->min_amount : 0.0);

    // ...and as its maximum: the supplier max when nice, otherwise the round
    // step under it (Glo's ₦73,950 advertises - and validates - as ₦70,000).
    $customDisplayMax = ($isTopup && $variable)
        ? \App\Domain\Catalog\Support\TopupQuickPicks::presentationMax((float) $variable->max_amount)
        : ($variable ? (float) $variable->max_amount : 0.0);

    // Compact variant payload for Alpine. `price_usd` is the marked-up USD price
    // for a FIXED denomination; variable variants are priced client-side from
    // the typed amount, so their price_usd is unused.
    $variantsForJs = $variants->map(function ($v) use ($pricing, $product) {
        $v->setRelation('product', $product);

        return [
            'id' => $v->id,
            'is_variable' => (bool) $v->is_variable,
            'face_value' => (float) ($v->face_value ?? $v->retail_price),
            'retail_price' => (float) $v->retail_price,
            'min' => (float) ($v->min_amount ?? 0),
            'max' => (float) ($v->max_amount ?? 0),
            'price_usd' => round((float) $pricing->calculatePricing($v, 1)['unit_price_snapshot'], 2),
        ];
    })->values();

    // Top-up offers carry their real type + benefits in the variant metadata
    // (Zendit subTypes + dataGB/durationDays/voiceMinutes/smsNumber). Group the
    // fixed offers into Credit / Data / Bundle so each plan shows exactly what it
    // gives instead of a bare amount. Nothing here is hardcoded - it all reads the
    // synced metadata; a missing field just drops that chip.
    $topupGroups = ['credit' => [], 'data' => [], 'bundle' => []];
    if ($isTopup) {
        // Converts engine USD prices into the operator's own currency for the
        // plan cards, so a ₦-symbol never fronts a USD figure. USD operators
        // resolve to 1.0 and are untouched.
        $operatorRate = (float) (CurrencyRate::where('code', $currency)->value('rate_per_usd') ?: 1.0);
        // Zendit's subTypes IS the classification and is the only thing trusted
        // here. Its catalogue defines three separate product categories - Mobile
        // Top Up (airtime credit), Mobile Data (data plans) and Mobile Bundles
        // (data + minutes + SMS) - so a "Mobile Top Up" offer is credit even when
        // its metadata carries dataGB / durationDays / dataUnlimited values.
        //
        // Do NOT infer the kind from those allowance fields. They are populated
        // on plain credit offers too (Straight Talk US ships durationDays 30 and
        // dataUnlimited on what Zendit's own catalogue lists as pure credit), so
        // inferring promotes credit denominations into Data/Bundle tabs and
        // advertises plans the carrier does not sell. Benefits belong ON the
        // credit card as descriptive text, not as a reclassification.
        $topupKindOf = function ($v) {
            $st = strtolower((string) ($v->metadata['subTypes'][0] ?? ''));

            return str_contains($st, 'data') ? 'data' : (str_contains($st, 'bundle') ? 'bundle' : 'credit');
        };

        foreach ($fixedDenoms as $v) {
            $m = $v->metadata ?? [];
            // Price through the SAME engine the cart uses. Reading retail_price
            // straight off the column skipped the markup rules, the cost floor
            // and the minimum margin, so a plan card advertised a figure the
            // checkout then contradicted - a "$35" Straight Talk plan that rang
            // up at $40. The card and the cart must never disagree.
            $v->setRelation('product', $product);
            $val = round((float) $pricing->calculatePricing($v, 1)['unit_price_snapshot'], 2);
            $topupGroups[$topupKindOf($v)][] = [
                'id' => $v->id,
                // The denomination's printed face value, in the operator's own
                // currency - what the /topups listing range advertises and what
                // the amount selector labels. The charged price stays in
                // 'price'/price_usd and surfaces in the Estimated price field,
                // exactly like the gift-card flow.
                'face' => round((float) $v->face_value, 2),
                'disp' => round($val * $displayRate, 2),
                // The plan card headlines the printed FACE VALUE in the
                // operator's own currency (₦350 for MTN NG's 0.5GB, matching
                // what the market calls the product); the engine's charge
                // reaches the customer through the Estimated price field.
                'price' => $conv((float) ($v->face_value ?? $v->retail_price)),
                // The offer name ("1GB/day + Call (30d)") carries nuance the GB total
                // misses, so it headlines the card.
                'name' => $m['notes'] ?? null,
                'dataGB' => $m['dataGB'] ?? null,
                'days' => $m['durationDays'] ?? null,
                'voice' => $m['voiceMinutes'] ?? null,
                'sms' => $m['smsNumber'] ?? null,
                'dataUnlimited' => (bool) ($m['dataUnlimited'] ?? false),
                'voiceUnlimited' => (bool) ($m['voiceUnlimited'] ?? false),
                'smsUnlimited' => (bool) ($m['smsUnlimited'] ?? false),
                'summary' => $m['shortNotes'] ?? ($m['notes'] ?? null),
            ];
        }
    }

    // Switcher tabs: Credit / Data / Bundle (only those the network offers). Credit's
    // panel is the amount selector; Data/Bundle panels are benefit cards.
    $topupTabMeta = [
        'credit' => __('products.detail_tab_credit'),
        'data' => __('products.detail_tab_data'),
        'bundle' => __('products.detail_tab_bundles'),
    ];
    $topupTabs = collect($topupTabMeta)
        ->filter(fn ($label, $key) => ! empty($topupGroups[$key]) || ($key === 'credit' && $variable))
        ->all();
    // The switcher only appears when there's an actual choice (data or bundle plans
    // alongside credit). Credit-only networks just show the amount selector.
    $topupHasPlans = ! empty($topupGroups['data']) || ! empty($topupGroups['bundle']);
    // The listing's "Credit, Data & Calls" filter passes ?plan=bundles so the detail
    // opens on a bundle/data tab; otherwise it opens on Credit (the amount selector).
    $topupPlanScope = (string) request()->query('plan', '');
    $topupDefaultTab = ($topupPlanScope === 'bundles' && $topupHasPlans)
        ? (! empty($topupGroups['bundle']) ? 'bundle' : 'data')
        : (array_key_first($topupTabs) ?: 'credit');
    // Credit denominations feed the amount selector's dropdown. Operators like
    // MTN NG sync 150+ denominations including supplier FX artifacts with odd
    // faces (₦1,729, ₦2,161.25). When a custom amount covers the whole range
    // anyway, a wall of options is worse UX than a curated few: keep at most
    // five clean, evenly spread faces and let the custom input carry the rest.
    // Operators without a custom option (US prepaid's 3 plans) are untouched.
    // The quick-pick ladder: real faces where the catalog has clean ones,
    // synthesized custom amounts where it doesn't (MTN NG's missing ₦2,000 and
    // ₦2,500; all of Airtel NG). TopupQuickPicks::plan() is the ONE source the
    // /topups listing range derives from too, so the pages cannot disagree.
    $topupCreditPlansAll = collect($topupGroups['credit'] ?? []);
    $topupCreditPlans = collect(); // legacy loop stays empty; the picks loop renders
    $topupPicks = collect();

    if ($isTopup) {
        $facePlansByValue = $topupCreditPlansAll->keyBy(fn ($p) => (string) round((float) $p['face'], 4));
        // Collapse to clean faces ONLY when a custom amount exists to carry
        // the dropped ones; a fixed-only operator (Koodo CA, US prepaid)
        // keeps every real face, junk-looking conversions included, because
        // a dropped face there is simply unpurchasable.
        $planFaces = $topupCreditPlansAll->map(fn ($p) => (float) $p['face'])
            ->when($variable !== null, fn ($faces) => $faces->filter(
                fn ($f) => \App\Domain\Catalog\Support\TopupQuickPicks::isCleanFace($f),
            ))
            ->unique()->sort()->values()->all();

        $topupPicks = collect(\App\Domain\Catalog\Support\TopupQuickPicks::plan(
            $planFaces,
            $variable !== null ? (float) $variable->min_amount : null,
            $variable !== null ? (float) $variable->max_amount : null,
            $operatorRate,
        ))->map(function ($pick) use ($facePlansByValue) {
            if ($pick['type'] === 'face') {
                $plan = $facePlansByValue[(string) round($pick['value'], 4)] ?? null;

                return $plan !== null ? ['plan' => $plan] : null;
            }

            return ['amount' => $pick['value']];
        })->filter()->values();

        // Operators whose clean faces exist but never anchored a ladder (no
        // variable, tiny catalogs) fall back to every credit plan they have.
        if ($topupPicks->isEmpty() && $topupCreditPlansAll->isNotEmpty()) {
            $topupPicks = $topupCreditPlansAll->sortBy('face')->values()->map(fn ($p) => ['plan' => $p]);
        }

        // The advertised range IS the picks' ends - "Between ₦500 and
        // ₦150,000" states exactly what the selector offers, presentation
        // floor included, instead of the supplier's raw ₦5 minimum.
        if ($topupPicks->isNotEmpty()) {
            $pickValue = fn ($pick) => isset($pick['plan']) ? (float) $pick['plan']['face'] : (float) $pick['amount'];
            $rangeMin = $pickValue($topupPicks->first());
            $rangeMax = $pickValue($topupPicks->last());
            $rangeText = $sym($currency).rtrim(rtrim(number_format((float) $rangeMin, 2), '0'), '.')
                .' - '
                .rtrim(rtrim(number_format((float) $rangeMax, 2), '0'), '.');
        }
    }

    // Similar brands in same subcategory + same country.
    $similarIds = Product::query()
        ->where('is_active', true)
        ->where('brand_key', '!=', $brandKey)
        ->whereNotNull('brand_key')
        ->where('category_id', $product->category_id)
        ->where('country_code', $product->country_code)
        ->when($product->subcategory_id, fn ($q) => $q->where('subcategory_id', $product->subcategory_id))
        ->select('brand_key', \Illuminate\Support\Facades\DB::raw('MIN(id) as id'))
        ->groupBy('brand_key')
        ->limit(6)
        ->pluck('id');

    $similar = Product::query()
        ->whereIn('id', $similarIds)
        ->orderByDesc('is_popular')
        ->orderByDesc('is_featured')
        ->orderBy('name')
        ->get(['id', 'name', 'slug', 'brand_key', 'country_code', 'logo_url', 'featured_image', 'brand_color']);
@endphp

@php
    /*
     * Structured data for this brand page. A Product schema (with an
     * AggregateOffer spanning the cheapest -> priciest denomination) lets search
     * engines and AI crawlers surface the page as a buyable product, and a
     * BreadcrumbList gives the listing -> brand trail.
     *
     * Offers are priced in USD - the marked-up amount the customer actually pays
     * (price_usd), not the card's local face value. USD is the settlement
     * currency for every order, so this keeps the schema consistent with the
     * real charge AND with Google Merchant's single shipping currency. Emitting a
     * local priceCurrency (EUR, NGN, ...) is exactly what trips Merchant's
     * "mismatched shipping currency" disapproval.
     */
    $schemaPrices = $variantsForJs
        ->pluck('price_usd')
        ->map(fn ($p) => round((float) $p, 2))
        ->filter(fn ($p) => $p > 0)
        ->values();

    // Real published-review aggregate + a few sample reviews so Product rich
    // results can show stars (fixes Search Console "missing aggregateRating /
    // review"). Cached for an hour since it's identical across every product.
    $reviewSchema = \Illuminate\Support\Facades\Cache::remember('product-schema-reviews-v2', now()->addHour(), function () {
        $published = \App\Models\Review::published()->ownCollected()->ordered()->get();

        if ($published->isEmpty()) {
            return ['count' => 0];
        }

        // Every rating counts toward the aggregate, including a score left with
        // no comment. A sample is a quoted review, so it needs text, and it names
        // the author the way a customer-facing surface may.
        return [
            'rating' => round((float) $published->avg('rating'), 1),
            'count' => $published->count(),
            'samples' => $published->filter(fn ($r) => filled($r->body))->take(5)->map(fn ($r) => [
                'author' => $r->displayName(),
                'body' => $r->body,
                'rating' => max(1, min(5, (int) round((float) $r->rating))),
                'date' => optional($r->reviewed_at)->toDateString(),
            ])->all(),
        ];
    });

    // Gift-card brand pages prefer THIS brand's own customer reviews for the
    // rating shown under the product name. When the brand has its own reviews we
    // use them for the visible block AND the rich-result markup so the two
    // always agree; brands with none fall back to the site-wide aggregate above
    // so the page still carries star markup. Cached per brand. Gift cards only -
    // top-ups and bills never carry a per-product rating.
    $brandReviewAgg = ['count' => 0];
    if ($isGiftCard) {
        $brandReviewAgg = \Illuminate\Support\Facades\Cache::remember("product-brand-reviews-v2-{$brandKey}", now()->addHour(), function () use ($brandKey) {
            $reviews = \App\Models\Review::published()->ownCollected()->forBrand($brandKey)->ordered()->get();

            if ($reviews->isEmpty()) {
                return ['count' => 0];
            }

            return [
                'rating' => round((float) $reviews->avg('rating'), 1),
                'count' => $reviews->count(),
                'samples' => $reviews->filter(fn ($r) => filled($r->body))->take(5)->map(fn ($r) => [
                    'author' => $r->displayName(),
                    'body' => $r->body,
                    'rating' => max(1, min(5, (int) round((float) $r->rating))),
                    'date' => optional($r->reviewed_at)->toDateString(),
                ])->all(),
            ];
        });

        if (($brandReviewAgg['count'] ?? 0) > 0) {
            $reviewSchema = $brandReviewAgg;
        }
    }

    // The on-page review carousel is the shared x-shop.brand-reviews
    // component (it fetches its own reviews and self-guards when empty).

    // One "Buy X in Y on Z." sentence opens both the schema description and
    // the meta description; only the delivery sentence after it differs.
    $seoReplace = ['brand' => $brandName, 'country' => $countryName, 'range' => (string) $rangeText, 'site' => $siteName];
    $seoBuyLine = $rangeText
        ? $kindText('products.detail_seo_buy_ranged_gift_card', 'products.detail_seo_buy_ranged_topup', 'products.detail_seo_buy_ranged_bill', $seoReplace)
        : $kindText('products.detail_seo_buy_gift_card', 'products.detail_seo_buy_topup', 'products.detail_seo_buy_bill', $seoReplace);

    $productJsonLd = [
        '@context' => 'https://schema.org',
        '@type' => 'Product',
        'name' => $pageTitle,
        'description' => $seoBuyLine.' '.__('products.detail_seo_digital_delivery'),
        'brand' => ['@type' => 'Brand', 'name' => $brandName],
        'category' => $kindTitle,
        'url' => url()->current(),
    ];

    if ($logoSrc) {
        $productJsonLd['image'] = $logoSrc;
    }

    if ($schemaPrices->isNotEmpty()) {
        $productJsonLd['offers'] = [
            '@type' => 'AggregateOffer',
            'priceCurrency' => 'USD',
            'lowPrice' => (string) $schemaPrices->min(),
            'highPrice' => (string) $schemaPrices->max(),
            'offerCount' => (string) $schemaPrices->count(),
            'availability' => 'https://schema.org/InStock',
            'url' => url()->current(),
        ];
    }

    if (($reviewSchema['count'] ?? 0) > 0) {
        $productJsonLd['aggregateRating'] = [
            '@type' => 'AggregateRating',
            'ratingValue' => (string) $reviewSchema['rating'],
            'reviewCount' => (string) $reviewSchema['count'],
            'bestRating' => '5',
            'worstRating' => '1',
        ];

        $productJsonLd['review'] = array_map(fn ($s) => array_filter([
            '@type' => 'Review',
            'reviewRating' => [
                '@type' => 'Rating',
                'ratingValue' => (string) $s['rating'],
                'bestRating' => '5',
                'worstRating' => '1',
            ],
            'author' => ['@type' => 'Person', 'name' => $s['author']],
            'reviewBody' => $s['body'],
            'datePublished' => $s['date'] ?? null,
        ]), $reviewSchema['samples']);
    }

    $crumbLabel = match ($categorySlug) {
        'mobile-airtime' => __('products.detail_crumb_topups'),
        'bill-payments' => __('navigation.bill_payments'),
        default => __('navigation.gift_cards'),
    };
    $crumbListing = match ($categorySlug) {
        'mobile-airtime' => 'shop.topups',
        'bill-payments' => 'shop.bills',
        default => 'shop.gift-cards',
    };

    // Home, then the category, then the subcategory this brand sits in. The
    // heading draws this same trail, so what a reader sees and what a search
    // engine is told can never drift apart.
    $crumbTrail = [
        ['name' => __('products.detail_crumb_home'), 'url' => url('/')],
        ['name' => $crumbLabel, 'url' => route($crumbListing)],
    ];

    if ($product->subcategory?->name) {
        $crumbTrail[] = [
            'name' => $product->subcategory->name,
            'url' => route($crumbListing, ['subcategory' => $product->subcategory->slug]),
        ];
    }

    $breadcrumbJsonLd = [
        '@context' => 'https://schema.org',
        '@type' => 'BreadcrumbList',
        'itemListElement' => collect($crumbTrail)
            ->push(['name' => $pageTitle, 'url' => url()->current()])
            ->values()
            ->map(fn (array $crumb, int $index) => [
                '@type' => 'ListItem',
                'position' => $index + 1,
                'name' => $crumb['name'],
                'item' => $crumb['url'],
            ])
            ->all(),
    ];

    $pageJsonLd = [$productJsonLd, $breadcrumbJsonLd];

    $metaDescription = $seoBuyLine.' '.__('products.detail_seo_email_delivery');
    $metaKeywords = $kindText('products.detail_keywords_gift_card', 'products.detail_keywords_topup', 'products.detail_keywords_bill', ['brand' => $brandName, 'site' => $siteName]);
@endphp

<x-shop.layout
    :title="$pageTitle . ' | '.$siteName"
    :description="$metaDescription"
    :keywords="$metaKeywords"
    :og-image="$logoSrc ?: asset('assets/og-image.png')"
    og-type="product"
    :json-ld="$pageJsonLd"
>

    <div
        @php
            $dialCode = config('dial_codes.codes.'.strtoupper((string) $product->country_code), '+1');
        @endphp
        x-data="brandDetail({
            variants: @js($variantsForJs),
            rangeText: @js($rangeText),
            cryptos: @js($cryptoRatesForJs),
            defaultCrypto: @js($defaultCrypto),
            markup: @js($markup),
            customRate: @js($customRate),
            customMin: @js($customDisplayMin),
            customMax: @js($customDisplayMax),
            requiresRecipientPhone: @js($isTopup),
            defaultDialCode: @js($dialCode),
            requiresAccountId: @js($isBill),
            rcoinConfig: @js([
                'enabled' => (bool) \App\Models\Setting::get('rcoin_enabled', true),
                'cashback_percentage' => (float) \App\Models\Setting::get('cashback_percentage', 0.5),
                'usd_rate' => (float) \App\Models\Setting::rcoinUsdRate(),
            ]),
            purchasable: @js($productBuyable),
        })"
        x-init="init()"
        style="--brand: {{ $brandColor }};"
        {{-- Narrower than the nav and the listing pages, which run the full 1550px:
             a product page is one thing to read and buy, not a grid of many. --}}
        class="mx-auto w-full max-w-7xl px-4 py-6 sm:px-6 lg:px-8 lg:py-10"
    >

        {{-- Two columns: the writing (with the art and values inside it for a gift
             card), and the buy panel, which for a gift card is the purchase card. --}}
        <div class="mt-6 grid grid-cols-1 gap-8 lg:mt-10 lg:gap-8 {{ $isGiftCard ? 'lg:grid-cols-[minmax(0,1fr)_360px]' : 'lg:grid-cols-2' }}">

            {{-- A gift card's buy rail: the purchase card alone, standing in a narrow
                 column beside the writing and riding the scroll with it. The other
                 kinds keep the hero plate here, and a gift card draws that plate
                 beside its values instead. On a phone both columns dissolve
                 (max-lg:contents) and order runs writing, card, the rest. --}}
            {{-- The rail spans the rows beside it as well as its own, so the card has
                 room to ride the scroll: a sticky element can only travel inside its
                 own cell, and a cell that is exactly the card's height never moves. --}}
            <div class="{{ $isGiftCard ? 'max-lg:contents lg:col-start-2 lg:row-start-2 lg:row-span-3' : '' }}">
                {{-- top offset clears the sticky header (top-bar 36 + nav 64 + category bar 40)
                     so it parks below it instead of sliding up behind the nav. --}}
                <div class="{{ $isGiftCard ? 'max-lg:contents ' : '' }}lg:sticky lg:top-[156px]">
                    @if ($isGiftCard)
                        @if ($hasStock)
                            {{-- The purchase card, alone in the rail: the brand mark with the
                                 chosen value and the quantity, the price, then the buy actions.
                                 It reads the page's own state, and the values it prices stand
                                 beside the art in the column next to it. --}}
                            <aside class="pure-card w-full rounded-[20px] border border-zinc-200 bg-white p-5 shadow-md shadow-zinc-900/[0.06] max-lg:order-3 dark:border-white/10 dark:shadow-none" aria-label="{{ $productLabel }}">
                                <div class="flex items-center gap-3">
                                    @if ($logoSrc)
                                        <img src="{{ $logoSrc }}" alt="" class="h-12 w-12 shrink-0 rounded-full object-cover ring-1 ring-zinc-200 dark:ring-white/10" loading="lazy">
                                    @endif
                                    <div class="min-w-0 flex-1 leading-tight">
                                        <p class="truncate text-sm text-zinc-700 dark:text-zinc-300">{{ $brandName }}</p>
                                        {{-- The chosen value with its code, then the kind: "25 USD Gift Card". --}}
                                        <p class="truncate text-sm font-semibold text-zinc-900 dark:text-white">
                                            <span x-text="customMode
                                                ? (amount ? Number(amount).toLocaleString('en-US') + ' {{ strtoupper((string) $customCurrency) }}' : @js(__('products.detail_custom_amount')))
                                                : (variants.find(v => v.id === selectedVariantId) ? Number(variants.find(v => v.id === selectedVariantId).face_value).toLocaleString('en-US') + ' {{ strtoupper((string) $currency) }}' : @js(__('products.detail_select_amount')))">{{ __('products.detail_select_amount') }}</span>
                                            {{ __('products.detail_kind_gift_card') }}
                                        </p>
                                    </div>
                                    {{-- Quantity, in the site's own dropdown rather than the
                                         browser's, so it matches every other picker here. --}}
                                    <div x-data="{ open: false }" @click.outside="open = false" @keydown.escape="open = false" class="relative shrink-0">
                                        <button
                                            type="button"
                                            id="gift-quantity"
                                            @click="open = ! open"
                                            :aria-expanded="open.toString()"
                                            aria-haspopup="listbox"
                                            aria-label="{{ __('products.detail_quantity') }}"
                                            class="flex h-9 items-center gap-1.5 rounded-[12px] border border-zinc-200 bg-white pl-3 pr-2 text-sm font-semibold tabular-nums text-zinc-900 transition-colors hover:border-blue-300 focus:outline-none focus-visible:ring-2 focus-visible:ring-blue-500/40 dark:border-white/10 dark:bg-white/5 dark:text-white dark:hover:border-blue-400/50"
                                        >
                                            <span x-text="quantity">1</span>
                                            <svg class="h-4 w-4 shrink-0 text-zinc-500 transition-transform duration-150" :class="{ 'rotate-180': open }" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
                                                <path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7"/>
                                            </svg>
                                        </button>

                                        <div
                                            x-show="open"
                                            x-cloak
                                            x-transition:enter="transition ease-out duration-150"
                                            x-transition:enter-start="opacity-0 -translate-y-1"
                                            x-transition:enter-end="opacity-100 translate-y-0"
                                            role="listbox"
                                            class="absolute right-0 top-full z-20 mt-1 max-h-60 w-[4.5rem] overflow-y-auto rounded-[12px] border border-zinc-200 bg-white p-1 shadow-xl shadow-zinc-900/10 [scrollbar-width:none] dark:border-white/10 dark:bg-[#1a1a1e] [&::-webkit-scrollbar]:hidden"
                                        >
                                            @for ($n = 1; $n <= 10; $n++)
                                                <button
                                                    type="button"
                                                    role="option"
                                                    :aria-selected="(quantity === {{ $n }}).toString()"
                                                    @click="quantity = {{ $n }}; open = false"
                                                    :class="quantity === {{ $n }} ? 'bg-blue-50 text-blue-700 dark:bg-blue-500/15 dark:text-blue-300' : 'text-zinc-800 hover:bg-zinc-100 dark:text-zinc-200 dark:hover:bg-white/10'"
                                                    class="flex w-full items-center justify-center rounded-[12px] px-2 py-1.5 text-sm font-medium tabular-nums transition-colors"
                                                >{{ $n }}</button>
                                            @endfor
                                        </div>
                                    </div>
                                </div>

                                {{-- The pay currency, then what this many of the chosen value
                                     costs in it. --}}
                                <div class="mt-4">
                                    @include('shop._pay-currency-picker', ['pickerCodeOnly' => true, 'pickerLabelText' => __('common.payment_currency')])
                                </div>

                                {{-- The estimated price. The tiles carry face values only,
                                     so this is the one figure the customer pays. --}}
                                <p class="mt-4 text-xs font-semibold text-zinc-600 dark:text-zinc-400">{{ __('products.detail_estimated_price') }}</p>
                                <p x-data="valueFlip()" x-effect="selectedVariantId; quantity; selectedCrypto; flash()" class="mt-1 text-[32px] font-bold leading-none tabular-nums text-zinc-900 dark:text-white" x-text="formatCrypto()">0.00 USD</p>

                                @include('shop._buy-actions')
                            </aside>
                        @endif
                    @else
                        @include('shop._brand-plate')
                    @endif
                </div>
            </div>

            {{-- The writing, and for the other kinds the buy panel itself. A gift card
                 takes the left column here and carries its art and values inside it. --}}
            {{-- A gift card's writing dissolves into the page grid (its three regions place
                 themselves), so this carries no display of its own there: a flex utility
                 alongside would win over contents and collapse them back into one cell. --}}
            <div class="{{ $isGiftCard ? 'contents' : 'flex flex-col gap-5' }}">
            @if ($isGiftCard)
                {{-- Split in two so that on a phone the art, the values and the card can
                     be ordered above the writing. --}}
                <div class="flex min-w-0 flex-col gap-5 max-lg:order-1 lg:col-start-1 lg:row-start-1">
            @endif

                @php
                    $redeemLabel = $isTopup
                        ? __('products.detail_badge_credited_to_number')
                        : ($isBill ? __('products.detail_badge_paid_to_account') : __('products.detail_badge_online_redeemable'));

                    $badges = [
                        ['label' => __('products.detail_badge_instant_delivery'), 'd' => 'M13 10V3L4 14h7v7l9-11h-7z'],
                        ['label' => $redeemLabel,       'd' => 'M9 12.75L11.25 15 15 9.75M21 12a9 9 0 11-18 0 9 9 0 0118 0z'],
                        ['label' => __('products.detail_badge_fair_refund'), 'd' => 'M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z'],
                    ];
                @endphp

                {{-- Heading: where the page sits, what it is, how it is rated, and what
                     it does for you, in that order. --}}
                <div>
                    {{-- The dashboard has its own sidebar saying where you are, so the
                         trail is a storefront thing only. --}}
                    @if ($isGiftCard && ! $inDashboard)
                        {{-- The same trail the page's BreadcrumbList carries: home, the
                             category, then the family of brands this one belongs to. --}}
                        <nav aria-label="{{ __('products.detail_breadcrumb') }}" class="mb-2 flex flex-wrap items-center gap-2 text-sm text-zinc-600 dark:text-zinc-400">
                            @foreach ($crumbTrail as $crumb)
                                @if (! $loop->first)
                                    <svg class="h-3.5 w-3.5 shrink-0 text-zinc-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7"/>
                                    </svg>
                                @endif
                                <a href="{{ $crumb['url'] }}" wire:navigate class="underline underline-offset-2 transition-colors hover:text-blue-700">{{ $crumb['name'] }}</a>
                            @endforeach
                        </nav>
                    @endif

                    <h1 class="{{ $isGiftCard ? 'text-[30px] sm:text-[36px]' : 'text-[24px]' }} font-bold leading-tight text-zinc-900">{{ $productLabel }}</h1>

                    {{-- Per-brand customer rating, from reviews left on delivered
                         orders for this gift card. Only shown for gift cards that
                         have at least one approved review. --}}
                    @if ($isGiftCard && ($brandReviewAgg['count'] ?? 0) > 0)
                        <a href="{{ route('shop.reviews') }}" wire:navigate class="mt-2 inline-flex items-center gap-2" aria-label="{{ trans_choice('products.detail_rating_aria', $brandReviewAgg['count'], ['rating' => number_format($brandReviewAgg['rating'], 1), 'count' => $brandReviewAgg['count']]) }}">
                            <span class="flex items-center gap-0.5">
                                @for ($i = 1; $i <= 5; $i++)
                                    <svg class="h-5 w-5 {{ $i <= (int) round($brandReviewAgg['rating']) ? 'text-[#2563eb]' : 'text-[#2563eb]/25' }}" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
                                        <path d="M12 .587l3.668 7.568L24 9.423l-6 5.951L19.336 24 12 19.897 4.664 24 6 15.374 0 9.423l8.332-1.268z"/>
                                    </svg>
                                @endfor
                            </span>
                            <span class="text-base font-bold text-zinc-900 dark:text-white">{{ number_format($brandReviewAgg['rating'], 1) }}</span>
                            <span class="text-base text-zinc-500 hover:text-blue-700">({{ trans_choice('products.detail_review_count', $brandReviewAgg['count'], ['count' => $brandReviewAgg['count']]) }})</span>
                        </a>
                    @endif

                    @if ($isGiftCard)
                        <ul class="mt-4 space-y-2 text-base text-zinc-800 dark:text-zinc-200">
                            @foreach ($badges as $badge)
                                <li class="flex items-start gap-2.5">
                                    <svg class="mt-[7px] h-2.5 w-2.5 shrink-0 text-[#2563eb]" viewBox="0 0 8 10" fill="currentColor" aria-hidden="true">
                                        <path d="M0 0l8 5-8 5z"/>
                                    </svg>
                                    {{ $badge['label'] }}
                                </li>
                            @endforeach
                        </ul>
                    @endif

                    @if ($isGiftCard)
                        {{-- What this card solves, in one line: what it pays for, where,
                             and how it is bought. The supplier's company blurb sits in the
                             long-form copy instead. --}}
                        <p class="mt-3 text-base leading-relaxed text-zinc-600 dark:text-zinc-300">
                            {{ __($purposeKey, ['brand' => $brandName, 'country' => $countryName]) }}
                        </p>
                    @elseif ($product->description)
                        <div class="mt-3 text-base leading-relaxed text-zinc-600 [&>p]:mb-2 [&_a]:text-blue-600 [&_a]:underline">{!! $product->description !!}</div>
                    @elseif ($isTopup)
                        <p class="mt-3 text-base leading-relaxed text-zinc-600">
                            {{ __('products.detail_intro_topup', ['brand' => $brandName]) }}
                        </p>
                    @elseif ($isBill)
                        <p class="mt-3 text-base leading-relaxed text-zinc-600">
                            {{ __('products.detail_intro_bill', ['brand' => $brandName]) }}
                        </p>
                    @else
                        <p class="mt-3 text-base leading-relaxed text-zinc-600">
                            {{ __('products.detail_intro_gift_card', ['brand' => $brandName]) }}
                        </p>
                    @endif
                </div>

                {{-- Trust badges — each is a small emerald-tinted circle + label,
                     mirroring the reference. Mobile keeps them on one scrollable row;
                     sm+ wraps naturally. --}}
                {{-- Top-ups and bills keep the badge row; a gift card prints the same
                     three as bullets in its heading. py-1 stops the overflow-x container
                     clipping the badge circles' ring on mobile (sm+ needs none). --}}
                @unless ($isGiftCard)
                <div class="flex items-center gap-x-6 gap-y-3 overflow-x-auto py-1 text-sm font-medium text-zinc-700 [scrollbar-width:none] [&::-webkit-scrollbar]:hidden sm:flex-wrap sm:overflow-visible sm:py-0 dark:text-zinc-200">
                    @foreach ($badges as $badge)
                        <span class="flex shrink-0 items-center gap-2">
                            <span class="flex h-7 w-7 shrink-0 items-center justify-center rounded-[12px] bg-emerald-50 text-emerald-600 ring-1 ring-emerald-100 dark:bg-emerald-500/15 dark:ring-emerald-500/30">
                                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="{{ $badge['d'] }}"/>
                                </svg>
                            </span>
                            {{ $badge['label'] }}
                        </span>
                    @endforeach
                </div>
                @endunless

                {{-- Category type pill — small chip below the trust badges so the
                     buyer instantly knows what kind of product this is (matches the
                     "Credits" tag in the reference). --}}
                @php
                    $kindPillLabel = $isTopup ? __('products.detail_pill_credits') : ($isBill ? __('products.detail_kind_bill') : __('products.detail_kind_gift_card'));
                @endphp
                {{-- A gift card says where it can be redeemed here instead: the kind is
                     already in the heading above, and where it works is the thing a buyer
                     checks before choosing a value. --}}
                @if ($isGiftCard)
                    @include('shop._country-availability', ['availabilityClass' => 'mb-1'])
                @else
                    <div>
                        <span class="inline-flex items-center rounded-[5px] bg-blue-600 px-3 py-1 text-xs font-bold uppercase tracking-wide text-white">{{ $kindPillLabel }}</span>
                    </div>
                @endif

                @if ($isGiftCard)
                    </div>{{-- /heading --}}

                    {{-- The second row: the art with its values, standing level with the
                         purchase card in the column beside it. --}}
                    <div class="flex min-w-0 flex-col gap-5 max-lg:order-2 lg:col-start-1 lg:row-start-2">
                @endif

                @if ($hasStock)
                    {{-- Mobile top-ups: a Credit / Data / Bundle switcher sharing one ttab
                         scope. The Credit panel is the amount selector below; Data/Bundle
                         panels are benefit cards. All read from synced Zendit metadata. --}}
                    {{-- This wrapper only carries the top-up tab state. Gift cards skip
                         it: it would span their region boundaries. --}}
                    @unless ($isGiftCard)
                    <div @if ($isTopup && $topupHasPlans) x-data="{ ttab: '{{ $topupDefaultTab }}' }" @endif>
                    @endunless

                    @if ($isTopup && $topupHasPlans)
                        <div class="mb-5">
                            <label class="mb-1.5 block text-xs font-semibold text-zinc-900 dark:text-white">{{ __('products.detail_choose_topup') }}</label>

                            <div class="inline-flex items-center rounded-[12px] bg-zinc-100 p-1 dark:bg-[#0c1a36]" role="tablist" aria-label="{{ __('products.detail_plan_type') }}">
                                @foreach ($topupTabs as $key => $label)
                                    <button
                                        type="button"
                                        role="tab"
                                        @click="ttab = '{{ $key }}'"
                                        :aria-selected="ttab === '{{ $key }}'"
                                        :class="ttab === '{{ $key }}' ? 'bg-white text-zinc-900 shadow-sm ring-1 ring-zinc-200 dark:bg-[#1d3252] dark:text-white dark:ring-[#24364f]' : 'text-zinc-600 hover:text-zinc-900 dark:text-zinc-400 dark:hover:text-white'"
                                        class="rounded-[10px] px-4 py-1.5 text-xs font-semibold transition-all"
                                    >{{ $label }}</button>
                                @endforeach
                            </div>

                            @foreach ($topupTabs as $key => $label)
                                @if ($key === 'credit') @continue @endif
                                <div x-show="ttab === '{{ $key }}'" x-cloak class="mt-3 grid grid-cols-1 gap-2 sm:grid-cols-2">
                                    @foreach ($topupGroups[$key] as $plan)
                                        @php
                                            // Data/Bundle render as eSIM-style cards: an icon feature list built
                                            // from the offer's structured benefits. Unlimited flags win over a number.
                                            $dataIcon = 'M8.288 15.038a5.25 5.25 0 017.424 0M5.106 11.856c3.807-3.808 9.98-3.808 13.788 0M1.924 8.674c5.565-5.565 14.587-5.565 20.152 0M12.53 18.22l-.53.53-.53-.53a.75.75 0 011.06 0z';
                                            $voiceIcon = 'M2.25 6.75c0 8.284 6.716 15 15 15h2.25a2.25 2.25 0 002.25-2.25v-1.372c0-.516-.351-.966-.852-1.091l-4.423-1.106c-.44-.11-.902.055-1.173.417l-.97 1.293c-.282.376-.769.542-1.21.38a12.035 12.035 0 01-7.143-7.143c-.162-.441.004-.928.38-1.21l1.293-.97c.363-.271.527-.734.417-1.173L6.963 3.102a1.125 1.125 0 00-1.091-.852H4.5A2.25 2.25 0 002.25 4.5v2.25z';
                                            $smsIcon = 'M8.625 9.75a.375.375 0 11-.75 0 .375.375 0 01.75 0zm0 0H8.25m4.125 0a.375.375 0 11-.75 0 .375.375 0 01.75 0zm0 0H12m4.125 0a.375.375 0 11-.75 0 .375.375 0 01.75 0zm0 0h-.375M21 12c0 4.556-4.03 8.25-9 8.25a9.764 9.764 0 01-2.555-.337A5.972 5.972 0 015.41 20.97a5.969 5.969 0 01-.474-.065 4.48 4.48 0 00.978-2.025c.09-.457-.133-.901-.467-1.226C3.93 16.178 3 14.189 3 12c0-4.556 4.03-8.25 9-8.25s9 3.694 9 8.25z';
                                            $clockIcon = 'M12 6v6h4.5m4.5 0a9 9 0 11-18 0 9 9 0 0118 0z';

                                            $features = [];
                                            if ($plan['dataUnlimited']) { $features[] = [$dataIcon, __('products.detail_unlimited_data')]; }
                                            elseif (! empty($plan['dataGB'])) { $features[] = [$dataIcon, __('products.detail_data_size', ['size' => rtrim(rtrim(number_format((float) $plan['dataGB'], 2, '.', ''), '0'), '.').'GB'])]; }
                                            if ($plan['voiceUnlimited']) { $features[] = [$voiceIcon, __('products.detail_unlimited_minutes')]; }
                                            elseif (! empty($plan['voice'])) { $features[] = [$voiceIcon, __('products.detail_minutes', ['count' => (string) $plan['voice']])]; }
                                            if ($plan['smsUnlimited']) { $features[] = [$smsIcon, __('products.detail_unlimited_sms')]; }
                                            elseif (! empty($plan['sms'])) { $features[] = [$smsIcon, __('products.detail_sms', ['count' => (string) $plan['sms']])]; }
                                            if (! empty($plan['days'])) { $features[] = [$clockIcon, __('products.detail_validity_days', ['count' => (string) $plan['days']])]; }
                                        @endphp
                                        <button
                                            type="button"
                                            @click="amount = {{ $plan['face'] }}; selectedVariantId = {{ $plan['id'] }}; customMode = false; window.innerWidth < 1024 && setTimeout(() => document.getElementById('topup-recipient')?.scrollIntoView({ behavior: 'smooth', block: 'center' }), 140)"
                                            :class="(! customMode && selectedVariantId === {{ $plan['id'] }}) ? 'border-blue-600 ring-1 ring-blue-600 dark:border-blue-500 dark:ring-blue-500' : 'border-zinc-200 hover:border-blue-300 dark:border-[#24364f] dark:hover:border-blue-400/50'"
                                            class="esim-tile flex h-full flex-col rounded-[14px] border bg-[#eff6ff] px-4 py-4 text-left transition-colors"
                                        >
                                            <span class="inline-flex w-fit items-center rounded-[8px] bg-blue-50 px-2 py-0.5 text-[10px] font-bold uppercase tracking-wide text-blue-700 dark:bg-blue-500/15 dark:text-blue-300">{{ $key === 'data' ? __('products.detail_tab_data') : __('products.detail_plan_bundle') }}</span>
                                                @if (! empty($plan['name']))
                                                    <span class="mt-2 block text-sm font-bold leading-snug text-zinc-900 dark:text-white">{{ $plan['name'] }}</span>
                                                @endif
                                                @if (! empty($features))
                                                    <span class="my-3 block h-px bg-zinc-200 dark:bg-zinc-700"></span>
                                                    <span class="block space-y-2 text-sm text-zinc-900 dark:text-white">
                                                        @foreach ($features as [$fIcon, $fLabel])
                                                            <span class="flex items-center gap-2">
                                                                <svg class="h-4 w-4 shrink-0 text-[#2563eb]" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="{{ $fIcon }}"/></svg>
                                                                <span>{{ $fLabel }}</span>
                                                            </span>
                                                        @endforeach
                                                    </span>
                                                @elseif (! empty($plan['summary']))
                                                    <span class="mt-2 block text-xs leading-snug text-zinc-600 dark:text-zinc-400">{{ $plan['summary'] }}</span>
                                                @endif
                                                <span class="mt-auto pt-3 text-right text-lg font-bold tabular-nums text-zinc-900 dark:text-white">{{ $plan['price'] }}</span>
                                        </button>
                                    @endforeach
                                </div>
                            @endforeach
                        </div>
                    @endif

                    {{-- The art and the values it belongs to, side by side. The purchase
                         card that prices them stands in the rail. The other kinds keep
                         their amount fields in the panel, with no plate beside them. --}}
                    @if ($isGiftCard)
                        <div class="grid gap-5 sm:grid-cols-[minmax(0,300px)_minmax(0,1fr)]">
                            @include('shop._brand-plate', ['plateClass' => 'w-full self-start p-4', 'artWidth' => 'w-full'])
                            <div class="min-w-0">
                                @include('shop._amount-fields')
                            </div>
                        </div>
                    @else
                        @include('shop._amount-fields')
                    @endif

                    @if ($isGiftCard)
                        </div>{{-- /art and values --}}
                        <div class="flex min-w-0 flex-col gap-5 max-lg:order-4 lg:col-start-1 lg:row-start-3">
                    @endif

                    {{-- Account ID — bill payments only. Captures the meter
                         number / customer ID the biller expects. Mirrors the
                         top-up phone pattern: validated client-side, blocks
                         Add to cart until non-empty, persisted to cart→order
                         metadata for the Zendit billing.accountId field. --}}
                    @if ($isBill)
                        <div>
                            <label class="mb-1.5 flex items-center gap-1.5 text-xs font-semibold text-zinc-900 dark:text-zinc-100">
                                {{ __('products.detail_account_label', ['brand' => $brandName]) }}
                            </label>
                            <div
                                :class="accountIdValid()
                                    ? 'border-emerald-500 ring-2 ring-emerald-500/15'
                                    : (accountId.length > 0 ? 'border-red-300' : 'border-zinc-200')"
                                class="flex h-[52px] w-full items-center gap-3 rounded-[12px] border bg-[#eff6ff] px-4 transition-colors focus-within:border-blue-500 focus-within:ring-2 focus-within:ring-blue-500/15 dark:bg-[#26416b]"
                            >
                                <input
                                    type="text"
                                    inputmode="numeric"
                                    autocomplete="off"
                                    x-model="accountId"
                                    placeholder="{{ __('products.detail_account_placeholder') }}"
                                    class="min-w-0 flex-1 bg-transparent text-base font-medium tracking-wider text-zinc-900 outline-none placeholder:text-zinc-400 dark:text-white"
                                >
                                <svg
                                    x-show="accountIdValid()"
                                    x-cloak
                                    class="h-5 w-5 shrink-0 text-emerald-600"
                                    fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"
                                    aria-hidden="true"
                                >
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5"/>
                                </svg>
                            </div>
                            <p
                                x-show="accountId.length > 0 && ! accountIdValid()"
                                x-cloak
                                class="mt-1 text-[11px] font-medium text-red-600"
                            >{{ __('products.detail_account_invalid') }}</p>
                        </div>
                    @endif

                    {{-- Recipient phone — top-ups only. The buyer enters the
                         number to credit before adding to cart; canAddToCart()
                         enforces a valid number. Country flag + dial code are
                         locked to the product's country_code via dial_codes.php. --}}
                    @if ($isTopup)
                        <div id="topup-recipient">
                            <label class="mb-1.5 flex items-center gap-1.5 text-xs font-semibold text-zinc-900 dark:text-zinc-100">
                                @if (Product::flagUrl($product->country_code))
                                    <img src="{{ Product::flagUrl($product->country_code) }}" alt="" class="h-3.5 w-5 rounded-[2px] object-cover ring-1 ring-zinc-200" loading="lazy">
                                @endif
                                {{ __('products.detail_phone_label', ['country' => $countryName]) }}
                            </label>
                            <div
                                :class="recipientPhoneValid()
                                    ? 'border-emerald-500 ring-2 ring-emerald-500/15'
                                    : (recipientPhone.length > 0 ? 'border-red-300' : 'border-zinc-200')"
                                class="flex h-[52px] w-full items-center gap-3 rounded-[12px] border bg-[#eff6ff] px-4 transition-colors focus-within:border-blue-500 focus-within:ring-2 focus-within:ring-blue-500/15 dark:bg-[#26416b]"
                            >
                                <span class="shrink-0 text-sm font-semibold text-zinc-600 dark:text-zinc-200" x-text="recipientDialCode">{{ $dialCode }}</span>
                                <input
                                    type="tel"
                                    inputmode="tel"
                                    autocomplete="tel-national"
                                    x-model="recipientPhone"
                                    placeholder="555 123 4567"
                                    class="min-w-0 flex-1 bg-transparent text-base font-medium text-zinc-900 outline-none placeholder:text-zinc-400 dark:text-white"
                                >
                                <svg
                                    x-show="recipientPhoneValid()"
                                    x-cloak
                                    class="h-5 w-5 shrink-0 text-emerald-600"
                                    fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"
                                    aria-hidden="true"
                                >
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5"/>
                                </svg>
                            </div>
                            <p
                                x-show="recipientPhone.length > 0 && ! recipientPhoneValid()"
                                x-cloak
                                class="mt-1 text-[11px] font-medium text-red-600"
                            >{{ __('products.detail_phone_invalid', ['country' => $countryName]) }}</p>
                        </div>
                    @endif

                    {{-- The points line, the stock notice and the two buttons. Gift cards carry
                         them in the purchase card under the art instead. --}}
                    @unless ($isGiftCard)
                        @include('shop._buy-actions')
                    @endunless

                    {{-- A gift card carries this line up with the heading instead. --}}
                    @unless ($isGiftCard)
                        @include('shop._country-availability')
                    @endunless
                    @unless ($isGiftCard)
                    </div>{{-- /top-up switcher ttab scope --}}
                    @endunless
                @else
                    @if ($isGiftCard)
                        @include('shop._brand-plate', ['plateClass' => 'mx-auto w-full max-w-[300px] self-start p-4', 'artWidth' => 'w-full'])
                    @endif
                    <div class="dash-shimmer pure-card rounded-[12px] bg-[#eff6ff] px-4 py-8 text-center border border-zinc-200 shadow-md shadow-zinc-900/[0.06] dark:border-zinc-700 dark:shadow-none">
                        <p class="text-base font-semibold text-zinc-900">{{ __('products.out_of_stock') }}</p>
                        <p class="mt-1 text-sm text-zinc-600">{{ $kindText('products.detail_no_amounts_gift_card', 'products.detail_no_amounts_topup', 'products.detail_no_amounts_bill') }}</p>
                    </div>
                    @if ($isGiftCard)
                        {{-- Nothing to buy, so no purchase card: close the second row, open the third. --}}
                        </div>
                        <div class="flex min-w-0 flex-col gap-5 max-lg:order-4 lg:col-start-1 lg:row-start-3">
                    @endif
                @endif

                {{-- Accordion sections: How to redeem / Terms / FAQ — INSIDE the right column so the
                     gift card stays alone on the left. No dividers between items. --}}
                <section class="mt-6 space-y-3">
            @if ($redeemUrl)
                {{-- Jump straight to the brand's redemption page in a new tab. --}}
                <a
                    href="{{ $redeemUrl }}"
                    target="_blank"
                    rel="noopener noreferrer"
                    class="mb-2 inline-flex items-center gap-2 rounded-[12px] bg-blue-600 px-4 py-3 text-sm font-semibold text-white transition-colors hover:bg-blue-700 focus:outline-none focus-visible:ring-2 focus-visible:ring-blue-500/40"
                >
                    {{ __('products.detail_redeem_at', ['brand' => $brandName]) }}
                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M13.5 6H5.25A2.25 2.25 0 003 8.25v10.5A2.25 2.25 0 005.25 21h10.5A2.25 2.25 0 0018 18.75V10.5m-10.5 6L21 3m0 0h-5.25M21 3v5.25"/>
                    </svg>
                </a>
            @endif

            @if ($product->redeem_instructions)
                <x-accordion :title="$categorySlug === 'gift-cards' ? __('products.detail_how_to_redeem') : __('products.detail_how_it_works')" :open="true">
                    <div class="[&>p]:mb-3 [&>ol]:list-decimal [&>ol]:pl-5 [&>ul]:list-disc [&>ul]:pl-5 [&>ol>li]:mb-1.5 [&>ul>li]:mb-1.5 [&_a]:text-blue-600 [&_a]:underline [&_a]:hover:text-blue-700">
                        {!! $linkify($product->redeem_instructions) !!}
                    </div>
                </x-accordion>
            @endif

            @if ($product->terms_and_conditions)
                <x-accordion :title="__('products.detail_terms')">
                    <div class="text-sm [&>p]:mb-3 [&>ol]:list-decimal [&>ol]:pl-5 [&>ul]:list-disc [&>ul]:pl-5 [&_a]:text-blue-600 [&_a]:underline">
                        {!! $linkify($product->terms_and_conditions) !!}
                    </div>
                </x-accordion>
            @endif

            @if ($redemptionVideo)
                <x-accordion :title="__('products.detail_walkthrough')">
                    <div class="overflow-hidden rounded-[12px] bg-zinc-900 ring-1 ring-zinc-100">
                        <video controls class="aspect-video w-full">
                            <source src="{{ $redemptionVideo }}">
                            {{ __('products.detail_video_unsupported') }}
                        </video>
                    </div>
                </x-accordion>
            @endif

            {{-- One row per question, so a reader opens only what they asked. --}}
            <h2 class="pt-4 text-lg font-bold text-zinc-900 dark:text-white">{{ __('products.detail_faq_heading') }}</h2>
            @php
                if ($isTopup) {
                    $faqs = [
                        [__('products.detail_faq_topup_speed_q'), __('products.detail_faq_topup_speed_a')],
                        [__('products.detail_faq_topup_numbers_q', ['brand' => $brandName]), __('products.detail_faq_topup_numbers_a', ['brand' => $brandName, 'country' => $countryName])],
                        [__('products.detail_faq_topup_failed_q'), __('products.detail_faq_topup_failed_a')],
                    ];
                } elseif ($isBill) {
                    $faqs = [
                        [__('products.detail_faq_bill_speed_q'), __('products.detail_faq_bill_speed_a')],
                        [__('products.detail_faq_bill_need_q', ['brand' => $brandName]), __('products.detail_faq_bill_need_a')],
                        [__('products.detail_faq_bill_failed_q'), __('products.detail_faq_bill_failed_a')],
                    ];
                } else {
                    $faqs = [
                        [__('products.detail_faq_gift_card_speed_q'), __('products.detail_faq_gift_card_speed_a')],
                        [__('products.detail_faq_gift_card_country_q', ['brand' => $brandName]), __('products.detail_faq_gift_card_country_a', ['country' => $countryName])],
                        [__('products.detail_faq_gift_card_failed_q'), __('products.detail_faq_gift_card_failed_a')],
                    ];
                }
            @endphp
            @foreach ($faqs as [$question, $answer])
                <x-accordion :title="$question">{{ $answer }}</x-accordion>
            @endforeach
                </section>
            @if ($isGiftCard)

            @if ($isGiftCard)
                {{-- A gift card reads its long-form copy in this column, under the
                     questions, so the purchase card beside it has room to ride the
                     scroll. The other kinds keep it full width below the grid. --}}
                        {{-- Long-form, data-driven brand copy. Everything here is derived from
                         THIS brand's real catalog data (denomination range, the countries it
                         sells in, category), so every brand page carries genuinely unique,
                         indexable text instead of a shared template - the fix for Search
                         Console "Crawled - currently not indexed" thin/near-duplicate pages. --}}
                    <section class="mt-14 border-t border-zinc-100 pt-10 dark:border-white/10">
                        <div class="max-w-3xl">
                            @php
                                $aboutReplace = ['brand' => $brandName, 'country' => $countryName, 'site' => $siteName, 'range' => (string) $rangeText];
                            @endphp

                            @if ($isGiftCard && $product->description)
                                {{-- The supplier's own words about the brand, kept out of the
                                     heading where they said nothing about the card itself. --}}
                                <div class="mt-4 text-base leading-relaxed text-zinc-600 [&>p]:mb-2 [&_a]:text-blue-600 [&_a]:underline dark:text-zinc-300">{!! $product->description !!}</div>
                            @endif
                            <h2 class="text-xl font-bold tracking-tight text-zinc-900 sm:text-2xl dark:text-white">
                                {{ $kindText('products.detail_about_heading_gift_card', 'products.detail_about_heading_topup', 'products.detail_about_heading_bill', $aboutReplace) }}
                            </h2>

                            <p class="mt-4 text-base leading-relaxed text-zinc-600 dark:text-zinc-300">
                                {{ $rangeText ? $kindText('products.detail_about_delivery_ranged_gift_card', 'products.detail_about_delivery_ranged_topup', 'products.detail_about_delivery_ranged_bill', $aboutReplace) : $kindText('products.detail_about_delivery_gift_card', 'products.detail_about_delivery_topup', 'products.detail_about_delivery_bill', $aboutReplace) }} {{ $kindText('products.detail_about_payment_gift_card', 'products.detail_about_payment_topup', 'products.detail_about_payment_bill') }} {{ __('products.detail_about_refund') }}
                            </p>

                            @if ($brandCountries->count() > 1)
                                @php
                                    $aboutReplace['countries'] = $brandCountries->map(fn ($c) => $countryNames[strtoupper((string) $c)] ?? $c)->reject(fn ($n) => $n === $countryName)->take(8)->join(', ', ' '.__('products.detail_list_and').' ');
                                @endphp
                                <p class="mt-4 text-base leading-relaxed text-zinc-600 dark:text-zinc-300">
                                    {{ $kindText('products.detail_about_countries_gift_card', 'products.detail_about_countries_topup', 'products.detail_about_countries_bill', $aboutReplace) }} {{ __('products.detail_about_switch_country') }}
                                </p>
                            @endif

                            @php
                                // The same three steps either way: choose the amount, pay, receive it.
                                // The "then redeem it at" tail is gift cards only; any other category
                                // that lands here gets the plain line.
                                $buyingSteps = [
                                    __('products.detail_step_title_amount') => $kindText('products.detail_step_amount_gift_card', 'products.detail_step_amount_topup', 'products.detail_step_amount_bill', $aboutReplace),
                                    __('products.detail_step_title_pay') => __('products.detail_step_pay'),
                                    __('products.detail_step_title_receive') => $isGiftCard ? __('products.detail_step_receive_gift_card', $aboutReplace) : $kindText('products.detail_step_receive_code', 'products.detail_step_receive_topup', 'products.detail_step_receive_bill'),
                                ];
                            @endphp

                            <h3 class="mt-8 text-lg font-bold text-zinc-900 dark:text-white">{{ $kindText('products.detail_how_to_gift_card', 'products.detail_how_to_topup', 'products.detail_how_to_bill', $aboutReplace) }}</h3>

                            {{-- One card per step, side by side on a wide screen and stacked on a
                                 phone, numbered so the order is still read as a sequence. --}}
                            <ol class="mt-4 grid gap-4 sm:grid-cols-3">
                                @foreach ($buyingSteps as $stepTitle => $stepBody)
                                    <li class="pure-card rounded-[12px] border border-zinc-200 bg-white p-5 dark:border-white/10">
                                        <span class="flex h-9 w-9 items-center justify-center rounded-full bg-blue-600 text-sm font-bold text-white" aria-hidden="true">{{ $loop->iteration }}</span>
                                        <p class="mt-3 text-base font-bold text-zinc-900 dark:text-white">{{ $stepTitle }}</p>
                                        <p class="mt-1 text-sm leading-relaxed text-zinc-600 dark:text-zinc-300">{{ $stepBody }}</p>
                                    </li>
                                @endforeach
                            </ol>
                        </div>
                    </section>
            @endif
                </div>{{-- /gift card lower details --}}
            @endif
            </div>{{-- /right column --}}
        </div>{{-- /grid --}}

        @unless ($isGiftCard)
                    {{-- Long-form, data-driven brand copy. Everything here is derived from
                     THIS brand's real catalog data (denomination range, the countries it
                     sells in, category), so every brand page carries genuinely unique,
                     indexable text instead of a shared template - the fix for Search
                     Console "Crawled - currently not indexed" thin/near-duplicate pages. --}}
                <section class="mt-14 border-t border-zinc-100 pt-10 dark:border-white/10">
                    <div class="max-w-3xl">
                        @php
                            $aboutReplace = ['brand' => $brandName, 'country' => $countryName, 'site' => $siteName, 'range' => (string) $rangeText];
                        @endphp
                        <h2 class="text-xl font-bold tracking-tight text-zinc-900 sm:text-2xl dark:text-white">
                            {{ $kindText('products.detail_about_heading_gift_card', 'products.detail_about_heading_topup', 'products.detail_about_heading_bill', $aboutReplace) }}
                        </h2>

                        <p class="mt-4 text-base leading-relaxed text-zinc-600 dark:text-zinc-300">
                            {{ $rangeText ? $kindText('products.detail_about_delivery_ranged_gift_card', 'products.detail_about_delivery_ranged_topup', 'products.detail_about_delivery_ranged_bill', $aboutReplace) : $kindText('products.detail_about_delivery_gift_card', 'products.detail_about_delivery_topup', 'products.detail_about_delivery_bill', $aboutReplace) }} {{ $kindText('products.detail_about_payment_gift_card', 'products.detail_about_payment_topup', 'products.detail_about_payment_bill') }} {{ __('products.detail_about_refund') }}
                        </p>

                        @if ($brandCountries->count() > 1)
                            @php
                                $aboutReplace['countries'] = $brandCountries->map(fn ($c) => $countryNames[strtoupper((string) $c)] ?? $c)->reject(fn ($n) => $n === $countryName)->take(8)->join(', ', ' '.__('products.detail_list_and').' ');
                            @endphp
                            <p class="mt-4 text-base leading-relaxed text-zinc-600 dark:text-zinc-300">
                                {{ $kindText('products.detail_about_countries_gift_card', 'products.detail_about_countries_topup', 'products.detail_about_countries_bill', $aboutReplace) }} {{ __('products.detail_about_switch_country') }}
                            </p>
                        @endif

                        @php
                            // The same three steps either way: choose the amount, pay, receive it.
                            // The "then redeem it at" tail is gift cards only; any other category
                            // that lands here gets the plain line.
                            $buyingSteps = [
                                __('products.detail_step_title_amount') => $kindText('products.detail_step_amount_gift_card', 'products.detail_step_amount_topup', 'products.detail_step_amount_bill', $aboutReplace),
                                __('products.detail_step_title_pay') => __('products.detail_step_pay'),
                                __('products.detail_step_title_receive') => $isGiftCard ? __('products.detail_step_receive_gift_card', $aboutReplace) : $kindText('products.detail_step_receive_code', 'products.detail_step_receive_topup', 'products.detail_step_receive_bill'),
                            ];
                        @endphp

                        <h3 class="mt-8 text-lg font-bold text-zinc-900 dark:text-white">{{ $kindText('products.detail_how_to_gift_card', 'products.detail_how_to_topup', 'products.detail_how_to_bill', $aboutReplace) }}</h3>

                        {{-- One card per step, side by side on a wide screen and stacked on a
                             phone, numbered so the order is still read as a sequence. --}}
                        <ol class="mt-4 grid gap-4 sm:grid-cols-3">
                            @foreach ($buyingSteps as $stepTitle => $stepBody)
                                <li class="pure-card rounded-[12px] border border-zinc-200 bg-white p-5 dark:border-white/10">
                                    <span class="flex h-9 w-9 items-center justify-center rounded-full bg-blue-600 text-sm font-bold text-white" aria-hidden="true">{{ $loop->iteration }}</span>
                                    <p class="mt-3 text-base font-bold text-zinc-900 dark:text-white">{{ $stepTitle }}</p>
                                    <p class="mt-1 text-sm leading-relaxed text-zinc-600 dark:text-zinc-300">{{ $stepBody }}</p>
                                </li>
                            @endforeach
                        </ol>
                    </div>
                </section>
        @endunless

        {{-- Similar brands --}}
        @if ($similar->isNotEmpty())
            <section class="mt-12">
                <div class="mb-4 flex items-baseline justify-between">
                    <h2 class="text-lg font-bold text-zinc-900">{{ __('products.detail_similar_in', ['country' => $countryName]) }}</h2>
                    <a href="{{ route($listingRoute, array_filter(['country' => $product->country_code, 'subcategory' => $product->subcategory?->slug])) }}" wire:navigate class="text-sm font-semibold text-zinc-900 underline underline-offset-2 transition-colors hover:text-blue-700 dark:text-white dark:hover:text-blue-300">
                        {{ __('products.view_all') }}
                    </a>
                </div>

                {{-- Horizontal carousel on mobile (scrollbar hidden); a static grid from sm up. --}}
                <div class="flex gap-3 overflow-x-auto pb-1 [scrollbar-width:none] [&::-webkit-scrollbar]:hidden sm:grid sm:grid-cols-4 sm:overflow-visible sm:pb-0 lg:grid-cols-6">
                    @foreach ($similar as $s)
                        {{-- Block form, not the inline parenthesised one: that compiled
                             here to an open tag with no space after it, which PHP does
                             not read as an open tag at all, so everything below stopped
                             being compiled and every product page 500'd. --}}
                        @php
                            $sLogo = Product::brandLogoUrl($s->brand_key, $s->logo_url);
                        @endphp
                        <a href="{{ route($detailRoute, ['brandSlug' => Product::brandSlug($s->brand_key), 'country' => $product->country_code]) }}" wire:navigate class="card-3d-scene group block w-36 shrink-0 sm:w-auto">
                            {{-- keep-light: same white plate, same reason as the hero above. --}}
                            <div
                                class="card-3d relative flex aspect-[16/10] items-center justify-center overflow-hidden rounded-[15px] keep-light bg-[#ffffff] shadow-sm ring-1 ring-zinc-200 group-hover:shadow-lg group-hover:ring-zinc-300"
                                x-data="cardTilt()"
                                @mousemove="tilt($event)"
                                @mouseleave="reset()"
                            >
                                @if ($sLogo)
                                    <img src="{{ $sLogo }}" alt="" class="h-full w-full object-cover" loading="lazy">
                                @else
                                    <span class="text-xl font-black uppercase text-[#3f3f46]">{{ str(Product::brandDisplayName($s->brand_key))->substr(0, 2)->upper() }}</span>
                                @endif
                                <span class="card-3d-glare pointer-events-none absolute inset-0" aria-hidden="true"></span>
                            </div>
                            <p class="mt-2 truncate text-[13px] font-bold text-zinc-900 group-hover:text-blue-700">{{ Product::brandDisplayName($s->brand_key) }}</p>
                        </a>
                    @endforeach
                </div>
            </section>
        @endif

        {{-- Customer reviews for THIS gift card: the score with the spread of
             ratings, then the reviews themselves. Self-guarding, so it renders
             nothing when the brand has no approved reviews. The order
             confirmation page keeps the carousel. --}}
        @if ($isGiftCard)
            <x-shop.product-reviews :brand-key="$brandKey" :brand-name="$brandName" />
        @endif


    <script>
        window.brandDetail = function ({ variants, rangeText, cryptos, defaultCrypto, markup, customRate, customMin, customMax, requiresRecipientPhone, defaultDialCode, requiresAccountId, rcoinConfig, purchasable }) {
            // `cryptos` is an array of {code, name, type, perUsd, decimals, icon} from the
            // admin currency_rates table. Reshape into a code-keyed object.
            const cryptoMap = {};
            (cryptos || []).forEach((c) => { cryptoMap[c.code] = c; });

            return {
                rcoinConfig,
                purchasable,          // server truth: category feature on AND product in stock
                variants,
                rangeText,
                markup,               // { type, value, min_margin_percent } from CartPricingService
                customRate,           // variant-currency → USD divisor for a variable amount
                amount: '',           // selected face value, in the card's own currency
                customMode: false,
                customMin,            // min/max for a custom amount, in the card's currency
                customMax,
                selectedVariantId: null,
                quantity: 1,
                cryptos: cryptoMap,
                selectedCrypto: cryptoMap[defaultCrypto] ? defaultCrypto : (cryptos[0]?.code ?? null),

                // Add-to-cart micro-interaction: idle -> loading -> success -> idle.
                cartState: 'idle',
                _cartTimer: null,

                // Mobile top-up: the buyer enters the phone number to credit on
                // the product page. The form below is only rendered when
                // requiresRecipientPhone is true; the digits live here.
                requiresRecipientPhone: !! requiresRecipientPhone,
                recipientPhone: '',                          // digits the buyer typed (no country code)
                recipientDialCode: defaultDialCode || '',    // e.g. '+1', shown as a prefix on the input

                // Bill payment: account / meter number the biller will credit.
                requiresAccountId: !! requiresAccountId,
                accountId: '',

                init() {
                    // Empty by default so the placeholder hint stays visible.
                },

                // Validation: digits + spaces + optional dashes, 6-15 digits when
                // stripped. Server re-validates with the same regex but more strict.
                recipientPhoneValid() {
                    if (! this.requiresRecipientPhone) { return true; }
                    const digits = (this.recipientPhone || '').replace(/[^0-9]/g, '');
                    return digits.length >= 6 && digits.length <= 15;
                },

                // E.164-ish string we ship to the server. Strips the user-visible
                // separators and prefixes the dial code, e.g. '+17575551234'.
                fullRecipientPhone() {
                    const digits = (this.recipientPhone || '').replace(/[^0-9]/g, '');
                    if (! digits) { return ''; }
                    const dial = (this.recipientDialCode || '').replace(/[^0-9+]/g, '');
                    return dial && ! digits.startsWith(dial.replace('+', ''))
                        ? dial + digits
                        : '+' + digits;
                },

                // Bill payment account-id validation. Most billers accept 6-20
                // characters of digits + optional separators. We don't restrict
                // hyphens/spaces since some operators format their meter IDs.
                accountIdValid() {
                    if (! this.requiresAccountId) { return true; }
                    const cleaned = (this.accountId || '').replace(/[^0-9A-Za-z]/g, '');
                    return cleaned.length >= 4 && cleaned.length <= 30;
                },

                canAddToCart() {
                    // Category feature off or product out of stock: never buyable. Mirrors
                    // the server-side block so the buttons visibly reflect what is enforced.
                    if (! this.purchasable) {
                        return false;
                    }
                    if (! this.selectedVariantId) {
                        return false;
                    }
                    if (this.customMode) {
                        // A custom amount must fall within the variable variant's range.
                        const value = Number(this.amount);
                        if (value < this.customMin || value > this.customMax) { return false; }
                    }
                    if (this.requiresRecipientPhone && ! this.recipientPhoneValid()) {
                        return false;
                    }
                    if (this.requiresAccountId && ! this.accountIdValid()) {
                        return false;
                    }
                    return true;
                },

                async addToCart(buyNow = false) {
                    // Ignore re-clicks while the spinner/success cue is still playing.
                    if (this.cartState !== 'idle') {
                        return false;
                    }
                    if (! this.canAddToCart()) {
                        return false;
                    }
                    // Variable variants pass the requested face value back in USD (the cart works in USD).
                    const requested = this.customMode
                        ? Number(this.amount) / (this.customRate || 1)
                        : null;

                    // Per-item context: recipient phone (top-ups) or account
                    // ID (bill payments). Persisted to cart→order metadata
                    // and consumed by the fulfilment provider.
                    let metadata = null;
                    if (this.requiresRecipientPhone) {
                        metadata = { recipient_phone: this.fullRecipientPhone() };
                    } else if (this.requiresAccountId) {
                        metadata = { account_id: (this.accountId || '').trim() };
                    }

                    this.cartState = 'loading';
                    const ok = await this.$store.cart.add(this.selectedVariantId, this.quantity || 1, requested, metadata, buyNow);

                    if (ok) {
                        // Hold the success cue briefly, then settle back to idle.
                        this.cartState = 'success';
                        clearTimeout(this._cartTimer);
                        this._cartTimer = setTimeout(() => { this.cartState = 'idle'; }, 1600);
                    } else {
                        this.cartState = 'idle';
                    }
                    return ok;
                },

                async buyNow() {
                    const ok = await this.addToCart(true);
                    if (ok) {
                        window.location.href = '{{ route('shop.checkout') }}';
                    }
                },

                // Payable USD price for one unit of the selected variant. Fixed
                // denominations use the precomputed price_usd (face_value + markup).
                // Variable amounts apply markup to the entered face value, matching
                // the backend's CartPricingService logic.
                unitPriceUsd() {
                    if (! this.selectedVariantId) {
                        return 0;
                    }
                    const variant = this.variants.find((v) => v.id === this.selectedVariantId);
                    if (! variant) {
                        return 0;
                    }
                    if (variant.is_variable) {
                        // The entered amount is the face value in the card's currency.
                        // Convert to USD, estimate the real supplier cost via the
                        // operator's premium, then apply markup (same as backend).
                        const faceUsd = Number(this.amount || 0) / (this.customRate || 1);
                        return this.applyMarkup(faceUsd * (this.markup.cost_premium || 1));
                    }
                    return variant.price_usd || 0;
                },

                // Mirror of CartPricingService::resolveRetailPrice — apply the
                // markup, then clamp to the min-margin floor.
                applyMarkup(costUsd) {
                    if (costUsd <= 0) {
                        return 0;
                    }
                    const retail = this.markup.type === 'fixed'
                        ? costUsd + Number(this.markup.value)
                        : costUsd * (1 + Number(this.markup.value) / 100);
                    const floor = costUsd * (1 + Number(this.markup.min_margin_percent || 0) / 100);
                    return Math.max(retail, floor);
                },

                // Payable USD total for the whole order.
                totalUsd() {
                    return this.unitPriceUsd() * (this.quantity || 1);
                },

                // Calculated from backend settings.
                pointsEarned() {
                    const cashbackUsd = this.totalUsd() * (this.rcoinConfig.cashback_percentage / 100);
                    return Math.floor(cashbackUsd / this.rcoinConfig.usd_rate);
                },

                // Estimated equivalent in the currently selected currency (fiat or crypto).
                formatCrypto() {
                    return this.formatCryptoFor(this.selectedCrypto);
                },

                formatCryptoFor(code) {
                    return this.formatUsdIn(code, this.totalUsd());
                },

                {{-- One USD figure in one pay currency. The total and every value
                     tile both come through here, so a tile can never show a figure
                     the total then contradicts. (A Blade comment, not a script one:
                     script comments ship in the page, in English, to every locale.) --}}
                formatUsdIn(code, usd) {
                    const meta = this.cryptos[code];
                    if (! meta) return '0 ' + code;
                    let value = Number(usd || 0) * meta.perUsd;
                    // Charm rounding for fiat, mirroring CharmPrice::roundUp -
                    // UP only, to the step the currency's people pay in
                    // (₦1,056.80 → ₦1,060). Crypto amounts stay exact.
                    if (meta.type !== 'crypto' && meta.perUsd >= 3) {
                        const step = meta.perUsd >= 100 ? 10 : 1;
                        value = Math.ceil(Number((value / step).toFixed(6))) * step;
                        return value.toLocaleString('en-US') + ' ' + code;
                    }
                    return value.toFixed(meta.decimals) + ' ' + code;
                },
            };
        };
    </script>

    {{-- Region notice lives on the checkout + order pages (amber strip), so we don't
         interrupt browsing with a modal here. Customers see the warning at the point
         it matters most — right before paying. --}}

</x-shop.layout>

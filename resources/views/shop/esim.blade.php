@php
    use App\Domain\Cart\Services\CartPricingService;
    use App\Models\Category;
    use App\Models\CurrencyRate;
    use App\Models\Product;
    use Illuminate\Support\Facades\Cache;
    use Illuminate\Support\Str;

    /** @var \App\Models\Product $product  An `esims`-category Product = one coverage region. */

    // Route name swaps between storefront + dashboard chrome so every internal
    // shop link keeps the user on whichever side they entered from.
    $inDash = request()->is('dashboard/shop*') && auth()->check();
    $shopRoute = fn (string $name, $params = []) => route(($inDash ? 'dashboard.shop.' : 'shop.').$name, $params);

    // Variants are merged across suppliers for the country by EsimStoreController;
    // fall back to the product's own when rendered without that controller.
    $variants = $variants ?? $product->variants;
    $hasPlans = $variants->isNotEmpty();
    $pricing  = app(CartPricingService::class);

    // Feature A/B: keep the region + plans visible but disable buying when the eSIMs
    // feature is off or the product is out of stock. The purchase is blocked server-side.
    $esimsEnabled   = \App\Support\FeatureFlag::on('esims');
    $productBuyable = $esimsEnabled && ! $product->is_out_of_stock;

    // Region name + scope from the shared classifier (suppliers mislabel every
    // region "Global eSIM", so non-country products derive their name from the slug).
    $regionMeta  = \App\Http\Controllers\EsimStoreController::regionMeta($product->country_code, $product->slug, $product->name);
    $regionLabel = $regionMeta['name'];
    $cc          = $regionMeta['cc'];
    $isLocal     = strlen($cc) === 2 && $cc !== 'WW';
    $flag        = $isLocal ? Product::flagUrl($cc) : \App\Http\Controllers\EsimStoreController::globalFlag();
    $curScope    = ucfirst($regionMeta['scope']);
    // Key used to mark the active entry in the (country-deduped) region picker.
    $currentKey  = $isLocal ? $cc : 'slug:'.$product->slug;

    // Generic placeholder network names that aren't real carriers (Zendit reports
    // "eSIM"); only real operator names (Airalo: "Orange", "T-Mobile") are shown.
    $cleanNetwork = function ($n) {
        $n = trim((string) $n);

        return ($n === '' || in_array(strtolower($n), ['esim', 'multiple', 'unknown'], true)) ? null : $n;
    };

    // Splits "1 GB" / "120 mins" / "100 SMS" into figure + unit so the row can style
    // the number and the unit separately. Bare strings like "Unlimited" stay in 'num'.
    $splitUnit = function (?string $s): array {
        $s = trim((string) $s);
        if ($s === '') {
            return ['num' => '', 'unit' => ''];
        }
        if (preg_match('~^([\d.,/]+)\s*(.*)$~', $s, $m)) {
            return ['num' => trim($m[1]), 'unit' => trim($m[2])];
        }

        return ['num' => $s, 'unit' => ''];
    };

    // Each variant is a plan. Airalo provides clean data_limit + reliable
    // validity_days + Voice/SMS; Zendit leaves those unreliable so we fall back to
    // its raw_payload (dataGB / durationDays / dataUnlimited / shortNotes).
    $plans = $variants->values()->map(function ($v) use ($pricing, $product, $cleanNetwork, $splitUnit) {
        // Each merged variant already carries its own product (for correct markup);
        // only fall back to the page product when the relation isn't set.
        if (! $v->relationLoaded('product') || ! $v->product) {
            $v->setRelation('product', $product);
        }
        $meta = $v->metadata ?? [];
        $raw  = $meta['raw_payload'] ?? [];

        $dl          = trim((string) ($meta['data_limit'] ?? ''));
        $unlimited   = (bool) ($raw['dataUnlimited'] ?? false);
        $isUnlimited = $unlimited || str_contains(strtolower($dl), 'unlimited');

        if ($isUnlimited) {
            $dataLabel = 'Unlimited';
        } elseif ($dl !== '' && strcasecmp($dl, 'Unknown') !== 0) {
            $dataLabel = $dl;
        } else {
            $gb        = (float) ($raw['dataGB'] ?? 0);
            $dataLabel = $gb > 0 ? rtrim(rtrim(number_format($gb, 2), '0'), '.').' GB' : 'Data';
        }

        $days = (int) ($meta['validity_days'] ?? 0);
        if ($days <= 0) {
            $days = (int) ($raw['durationDays'] ?? 0);
        }

        // Voice/SMS: real numeric allowance only (prefer the raw package field). No
        // "Included" placeholder — a plan is voice only if it actually carries minutes.
        $voiceVal = $raw['voice'] ?? ($meta['voice_limit'] ?? null);
        $smsVal   = $raw['text'] ?? ($meta['sms_limit'] ?? null);
        $hasVoice = is_numeric($voiceVal) && (float) $voiceVal > 0;
        $hasSms   = is_numeric($smsVal) && (float) $smsVal > 0;

        $dataParts  = $splitUnit($dataLabel);
        $voiceLabel = $hasVoice ? $voiceVal.' mins' : null;
        $smsLabel   = $hasSms ? $smsVal.' SMS' : null;
        $voiceParts = $hasVoice ? $splitUnit($voiceLabel) : ['num' => '', 'unit' => ''];
        $smsParts   = $hasSms ? $splitUnit($smsLabel) : ['num' => '', 'unit' => ''];

        return [
            'id'           => $v->id,
            'data'         => $dataLabel,
            'data_num'     => $dataParts['num'],
            'data_unit'    => $dataParts['unit'],
            'days'         => $days,
            'is_unlimited' => $isUnlimited,
            'is_voice'     => $hasVoice || $hasSms,
            'voice'        => $voiceLabel,
            'voice_num'    => $voiceParts['num'],
            'voice_unit'   => $voiceParts['unit'],
            'sms'          => $smsLabel,
            'sms_num'      => $smsParts['num'],
            'sms_unit'     => $smsParts['unit'],
            'note'         => $isUnlimited ? trim((string) ($raw['shortNotes'] ?? '')) : '',
            'network'      => $cleanNetwork($meta['network'] ?? null),
            'top_up'       => (bool) ($meta['is_rechargeable'] ?? ($meta['supports_topup'] ?? false)),
            'cost'         => round((float) $v->cost_price, 2),
            'price'        => round((float) $pricing->calculatePricing($v, 1)['unit_price_snapshot'], 2),
        ];
    })->values();

    // Suppliers list the same offering twice (e.g. Airalo's "discover" and "discover+"
    // at the same retail price). Keep one per data+days+voice+sms — the cheapest.
    $plans = $plans
        ->sortBy(fn ($p) => sprintf('%012.2f|%012.2f', $p['price'], $p['cost']))
        ->unique(fn ($p) => ($p['is_voice'] ? 'v' : 'd').'|'.$p['days'].'|'.strtolower($p['data']).'|'.$p['voice'].'|'.$p['sms'])
        ->values();

    // Coverage list for multi-country (regional/global) eSIMs. Zendit stores ISO
    // codes in metadata.coverage; Airalo lists country names inside the package HTML.
    // All packages of a product share coverage, so the first one with data is enough.
    $isoToName = array_flip(config('countries.codes', []));
    $coverageList = collect();
    foreach ($variants as $v) {
        $meta = $v->metadata ?? [];
        foreach ((array) ($meta['coverage'] ?? []) as $c) {
            $code = strtoupper((string) $c);
            $coverageList->push($isoToName[$code] ?? $code);
        }
        if ($coverageList->isEmpty()) {
            $html = $meta['raw_payload']['qr_installation'] ?? ($meta['raw_payload']['manual_installation'] ?? '');
            if ($html && preg_match('~Coverage:\s*</b>\s*(.*?)</p>~is', $html, $cm)) {
                foreach (preg_split('~,|\sand\s~i', strip_tags($cm[1])) as $name) {
                    $name = trim($name, " .\t\n\r\0\x0B");
                    if ($name !== '') {
                        $coverageList->push($name);
                    }
                }
            }
        }
        if ($coverageList->isNotEmpty()) {
            break;
        }
    }
    $coverageList = $coverageList->unique()->sort()->values();
    $coverageCount = $coverageList->count();

    // Real carrier networks (e.g. T-Mobile 5G, Verizon 5G) captured from the supplier
    // per operator. Deduped by carrier name. We deliberately do NOT fall back to
    // metadata.network — that field holds Airalo's operator brand name ("Change",
    // "Change+", "Eurolink", "Merhaba"…), which is product marketing, not a carrier.
    $carrierNetworks = $variants
        ->flatMap(fn ($v) => (array) ($v->metadata['networks_detail'] ?? []))
        ->filter(fn ($n) => is_array($n) && ! empty($n['name']))
        ->unique(fn ($n) => $n['name'])
        ->values();

    // Per-country network map for the Countries & Networks modal - Airalo
    // ships this inside plan details (operator coverages), captured by the
    // sync as metadata.coverage_networks. First variant with data wins.
    $nameToIso = config('countries.codes', []);
    $countryNetworks = collect();
    foreach ($variants as $v) {
        $rows = (array) (($v->metadata ?? [])['coverage_networks'] ?? []);
        if ($rows === []) {
            continue;
        }
        $countryNetworks = collect($rows)
            ->filter(fn ($r) => is_array($r) && ! empty($r['country']))
            ->map(function ($r) use ($isoToName) {
                $raw = trim((string) $r['country']);
                $iso = strlen($raw) === 2 ? strtoupper($raw) : null;

                return [
                    'name' => $iso ? ($isoToName[$iso] ?? $raw) : $raw,
                    'iso' => $iso,
                    'networks' => array_values(array_filter((array) ($r['networks'] ?? []), fn ($n) => is_array($n) && ! empty($n['name']))),
                ];
            })
            ->unique('name')
            ->sortBy('name')
            ->values();
        break;
    }

    // The per-country map is the richest coverage source - when present it
    // supersedes the legacy coverage list (Airalo products often have an
    // empty metadata.coverage, which used to leave coverageCount at 0 and
    // route the modal to the flat carrier list).
    if ($countryNetworks->isNotEmpty()) {
        $coverageList = $countryNetworks->pluck('name');
        $coverageCount = $coverageList->count();
    }

    // Supplier plan-policy details for the Package details modal - activation
    // policy, rechargeability and the provider's own info bullets, all pulled
    // from the synced plan metadata (no invented copy: sections without real
    // data simply don't render).
    $planPolicy = ['activation' => null, 'rechargeable' => null, 'bullets' => collect(), 'other' => null];
    foreach ($variants as $v) {
        $m = $v->metadata ?? [];
        $planPolicy['activation'] = $planPolicy['activation'] ?? ($m['activation_policy'] ?? null);
        $planPolicy['rechargeable'] = $planPolicy['rechargeable'] ?? (array_key_exists('is_rechargeable', $m) ? (bool) $m['is_rechargeable'] : null);
        if ($planPolicy['bullets']->isEmpty() && ! empty($m['operator_info'])) {
            $planPolicy['bullets'] = collect((array) $m['operator_info'])->filter(fn ($b) => is_scalar($b) && trim((string) $b) !== '')->values();
        }
        $planPolicy['other'] = $planPolicy['other'] ?? (is_scalar($m['other_info'] ?? null) ? trim((string) $m['other_info']) : null);
    }
    $activationText = match ($planPolicy['activation']) {
        'first-usage' => 'The validity period starts when the eSIM connects to a supported network in its coverage area - not at purchase. If you install it outside the coverage area, it activates when you arrive.',
        'installation' => 'The validity period starts as soon as the eSIM is installed on your device.',
        'automatic' => 'The eSIM activates automatically once installed and connected to a supported network.',
        default => null,
    };

    $anyTopUp = $plans->contains(fn ($p) => $p['top_up']);

    // Region picker — one entry per country (deduped across suppliers); regional and
    // global eSIMs stay per-product. Selecting one opens that country's merged page.
    $esimRegions = Cache::remember('esim-regions-dropdown-v3', now()->addMinutes(10), function () {
        $cat = Category::where('slug', 'esims')->first();

        return Product::query()
            ->where('is_active', true)
            ->when($cat, fn ($q) => $q->where('category_id', $cat->id))
            ->orderBy('name')
            ->get(['slug', 'name', 'country_code'])
            ->groupBy(function ($r) {
                $cc = strtoupper((string) $r->country_code);

                return (strlen($cc) === 2 && $cc !== 'WW') ? $cc : 'slug:'.$r->slug;
            })
            ->map(function ($group) {
                $r = $group->first();
                $cc = strtoupper((string) $r->country_code);
                $isLocal = strlen($cc) === 2 && $cc !== 'WW';

                return [
                    'key'  => $isLocal ? $cc : 'slug:'.$r->slug,
                    'slug' => $r->slug,
                    'name' => \App\Http\Controllers\EsimStoreController::regionMeta($r->country_code, $r->slug, $r->name)['name'],
                    'flag' => $isLocal ? Product::flagUrl($cc) : \App\Http\Controllers\EsimStoreController::globalFlag(),
                ];
            })
            ->sortBy('name')
            ->values();
    });

    // Estimated-price currency selector (admin-managed fiat + crypto rates).
    $currencyFlagOverrides = ['XAF' => 'CM', 'XOF' => 'SN', 'XCD' => 'AG'];
    $currencyFlag = fn (string $code) => Product::flagUrl($currencyFlagOverrides[strtoupper($code)] ?? substr(strtoupper($code), 0, 2));

    // Only true glyph symbols. Currencies without one (XAF, AED, KES, crypto…) show
    // their code instead — e.g. "8.50 XAF" rather than an abbreviation.
    $currencySymbols = [
        'USD' => '$', 'EUR' => '€', 'GBP' => '£', 'NGN' => '₦', 'ZAR' => 'R', 'GHS' => '₵',
        'JPY' => '¥', 'CNY' => '¥', 'INR' => '₹', 'TRY' => '₺', 'KRW' => '₩', 'THB' => '฿',
        'PHP' => '₱', 'VND' => '₫', 'BRL' => 'R$', 'AUD' => 'A$', 'CAD' => 'CA$', 'MXN' => 'MX$',
        'HKD' => 'HK$', 'SGD' => 'S$', 'TWD' => 'NT$',
    ];

    $cryptoRatesForJs = CurrencyRate::active()
        ->orderBy('type')->orderBy('sort_order')->orderBy('code')
        ->get(['code', 'name', 'type', 'rate_per_usd', 'icon_path'])
        ->map(fn ($r) => [
            'code'     => $r->code,
            'name'     => $r->name,
            'type'     => $r->type,
            'perUsd'   => (float) $r->rate_per_usd,
            'decimals' => $r->rate_per_usd < 0.01 ? 8 : ($r->rate_per_usd < 1 ? 4 : 2),
            'icon'     => ($r->type === 'crypto' && $r->icon_path) ? asset('assets/'.$r->icon_path) : null,
            'flag'     => $r->type === 'fiat' ? $currencyFlag($r->code) : null,
            'symbol'   => $r->type === 'fiat' ? ($currencySymbols[strtoupper($r->code)] ?? null) : null,
        ])
        ->values();

    $countryCurrency = strtoupper(config('country_currency.map.'.$cc, 'USD'));
    $defaultCrypto = $cryptoRatesForJs->firstWhere('code', $countryCurrency)
        ? $countryCurrency
        : ($cryptoRatesForJs->firstWhere('code', 'USD') ? 'USD' : ($cryptoRatesForJs->first()['code'] ?? 'USDT'));

    // One list of MANUFACTURERS, not an iOS/Android split. A buyer knows the make
    // of their phone, not which of our two lists we filed it under, so the modal
    // asks for the make. Apple carries what the config keeps as the flat iOS
    // list; the Android brands come through as they already are. Counts are read
    // off this same structure, so the figures can never disagree with the list.
    $compatRaw = config('esim_compatibility', ['ios' => [], 'android' => []]);

    $compatBrands = collect($compatRaw['android'] ?? [])
        ->when(! empty($compatRaw['ios']), fn ($b) => $b->put('Apple', $compatRaw['ios']))
        ->map(fn ($models) => collect($models)->unique()->values()->all())
        ->sortKeys(SORT_NATURAL | SORT_FLAG_CASE);

    $compatModelCount = $compatBrands->sum(fn ($models) => count($models));

    // Regional + global eSIMs for the "Need broader coverage?" section under the
    // plan selector — sourced from the shared catalog summary (deduped, cached).
    // Figures on the "why travelers choose us" card, read from the catalogue and
    // the language picker rather than typed. A typed number cannot be wrong
    // loudly, only quietly: that card claimed "80+ languages" while the picker
    // has only ever offered English and French, and "200+ destinations" was
    // right by luck rather than by construction.
    $whyDestinations = \App\Http\Controllers\EsimStoreController::catalogSummary()->count();
    $whyLanguages = array_values(\App\Support\Languages::names());
    $whyLanguageLine = count($whyLanguages) > 1
        ? 'Pay in your currency or crypto, with the store in '.\Illuminate\Support\Arr::join($whyLanguages, ', ', ' and ')
        : 'Pay in your currency or crypto';

    // Never the destination being viewed. "Need broader coverage?" offers a way
    // OUT of this plan, so listing this plan in it is a link back to the page
    // you are already on.
    //
    // Matched on the SLUG alone. Matching the country code as well looked safer
    // and was not: regional and global rows share one non-country code, so on
    // the worldwide plan's own page that test rejected every row and emptied the
    // section instead of dropping the single card meant to go.
    $broaderCoverage = \App\Http\Controllers\EsimStoreController::catalogSummary()
        ->whereIn('scope', ['regional', 'global'])
        ->reject(fn ($b) => $b['slug'] === $product->slug)
        ->sortBy('from')
        ->take(6)
        ->values();
@endphp

<x-shop.layout :title="$regionLabel . ' eSIM | '.$siteName" :og-image="asset('assets/'.rawurlencode('Esim.webp'))">

    {{-- translate="no": the page translator (Google) rewrites text nodes, which
         corrupts Alpine's reactive <template x-for> package list (it renders then
         vanishes). Excluding the interactive store keeps it stable; the rest of the
         site still translates. --}}
    <div
        x-data="esimStore({
            plans: @js($plans),
            cryptos: @js($cryptoRatesForJs),
            defaultCrypto: @js($defaultCrypto),
            rcoinConfig: @js([
                'enabled' => (bool) \App\Models\Setting::get('rcoin_enabled', true),
                'cashback_percentage' => (float) \App\Models\Setting::get('cashback_percentage', 0.5),
                'usd_rate' => (float) \App\Models\Setting::rcoinUsdRate(),
            ]),
            checkoutUrl: '{{ route('shop.checkout') }}',
            purchasable: @js($productBuyable),
        })"
        x-init="init()"
        translate="no"
        class="notranslate mx-auto w-full max-w-[1150px] px-4 pb-32 pt-6 sm:px-6 lg:pt-10"
    >

        {{-- ── Breadcrumb (aligned with the 1100px cards) ────────────────────── --}}
        <nav class="mx-auto flex w-full max-w-[1100px] flex-wrap items-center gap-1.5 text-sm text-zinc-500 dark:text-zinc-400" aria-label="Breadcrumb">
            <a href="{{ $shopRoute('esims') }}" wire:navigate class="font-medium transition-colors hover:text-zinc-900 dark:hover:text-white">eSIM Store</a>
            <span aria-hidden="true">&rsaquo;</span>
            <a href="{{ $shopRoute('esims', ['scope' => strtolower($curScope)]) }}" wire:navigate class="transition-colors hover:text-zinc-900 dark:hover:text-white">{{ $curScope }} eSIMs</a>
            <span aria-hidden="true">&rsaquo;</span>
            <span class="font-semibold text-zinc-900 dark:text-white">{{ $regionLabel }}</span>
        </nav>

        {{-- ── Country header (glass card, centered) ─────────────────────── --}}
        {{-- z-20: backdrop-blur creates a stacking context - without lifting it
             above the plan selector (also a blur context, later in DOM), the
             region picker dropdown gets clipped behind the plans card. --}}
        <div class="esim-tile relative z-20 mx-auto mt-4 w-full max-w-[1100px] rounded-[12px] bg-[#ffffff] p-6 ring-1 ring-zinc-200 sm:p-8 dark:ring-zinc-700/60">
            <div class="flex flex-col gap-5 sm:flex-row sm:items-start sm:justify-between">
                <div class="min-w-0">
                    <div class="flex items-center gap-3">
                        @if ($flag)
                            <img src="{{ $flag }}" alt="" class="h-9 w-12 shrink-0 rounded-[4px] object-cover ring-1 ring-zinc-200 dark:ring-zinc-700">
                        @else
                            <span class="flex h-9 w-12 shrink-0 items-center justify-center rounded-[4px] bg-[#f4f6f5] ring-1 ring-zinc-200 dark:bg-blue-950/40 dark:ring-zinc-700">
                                <svg class="h-5 w-5 text-blue-600 dark:text-blue-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75" aria-hidden="true">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 21a9 9 0 100-18 9 9 0 000 18zm0 0c2.5 0 4.5-4.03 4.5-9S14.5 3 12 3 7.5 7.03 7.5 12s2 9 4.5 9zM3.6 9h16.8M3.6 15h16.8"/>
                                </svg>
                            </span>
                        @endif
                        <h1 class="truncate text-2xl font-bold tracking-tight text-zinc-900 sm:text-3xl dark:text-white">{{ $regionLabel }} eSIMs</h1>
                    </div>

                    <div class="my-5 h-px bg-[#f4f6f5] dark:bg-zinc-700/60"></div>

                    {{-- Supported networks (real provider data) --}}
                    <div class="flex flex-wrap items-center gap-3">
                        @if ($coverageCount > 1)
                            <button type="button" @click="showNetworks = true" class="inline-flex items-center gap-2 text-sm font-semibold text-zinc-900 underline-offset-2 hover:underline dark:text-white">
                                <svg class="h-4 w-4 text-zinc-500 dark:text-zinc-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75" aria-hidden="true">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 21a9 9 0 100-18 9 9 0 000 18zm0 0c2.5 0 4.5-4.03 4.5-9S14.5 3 12 3 7.5 7.03 7.5 12s2 9 4.5 9zM3.6 9h16.8M3.6 15h16.8"/>
                                </svg>
                                {{ $coverageCount }} Countries and Networks
                            </button>
                        @elseif ($carrierNetworks->isNotEmpty())
                            <span class="inline-flex items-center gap-2 text-sm font-semibold text-zinc-900 dark:text-white">
                                <img src="{{ asset('assets/'.rawurlencode('Network Svg.svg')) }}" alt="" class="h-4 w-4 dark:invert" aria-hidden="true">
                                {{ $carrierNetworks->first()['name'] }}
                            </span>
                            <button type="button" @click="showNetworks = true" class="text-sm font-semibold text-blue-600 transition-colors hover:text-blue-700 dark:text-blue-400 dark:hover:text-blue-300">Networks</button>
                        @endif
                    </div>

                    {{-- Check compatibility --}}
                    <div class="mt-4">
                        <button type="button" @click="showCompat = true" class="inline-flex items-center gap-2 rounded-[12px] bg-blue-600 px-4 py-2 text-sm font-semibold text-white transition-colors hover:bg-blue-700 focus:outline-none focus-visible:ring-2 focus-visible:ring-blue-500/40">
                            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75" aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M10.5 1.5H8.25A2.25 2.25 0 006 3.75v16.5a2.25 2.25 0 002.25 2.25h7.5A2.25 2.25 0 0018 20.25V3.75a2.25 2.25 0 00-2.25-2.25H13.5m-3 0V3h3V1.5m-3 0h3m-3 18.75h3"/>
                            </svg>
                            Check compatibility
                        </button>
                    </div>

                    {{-- Reassurance notes --}}
                    <ul class="mt-5 space-y-2 text-sm text-zinc-600 dark:text-zinc-400">
                        @if ($anyTopUp)
                            <li class="flex items-center gap-2">
                                <svg class="h-4 w-4 shrink-0 text-emerald-600 dark:text-emerald-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5"/></svg>
                                {{-- Stated, not linked. It pointed at the airtime
                                     top-ups page, which is a different product;
                                     an eSIM is topped up from the order itself.

                                     The whole sentence is ONE span on purpose. Left
                                     as a bare text node beside a second span, the
                                     two became separate flex items, so "top up" was
                                     laid out as its own column and stranded on the
                                     right once the line wrapped. --}}
                                <span>If you're running low, you can always <span class="font-semibold text-zinc-900 dark:text-white">top up</span></span>
                            </li>
                        @endif
                        <li class="flex items-center gap-2">
                            <svg class="h-4 w-4 shrink-0 text-emerald-600 dark:text-emerald-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5"/></svg>
                            The package starts when you connect to a supported network
                        </li>
                    </ul>

                    {{-- Wrong-destination guard: this eSIM only works in its own
                         coverage area, so steer anyone not travelling here to the
                         worldwide Discover Global eSIM. Hidden on that page itself. --}}
                    @php
                        $discoverGlobalSlug = 'esim-ww-discover-global';
                        $isDiscoverGlobal = $product->slug === $discoverGlobalSlug || strtoupper((string) $product->country_code) === 'WW';
                    @endphp
                    @unless ($isDiscoverGlobal)
                        <div class="mt-5 flex items-start gap-2.5 rounded-[12px] bg-amber-50 p-3.5 ring-1 ring-amber-200 dark:bg-amber-500/10 dark:ring-amber-500/30">
                            <svg class="mt-0.5 h-4 w-4 shrink-0 text-amber-600 dark:text-amber-300" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.9" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m9-.75a9 9 0 11-18 0 9 9 0 0118 0zm-9 3.75h.008v.008H12v-.008z"/></svg>
                            <p class="text-sm leading-relaxed text-amber-800 dark:text-amber-200">
                                Only buy this if you're in <span class="font-semibold">{{ $regionLabel }}</span> or travelling there - it only works on {{ $regionLabel }} networks. Not headed there?
                                <a href="{{ $shopRoute('esim', $discoverGlobalSlug) }}" wire:navigate class="font-semibold text-amber-900 underline underline-offset-2 hover:text-amber-950 dark:text-white dark:hover:text-blue-200">Buy Discover Global instead</a> for worldwide coverage.
                            </p>
                        </div>
                    @endunless

                </div>

                {{-- Region switcher --}}
                <div class="w-full shrink-0 sm:w-64">
                    <label class="mb-1.5 block text-xs font-semibold text-zinc-900 dark:text-zinc-200">Change country or region</label>
                    <div x-data="{ open: false, search: '' }" @click.outside="open = false; search = ''" @keydown.escape.window="open = false; search = ''" class="relative">
                        <button
                            type="button"
                            @click="open = ! open; if (open) $nextTick(() => $refs.regionSearch?.focus())"
                            {{-- White edge in dark mode, not zinc-700: on the dark
                                 panel that border was near invisible, so the
                                 control did not read as a control. --}}
                            :class="open ? 'border-blue-500 ring-2 ring-blue-500/15' : 'border-zinc-300 hover:border-zinc-400 dark:border-white dark:hover:border-white'"
                            class="flex h-[46px] w-full items-center gap-2 rounded-[12px] border bg-transparent px-3 text-sm font-medium text-zinc-900 outline-none transition-colors dark:text-white"
                            aria-haspopup="listbox"
                            :aria-expanded="open.toString()"
                        >
                            @if ($flag)
                                <img src="{{ $flag }}" alt="" class="h-3.5 w-5 shrink-0 rounded-[2px] object-cover ring-1 ring-zinc-200 dark:ring-zinc-700">
                            @endif
                            <span class="flex-1 truncate text-left">{{ $regionLabel }}</span>
                            <svg class="h-4 w-4 shrink-0 text-zinc-600 transition-transform duration-150 dark:text-zinc-400" :class="{ 'rotate-180': open }" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7"/></svg>
                        </button>
                        <div
                            x-show="open"
                            x-transition:enter="transition ease-out duration-150" x-transition:enter-start="opacity-0 -translate-y-1" x-transition:enter-end="opacity-100 translate-y-0"
                            style="display:none;"
                            {{-- Solid surface on purpose: a `backdrop-filter` here plus
                                 the `overflow-hidden` needed for the rounded corners is a
                                 known iOS Safari combo that kills touch-scrolling of the
                                 list inside. Opaque bg (matching the selector card below)
                                 keeps the country list natively scrollable on mobile. --}}
                            class="absolute left-0 right-0 top-full z-50 mt-2 overflow-hidden rounded-[12px] bg-white shadow-xl shadow-zinc-900/15 ring-1 ring-zinc-200 dark:bg-[#0c1a36] dark:shadow-black/40 dark:ring-1 dark:ring-white/10"
                        >
                            <div class="border-b border-zinc-200 p-2 dark:border-white/10">
                                <div class="relative">
                                    <svg class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-zinc-500 dark:text-zinc-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg>
                                    <input x-ref="regionSearch" x-model="search" type="text" placeholder="Search a country or region" aria-label="Search a country or region" class="w-full rounded-[12px] border border-zinc-300 bg-white py-2.5 pl-9 pr-3 text-sm text-zinc-900 placeholder:text-zinc-500 outline-none transition-colors focus:border-blue-500 focus:ring-2 focus:ring-blue-500/15 dark:border-zinc-700 dark:bg-[#26416b] dark:text-white dark:placeholder:text-zinc-400">
                                </div>
                            </div>
                            <div
                                class="max-h-72 overflow-y-auto overscroll-contain p-1 [-webkit-overflow-scrolling:touch]"
                                role="listbox"
                                aria-label="Choose a country or region"
                            >
                                @foreach ($esimRegions as $r)
                                    <a
                                        href="{{ $shopRoute('esim', $r['slug']) }}"
                                        wire:navigate
                                        role="option"
                                        aria-selected="{{ $r['key'] === $currentKey ? 'true' : 'false' }}"
                                        data-name="{{ Str::lower($r['name']) }}"
                                        x-show="search === '' || $el.dataset.name.includes(search.toLowerCase())"
                                        @class([
                                            'flex w-full items-center gap-2.5 rounded-[12px] px-3 py-2 text-left text-sm font-medium transition-colors',
                                            'bg-[#f4f6f5] text-blue-700 dark:bg-blue-600/15 dark:text-blue-300' => $r['key'] === $currentKey,
                                            'text-zinc-700 hover:bg-[#f4f6f5] dark:text-zinc-300 dark:hover:bg-[#26416b]' => $r['key'] !== $currentKey,
                                        ])
                                    >
                                        @if ($r['flag'])
                                            <img src="{{ $r['flag'] }}" alt="" class="h-3.5 w-5 shrink-0 rounded-[2px] object-cover ring-1 ring-zinc-200 dark:ring-zinc-700" loading="lazy">
                                        @else
                                            <span class="h-3.5 w-5 shrink-0 rounded-[2px] bg-[#f4f6f5] ring-1 ring-zinc-200 dark:bg-[#26416b] dark:ring-white/10"></span>
                                        @endif
                                        <span class="flex-1 truncate">{{ $r['name'] }}</span>
                                    </a>
                                @endforeach
                            </div>
                        </div>
                    </div>

                    {{-- Quick reassurance badges under the region picker. --}}
                    <div class="mt-5 space-y-3">
                        @php
                            // Built here, not inside the @foreach, because the third
                            // line is conditional and Blade's @if cannot live inside a
                            // PHP array literal: it compiles to an if statement dropped
                            // into the middle of the array and takes the whole page down
                            // with a parse error.
                            //
                            // That line used to read "180+ countries and territories" on
                            // every page. It contradicted the live count in this same
                            // panel (173 on the worldwide plan) and on a single-country
                            // eSIM it was simply false, so it now states the plan's real
                            // reach and is dropped where the plan covers one country.
                            $headerFeatures = [
                                ['t' => 'Instant activation', 'icon' => 'M3.75 13.5l10.5-11.25L12 10.5h8.25L9.75 21.75 12 13.5H3.75z'],
                                ['t' => 'No ID required', 'icon' => 'M9 12.75L11.25 15 15 9.75m-3-7.036A11.959 11.959 0 013.598 6 11.99 11.99 0 003 9.749c0 5.592 3.824 10.29 9 11.623 5.176-1.332 9-6.03 9-11.622 0-1.31-.21-2.571-.598-3.751h-.152c-3.196 0-6.1-1.248-8.25-3.285z'],
                            ];

                            if ($coverageCount > 1) {
                                $headerFeatures[] = [
                                    't' => 'Covers '.number_format($coverageCount).' countries and territories',
                                    'icon' => 'M12 21a9 9 0 100-18 9 9 0 000 18zm0 0c2.5 0 4.5-4.03 4.5-9S14.5 3 12 3 7.5 7.03 7.5 12s2 9 4.5 9zM3.6 9h16.8M3.6 15h16.8',
                                ];
                            }
                        @endphp
                        @foreach ($headerFeatures as $feat)
                            <div class="flex items-center gap-2.5 text-sm font-semibold text-zinc-900 dark:text-white">
                                <svg class="h-5 w-5 shrink-0 text-blue-600 dark:text-blue-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="{{ $feat['icon'] }}"/></svg>
                                {{ $feat['t'] }}
                            </div>
                        @endforeach
                    </div>
                </div>
            </div>
        </div>

        @unless ($productBuyable)
            <div class="mx-auto mt-6 flex w-full max-w-[1100px] items-start gap-3 rounded-[12px] border px-4 py-3 {{ $product->is_out_of_stock ? 'border-zinc-200 bg-[#ffffff] dark:border-zinc-700 dark:bg-white/5' : 'border-amber-200 bg-amber-50 dark:border-amber-500/20 dark:bg-amber-500/10' }}">
                <svg class="mt-0.5 h-5 w-5 shrink-0 {{ $product->is_out_of_stock ? 'text-zinc-400' : 'text-amber-500' }}" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m9-.75a9 9 0 11-18 0 9 9 0 0118 0zm-9 3.75h.008v.008H12v-.008z"/>
                </svg>
                <div>
                    <p class="text-sm font-semibold {{ $product->is_out_of_stock ? 'text-zinc-900 dark:text-white' : 'text-amber-900 dark:text-amber-200' }}">{{ $product->is_out_of_stock ? 'Out of stock' : 'eSIMs are temporarily unavailable' }}</p>
                    <p class="mt-0.5 text-xs {{ $product->is_out_of_stock ? 'text-zinc-500 dark:text-zinc-400' : 'text-amber-800/80 dark:text-amber-200/70' }}">You can view the plans, but purchases are paused right now. Please check back soon.</p>
                </div>
            </div>
        @endunless

        @if ($hasPlans)
            {{-- ── Selector card (glass): holds the data/voice + fixed/unlimited
                 segmented controls only. The plan cards live outside it, below. ── --}}
            <div id="esim-packages" class="mx-auto mt-6 w-full max-w-[1100px] scroll-mt-24 overflow-hidden rounded-[14px] bg-[#ffffff] border border-zinc-200 dark:bg-[#0c1a36] dark:border-zinc-700">
                <div class="p-5 sm:p-6">
                    {{-- Category selector (Data Only / Data + Calls + Texts) - segmented control with
                         a smooth sliding bubble. The bubble width adapts to however
                         many categories this region actually carries. --}}
                    <div class="relative flex rounded-[12px] bg-[#f4f6f5] p-1 dark:bg-[#26416b]" role="tablist">
                        <div
                            class="pointer-events-none absolute inset-y-1 left-1 rounded-[8px] bg-white shadow-sm transition-transform duration-300 ease-out dark:border dark:border-white dark:bg-[#1d3252]"
                            :style="`width: calc((100% - 0.5rem) / ${tabList().length}); transform: translateX(${tabIndex() * 100}%)`"
                        ></div>
                        <template x-for="t in tabList()" :key="t">
                            <button
                                type="button"
                                @click="setTab(t)"
                                :class="activeTab === t ? 'text-zinc-900 dark:text-white' : 'text-zinc-500 hover:text-zinc-900 dark:text-zinc-300 dark:hover:text-white'"
                                {{-- Tighter padding and leading on small screens: the
                                     voice label is three words now and would wrap
                                     mid-phrase inside a half-width tab on a phone. --}}
                                class="relative z-10 flex-1 rounded-[8px] px-2 py-2.5 text-sm font-bold leading-tight transition-colors sm:px-4"
                                x-text="t === 'voice' ? 'Data / Calls / Texts' : 'Data Only'"
                            ></button>
                        </template>
                    </div>

                    {{-- Fixed data / Unlimited selector - same sliding-bubble control,
                         only shown when the category carries both tiers. --}}
                    <div x-show="showModeToggle()" class="relative mt-3 flex rounded-[12px] bg-[#f4f6f5] p-1 dark:bg-[#26416b]" role="tablist">
                        <div
                            class="pointer-events-none absolute inset-y-1 left-1 w-[calc((100%_-_0.5rem)/2)] rounded-[8px] bg-blue-600 shadow-sm transition-transform duration-300 ease-out"
                            :style="`transform: translateX(${dataMode === 'unlimited' ? 100 : 0}%)`"
                        ></div>
                        <button type="button" @click="setMode('standard')" :class="dataMode === 'standard' ? 'text-white' : 'text-zinc-600 hover:text-zinc-900 dark:text-zinc-300 dark:hover:text-white'" class="relative z-10 flex-1 rounded-[8px] px-4 py-2 text-sm font-semibold transition-colors">Fixed data</button>
                        <button type="button" @click="setMode('unlimited')" :class="dataMode === 'unlimited' ? 'text-white' : 'text-zinc-600 hover:text-zinc-900 dark:text-zinc-300 dark:hover:text-white'" class="relative z-10 flex-1 rounded-[8px] px-4 py-2 text-sm font-semibold transition-colors">Unlimited</button>
                    </div>
                </div>
            </div>

            {{-- Plan section. Uses the same carousel component, alignment + scroll as the
                 storefront brand rows. Re-keyed per tab/mode so the whole row rebuilds,
                 re-aligns and resets its scroll when the buyer switches Voice / Data. --}}
            <div class="mt-3">
                <template x-for="frame in [activeTab + '-' + dataMode]" :key="frame">
                    <div
                        x-transition:enter="transition ease-out duration-300"
                        x-transition:enter-start="opacity-0 translate-y-2"
                        x-transition:enter-end="opacity-100 translate-y-0"
                    >
                        <x-home.brand-row title="Choose your package" :carousel="true" :bleed="! $inDash" view-all-variant="none">
                            <template x-for="(p, idx) in sortedPlans()" :key="p.id">
                            <button
                                type="button"
                                @click="selectedId = p.id"
                                {{-- Same shape as a gift card tile: a white plate,
                                     rounded-[14px], a hairline RING and a soft
                                     shadow, never a border. Selection thickens
                                     that ring to blue rather than swapping in a
                                     border, so the card's size never changes as
                                     you click along the row. Dark mode is not set
                                     here: `.esim-tile` already carries the ash +
                                     grain card tone for both dark themes. --}}
                                :class="selectedId === p.id ? 'ring-2 ring-blue-600 dark:ring-blue-500' : 'ring-1 ring-zinc-200 hover:ring-zinc-300 dark:ring-[#24364f] dark:hover:ring-white/40'"
                                class="esim-tile relative isolate flex h-full w-[70vw]! min-w-[70vw]! flex-col overflow-hidden rounded-[14px] bg-[#ffffff] p-4 text-left shadow-sm transition-shadow focus:outline-none sm:w-[274px]! sm:min-w-[274px]!"
                            >
                {{-- Same shape as a destination card on the eSIM store: round
                                     glyph, a title, a row of chips saying what the package holds,
                                     the price, then one full-width action. The decorated version
                                     this replaces (SIM watermark, tilted country plate, glowing
                                     badges, a 58px allowance figure) said the same things in five
                                     different type sizes and did not match anything else on the
                                     site. The chips read off the plan's own fields, so a card can
                                     never advertise something the package does not carry. --}}
                                @php
                                    // Stated once here rather than repeated on eight spans.
                                    $planChip = 'rounded-full bg-[#f4f6f5] px-2.5 py-1 text-[12px] text-[#0c1337] dark:bg-white/10 dark:text-zinc-200';
                                @endphp

                                <span class="flex items-start justify-between gap-3">
                                    <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-[#f4f6f5] ring-1 ring-zinc-200 dark:bg-white/10 dark:ring-white/10">
                                        {{-- A real SIM: the cut corner and the CONTACT GRID are
                                             what make it read as one. The previous glyph was a
                                             card outline with a plain rectangle inside, which
                                             looked like a document icon. Same artwork the card's
                                             old watermark used, normalised to a 24px box. --}}
                                        <svg class="h-5 w-5 text-zinc-500 dark:text-zinc-300" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.6" aria-hidden="true">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M7 3h7.2L18 6.8V20a1.5 1.5 0 0 1-1.5 1.5H7A1.5 1.5 0 0 1 5.5 20V4.5A1.5 1.5 0 0 1 7 3Z"/>
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M14.2 3v2.8a1 1 0 0 0 1 1H18"/>
                                            <rect x="8.5" y="11" width="7" height="6.5" rx="1"/>
                                            <path stroke-linecap="round" d="M8.5 13.2h7M8.5 15.3h7M10.8 11v6.5M13.2 11v6.5"/>
                                        </svg>
                                    </span>

                                    {{-- The badge slot the store card gives "Popular". Every card
                                         carries one, naming which of the two families this plan
                                         belongs to: a package with a phone number is Calls, one
                                         without is Data. The two are mutually exclusive, so a card
                                         never shows both. --}}
                                    <template x-if="p.is_voice">
                                        <span class="shrink-0 rounded-full bg-[#0c1337] px-2 py-0.5 text-[10px] uppercase tracking-wide text-white dark:bg-[#fdfdfd] dark:text-[#0c1337]">Calls</span>
                                    </template>
                                    <template x-if="! p.is_voice">
                                        <span class="shrink-0 rounded-full bg-[#0c1337] px-2 py-0.5 text-[10px] uppercase tracking-wide text-white dark:bg-[#fdfdfd] dark:text-[#0c1337]">Data</span>
                                    </template>
                                </span>

                                <span class="mt-3 block truncate text-[17px] text-[#0c1337] dark:text-white" x-text="p.is_unlimited ? 'Unlimited data' : p.data"></span>

                                <span class="mt-2 flex flex-wrap items-center gap-1.5">
                                    <span class="{{ $planChip }} tabular-nums" x-text="p.days + (p.days === 1 ? ' day' : ' days')"></span>

                                    <template x-if="p.is_unlimited">
                                        <span class="rounded-full bg-[#0c1337] px-2.5 py-1 text-[12px] text-white dark:bg-[#fdfdfd] dark:text-[#0c1337]">Unlimited</span>
                                    </template>

                                    {{-- One chip per thing the package carries. Where there is no
                                         number, the single chip says so: "Data only" is the fact a
                                         traveller needs, where a bare "Data" leaves them wondering
                                         about calls. Same wording as the store cards. --}}
                                    <template x-if="p.is_voice">
                                        <span class="{{ $planChip }}">Data</span>
                                    </template>
                                    <template x-if="p.is_voice">
                                        <span class="{{ $planChip }}">Phone number</span>
                                    </template>
                                    <template x-if="! p.is_voice">
                                        <span class="{{ $planChip }}">Data only</span>
                                    </template>

                                    <template x-if="p.is_voice && p.voice">
                                        <span class="{{ $planChip }}" x-text="p.voice"></span>
                                    </template>
                                    <template x-if="p.is_voice && p.sms">
                                        <span class="{{ $planChip }}" x-text="p.sms"></span>
                                    </template>
                                </span>

                                <template x-if="p.note">
                                    <span class="mt-2 block text-[12px] leading-relaxed text-zinc-500 dark:text-white/50" x-text="p.note"></span>
                                </template>

                                {{-- Pushes the price and action to the bottom, so cards of
                                     different chip counts still line their footers up. --}}
                                <span class="mt-3 flex flex-1 items-end">
                                    <span class="text-[17px] tabular-nums text-[#0c1337] dark:text-white" x-text="rowPrice(p)"></span>
                                </span>

                                {{-- role=button, not <button>: the card itself is a <button> and
                                     nesting them is invalid HTML. The store's action pill shape,
                                     in brand navy because this one actually buys. --}}
                                <span
                                    @click.stop="selectedId = p.id; buyNow()"
                                    @keydown.enter.stop="selectedId = p.id; buyNow()"
                                    role="button" tabindex="0"
                                    class="mt-4 block w-full cursor-pointer rounded-full bg-[#0c1337] px-3 py-2 text-center text-[14px] text-white transition-colors hover:bg-[#1a2560] dark:bg-[#fdfdfd] dark:text-[#0c1337] dark:hover:bg-[#e4e5f3]"
                                >Get eSIM</span>

                                <span
                                    @click.stop="detailsId = p.id; showDetails = true"
                                    @keydown.enter.stop="detailsId = p.id; showDetails = true"
                                    role="button" tabindex="0"
                                    class="mt-2 block w-full cursor-pointer text-center text-[13px] text-zinc-500 transition-colors hover:text-zinc-900 dark:text-zinc-400 dark:hover:text-white"
                                >See more</span>
                            </button>
                        </template>
                        </x-home.brand-row>
                    </div>
                </template>
                    <p x-show="sortedPlans().length === 0" class="mt-4 rounded-[12px] bg-[#ffffff] px-4 py-8 text-center text-sm text-zinc-600 ring-1 ring-zinc-100 dark:bg-[#1d3252] dark:text-zinc-400 dark:ring-zinc-700">No packages in this category right now.</p>

                    {{-- Voice-plan inclusions - only on the Voice tab.
                         Each inclusion is its own card. --}}
                    <div x-show="activeTab === 'voice'" x-cloak class="mt-6">
                        <p class="text-sm font-bold text-zinc-900 dark:text-white">Every voice plan includes</p>
                        <div class="mt-3 grid grid-cols-1 gap-3 sm:grid-cols-3">
                            @foreach ([
                                ['title' => 'App verification', 'desc' => 'WhatsApp, Telegram, TikTok and many other apps that need a number.', 'icon' => 'M9 12.75L11.25 15 15 9.75M21 12a9 9 0 11-18 0 9 9 0 0118 0z'],
                                ['title' => 'Unlimited iMessage', 'desc' => 'Unlimited iMessage for the full validity period your plan carries.', 'icon' => 'M2.25 12.76c0 1.6 1.123 2.994 2.707 3.227 1.087.16 2.185.283 3.293.369V21l4.184-4.183a1.14 1.14 0 01.778-.332 48.294 48.294 0 005.83-.498c1.585-.233 2.708-1.626 2.708-3.228V6.741c0-1.602-1.123-2.995-2.707-3.228A48.394 48.394 0 0012 3c-2.392 0-4.744.175-7.043.513C3.373 3.746 2.25 5.14 2.25 6.741v6.018z'],
                                ['title' => 'Renewable & top-up', 'desc' => 'Top up for local calls any time, with iMessage unlimited.', 'icon' => 'M16.023 9.348h4.992v-.001M2.985 19.644v-4.992m0 0h4.992m-4.993 0l3.181 3.183a8.25 8.25 0 0013.803-3.7M4.031 9.865a8.25 8.25 0 0113.803-3.7l3.181 3.182m0-4.991v4.99'],
                            ] as $voiceCard)
                                <div class="rounded-[12px] bg-[#ffffff] p-4 ring-1 ring-zinc-200 dark:bg-blue-500/10 dark:ring-blue-500/20">
                                    <span class="flex h-9 w-9 items-center justify-center rounded-[12px] bg-blue-600/10 text-blue-600 dark:bg-blue-500/20 dark:text-blue-300">
                                        <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="{{ $voiceCard['icon'] }}"/></svg>
                                    </span>
                                    <p class="mt-3 text-sm font-bold text-zinc-900 dark:text-white">{{ $voiceCard['title'] }}</p>
                                    <p class="mt-1 text-xs leading-relaxed text-zinc-600 dark:text-zinc-400">{{ $voiceCard['desc'] }}</p>
                                </div>
                            @endforeach
                        </div>
                    </div>

                    {{-- Need broader coverage - sits beneath the plans. --}}
                    @if ($broaderCoverage->isNotEmpty())
                        <div class="mt-8 border-t border-zinc-200 pt-6 dark:border-zinc-700/60">
                            {{-- Broader coverage as a full-bleed carousel, same as the storefront brand rows. --}}
                            <x-home.brand-row
                                title="Need broader coverage?"
                                subtitle="Explore our regional and global eSIMs. Packages start from the shown price and include coverage for the selected location."
                                :carousel="true"
                                :bleed="! $inDash"
                                view-all-variant="none"
                            >
                                {{-- The same card the eSIM store grid uses, not a
                                     second one shaped like it. These rows come from
                                     catalogSummary(), which is exactly what that
                                     component takes, so a destination shows the same
                                     chips and price here as it does on the store.

                                     filter off: the card normally hides itself against
                                     the parent scope's locTab/locSearch, and this page
                                     has no tabs. ask off: it normally raises the
                                     purchase-check modal by writing `pick`, which only
                                     the store page's scope holds; with it off the card
                                     is a plain link and keeps wire:navigate. --}}
                                @foreach ($broaderCoverage as $b)
                                    <x-esim.location-card
                                        :item="$b"
                                        :filter="false"
                                        :ask="false"
                                        class="w-[70vw]! min-w-[70vw]! sm:w-[274px]! sm:min-w-[274px]!"
                                    />
                                @endforeach
                            </x-home.brand-row>
                        </div>
                    @endif
                </div>

            {{-- ── Why choose us (1000px centered) ───────────────────────────── --}}
            <section class="mx-auto mt-8 w-full max-w-[1100px] rounded-[20px] bg-[#ffffff] p-6 sm:p-10 dark:bg-[#1d3252]">
                <h2 class="text-center text-2xl font-bold tracking-tight text-zinc-900 sm:text-3xl dark:text-white">Why travelers choose {{ $siteName }} eSIMs</h2>
                <div class="mt-8 grid grid-cols-2 gap-6 lg:grid-cols-4">
                    @foreach ([
                        ['t' => 'Local, regional and global coverage for '.number_format($whyDestinations).' destinations', 'd' => 'M12 21a9 9 0 100-18 9 9 0 000 18zm0 0c2.5 0 4.5-4.03 4.5-9S14.5 3 12 3 7.5 7.03 7.5 12s2 9 4.5 9zM3.6 9h16.8M3.6 15h16.8'],
                        ['t' => 'Flexible packages, including unlimited data options', 'd' => 'M3 7.5L7.5 3m0 0L12 7.5M7.5 3v13.5m13.5 0L16.5 21m0 0L12 16.5m4.5 4.5V7.5'],
                        ['t' => $whyLanguageLine, 'image' => 'total transactions.svg'],
                        ['t' => 'Easy install and set up to get connected in minutes', 'd' => 'M3.75 4.5a.75.75 0 01.75-.75h3a.75.75 0 01.75.75v3a.75.75 0 01-.75.75h-3a.75.75 0 01-.75-.75v-3zm12 0a.75.75 0 01.75-.75h3a.75.75 0 01.75.75v3a.75.75 0 01-.75.75h-3a.75.75 0 01-.75-.75v-3zm-12 12a.75.75 0 01.75-.75h3a.75.75 0 01.75.75v3a.75.75 0 01-.75.75h-3a.75.75 0 01-.75-.75v-3zM15 15.75h.008v.008H15v-.008zm0 3h.008v.008H15v-.008zm3-3h.008v.008H18v-.008zm0 3h.008v.008H18v-.008zm3-3h.008v.008H21v-.008zm0 3h.008v.008H21v-.008z'],
                    ] as $feature)
                        <div class="flex flex-col items-center text-center">
                            <span class="flex h-16 w-16 items-center justify-center rounded-[12px] bg-white shadow-sm dark:bg-[#26416b]">
                                @if (! empty($feature['image']))
                                    <img src="{{ asset('assets/'.rawurlencode($feature['image'])) }}" alt="" class="h-7 w-7 object-contain dark:invert" aria-hidden="true">
                                @else
                                    <svg class="h-7 w-7 text-black dark:text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.6" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="{{ $feature['d'] }}"/></svg>
                                @endif
                            </span>
                            <p class="mt-3 text-sm font-semibold leading-snug text-zinc-700 dark:text-zinc-300">{{ $feature['t'] }}</p>
                        </div>
                    @endforeach
                </div>
            </section>

            {{-- FAQ. The questions come from the faqs table through <x-home.faq>,
                 the same component and the same admin editor as the homepage and
                 the gift card store, scoped to the eSIM topic. Nothing is written
                 here, so the copy cannot drift from /faq.

                 It replaced four questions hardcoded in an @foreach on this page,
                 which nobody could edit without a deploy and which said nothing
                 about coverage, compatibility, roaming or refunds. --}}
            <x-home.faq
                :topics="['eSIMs']"
                :limit="17"
                heading-id="esim-faq-heading"
                :grow-from="1100"
            />

            {{-- ── How to add your eSIM (three-step walkthrough) ─────────────── --}}
            @php
                $howSteps = [
                    [
                        'image' => 'esim-how-to-1.webp',
                        'title' => 'Open Settings &rarr; Mobile Service',
                        'body'  => 'Unlock your phone and tap Mobile Service (or Cellular on older iPhones). This is where every line on your device already lives.',
                    ],
                    [
                        'image' => 'esim-how-to-2.webp',
                        'title' => 'Tap Add eSIM and scan your QR code',
                        'body'  => 'We email the QR code seconds after checkout. Point your camera at it, or tap Enter Details Manually to paste the code instead.',
                    ],
                    [
                        'image' => 'esim-how-to-3.webp',
                        'title' => 'Turn it on, switch on Data Roaming',
                        'body'  => 'Flip Turn On This Line, then enable Data Roaming for the new line. The moment you land, your phone connects at local rates.',
                    ],
                ];
            @endphp
            {{-- How-to band. Full-bleed (edge-to-edge) on the storefront, where the
                 page is centered in the viewport. In the dashboard the content column
                 is offset by the sidebar, so calc(50%-50vw) breaks out under the rail
                 and overflows - there we keep it contained as a rounded band instead. --}}
            <section @class([
                'esim-tile mt-8 bg-[#f4f6f5] py-10 sm:py-14 dark:bg-[#1d3252]',
                '[margin-left:calc(50%-50vw)] [margin-right:calc(50%-50vw)]' => ! $inDash,
                'overflow-hidden rounded-[15px]' => $inDash,
            ])>
                <div class="mx-auto w-full max-w-[1450px] px-4 sm:px-6">
                <div>
                    <h2 class="text-2xl font-bold tracking-tight text-zinc-900 sm:text-3xl dark:text-white">How to add your eSIM</h2>
                    <p class="mt-2 max-w-xl text-sm leading-relaxed text-zinc-600 sm:text-base dark:text-zinc-300">Three quick steps and you are roaming on local rates. No SIM tray, no queues at the airport kiosk.</p>
                </div>

                {{-- Mobile: a smooth snap-scrolling carousel (one card at a time with
                     a peek of the next). Desktop (lg+): the static three-column grid. --}}
                <ol class="mt-8 flex snap-x snap-mandatory gap-5 overflow-x-auto scroll-smooth pb-4 [-webkit-overflow-scrolling:touch] [scrollbar-width:none] [&::-webkit-scrollbar]:hidden lg:grid lg:grid-cols-3 lg:gap-8 lg:overflow-visible lg:pb-0">
                    @foreach ($howSteps as $i => $step)
                        <li class="flex w-[82%] shrink-0 snap-center flex-col sm:w-[55%] lg:w-auto lg:shrink">
                            <img
                                src="{{ asset('assets/'.$step['image']) }}"
                                alt=""
                                width="785"
                                height="900"
                                decoding="async"
                                loading="eager"
                                fetchpriority="high"
                                class="aspect-[3/4] w-full object-contain [image-rendering:high-quality] [image-rendering:-webkit-optimize-contrast]"
                            >
                            <div class="mt-4 flex items-center gap-3">
                                <span class="flex h-7 w-7 shrink-0 items-center justify-center rounded-[12px] bg-blue-600 text-xs font-bold text-white">{{ $i + 1 }}</span>
                                <p class="text-base font-bold text-zinc-900 dark:text-white">{!! $step['title'] !!}</p>
                            </div>
                            <p class="mt-2 text-sm leading-relaxed text-zinc-600 dark:text-zinc-400">{{ $step['body'] }}</p>
                        </li>
                    @endforeach
                </ol>

                <div class="mt-8 flex flex-col items-center gap-3 sm:flex-row sm:justify-center">
                    <a href="#esim-packages" class="inline-flex items-center rounded-[12px] bg-blue-600 px-5 py-2.5 text-sm font-semibold text-white transition-colors hover:bg-blue-700">
                        Choose your package
                    </a>
                    <a href="{{ route('shop.help') }}" wire:navigate class="text-sm font-semibold text-zinc-700 underline-offset-2 transition-colors hover:text-blue-600 hover:underline dark:text-zinc-300 dark:hover:text-blue-300">Still stuck? Read the full guide</a>
                </div>
                </div>
            </section>

            {{-- ── Anonymous eSIM with crypto (no KYC) ──────────────────────── --}}
            <section class="mx-auto mt-12 grid w-full max-w-[1450px] grid-cols-1 items-start gap-10 lg:grid-cols-2 lg:gap-16">
                <div>
                    <span class="flex h-12 w-12 items-center justify-center rounded-[12px] bg-[#ffffff] ring-1 ring-zinc-200 dark:bg-blue-950/40 dark:ring-blue-900/40">
                        <svg class="h-6 w-6 text-blue-600 dark:text-blue-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75L11.25 15 15 9.75M21 12c0 1.268-.63 2.39-1.593 3.068a3.745 3.745 0 01-1.043 3.296 3.745 3.745 0 01-3.296 1.043A3.745 3.745 0 0112 21c-1.268 0-2.39-.63-3.068-1.593a3.746 3.746 0 01-3.296-1.043 3.745 3.745 0 01-1.043-3.296A3.745 3.745 0 013 12c0-1.268.63-2.39 1.593-3.068a3.745 3.745 0 011.043-3.296 3.746 3.746 0 013.296-1.043A3.746 3.746 0 0112 3c1.268 0 2.39.63 3.068 1.593a3.746 3.746 0 013.296 1.043 3.746 3.746 0 011.043 3.296A3.745 3.745 0 0121 12z"/></svg>
                    </span>
                    <h2 class="mt-4 text-2xl font-bold tracking-tight text-zinc-900 sm:text-3xl dark:text-white">Anonymous eSIM with MOMO, Cards, Crypto and more. No KYC required.</h2>
                    <p class="mt-3 max-w-xl text-sm leading-relaxed text-zinc-600 sm:text-base dark:text-zinc-300">Unlike traditional SIM cards that ask for a passport and personal details, your {{ $siteName }} eSIM can be bought with mobile money, cards, crypto and more. No identity verification, no personal data collected.</p>
                </div>

                <ul class="space-y-5">
                    @foreach ([
                        ['t' => 'No KYC required', 'b' => 'Buy instantly without ID verification or personal information.'],
                        ['t' => 'Pay your way', 'b' => 'Mobile money, cards, Apple Pay, bank transfer or crypto (Bitcoin, Ethereum, Tether, BNB, Solana, Litecoin). Your call.'],
                        ['t' => 'Instant delivery', 'b' => 'Receive your eSIM QR code in your inbox the moment your payment confirms.'],
                    ] as $row)
                        <li class="flex items-start gap-3">
                            <svg class="mt-0.5 h-5 w-5 shrink-0 text-blue-600 dark:text-blue-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/><path stroke-linecap="round" stroke-linejoin="round" d="M8.25 12.75l2.25 2.25L15.75 9.75"/></svg>
                            <div>
                                <p class="text-base font-bold text-zinc-900 dark:text-white">{{ $row['t'] }}</p>
                                <p class="mt-1 text-sm leading-relaxed text-zinc-600 dark:text-zinc-400">{{ $row['b'] }}</p>
                            </div>
                        </li>
                    @endforeach
                </ul>
            </section>

            {{-- Payment methods we accept, as a continuous marquee.

                 The list is PaymentStripBadges::all(), the same source the
                 homepage strip uses, so this row carries MoMo, Orange Money and
                 every other rail with the real brand marks rather than a second
                 hand-kept list. That class also holds the rule that a badge may
                 only claim a rail checkout actually accepts, which a local array
                 here could quietly break.

                 It replaced ten entries of my own, four of which were 210 to 790
                 byte .webp bitmaps blown up to 40px and then flattened by
                 `brightness-0 dark:invert` into featureless silhouettes. --}}
            @php
                $payBadges = \App\Domain\Payment\Support\PaymentStripBadges::all();
            @endphp
            <section class="mx-auto mt-12 w-full max-w-[1450px]">
                <h2 class="text-2xl font-bold tracking-tight text-zinc-900 sm:text-3xl dark:text-white">Payment methods we accept</h2>
                <p class="mt-2 max-w-xl text-sm leading-relaxed text-zinc-600 sm:text-base dark:text-zinc-300">Pay with crypto or your local card. Settle in the currency you already hold.</p>

                {{-- The track holds TWO copies of the list and travels exactly
                     -50%, so the second copy sits where the first began at the
                     moment the animation restarts and the loop has no seam. That
                     holds for any item width, since both copies are identical:
                     half the track is always exactly one copy. The clone is
                     aria-hidden, so a screen reader hears each method once.
                     Hover pauses it; prefers-reduced-motion stops it. --}}
                <div class="pay-marquee mt-6 overflow-hidden" role="group" aria-label="Accepted payment methods">
                    <div class="pay-marquee-track flex w-max items-center">
                        @foreach ([false, true] as $isClone)
                            <div class="flex w-max items-center" @if ($isClone) aria-hidden="true" @endif>
                                @foreach ($payBadges as $badge)
                                    <div class="flex h-[52px] w-[128px] shrink-0 items-center justify-center px-2">
                                        @isset($badge['img'])
                                            <img
                                                src="{{ asset('assets/'.rawurlencode($badge['img'])) }}"
                                                alt="{{ $isClone ? '' : $badge['label'] }}"
                                                @class([
                                                    'h-8 w-auto max-w-full object-contain',
                                                    // Monochrome glyphs want the global dark invert;
                                                    // colour brand marks must opt out or they flatten.
                                                    'brightness-0 dark:invert' => $badge['mono'] ?? false,
                                                    'no-dark-invert' => ! ($badge['mono'] ?? false),
                                                ])
                                                loading="lazy"
                                            >
                                        @else
                                            <x-home.payment-mark
                                                :mark="$badge['svg']"
                                                :label="$isClone ? null : $badge['label']"
                                            />
                                        @endisset
                                    </div>
                                @endforeach
                            </div>
                        @endforeach
                    </div>
                </div>
            </section>

            {{-- ── Buy toast: drops down from the top of the page on selection ──── --}}
            {{-- A rounded glass toast that slides in from above when a plan is
                 selected (selectedId). Non-blocking, so plans stay browsable; the
                 X chip clears selectedId, which hides the toast via x-show. --}}
            <div
                x-show="selectedId"
                x-transition:enter="transition ease-out duration-300"
                x-transition:enter-start="opacity-0 -translate-y-6"
                x-transition:enter-end="opacity-100 translate-y-0"
                x-transition:leave="transition ease-in duration-200"
                x-transition:leave-start="opacity-100 translate-y-0"
                x-transition:leave-end="opacity-0 -translate-y-6"
                {{-- A plain 12px from the top. It used to track the live
                     header height to sit below the pinned nav; the header is
                     not sticky any more, so there is nothing to clear. --}}
                {{-- One style attribute, not two. A duplicate is discarded by the
                     parser, which kept `top` and threw the `display:none` away,
                     so the toast flashed at the top of every page load until
                     Alpine booted and x-show hid it. --}}
                style="top: 12px; display: none;"
                class="fixed inset-x-0 z-[100] px-3 sm:px-5"
            >
                <div class="relative mx-auto w-full max-w-[860px] rounded-[20px] border border-white/40 bg-white/70 shadow-[0_14px_44px_-12px_rgba(15,23,42,0.4),inset_0_1px_0_rgba(255,255,255,0.4)] backdrop-blur-2xl backdrop-saturate-150 dark:border-white/10 dark:bg-[#1d3252]/80">
                    {{-- Close chip: half-out the bottom edge, deselects the plan. --}}
                    <button
                        type="button"
                        @click="selectedId = null"
                        aria-label="Deselect plan"
                        class="absolute bottom-0 left-1/2 z-10 flex h-8 w-8 -translate-x-1/2 translate-y-1/2 items-center justify-center rounded-[12px] bg-white text-zinc-700 ring-1 ring-zinc-200 shadow-md transition-colors hover:bg-[#f4f6f5] hover:text-zinc-900 dark:bg-[#26416b] dark:text-white dark:ring-zinc-700/60 dark:hover:bg-[#34507a]"
                    >
                        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.25" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg>
                    </button>

                    <div class="flex w-full flex-wrap items-center gap-3 px-4 py-4 sm:px-6">
                    <button type="button" @click="detailsId = selectedId; showDetails = true" class="inline-flex items-center gap-2 rounded-[12px] px-4 py-2.5 text-sm font-semibold text-zinc-900 ring-1 ring-zinc-400/60 backdrop-blur-md transition-colors hover:bg-white/60 dark:text-white dark:ring-white/60 dark:hover:bg-white/10">
                        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M19.5 14.25v-2.625a3.375 3.375 0 00-3.375-3.375h-1.5A1.125 1.125 0 0113.5 7.125v-1.5a3.375 3.375 0 00-3.375-3.375H8.25m2.25 0H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 00-9-9z"/></svg>
                        Package details
                    </button>

                    {{-- Currency selector --}}
                    <div x-data="{ open: false }" @click.outside="open = false" class="relative">
                        <button type="button" @click="open = ! open" class="flex items-center gap-1.5 rounded-[12px] px-3.5 py-2.5 text-sm font-semibold text-zinc-700 ring-1 ring-zinc-400/60 backdrop-blur-md transition-colors hover:bg-white/60 dark:text-white dark:ring-white/60 dark:hover:bg-white/10">
                            <template x-if="cryptos[selectedCrypto]?.icon"><img :src="cryptos[selectedCrypto].icon" :alt="selectedCrypto" class="h-4 w-4 rounded-[12px]"></template>
                            <template x-if="!cryptos[selectedCrypto]?.icon && cryptos[selectedCrypto]?.flag"><img :src="cryptos[selectedCrypto].flag" :alt="selectedCrypto" class="h-4 w-4 rounded-[12px] object-cover"></template>
                            <span x-text="selectedCrypto"></span>
                            <svg class="h-3.5 w-3.5 text-zinc-500 dark:text-zinc-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7"/></svg>
                        </button>
                        <div x-show="open" x-transition style="display:none;" class="absolute top-full left-0 z-20 mt-2 max-h-72 w-56 overflow-y-auto rounded-[12px] border border-zinc-200 bg-[#ffffff] p-1 shadow-xl shadow-zinc-900/10 [scrollbar-width:none] [&::-webkit-scrollbar]:hidden dark:border-zinc-700 dark:bg-[#1d3252] dark:shadow-black/40" role="listbox">
                            <template x-for="(meta, code) in cryptos" :key="code">
                                <button type="button" @click="selectedCrypto = code; open = false" :class="selectedCrypto === code ? 'bg-[#f4f6f5] text-blue-700 dark:bg-blue-600/15 dark:text-blue-300' : 'text-zinc-800 hover:bg-[#f4f6f5] dark:text-zinc-200 dark:hover:bg-[#26416b]'" class="flex w-full items-center gap-2 rounded-[12px] px-3 py-2 text-left text-sm font-medium transition-colors">
                                    <template x-if="meta.icon"><img :src="meta.icon" :alt="code" class="h-5 w-5 shrink-0 rounded-[12px]"></template>
                                    <template x-if="!meta.icon && meta.flag"><img :src="meta.flag" :alt="code" class="h-5 w-5 shrink-0 rounded-[12px] object-cover ring-1 ring-zinc-200"></template>
                                    <template x-if="!meta.icon && !meta.flag"><span class="inline-flex h-5 w-5 shrink-0 items-center justify-center rounded-[12px] text-[10px] font-black text-white" :class="meta.type === 'crypto' ? 'bg-amber-500' : 'bg-emerald-500'" x-text="code.charAt(0)"></span></template>
                                    <span class="flex-1 truncate" x-text="code"></span>
                                    <svg x-show="selectedCrypto === code" class="h-4 w-4 shrink-0 text-blue-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5"/></svg>
                                </button>
                            </template>
                        </div>
                    </div>

                    <div class="ml-auto flex items-center gap-4">
                        <div class="text-right">
                            <p class="text-[11px] font-medium uppercase tracking-wide text-zinc-500 dark:text-zinc-400">Total</p>
                            <p class="text-xl font-extrabold tabular-nums text-zinc-900 dark:text-white" x-text="totalLabel()">0.00</p>
                        </div>
                        <span x-show="rcoinConfig.enabled" class="hidden items-center gap-1 text-xs font-semibold text-zinc-600 sm:flex dark:text-zinc-400">
                            <img src="{{ asset('assets/favicon.ico') }}" alt="" class="h-5 w-5 object-contain">
                            <span x-text="pointsEarned()">0</span>
                        </span>
                        <button
                            type="button"
                            @click="addToCart()"
                            :disabled="! selectedId || ! purchasable"
                            :class="cartState === 'success' ? 'border-emerald-500 bg-emerald-500 text-white' : 'border-blue-600 bg-white/70 text-blue-600 backdrop-blur-md hover:bg-blue-600 hover:text-white dark:bg-white/10 dark:text-blue-300 dark:hover:bg-blue-600 dark:hover:text-white'"
                            class="hidden h-12 items-center justify-center rounded-[12px] border-2 px-6 text-sm font-semibold transition-colors disabled:cursor-not-allowed disabled:opacity-50 sm:flex"
                        >
                            <span x-show="cartState !== 'success'">Add to cart</span>
                            <span x-show="cartState === 'success'" style="display:none;">Added</span>
                        </button>
                        <button
                            type="button"
                            @click="buyNow()"
                            :disabled="! selectedId || ! purchasable || $store.cart.loading"
                            class="flex h-12 items-center justify-center rounded-[12px] bg-blue-600 px-7 text-base font-semibold text-white shadow-lg shadow-blue-600/25 transition-colors hover:bg-blue-700 focus:outline-none focus-visible:ring-2 focus-visible:ring-blue-500/40 disabled:opacity-50 disabled:hover:bg-blue-600"
                        >
                            Buy now
                        </button>
                    </div>
                </div>
                </div>
            </div>
        @else
            <div class="mt-8 rounded-[12px] bg-[#ffffff] px-4 py-10 text-center ring-1 ring-zinc-100 dark:bg-[#1d3252] dark:ring-zinc-700/60">
                <p class="text-base font-semibold text-zinc-900 dark:text-white">No data plans available</p>
                <p class="mt-1 text-sm text-zinc-600 dark:text-zinc-400">This region has no plans in stock right now. Check back later.</p>
                <a href="{{ $shopRoute('esims') }}" wire:navigate class="mt-4 inline-flex items-center gap-1.5 text-sm font-semibold text-blue-600 hover:text-blue-700 dark:text-blue-400 dark:hover:text-blue-300">Browse other regions</a>
            </div>
        @endif

        {{-- ════ Modals ═══════════════════════════════════════════════════════ --}}

        {{-- Networks modal --}}
        <div x-show="showNetworks" style="display:none;" class="fixed inset-0 z-[70] flex items-end justify-center px-3 pb-3 sm:items-center sm:p-4" role="dialog" aria-modal="true" aria-labelledby="networks-title">
            <div x-show="showNetworks" @click="showNetworks = false" x-transition.opacity class="absolute inset-0 bg-zinc-900/50 dark:bg-zinc-950/70"></div>
            <div x-show="showNetworks" x-transition class="relative max-h-[85vh] w-full max-w-[760px] overflow-y-auto p-6 rounded-[12px] bg-[#eff6ff] shadow-2xl ring-1 ring-zinc-200 dark:ring-[#24364f]">
                <div class="flex items-start justify-between gap-4">
                    <h2 id="networks-title" class="text-lg font-bold text-zinc-900 dark:text-white">{{ $coverageCount > 1 ? 'Countries & Networks' : 'Networks' }}</h2>
                    <button type="button" @click="showNetworks = false" aria-label="Close" class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-[#dde3ee] text-zinc-600 transition-colors hover:bg-[#cdd5e4] hover:text-zinc-900 dark:bg-white/10 dark:text-zinc-300 dark:hover:bg-white/20 dark:hover:text-white"><svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg></button>
                </div>
                @if ($coverageCount > 1)
                    <p class="mt-3 text-sm leading-relaxed text-zinc-600 dark:text-white">This eSIM works across {{ $coverageCount }} countries and connects to a local network automatically in each.</p>
                    @if ($countryNetworks->isNotEmpty())
                        {{-- Per-country networks straight from the supplier's plan
                             details: flag + country left, its carriers and speeds
                             right. Searchable, scrolls inside the modal. --}}
                        <div class="mt-4" x-data="{ netQ: '' }">
                            <div class="relative">
                                <svg class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-zinc-500 dark:text-zinc-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg>
                                <input x-model="netQ" type="text" placeholder="Search by country" aria-label="Search by country" class="w-full rounded-[15px] border border-zinc-200 bg-white py-2.5 pl-9 pr-3 text-sm text-zinc-900 outline-none transition-colors focus:border-blue-500 focus:ring-2 focus:ring-blue-500/15 dark:border-zinc-700 dark:bg-[#26416b] dark:text-white dark:placeholder:text-zinc-400">
                            </div>
                            <div class="mt-3 max-h-80 modal-scroll-fade overflow-y-auto overscroll-contain pr-1 [-webkit-overflow-scrolling:touch]">
                                <ul class="divide-y divide-zinc-100 dark:divide-zinc-700/60">
                                    @foreach ($countryNetworks as $cn)
                                        @php $cnIso = $cn['iso'] ?? ($nameToIso[$cn['name']] ?? null); @endphp
                                        <li data-name="{{ Str::lower($cn['name']) }}" x-show="netQ === '' || $el.dataset.name.includes(netQ.toLowerCase())" class="flex items-center justify-between gap-3 py-2.5">
                                            <span class="flex min-w-0 items-center gap-2.5 text-sm font-bold text-zinc-900 dark:text-white">
                                                @if ($cnIso)
                                                    <img src="{{ Product::flagUrl($cnIso) }}" alt="" class="h-4 w-6 shrink-0 rounded-[2px] object-cover ring-1 ring-zinc-200 dark:ring-zinc-700" loading="lazy">
                                                @endif
                                                <span class="truncate">{{ $cn['name'] }}</span>
                                            </span>
                                            <span class="flex shrink-0 flex-col items-end gap-1">
                                                @foreach ($cn['networks'] as $net)
                                                    <span class="flex items-center gap-1.5 text-xs text-zinc-700 dark:text-zinc-300">
                                                        {{ $net['name'] }}
                                                        @if (! empty($net['speed']))
                                                            <span class="rounded-[4px] border border-zinc-300 px-1 py-px text-[9px] font-bold text-zinc-600 dark:border-zinc-600 dark:text-zinc-300">{{ $net['speed'] }}</span>
                                                        @endif
                                                    </span>
                                                @endforeach
                                            </span>
                                        </li>
                                    @endforeach
                                </ul>
                            </div>
                        </div>
                    @else
                        {{-- Fallback (supplier sent no per-country networks):
                             plain country grid. --}}
                        <div class="mt-4 max-h-72 modal-scroll-fade overflow-y-auto overscroll-contain [-webkit-overflow-scrolling:touch] pr-1">
                            <ul class="grid grid-cols-2 gap-x-4 gap-y-1.5 text-sm text-zinc-700 sm:grid-cols-3 dark:text-zinc-300">
                                @foreach ($coverageList as $c)
                                    <li class="truncate">{{ $c }}</li>
                                @endforeach
                            </ul>
                        </div>
                    @endif
                @else
                    <p class="mt-3 text-sm leading-relaxed text-zinc-600 dark:text-white">This eSIM may switch between the following networks based on availability and speed. You can adjust this in your device settings.</p>
                    {{-- Global eSIMs can list 100+ carriers - cap the list like
                         the countries branch so the modal never outgrows the
                         screen; the list itself scrolls. --}}
                    <div class="mt-4 max-h-72 modal-scroll-fade overflow-y-auto overscroll-contain [-webkit-overflow-scrolling:touch] pr-1">
                        <ul class="space-y-2.5">
                            @forelse ($carrierNetworks as $net)
                                <li class="flex items-center gap-2.5 text-sm font-semibold text-zinc-900 dark:text-white">
                                    <img src="{{ asset('assets/'.rawurlencode('Network Svg.svg')) }}" alt="" class="h-4 w-4 dark:invert" aria-hidden="true">
                                    {{ $net['name'] }}
                                    @if (! empty($net['speed']))
                                        <span class="rounded-[5px] bg-[#f4f6f5] px-1.5 py-0.5 text-[10px] font-bold text-zinc-600 dark:bg-[#26416b] dark:text-zinc-200">{{ $net['speed'] }}</span>
                                    @endif
                                </li>
                            @empty
                                <li class="text-sm text-zinc-600 dark:text-white">Your device connects automatically to the best available local network in {{ $regionLabel }}.</li>
                            @endforelse
                        </ul>
                    </div>
                @endif
            </div>
        </div>

        {{-- Check compatibility modal.

             Reference shape: one searchable list of manufacturers, each a
             collapsible row carrying its own model count, rather than the iOS /
             Android tabs this used to be. Someone checking a device knows its
             make, not which platform list we keep it under, and with 60-odd
             makes a tab holding 300 loose model names is not readable. The
             search waits for three characters because one or two match most of
             the list and the result reads as noise. --}}
        <div x-show="showCompat" style="display:none;" class="fixed inset-0 z-[70] flex items-end justify-center p-3 sm:items-center sm:p-4" role="dialog" aria-modal="true" aria-labelledby="compat-title">
            <div x-show="showCompat" @click="showCompat = false" x-transition.opacity class="absolute inset-0 bg-zinc-900/50 dark:bg-zinc-950/70"></div>
            <div x-show="showCompat" x-transition class="relative flex max-h-[85vh] w-full max-w-[760px] flex-col overflow-hidden rounded-[12px] bg-[#eff6ff] shadow-2xl ring-1 ring-zinc-200 dark:ring-[#24364f]">

                {{-- Header, held above the body so the title stays put while the
                     manufacturer list scrolls under it. --}}
                <div class="flex items-start justify-between gap-4 border-b border-zinc-200 px-6 py-5 dark:border-zinc-700/60">
                    <h2 id="compat-title" class="text-lg font-bold text-zinc-900 dark:text-white">Check compatibility</h2>
                    <button type="button" @click="showCompat = false" aria-label="Close" class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-[#dde3ee] text-zinc-600 transition-colors hover:bg-[#cdd5e4] hover:text-zinc-900 dark:bg-white/10 dark:text-zinc-300 dark:hover:bg-white/20 dark:hover:text-white">
                        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg>
                    </button>
                </div>

                <div class="px-6 pt-5 text-sm leading-relaxed text-zinc-600 dark:text-zinc-300">
                    <p>To use an eSIM, a device must meet all three of these conditions:</p>
                    <ul class="mt-2 list-disc space-y-1 pl-5">
                        <li>The device supports eSIMs.</li>
                        <li>The device is not carrier or network-locked.</li>
                        <li>The device is not jailbroken (iOS) or rooted (Android).</li>
                    </ul>
                    <p class="mt-3">
                        <span class="font-semibold text-zinc-900 dark:text-white">Don't see your device?</span>
                        The list is kept up to date but is not complete, and some regional models of a listed device do not carry eSIM. Check with the manufacturer if yours is missing.
                    </p>
                </div>

                {{-- Find your device. Both figures are counted off the list below,
                     so they cannot drift away from what is actually shown. --}}
                <div class="mt-5 flex flex-wrap items-baseline justify-between gap-x-4 gap-y-1 border-t border-zinc-200 px-6 pt-4 dark:border-zinc-700/60">
                    <p class="text-sm font-bold text-zinc-900 dark:text-white">Find your device</p>
                    <p class="text-xs text-zinc-500 dark:text-zinc-400">{{ number_format($compatModelCount) }} models across {{ number_format($compatBrands->count()) }} manufacturers</p>
                </div>

                <div class="px-6 pb-0 pt-3">
                    <div class="relative">
                        <svg class="pointer-events-none absolute left-4 top-1/2 h-4 w-4 -translate-y-1/2 text-zinc-500 dark:text-zinc-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-4.35-4.35M17 11a6 6 0 11-12 0 6 6 0 0112 0z"/></svg>
                        <input
                            x-model="compatSearch"
                            type="text"
                            placeholder="Search by manufacturer or model"
                            aria-label="Search by manufacturer or model"
                            class="w-full rounded-full border border-zinc-200 bg-white py-3 pl-11 pr-10 text-sm text-zinc-900 placeholder:text-zinc-400 focus:border-zinc-400 focus:outline-none dark:border-zinc-700/60 dark:bg-white/5 dark:text-white dark:placeholder:text-zinc-500"
                        >
                        <button
                            type="button"
                            x-show="compatSearch !== ''"
                            x-cloak
                            @click="compatSearch = ''"
                            aria-label="Clear search"
                            class="absolute right-3 top-1/2 flex h-7 w-7 -translate-y-1/2 items-center justify-center rounded-full text-zinc-500 transition-colors hover:bg-[#f4f6f5] hover:text-zinc-900 dark:hover:bg-white/10 dark:hover:text-white"
                        >
                            <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg>
                        </button>
                    </div>
                    {{-- Shown only while the query is too short to act on, so it
                         reads as a hint rather than an error. --}}
                    <p x-show="compatSearch.trim().length > 0 && compatSearch.trim().length < 3" x-cloak class="mt-2 pl-4 text-xs text-zinc-500 dark:text-zinc-400">Keep typing, three characters or more.</p>
                </div>

                <div class="modal-scroll-fade min-h-0 flex-1 space-y-2 overflow-y-auto overscroll-contain px-6 pb-6 pt-6 [-webkit-overflow-scrolling:touch]">
                    @foreach ($compatBrands as $brand => $models)
                        @php $brandKey = \Illuminate\Support\Str::slug($brand) ?: \Illuminate\Support\Str::lower($brand); @endphp
                        <div
                            data-brand="{{ \Illuminate\Support\Str::lower($brand) }}"
                            data-models="{{ \Illuminate\Support\Str::lower(implode('|', $models)) }}"
                            x-show="compatTerm() === '' || $el.dataset.brand.includes(compatTerm()) || $el.dataset.models.includes(compatTerm())"
                            class="overflow-hidden rounded-[12px] ring-1 ring-zinc-200 dark:ring-zinc-700/60"
                        >
                            <button
                                type="button"
                                @click="compatOpen = (compatOpen === @js($brandKey) ? '' : @js($brandKey))"
                                :aria-expanded="compatIsOpen($el, @js($brandKey)) ? 'true' : 'false'"
                                class="flex w-full items-center justify-between gap-3 px-4 py-3.5 text-left transition-colors hover:bg-[#f4f6f5] dark:hover:bg-white/5"
                            >
                                <span class="flex min-w-0 items-baseline gap-2">
                                    <span class="truncate text-sm font-bold text-zinc-900 dark:text-white">{{ $brand }}</span>
                                    <span class="shrink-0 text-xs text-zinc-500 dark:text-zinc-400">{{ count($models) }} {{ \Illuminate\Support\Str::plural('model', count($models)) }}</span>
                                </span>
                                <svg
                                    class="h-4 w-4 shrink-0 text-zinc-500 transition-transform duration-200 dark:text-zinc-400"
                                    :class="compatIsOpen($el, @js($brandKey)) ? 'rotate-180' : ''"
                                    fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"
                                ><path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7"/></svg>
                            </button>

                            <div x-show="compatIsOpen($el, @js($brandKey))" x-cloak class="border-t border-zinc-200 px-4 py-2 dark:border-zinc-700/60">
                                @foreach ($models as $model)
                                    <p
                                        data-name="{{ \Illuminate\Support\Str::lower($model) }}"
                                        x-show="compatTerm() === '' || $el.closest('[data-brand]').dataset.brand.includes(compatTerm()) || $el.dataset.name.includes(compatTerm())"
                                        class="py-1.5 text-sm text-zinc-600 dark:text-zinc-300"
                                    >{{ $model }}</p>
                                @endforeach
                            </div>
                        </div>
                    @endforeach

                    {{-- Worth saying when nothing matches, or the list just empties. --}}
                    <p
                        x-show="compatTerm() !== '' && compatMatches() === 0"
                        x-cloak
                        class="py-6 text-center text-sm text-zinc-500 dark:text-zinc-400"
                    >No manufacturer or model matches that search.</p>
                </div>
            </div>
        </div>

        {{-- Package details modal --}}
        <div x-show="showDetails" style="display:none;" class="fixed inset-0 z-[70] flex items-end justify-center px-3 pb-3 sm:items-center sm:p-4" role="dialog" aria-modal="true" aria-labelledby="details-title">
            <div x-show="showDetails" @click="showDetails = false" x-transition.opacity class="absolute inset-0 bg-zinc-900/50 dark:bg-zinc-950/70"></div>
            <div x-show="showDetails" x-transition class="relative flex max-h-[85vh] w-full max-w-[760px] flex-col overflow-hidden rounded-[12px] bg-[#eff6ff] shadow-2xl ring-1 ring-zinc-200 dark:ring-[#24364f]">
                {{-- Inner scroller: the sheet itself must NOT scroll, or the mask would
                     fade its glass background and rounded corners too, not just the text. --}}
                <div class="modal-scroll-fade min-h-0 flex-1 overflow-y-auto overscroll-contain p-6 [-webkit-overflow-scrolling:touch]">
                <div class="flex items-start justify-between gap-4">
                    <h2 id="details-title" class="flex items-center gap-2.5 text-lg font-bold text-zinc-900 dark:text-white">
                        @if ($flag)
                            <img src="{{ $flag }}" alt="" class="h-5 w-7 rounded-[2px] object-cover ring-1 ring-zinc-200 dark:ring-zinc-700">
                        @endif
                        {{ $regionLabel }}
                    </h2>
                    <button type="button" @click="showDetails = false" aria-label="Close" class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-[#dde3ee] text-zinc-600 transition-colors hover:bg-[#cdd5e4] hover:text-zinc-900 dark:bg-white/10 dark:text-zinc-300 dark:hover:bg-white/20 dark:hover:text-white"><svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg></button>
                </div>
                <div class="mt-5">
                    <p class="text-sm font-bold text-zinc-900 dark:text-white">Package</p>
                    <div class="mt-3 flex flex-wrap gap-2">
                        <span class="inline-flex flex-col rounded-[12px] bg-[#f4f6f5] px-3 py-2 ring-1 ring-zinc-100 dark:bg-[#26416b] dark:ring-zinc-700/60">
                            <span class="text-[11px] font-medium uppercase tracking-wide text-zinc-500 dark:text-zinc-400">Coverage</span>
                            <span class="text-sm font-semibold text-zinc-900 dark:text-white">{{ $regionLabel }}</span>
                        </span>
                        <span class="inline-flex flex-col rounded-[12px] bg-[#f4f6f5] px-3 py-2 ring-1 ring-zinc-100 dark:bg-[#26416b] dark:ring-zinc-700/60">
                            <span class="text-[11px] font-medium uppercase tracking-wide text-zinc-500 dark:text-zinc-400">Data</span>
                            <span class="text-sm font-semibold text-zinc-900 dark:text-white" x-text="detailsPlan()?.data || '-'"></span>
                        </span>
                        <span x-show="detailsPlan()?.is_voice" x-cloak class="inline-flex flex-col rounded-[12px] bg-[#f4f6f5] px-3 py-2 ring-1 ring-zinc-100 dark:bg-[#26416b] dark:ring-zinc-700/60">
                            <span class="text-[11px] font-medium uppercase tracking-wide text-zinc-500 dark:text-zinc-400">Calls</span>
                            <span class="text-sm font-semibold text-zinc-900 dark:text-white" x-text="detailsPlan()?.voice || '-'"></span>
                        </span>
                        <span x-show="detailsPlan()?.is_voice" x-cloak class="inline-flex flex-col rounded-[12px] bg-[#f4f6f5] px-3 py-2 ring-1 ring-zinc-100 dark:bg-[#26416b] dark:ring-zinc-700/60">
                            <span class="text-[11px] font-medium uppercase tracking-wide text-zinc-500 dark:text-zinc-400">Texts</span>
                            <span class="text-sm font-semibold text-zinc-900 dark:text-white" x-text="detailsPlan()?.sms || '-'"></span>
                        </span>
                        <span class="inline-flex flex-col rounded-[12px] bg-[#f4f6f5] px-3 py-2 ring-1 ring-zinc-100 dark:bg-[#26416b] dark:ring-zinc-700/60">
                            <span class="text-[11px] font-medium uppercase tracking-wide text-zinc-500 dark:text-zinc-400">Validity</span>
                            <span class="text-sm font-semibold text-zinc-900 dark:text-white" x-text="(detailsPlan()?.days > 0 ? detailsPlan().days + ' days' : 'Flexible')"></span>
                        </span>
                    </div>

                    {{-- iMessage / Apple + verification features - voice plans only. --}}
                    <div x-show="detailsPlan()?.is_voice" x-cloak class="mt-5">
                        <p class="text-sm font-bold text-zinc-900 dark:text-white">iMessage &amp; Apple services</p>
                        <div class="mt-3 grid grid-cols-2 gap-2.5">
                            @foreach ([
                                ['t' => 'Unlimited iMessage', 'd' => 'No limit messaging other Apple devices.', 'icon' => 'M2.25 12.76c0 1.6 1.123 2.994 2.707 3.227 1.087.16 2.185.283 3.293.369V21l4.184-4.183a1.14 1.14 0 01.778-.332 48.294 48.294 0 005.83-.498c1.585-.233 2.708-1.626 2.708-3.228V6.741c0-1.602-1.123-2.995-2.707-3.228A48.394 48.394 0 0012 3c-2.392 0-4.744.175-7.043.513C3.373 3.746 2.25 5.14 2.25 6.741v6.018z'],
                                ['t' => 'iMessage Calls', 'd' => 'Voice calls over iMessage / data.', 'icon' => 'M2.25 6.75c0 8.284 6.716 15 15 15h2.25a2.25 2.25 0 002.25-2.25v-1.372c0-.516-.351-.966-.852-1.091l-4.423-1.106c-.44-.11-.902.055-1.173.417l-.97 1.293c-.282.376-.769.542-1.21.38a12.035 12.035 0 01-7.143-7.143c-.162-.441.004-.928.38-1.21l1.293-.97c.363-.271.527-.734.417-1.173L6.963 3.102a1.125 1.125 0 00-1.091-.852H4.5A2.25 2.25 0 002.25 4.5v2.25z'],
                                ['t' => 'iMessage text', 'd' => 'Send and receive texts over iMessage.', 'icon' => 'M8.625 9.75a.375.375 0 11-.75 0 .375.375 0 01.75 0zm0 0H8.25m4.125 0a.375.375 0 11-.75 0 .375.375 0 01.75 0zm0 0H12m4.125 0a.375.375 0 11-.75 0 .375.375 0 01.75 0zm0 0h-.375M21 12c0 4.556-4.03 8.25-9 8.25a9.764 9.764 0 01-2.555-.337A5.972 5.972 0 015.41 20.97a5.969 5.969 0 01-.474-.065 4.48 4.48 0 00.978-2.025c.09-.457-.133-.901-.467-1.226C3.93 16.178 3 14.189 3 12c0-4.556 4.03-8.25 9-8.25s9 3.694 9 8.25z'],
                                ['t' => 'FaceTime', 'd' => 'Video and audio FaceTime over data.', 'icon' => 'M15.75 10.5l4.72-4.72a.75.75 0 011.28.53v11.38a.75.75 0 01-1.28.53l-4.72-4.72M4.5 18.75h9a2.25 2.25 0 002.25-2.25v-9a2.25 2.25 0 00-2.25-2.25h-9A2.25 2.25 0 002.25 7.5v9a2.25 2.25 0 002.25 2.25z'],
                            ] as $svc)
                                <div class="flex items-start gap-2.5 rounded-[12px] bg-[#f4f6f5] p-3 ring-1 ring-zinc-100 dark:bg-[#26416b] dark:ring-zinc-700/60">
                                    <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-[12px] bg-blue-600/10 text-blue-600 dark:bg-blue-500/20 dark:text-blue-300">
                                        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="{{ $svc['icon'] }}"/></svg>
                                    </span>
                                    <div class="min-w-0">
                                        <p class="text-xs font-bold text-zinc-900 dark:text-white">{{ $svc['t'] }}</p>
                                        <p class="mt-0.5 text-[11px] leading-snug text-zinc-500 dark:text-zinc-400">{{ $svc['d'] }}</p>
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    </div>

                    {{-- Top-up + free iMessage note (voice plans). --}}
                    <div x-show="detailsPlan()?.is_voice" x-cloak class="mt-4 rounded-[12px] bg-[#f4f6f5] p-4 ring-1 ring-zinc-200 dark:bg-blue-500/10 dark:ring-blue-500/20">
                        <p class="flex items-center gap-2 text-sm font-bold text-zinc-900 dark:text-white">
                            <svg class="h-4 w-4 text-blue-600 dark:text-blue-300" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M16.023 9.348h4.992v-.001M2.985 19.644v-4.992m0 0h4.992m-4.993 0l3.181 3.183a8.25 8.25 0 0013.803-3.7M4.031 9.865a8.25 8.25 0 0113.803-3.7l3.181 3.182m0-4.991v4.99"/></svg>
                            Top-ups &amp; iMessage
                        </p>
                        <p class="mt-1 text-sm leading-relaxed text-zinc-600 dark:text-white">You can top up this plan any time for local calls, texts and regular data browsing. Using iMessage is always free and unlimited - it never counts against your allowance.</p>
                    </div>

                    {{-- Data-only note: pure connectivity, no calls/texts/number. --}}
                    <div x-show="detailsPlan() && ! detailsPlan().is_voice" x-cloak class="mt-4 rounded-[12px] bg-[#f4f6f5] p-4 ring-1 ring-zinc-100 dark:bg-[#26416b] dark:ring-zinc-700/60">
                        <p class="flex items-center gap-2 text-sm font-bold text-zinc-900 dark:text-white">
                            <svg class="h-4 w-4 text-zinc-500 dark:text-zinc-300" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M8.288 15.038a5.25 5.25 0 017.424 0M5.106 11.856c3.807-3.808 9.98-3.808 13.788 0M1.924 8.674c5.565-5.565 14.587-5.565 20.152 0M12.53 18.22l-.53.53-.53-.53a.75.75 0 011.06 0z"/></svg>
                            Data only
                        </p>
                        <p class="mt-1 text-sm leading-relaxed text-zinc-600 dark:text-white">This is a data-only plan - it keeps you connected online with mobile data for the full validity period. It has no calls, texts or phone number, just internet access.</p>
                    </div>

                    {{-- Supplier plan policies - every section below renders only
                         when the synced plan data carries it (no invented copy). --}}
                    <div class="mt-5 space-y-4 border-t border-zinc-100 pt-5 dark:border-zinc-700/60">
                        @if ($activationText)
                            <div>
                                <p class="flex items-center gap-2 text-sm font-bold text-zinc-900 dark:text-white">
                                    <svg class="h-4 w-4 text-zinc-500 dark:text-zinc-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6h4.5m4.5 0a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                                    Activation policy
                                </p>
                                <p class="mt-1 text-sm leading-relaxed text-zinc-600 dark:text-white">{{ $activationText }}</p>
                            </div>
                        @endif

                        @if ($planPolicy['rechargeable'] !== null)
                            <div>
                                <p class="flex items-center gap-2 text-sm font-bold text-zinc-900 dark:text-white">
                                    <svg class="h-4 w-4 text-zinc-500 dark:text-zinc-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M3 16.5v2.25A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75V16.5m-13.5-9L12 3m0 0l4.5 4.5M12 3v13.5"/></svg>
                                    Top-up option
                                </p>
                                <p class="mt-1 text-sm leading-relaxed text-zinc-600 dark:text-white">
                                    @if ($planPolicy['rechargeable'])
                                        You can top up this eSIM when your data or validity runs out - top-ups are available once the eSIM is installed.
                                    @else
                                        This package is not rechargeable - when it runs out, purchase a new plan for the same device.
                                    @endif
                                </p>
                            </div>
                        @endif

                        @if ($planPolicy['bullets']->isNotEmpty() || $planPolicy['other'])
                            {{-- Supplier info is product-level and mentions voice / SMS / a US number,
                                 so only show it on voice plans - data-only plans have none of that. --}}
                            <div x-show="detailsPlan()?.is_voice" x-cloak>
                                <p class="flex items-center gap-2 text-sm font-bold text-zinc-900 dark:text-white">
                                    <svg class="h-4 w-4 text-zinc-500 dark:text-zinc-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M11.25 11.25l.041-.02a.75.75 0 011.063.852l-.708 2.836a.75.75 0 001.063.853l.041-.021M21 12a9 9 0 11-18 0 9 9 0 0118 0zm-9-3.75h.008v.008H12V8.25z"/></svg>
                                    Other info
                                </p>
                                @if ($planPolicy['bullets']->isNotEmpty())
                                    <ul class="mt-1 list-disc space-y-1 pl-5 text-sm leading-relaxed text-zinc-600 dark:text-white">
                                        @foreach ($planPolicy['bullets'] as $bullet)
                                            <li>{{ $bullet }}</li>
                                        @endforeach
                                    </ul>
                                @endif
                                @if ($planPolicy['other'])
                                    <p class="mt-1 text-sm leading-relaxed text-zinc-600 dark:text-white">{{ $planPolicy['other'] }}</p>
                                @endif
                            </div>
                        @endif
                    </div>

                    {{-- What happens when the allowance runs out + how to manage the
                         eSIM. Shown for voice plans only; data-only plans intentionally
                         show no message here. --}}
                    <div x-show="detailsPlan()?.is_voice" x-cloak class="mt-4 rounded-[12px] bg-[#f4f6f5] p-4 ring-1 ring-zinc-100 dark:bg-[#26416b] dark:ring-[#24364f]">
                        <p class="text-sm leading-relaxed text-zinc-700 dark:text-white">After your data, SMS and calls finish, you can still call, text and FaceTime over iMessage, WhatsApp and your WiFi. To keep using normal texts, calls and data, top up before you let your eSIM expire. If you plan to keep using it, a top-up auto-renews your eSIM to the top-up plan you choose. On your Web App orders page you can manage your eSIM, see remaining data, credit and SMS, top up your eSIM (only from there) and install your eSIM from there too. Have fun 😊</p>
                    </div>

                    <p class="mt-5 text-xs leading-relaxed text-zinc-500 dark:text-zinc-400">To complete your order, confirm your device is eSIM-compatible and network-unlocked.</p>
                </div>
                </div>
            </div>
        </div>
    </div>

    <script>
        window.esimStore = function ({ plans, cryptos, defaultCrypto, checkoutUrl, rcoinConfig, purchasable }) {
            const cryptoMap = {};
            (cryptos || []).forEach((c) => { cryptoMap[c.code] = c; });

            return {
                rcoinConfig,
                purchasable,          // server truth: eSIMs feature on AND product in stock
                plans: plans || [],
                checkoutUrl,
                cryptos: cryptoMap,
                selectedCrypto: cryptoMap[defaultCrypto] ? defaultCrypto : (cryptos[0]?.code ?? 'USD'),

                activeTab: 'voice',    // 'voice' | 'data' - voice leads; buyers switch to data only
                dataMode: 'standard',  // 'standard' | 'unlimited'
                selectedId: null,
                detailsId: null,       // plan previewed in the details modal (independent of selection)

                // Display-only tier badges, cycled by card position. Each tier maps
                // to a status-dot colour on the global pill (matches x-ui.pill).
                tiers: ['TRIP', 'EXPLORER', 'ADVENTURER', 'NOMAD'],
                tierDotClasses: [null, 'bg-blue-500', 'bg-purple-500', 'bg-amber-500'],

                cartState: 'idle',
                _t: null,

                showNetworks: false,
                showCompat: false,
                showDetails: false,
                compatSearch: '',
                compatOpen: '',

                // The live query, lowercased, but only once it is long enough to
                // narrow anything. One or two characters match most of a list of
                // 368 model names, so below three this returns '' and every
                // caller falls back to showing the full list.
                compatTerm() {
                    const q = this.compatSearch.trim().toLowerCase();
                    return q.length >= 3 ? q : '';
                },

                // A manufacturer is open either because it was tapped, or because
                // the current search matched inside it. Reading the make and its
                // models off the row's own dataset keeps those strings in the one
                // place they already exist, rather than repeating all 368 of them
                // in a second attribute.
                compatIsOpen(el, key) {
                    const q = this.compatTerm();
                    if (q === '') return this.compatOpen === key;

                    const row = el.closest('[data-brand]');
                    return !! row && (row.dataset.brand.includes(q) || row.dataset.models.includes(q));
                },

                // How many manufacturers the current search leaves on screen, for
                // the empty state. Counted off the DOM for the same reason.
                compatMatches() {
                    const q = this.compatTerm();
                    if (q === '') return 1;

                    return Array.from(this.$el.querySelectorAll('[data-brand][data-models]'))
                        .filter((row) => row.dataset.brand.includes(q) || row.dataset.models.includes(q))
                        .length;
                },

                locTab: 'popular',
                locSearch: '',

                init() {
                    // Voice leads; fall back to data only when this region carries no voice plans.
                    if (! this.tabHasPlans('voice') && this.tabHasPlans('data')) {
                        this.activeTab = 'data';
                    }
                    this.normalizeMode();
                    // No auto-selection - the buyer chooses a package by tapping it.
                },

                // ---- filtering ----
                inTab(p) { return this.activeTab === 'voice' ? p.is_voice : ! p.is_voice; },
                tabHasPlans(tab) { return this.plans.some((p) => (tab === 'voice' ? p.is_voice : ! p.is_voice)); },
                // Categories this region actually carries, in display order. Drives
                // the segmented control + its sliding bubble width/offset.
                tabList() {
                    const t = [];
                    if (this.tabHasPlans('voice')) { t.push('voice'); }
                    if (this.tabHasPlans('data')) { t.push('data'); }
                    return t.length ? t : ['voice'];
                },
                tabIndex() { return Math.max(0, this.tabList().indexOf(this.activeTab)); },
                currentTabPlans() { return this.plans.filter((p) => this.inTab(p)); },
                modeHas(mode) { return this.currentTabPlans().some((p) => (mode === 'unlimited' ? p.is_unlimited : ! p.is_unlimited)); },
                // Only show the Standard/Unlimited toggle when the CURRENT tab actually
                // has both kinds — so it appears on Data but not on Data/Calls/Texts
                // (which has no unlimited tier).
                showModeToggle() { return this.modeHas('standard') && this.modeHas('unlimited'); },
                normalizeMode() {
                    if (! this.modeHas(this.dataMode)) {
                        this.dataMode = this.modeHas('standard') ? 'standard' : 'unlimited';
                    }
                },
                visiblePlans() {
                    return this.currentTabPlans().filter((p) => (this.showModeToggle()
                        ? (this.dataMode === 'unlimited' ? p.is_unlimited : ! p.is_unlimited)
                        : true));
                },
                // Flat list for the plan cards, cheapest first (matches the badge order).
                sortedPlans() {
                    return this.visiblePlans().slice().sort((a, b) => (a.price - b.price));
                },
                setTab(tab) { this.activeTab = tab; this.normalizeMode(); this.selectedId = null; },
                setMode(mode) { this.dataMode = mode; this.selectedId = null; },

                // ---- selected plan + pricing ----
                plan() { return this.plans.find((p) => p.id === this.selectedId) || null; },
                // Plan shown in the details modal: the previewed one (See more), else the selected one.
                detailsPlan() { return this.plans.find((p) => p.id === this.detailsId) || this.plan(); },
                unitPriceUsd() { return this.plan()?.price || 0; },
                totalUsd() { return this.unitPriceUsd(); },
                // Calculated from backend settings.
                pointsEarned() {
                    const cashbackUsd = this.totalUsd() * (this.rcoinConfig.cashback_percentage / 100);
                    return Math.floor(cashbackUsd / this.rcoinConfig.usd_rate);
                },

                money(usd) {
                    const meta = this.cryptos[this.selectedCrypto];
                    if (! meta) { return '$' + Number(usd).toFixed(2); }
                    const val = (Number(usd) * meta.perUsd).toFixed(meta.decimals);
                    // Glyph symbol only ("$8.50"); otherwise the code ("8.50 XAF").
                    return meta.symbol ? (meta.symbol + val) : (val + ' ' + this.selectedCrypto);
                },
                rowPrice(p) { return this.money(p.price); },
                totalLabel() { return this.money(this.totalUsd()); },

                // ---- cart ----
                async addToCart() {
                    if (! this.purchasable || this.cartState !== 'idle' || ! this.selectedId) { return false; }
                    this.cartState = 'loading';
                    const ok = await this.$store.cart.add(this.selectedId, 1);
                    if (ok) {
                        this.cartState = 'success';
                        clearTimeout(this._t);
                        this._t = setTimeout(() => { this.cartState = 'idle'; }, 1600);
                    } else {
                        this.cartState = 'idle';
                    }
                    return ok;
                },
                async buyNow() {
                    if (! this.purchasable || ! this.selectedId) { return; }
                    const ok = await this.$store.cart.add(this.selectedId, 1, null, null, true);
                    if (ok) { window.location.href = this.checkoutUrl; }
                },
            };
        };
    </script>

</x-shop.layout>

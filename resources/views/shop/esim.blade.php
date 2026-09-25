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
            $dataLabel = __('esim.unlimited');
        } elseif ($dl !== '' && strcasecmp($dl, 'Unknown') !== 0) {
            $dataLabel = $dl;
        } else {
            $gb        = (float) ($raw['dataGB'] ?? 0);
            $dataLabel = $gb > 0 ? rtrim(rtrim(number_format($gb, 2), '0'), '.').' GB' : __('esim.chip_data');
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
        $voiceLabel = $hasVoice ? __('esim.plan_minutes', ['count' => $voiceVal]) : null;
        $smsLabel   = $hasSms ? __('esim.plan_sms', ['count' => $smsVal]) : null;
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
        'first-usage' => __('esim.activation_first_usage'),
        'installation' => __('esim.activation_installation'),
        'automatic' => __('esim.activation_automatic'),
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
        ? __('esim.why_pay_languages', ['languages' => \Illuminate\Support\Arr::join($whyLanguages, ', ', ' '.__('products.detail_list_and').' ')])
        : __('esim.why_pay');

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

<x-shop.layout :title="__('esim.page_title', ['region' => $regionLabel]).' | '.$siteName" :og-image="asset('assets/'.rawurlencode('Esim.webp'))">

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
            <a href="{{ $shopRoute('esims') }}" wire:navigate class="font-medium transition-colors hover:text-zinc-900 dark:hover:text-white">{{ __('esim.breadcrumb_store') }}</a>
            <span aria-hidden="true">&rsaquo;</span>
            <a href="{{ $shopRoute('esims', ['scope' => strtolower($curScope)]) }}" wire:navigate class="transition-colors hover:text-zinc-900 dark:hover:text-white">{{ __('esim.title_'.strtolower($curScope)) }}</a>
            <span aria-hidden="true">&rsaquo;</span>
            <span class="font-semibold text-zinc-900 dark:text-white">{{ $regionLabel }}</span>
        </nav>

        {{-- ── Country header (glass card, centered) ─────────────────────── --}}
        {{-- z-40: this card opens the region dropdown, which hangs well below
             its own bottom edge, and z-index on this card makes it a stacking
             context that the dropdown can never escape. So this number has to
             beat everything the list hangs over: the plan selector below it
             sticks at md:z-30, and at z-20 the selector painted straight
             through the middle of the open list. It stays under the nav
             (z-50), which the list should still pass beneath. The card itself
             never overlaps the sticky selector - the selector pins at the nav
             edge only once this card has scrolled above it - so lifting it
             costs nothing while it is closed. --}}
        <div class="esim-tile relative z-40 mx-auto mt-4 w-full max-w-[1100px] rounded-[12px] bg-[#ffffff] p-6 ring-1 ring-zinc-200 sm:p-8 dark:ring-zinc-700/60">
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
                        <h1 class="truncate text-2xl font-bold tracking-tight text-zinc-900 sm:text-3xl dark:text-white">{{ __('esim.heading_region', ['region' => $regionLabel]) }}</h1>
                    </div>

                    <div class="my-5 h-px bg-[#f4f6f5] dark:bg-zinc-700/60"></div>

                    {{-- Supported networks (real provider data) --}}
                    <div class="flex flex-wrap items-center gap-3">
                        @if ($coverageCount > 1)
                            <button type="button" @click="showNetworks = true" class="inline-flex items-center gap-2 text-sm font-semibold text-zinc-900 underline-offset-2 hover:underline dark:text-white">
                                <svg class="h-4 w-4 text-zinc-500 dark:text-zinc-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75" aria-hidden="true">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 21a9 9 0 100-18 9 9 0 000 18zm0 0c2.5 0 4.5-4.03 4.5-9S14.5 3 12 3 7.5 7.03 7.5 12s2 9 4.5 9zM3.6 9h16.8M3.6 15h16.8"/>
                                </svg>
                                {{ __('esim.countries_and_networks_count', ['count' => $coverageCount]) }}
                            </button>
                        @elseif ($carrierNetworks->isNotEmpty())
                            <span class="inline-flex items-center gap-2 text-sm font-semibold text-zinc-900 dark:text-white">
                                <img src="{{ asset('assets/'.rawurlencode('Network Svg.svg')) }}" alt="" class="h-4 w-4 dark:invert" aria-hidden="true">
                                {{ $carrierNetworks->first()['name'] }}
                            </span>
                            <button type="button" @click="showNetworks = true" class="text-sm font-semibold text-blue-600 transition-colors hover:text-blue-700 dark:text-blue-400 dark:hover:text-blue-300">{{ __('esim.networks') }}</button>
                        @endif
                    </div>

                    {{-- Check compatibility --}}
                    <div class="mt-4">
                        <button type="button" @click="showCompat = true" class="inline-flex items-center gap-2 rounded-full bg-blue-600 px-4 py-2 text-sm font-semibold text-white transition-colors hover:bg-blue-700 focus:outline-none focus-visible:ring-2 focus-visible:ring-blue-500/40">
                            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75" aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M10.5 1.5H8.25A2.25 2.25 0 006 3.75v16.5a2.25 2.25 0 002.25 2.25h7.5A2.25 2.25 0 0018 20.25V3.75a2.25 2.25 0 00-2.25-2.25H13.5m-3 0V3h3V1.5m-3 0h3m-3 18.75h3"/>
                            </svg>
                            {{ __('esim.check_compatibility') }}
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
                                <span>{!! str_replace(':topup', '<span class="font-semibold text-zinc-900 dark:text-white">'.e(__('esim.topup_word')).'</span>', e(__('esim.topup_note'))) !!}</span>
                            </li>
                        @endif
                        <li class="flex items-center gap-2">
                            <svg class="h-4 w-4 shrink-0 text-emerald-600 dark:text-emerald-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5"/></svg>
                            {{ __('esim.starts_on_connect') }}
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
                                {!! strtr(e(__('esim.guard_warning')), [
                                    ':region' => '<span class="font-semibold">'.e($regionLabel).'</span>',
                                    ':local' => e($regionLabel),
                                    ':link' => '<a href="'.e($shopRoute('esim', $discoverGlobalSlug)).'" wire:navigate class="font-semibold text-amber-900 underline underline-offset-2 hover:text-amber-950 dark:text-white dark:hover:text-blue-200">'.e(__('esim.guard_link')).'</a>',
                                ]) !!}
                            </p>
                        </div>
                    @endunless

                </div>

                {{-- Region switcher --}}
                <div class="w-full shrink-0 sm:w-64">
                    <label class="mb-1.5 block text-xs font-semibold text-zinc-900 dark:text-zinc-200">{{ __('esim.change_region') }}</label>
                    <div x-data="{ open: false, search: '' }" @click.outside="open = false; search = ''" @keydown.escape.window="open = false; search = ''" class="relative">
                        <button
                            type="button"
                            @click="open = ! open; if (open) $nextTick(() => $refs.regionSearch?.focus())"
                            {{-- White edge in dark mode, not zinc-700: on the dark
                                 panel that border was near invisible, so the
                                 control did not read as a control. --}}
                            :class="open ? 'border-edge dark:border-edge' : 'border-zinc-200 hover:border-zinc-300 dark:border-white/10 dark:hover:border-white/25'"
                            class="flex h-9 w-full items-center gap-2 rounded-full border-2 bg-transparent px-3.5 text-sm text-zinc-900 outline-none transition-colors dark:text-white"
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
                                    <input x-ref="regionSearch" x-model="search" type="text" placeholder="{{ __('esim.search_region') }}" aria-label="{{ __('esim.search_region') }}" class="w-full rounded-full border-2 border-edge bg-white py-2.5 pl-9 pr-3 text-sm text-zinc-900 placeholder:text-zinc-500 outline-none transition-colors focus:ring-2 focus:ring-edge/30 dark:border-edge dark:bg-[#26416b] dark:text-white dark:placeholder:text-zinc-400">
                                </div>
                            </div>
                            <div
                                class="max-h-72 overflow-y-auto overscroll-contain p-1 [-webkit-overflow-scrolling:touch]"
                                role="listbox"
                                aria-label="{{ __('esim.choose_region') }}"
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
                                ['t' => __('esim.instant_activation'), 'icon' => 'M3.75 13.5l10.5-11.25L12 10.5h8.25L9.75 21.75 12 13.5H3.75z'],
                                ['t' => __('esim.no_id_required'), 'icon' => 'M9 12.75L11.25 15 15 9.75m-3-7.036A11.959 11.959 0 013.598 6 11.99 11.99 0 003 9.749c0 5.592 3.824 10.29 9 11.623 5.176-1.332 9-6.03 9-11.622 0-1.31-.21-2.571-.598-3.751h-.152c-3.196 0-6.1-1.248-8.25-3.285z'],
                            ];

                            if ($coverageCount > 1) {
                                $headerFeatures[] = [
                                    't' => __('esim.covers_countries', ['count' => number_format($coverageCount)]),
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
                    <p class="text-sm font-semibold {{ $product->is_out_of_stock ? 'text-zinc-900 dark:text-white' : 'text-amber-900 dark:text-amber-200' }}">{{ $product->is_out_of_stock ? __('products.out_of_stock') : __('esim.unavailable_title') }}</p>
                    <p class="mt-0.5 text-xs {{ $product->is_out_of_stock ? 'text-zinc-500 dark:text-zinc-400' : 'text-amber-800/80 dark:text-amber-200/70' }}">{{ __('esim.unavailable_view') }}</p>
                </div>
            </div>
        @endunless

        @if ($hasPlans)
            {{-- The selector and the plans it filters, in one box.

                 This wrapper is the whole reason the pinned card lets go at
                 the right moment. A sticky element is bound by its containing
                 block: with the card sitting directly in the page column its
                 containing block was the entire page, so it stayed pinned
                 under the nav through the "Why choose us" panel, the FAQ and
                 everything after - a control for a list that had ended. Inside
                 this box it releases at the last plan and scrolls away with
                 the rest of the page, which is the behaviour without any
                 scroll handler to write. --}}
            <div>

            {{-- ── Selector card (glass): holds the data/voice + fixed/unlimited
                 segmented controls only. The plan cards live outside it, below. ──

                 It rides under the nav while those plan cards scroll past, so
                 the control that decides which plans are listed is never
                 scrolled away from the list it decides. Offset by the bar's
                 real published height (--nav-h, republished as the category
                 strip folds), flush under it so nothing travels through a gap
                 between the two. Same treatment as the location tabs on the
                 eSIM landing page.

                 The card itself is what sticks, not the segmented control
                 inside it: this element carries overflow-hidden for its own
                 radius, and an overflow other than visible on an ancestor
                 stops a descendant sticking at all. On itself it is harmless -
                 it clips its children, not its own positioning.

                 It needs no band behind it the way the landing page's pill
                 did: it is already an opaque bordered card the full width of
                 the column, so the grid passes behind it rather than through
                 it.

                 Desktop only, as on the landing page: this card is two rows
                 of controls on a phone, and pinning it there would hold back
                 a third of a short viewport from the plans it is choosing
                 between. Below md it scrolls away with the page. --}}
            <div id="esim-packages" class="mx-auto mt-6 w-full max-w-[1100px] scroll-mt-24 overflow-hidden rounded-[14px] bg-[#ffffff] border border-zinc-200 md:sticky md:top-[var(--nav-h,64px)] md:z-30 dark:bg-[#0c1a36] dark:border-zinc-700">
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
                                class="relative z-10 flex-1 rounded-full px-2 py-2.5 text-sm font-bold leading-tight transition-colors sm:px-4"
                                x-text="t === 'voice' ? @js(__('esim.tab_voice')) : @js(__('esim.tab_data'))"
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
                        <button type="button" @click="setMode('standard')" :class="dataMode === 'standard' ? 'text-white' : 'text-zinc-600 hover:text-zinc-900 dark:text-zinc-300 dark:hover:text-white'" class="relative z-10 flex-1 rounded-full px-4 py-2 text-sm font-semibold transition-colors">{{ __('esim.mode_fixed') }}</button>
                        <button type="button" @click="setMode('unlimited')" :class="dataMode === 'unlimited' ? 'text-white' : 'text-zinc-600 hover:text-zinc-900 dark:text-zinc-300 dark:hover:text-white'" class="relative z-10 flex-1 rounded-full px-4 py-2 text-sm font-semibold transition-colors">{{ __('esim.unlimited') }}</button>
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
                        <x-home.brand-row :title="__('esim.choose_package')" :carousel="true" :bleed="! $inDash" view-all-variant="none">
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
                                        <span class="shrink-0 rounded-full bg-[#0c1337] px-2 py-0.5 text-[10px] uppercase tracking-wide text-white dark:bg-[#fdfdfd] dark:text-[#0c1337]">{{ __('esim.badge_calls') }}</span>
                                    </template>
                                    <template x-if="! p.is_voice">
                                        <span class="shrink-0 rounded-full bg-[#0c1337] px-2 py-0.5 text-[10px] uppercase tracking-wide text-white dark:bg-[#fdfdfd] dark:text-[#0c1337]">{{ __('esim.chip_data') }}</span>
                                    </template>
                                </span>

                                <span class="mt-3 block truncate text-[17px] text-[#0c1337] dark:text-white" x-text="p.is_unlimited ? @js(__('products.detail_unlimited_data')) : p.data"></span>

                                <span class="mt-2 flex flex-wrap items-center gap-1.5">
                                    <span class="{{ $planChip }} tabular-nums" x-text="(p.days === 1 ? @js(__('esim.day_one')) : @js(__('esim.days_other'))).replace(':count', p.days)"></span>

                                    <template x-if="p.is_unlimited">
                                        <span class="rounded-full bg-[#0c1337] px-2.5 py-1 text-[12px] text-white dark:bg-[#fdfdfd] dark:text-[#0c1337]">{{ __('esim.unlimited') }}</span>
                                    </template>

                                    {{-- One chip per thing the package carries. Where there is no
                                         number, the single chip says so: "Data only" is the fact a
                                         traveller needs, where a bare "Data" leaves them wondering
                                         about calls. Same wording as the store cards. --}}
                                    <template x-if="p.is_voice">
                                        <span class="{{ $planChip }}">{{ __('esim.chip_data') }}</span>
                                    </template>
                                    <template x-if="p.is_voice">
                                        <span class="{{ $planChip }}">{{ __('esim.chip_phone_number') }}</span>
                                    </template>

                                    {{-- What the number is actually for, on the card rather
                                         than three scrolls down in the details sheet: the
                                         messaging apps a traveller wants to keep working are
                                         the reason to pay for a number at all. Only on plans
                                         that carry one - a data-only plan has no number to
                                         verify against.

                                         The same claim the voice tab already makes under
                                         "Every voice plan includes" (app verification, and
                                         unlimited messaging to Apple devices), so the card
                                         and the panel under it cannot say different things.

                                         Text chips, not brand marks: the repo carries no
                                         artwork for either, and neither logo is ours to
                                         redraw from memory. --}}
                                    <template x-if="p.is_voice">
                                        <span class="{{ $planChip }}">WhatsApp</span>
                                    </template>
                                    <template x-if="p.is_voice">
                                        <span class="{{ $planChip }}">iMessage</span>
                                    </template>

                                    <template x-if="! p.is_voice">
                                        <span class="{{ $planChip }}">{{ __('esim.chip_data_only') }}</span>
                                    </template>

                                    <template x-if="p.is_voice && p.voice">
                                        <span class="{{ $planChip }}" x-text="p.voice"></span>
                                    </template>
                                    <template x-if="p.is_voice && p.sms">
                                        <span class="{{ $planChip }}" x-text="p.sms"></span>
                                    </template>

                                    {{-- Rechargeable, per plan. The page already says it
                                         once in the notes under the buy panel, but that is
                                         a claim about the destination: it shows whenever
                                         ANY plan here can be topped up, so on a destination
                                         where most can and a few cannot, the note is true
                                         and the card you are reading may not be. The chip
                                         is read off the same flag the note counts, so the
                                         two cannot disagree. --}}
                                    <template x-if="p.top_up">
                                        <span class="{{ $planChip }}">{{ __('esim.chip_top_up') }}</span>
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
                                    @click.stop="selectedId = p.id; showBuy = true"
                                    @keydown.enter.stop="selectedId = p.id; showBuy = true"
                                    role="button" tabindex="0"
                                    class="mt-4 block w-full cursor-pointer rounded-full bg-[#0c1337] px-3 py-2 text-center text-[14px] text-white transition-colors hover:bg-[#1a2560] dark:bg-[#fdfdfd] dark:text-[#0c1337] dark:hover:bg-[#e4e5f3]"
                                >{{ __('esim.get_esim') }}</span>

                                <span
                                    @click.stop="detailsId = p.id; showDetails = true"
                                    @keydown.enter.stop="detailsId = p.id; showDetails = true"
                                    role="button" tabindex="0"
                                    class="mt-2 block w-full cursor-pointer text-center text-[13px] text-zinc-500 transition-colors hover:text-zinc-900 dark:text-zinc-400 dark:hover:text-white"
                                >{{ __('products.see_more') }}</span>
                            </button>
                        </template>
                        </x-home.brand-row>
                    </div>
                </template>
                    <p x-show="sortedPlans().length === 0" class="mt-4 rounded-[12px] bg-[#ffffff] px-4 py-8 text-center text-sm text-zinc-600 ring-1 ring-zinc-100 dark:bg-[#1d3252] dark:text-zinc-400 dark:ring-zinc-700">{{ __('esim.no_packages') }}</p>

                    {{-- Voice-plan inclusions - only on the Voice tab.
                         Each inclusion is its own card. --}}
                    <div x-show="activeTab === 'voice'" x-cloak class="mt-6">
                        <p class="text-sm font-bold text-zinc-900 dark:text-white">{{ __('esim.voice_includes') }}</p>
                        <div class="mt-3 grid grid-cols-1 gap-3 sm:grid-cols-3">
                            @foreach ([
                                ['title' => __('esim.voice_app_title'), 'desc' => __('esim.voice_app_desc'), 'icon' => 'M9 12.75L11.25 15 15 9.75M21 12a9 9 0 11-18 0 9 9 0 0118 0z'],
                                ['title' => __('esim.voice_imessage_title'), 'desc' => __('esim.voice_imessage_desc'), 'icon' => 'M2.25 12.76c0 1.6 1.123 2.994 2.707 3.227 1.087.16 2.185.283 3.293.369V21l4.184-4.183a1.14 1.14 0 01.778-.332 48.294 48.294 0 005.83-.498c1.585-.233 2.708-1.626 2.708-3.228V6.741c0-1.602-1.123-2.995-2.707-3.228A48.394 48.394 0 0012 3c-2.392 0-4.744.175-7.043.513C3.373 3.746 2.25 5.14 2.25 6.741v6.018z'],
                                ['title' => __('esim.voice_topup_title'), 'desc' => __('esim.voice_topup_desc'), 'icon' => 'M16.023 9.348h4.992v-.001M2.985 19.644v-4.992m0 0h4.992m-4.993 0l3.181 3.183a8.25 8.25 0 0013.803-3.7M4.031 9.865a8.25 8.25 0 0113.803-3.7l3.181 3.182m0-4.991v4.99'],
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
                                :title="__('esim.broader_title')"
                                :subtitle="__('esim.broader_subtitle')"
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

            </div>{{-- /selector + plans: the sticky card releases here --}}

            {{-- ── Why choose us (1000px centered) ───────────────────────────── --}}
            <section class="mx-auto mt-8 w-full max-w-[1100px] rounded-[20px] bg-[#ffffff] p-6 sm:p-10 dark:bg-[#1d3252]">
                <h2 class="text-center text-2xl font-bold tracking-tight text-zinc-900 sm:text-3xl dark:text-white">{{ __('esim.why_heading', ['site' => $siteName]) }}</h2>
                <div class="mt-8 grid grid-cols-2 gap-6 lg:grid-cols-4">
                    @foreach ([
                        ['t' => __('esim.why_coverage', ['count' => number_format($whyDestinations)]), 'd' => 'M12 21a9 9 0 100-18 9 9 0 000 18zm0 0c2.5 0 4.5-4.03 4.5-9S14.5 3 12 3 7.5 7.03 7.5 12s2 9 4.5 9zM3.6 9h16.8M3.6 15h16.8'],
                        ['t' => __('esim.why_flexible'), 'd' => 'M3 7.5L7.5 3m0 0L12 7.5M7.5 3v13.5m13.5 0L16.5 21m0 0L12 16.5m4.5 4.5V7.5'],
                        ['t' => $whyLanguageLine, 'image' => 'total transactions.svg'],
                        ['t' => __('esim.why_install'), 'd' => 'M3.75 4.5a.75.75 0 01.75-.75h3a.75.75 0 01.75.75v3a.75.75 0 01-.75.75h-3a.75.75 0 01-.75-.75v-3zm12 0a.75.75 0 01.75-.75h3a.75.75 0 01.75.75v3a.75.75 0 01-.75.75h-3a.75.75 0 01-.75-.75v-3zm-12 12a.75.75 0 01.75-.75h3a.75.75 0 01.75.75v3a.75.75 0 01-.75.75h-3a.75.75 0 01-.75-.75v-3zM15 15.75h.008v.008H15v-.008zm0 3h.008v.008H15v-.008zm3-3h.008v.008H18v-.008zm0 3h.008v.008H18v-.008zm3-3h.008v.008H21v-.008zm0 3h.008v.008H21v-.008z'],
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
                        'title' => __('esim.how_step_1_title'),
                        'body'  => __('esim.how_step_1_body'),
                    ],
                    [
                        'image' => 'esim-how-to-2.webp',
                        'title' => __('esim.how_step_2_title'),
                        'body'  => __('esim.how_step_2_body'),
                    ],
                    [
                        'image' => 'esim-how-to-3.webp',
                        'title' => __('esim.how_step_3_title'),
                        'body'  => __('esim.how_step_3_body'),
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
                    <h2 class="text-2xl font-bold tracking-tight text-zinc-900 sm:text-3xl dark:text-white">{{ __('esim.how_heading') }}</h2>
                    <p class="mt-2 max-w-xl text-sm leading-relaxed text-zinc-600 sm:text-base dark:text-zinc-300">{{ __('esim.how_subtitle') }}</p>
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
                    <a href="#esim-packages" class="inline-flex items-center rounded-full bg-blue-600 px-5 py-2.5 text-sm font-semibold text-white transition-colors hover:bg-blue-700">
                        {{ __('esim.choose_package') }}
                    </a>
                    <a href="{{ route('shop.help') }}" wire:navigate class="text-sm font-semibold text-zinc-700 underline-offset-2 transition-colors hover:text-blue-600 hover:underline dark:text-zinc-300 dark:hover:text-blue-300">{{ __('esim.how_guide') }}</a>
                </div>
                </div>
            </section>

            {{-- ── Anonymous eSIM with crypto (no KYC) ──────────────────────── --}}
            <section class="mx-auto mt-12 grid w-full max-w-[1450px] grid-cols-1 items-start gap-10 lg:grid-cols-2 lg:gap-16">
                <div>
                    <span class="flex h-12 w-12 items-center justify-center rounded-[12px] bg-[#ffffff] ring-1 ring-zinc-200 dark:bg-blue-950/40 dark:ring-blue-900/40">
                        <svg class="h-6 w-6 text-blue-600 dark:text-blue-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75L11.25 15 15 9.75M21 12c0 1.268-.63 2.39-1.593 3.068a3.745 3.745 0 01-1.043 3.296 3.745 3.745 0 01-3.296 1.043A3.745 3.745 0 0112 21c-1.268 0-2.39-.63-3.068-1.593a3.746 3.746 0 01-3.296-1.043 3.745 3.745 0 01-1.043-3.296A3.745 3.745 0 013 12c0-1.268.63-2.39 1.593-3.068a3.745 3.745 0 011.043-3.296 3.746 3.746 0 013.296-1.043A3.746 3.746 0 0112 3c1.268 0 2.39.63 3.068 1.593a3.746 3.746 0 013.296 1.043 3.746 3.746 0 011.043 3.296A3.745 3.745 0 0121 12z"/></svg>
                    </span>
                    <h2 class="mt-4 text-2xl font-bold tracking-tight text-zinc-900 sm:text-3xl dark:text-white">{{ __('esim.anon_heading') }}</h2>
                    <p class="mt-3 max-w-xl text-sm leading-relaxed text-zinc-600 sm:text-base dark:text-zinc-300">{{ __('esim.anon_body', ['site' => $siteName]) }}</p>
                </div>

                <ul class="space-y-5">
                    @foreach ([
                        ['t' => __('esim.anon_kyc_title'), 'b' => __('esim.anon_kyc_body')],
                        ['t' => __('esim.anon_pay_title'), 'b' => __('esim.anon_pay_body')],
                        ['t' => __('esim.anon_delivery_title'), 'b' => __('esim.anon_delivery_body')],
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
                <h2 class="text-2xl font-bold tracking-tight text-zinc-900 sm:text-3xl dark:text-white">{{ __('esim.payments_heading') }}</h2>
                <p class="mt-2 max-w-xl text-sm leading-relaxed text-zinc-600 sm:text-base dark:text-zinc-300">{{ __('esim.payments_subtitle') }}</p>

                {{-- The track holds TWO copies of the list and travels exactly
                     -50%, so the second copy sits where the first began at the
                     moment the animation restarts and the loop has no seam. That
                     holds for any item width, since both copies are identical:
                     half the track is always exactly one copy. The clone is
                     aria-hidden, so a screen reader hears each method once.
                     Hover pauses it; prefers-reduced-motion stops it. --}}
                <div class="pay-marquee mt-6 overflow-hidden" role="group" aria-label="{{ __('esim.payments_label') }}">
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
        @else
            <div class="mt-8 rounded-[12px] bg-[#ffffff] px-4 py-10 text-center ring-1 ring-zinc-100 dark:bg-[#1d3252] dark:ring-zinc-700/60">
                <p class="text-base font-semibold text-zinc-900 dark:text-white">{{ __('esim.no_plans_title') }}</p>
                <p class="mt-1 text-sm text-zinc-600 dark:text-zinc-400">{{ __('esim.no_plans_body') }}</p>
                <a href="{{ $shopRoute('esims') }}" wire:navigate class="mt-4 inline-flex items-center gap-1.5 text-sm font-semibold text-blue-600 hover:text-blue-700 dark:text-blue-400 dark:hover:text-blue-300">{{ __('esim.browse_other_regions') }}</a>
            </div>
        @endif

        {{-- ════ Modals ═══════════════════════════════════════════════════════ --}}

        {{-- Purchase modal: what "Get eSIM" opens.

             It answers the three things a buyer needs between choosing a plan
             and paying for it: where it works, what it will cost them in the
             currency they pay in, and how to commit. It replaces a banner that
             was pinned to the top of the page for the same job, where it
             covered the plans it was describing and scrolled with nothing.

             Same shape as the Networks and Compatibility modals on this page:
             a fixed z-[70] layer, a tap-anywhere backdrop, and a sheet that
             rises from the bottom on a phone and centres from sm. The page
             uses style="display:none;" rather than x-cloak for modals, so the
             sheet cannot flash before Alpine boots. --}}
        <div
            x-show="showBuy"
            style="display: none;"
            @keydown.escape.window="showBuy = false"
            class="fixed inset-0 z-[70] flex items-end justify-center px-3 pb-3 sm:items-center sm:p-4"
            role="dialog"
            aria-modal="true"
            aria-labelledby="buy-title"
        >
            <div x-show="showBuy" @click="showBuy = false" x-transition.opacity class="absolute inset-0 bg-zinc-900/50 dark:bg-zinc-950/70"></div>

            <div x-show="showBuy" x-transition class="relative flex max-h-[85vh] w-full max-w-[560px] flex-col overflow-hidden rounded-[12px] bg-[#eff6ff] shadow-2xl ring-1 ring-zinc-200 dark:bg-[#0c1a36] dark:ring-[#24364f]">

                {{-- Header: the plan being bought, so the sheet never floats
                     free of what opened it. --}}
                <div class="flex items-start justify-between gap-4 border-b border-zinc-200 p-5 dark:border-zinc-700/60">
                    <div class="min-w-0">
                        <h2 id="buy-title" class="text-lg font-bold text-zinc-900 dark:text-white">{{ __('esim.page_title', ['region' => $regionLabel]) }}</h2>
                        <p class="mt-0.5 truncate text-sm text-zinc-600 dark:text-zinc-300">
                            <span x-text="plan()?.data"></span>
                            <span x-show="plan()?.days > 0"> &middot; <span x-text="(plan()?.days === 1 ? @js(__('esim.day_one')) : @js(__('esim.days_other'))).replace(':count', plan()?.days)"></span></span>
                            <span x-show="plan()?.is_voice"> &middot; {{ __('esim.calls_and_texts') }}</span>
                        </p>
                    </div>

                    <button type="button" @click="showBuy = false" aria-label="{{ __('common.close') }}" class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-[#dde3ee] text-zinc-600 transition-colors hover:bg-[#cdd5e4] hover:text-zinc-900 dark:bg-white/10 dark:text-zinc-300 dark:hover:bg-white/20 dark:hover:text-white">
                        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
                        </svg>
                    </button>
                </div>

                <div class="min-h-0 flex-1 overflow-y-auto p-5">

                    {{-- Coverage. Every figure here is the page's own synced
                         data - the country list the Networks modal renders, or
                         the carriers captured per operator - so this sheet
                         cannot promise coverage the plan does not carry. --}}
                    <p class="text-xs font-bold uppercase tracking-wide text-zinc-500 dark:text-zinc-400">{{ __('esim.coverage') }}</p>

                    @if ($coverageCount > 1)
                        <p class="mt-2 text-sm leading-relaxed text-zinc-700 dark:text-zinc-200">
                            {!! str_replace(':countries', '<span class="font-bold text-zinc-900 dark:text-white">'.e(__('esim.countries_count', ['count' => $coverageCount])).'</span>', e(__('esim.buy_works_across'))) !!}
                        </p>

                        {{-- The first dozen by name, then the count of the
                             rest: a 100-country list is a scroll, not an
                             answer, and the full list is one tap away in the
                             modal that already renders it. --}}
                        <div class="mt-3 flex flex-wrap gap-1.5">
                            @foreach ($coverageList->take(12) as $country)
                                <span class="rounded-full bg-[#ffffff] px-2.5 py-1 text-[12px] text-[#0c1337] ring-1 ring-zinc-200 dark:bg-white/10 dark:text-zinc-200 dark:ring-white/10">{{ $country }}</span>
                            @endforeach

                            @if ($coverageCount > 12)
                                <button type="button" @click="showBuy = false; showNetworks = true" class="rounded-full bg-[#ffffff] px-2.5 py-1 text-[12px] font-semibold text-blue-600 ring-1 ring-zinc-200 transition-colors hover:text-blue-700 dark:bg-white/10 dark:text-blue-300 dark:ring-white/10">
                                    {{ __('esim.more_count', ['count' => $coverageCount - 12]) }}
                                </button>
                            @endif
                        </div>
                    @else
                        <p class="mt-2 text-sm leading-relaxed text-zinc-700 dark:text-zinc-200">
                            {!! str_replace(':region', '<span class="font-bold text-zinc-900 dark:text-white">'.e($regionLabel).'</span>', e(__('esim.buy_works_in'))) !!}
                        </p>

                        @if ($carrierNetworks->isNotEmpty())
                            <div class="mt-3 flex flex-wrap gap-1.5">
                                @foreach ($carrierNetworks as $net)
                                    <span class="rounded-full bg-[#ffffff] px-2.5 py-1 text-[12px] text-[#0c1337] ring-1 ring-zinc-200 dark:bg-white/10 dark:text-zinc-200 dark:ring-white/10">{{ $net['name'] }}</span>
                                @endforeach
                            </div>
                        @endif
                    @endif

                    {{-- Currency. The same cryptos / selectedCrypto pair the
                         rest of the page prices in, so changing it here moves
                         every figure on the page with it, not just this
                         sheet's. --}}
                    <p class="mt-6 text-xs font-bold uppercase tracking-wide text-zinc-500 dark:text-zinc-400">{{ __('esim.pay_in') }}</p>

                    <div
                        x-data="{
                            open: false,
                            /* The list now grows the sheet's scroll body, which
                               may be scrolled away from it, and the chosen row
                               can sit far down a list of 49. Bring both into
                               view so opening the picker shows the buyer where
                               they already are. */
                            reveal() {
                                this.$nextTick(() => {
                                    const list = this.$refs.list;
                                    if (! list) { return; }
                                    const row = Array.from(list.children)
                                        .find((el) => el.getAttribute('aria-selected') === 'true');
                                    if (row) { list.scrollTop = Math.max(0, row.offsetTop - 8); }
                                    list.scrollIntoView({ block: 'nearest' });
                                });
                            },
                        }"
                        @click.outside="open = false"
                        class="relative mt-2"
                    >
                        <button type="button" @click="open = ! open; if (open) { reveal() }" :aria-expanded="open.toString()" aria-haspopup="listbox" class="flex h-11 w-full items-center gap-2 rounded-full border-2 border-edge bg-[#ffffff] px-4 text-sm text-zinc-900 transition-colors focus:outline-none focus:ring-2 focus:ring-edge/30 dark:border-edge dark:bg-white/5 dark:text-white">
                            <template x-if="cryptos[selectedCrypto]?.icon"><img :src="cryptos[selectedCrypto].icon" :alt="selectedCrypto" class="h-5 w-5 shrink-0 rounded-[12px]"></template>
                            <template x-if="!cryptos[selectedCrypto]?.icon && cryptos[selectedCrypto]?.flag"><img :src="cryptos[selectedCrypto].flag" :alt="selectedCrypto" class="h-5 w-5 shrink-0 rounded-[12px] object-cover"></template>
                            <span class="flex-1 text-left" x-text="(cryptos[selectedCrypto]?.name || selectedCrypto) + ' (' + selectedCrypto + ')'"></span>
                            <svg class="h-4 w-4 shrink-0 text-zinc-500 transition-transform" :class="{ 'rotate-180': open }" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7"/></svg>
                        </button>

                        {{-- The list sits in normal flow rather than floating
                             above the trigger. Floating, it was clipped: the
                             sheet's body scrolls (overflow-y-auto) and so
                             clips anything positioned outside it, and a panel
                             opening upward from the last control in that body
                             had nowhere to go. Overflow past a scroll
                             container's TOP edge is never reachable by
                             scrolling either, so the cut rows were simply
                             gone, and the first of the 49 is XAF. In flow, the
                             list adds its own height to the body the buyer can
                             already scroll, so no row can be cut off. --}}
                        <div x-show="open" x-transition x-ref="list" style="display:none;" class="mt-2 max-h-64 w-full overflow-y-auto rounded-[12px] border border-zinc-200 bg-[#ffffff] p-1 [scrollbar-width:none] [&::-webkit-scrollbar]:hidden dark:border-zinc-700 dark:bg-[#1d3252]" role="listbox">
                            <template x-for="(meta, code) in cryptos" :key="code">
                                <button type="button" @click="selectedCrypto = code; open = false" role="option" :aria-selected="(selectedCrypto === code).toString()" :class="selectedCrypto === code ? 'bg-[#f4f6f5] text-blue-700 dark:bg-blue-600/15 dark:text-blue-300' : 'text-zinc-800 hover:bg-[#f4f6f5] dark:text-zinc-200 dark:hover:bg-[#26416b]'" class="flex w-full items-center gap-2 rounded-full px-3 py-2 text-left text-sm font-medium transition-colors">
                                    <template x-if="meta.icon"><img :src="meta.icon" :alt="code" class="h-5 w-5 shrink-0 rounded-[12px]"></template>
                                    <template x-if="!meta.icon && meta.flag"><img :src="meta.flag" :alt="code" class="h-5 w-5 shrink-0 rounded-[12px] object-cover ring-1 ring-zinc-200"></template>
                                    <template x-if="!meta.icon && !meta.flag"><span class="inline-flex h-5 w-5 shrink-0 items-center justify-center rounded-[12px] text-[10px] font-black text-white" :class="meta.type === 'crypto' ? 'bg-amber-500' : 'bg-emerald-500'" x-text="code.charAt(0)"></span></template>
                                    <span class="flex-1 truncate" x-text="code"></span>
                                    <svg x-show="selectedCrypto === code" class="h-4 w-4 shrink-0 text-blue-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5"/></svg>
                                </button>
                            </template>
                        </div>
                    </div>
                </div>

                {{-- Actions, on their own ground so they stay put while the
                     coverage list above them scrolls. --}}
                <div class="border-t border-zinc-200 bg-[#ffffff] p-5 dark:border-zinc-700/60 dark:bg-[#0c1a36]">
                    <div class="flex items-center justify-between gap-4">
                        <span class="text-sm text-zinc-600 dark:text-zinc-300">{{ __('esim.total') }}</span>
                        <span class="text-xl font-extrabold tabular-nums text-zinc-900 dark:text-white" x-text="totalLabel()">0.00</span>
                    </div>

                    {{-- The two things the banner carried that the sections
                         above do not: the full package sheet, and what the
                         purchase earns back. Both keep their old behaviour;
                         the details sheet is a taller layer, so this one
                         steps aside rather than stacking under it. --}}
                    <div class="mt-3 flex items-center justify-between gap-4">
                        <button type="button" @click="detailsId = selectedId; showBuy = false; showDetails = true" class="inline-flex items-center gap-1.5 text-sm font-semibold text-blue-600 hover:text-blue-700 dark:text-blue-400 dark:hover:text-blue-300">
                            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M19.5 14.25v-2.625a3.375 3.375 0 00-3.375-3.375h-1.5A1.125 1.125 0 0113.5 7.125v-1.5a3.375 3.375 0 00-3.375-3.375H8.25m2.25 0H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 00-9-9z"/></svg>
                            {{ __('esim.package_details') }}
                        </button>

                        <span x-show="rcoinConfig.enabled" style="display:none;" class="flex items-center gap-1 text-xs font-semibold text-zinc-600 dark:text-zinc-400">
                            <img src="{{ asset('assets/favicon.ico') }}" alt="" class="h-5 w-5 object-contain">
                            <span x-text="pointsEarned()">0</span>
                        </span>
                    </div>

                    <div class="mt-4 flex flex-col gap-2 sm:flex-row">
                        <button
                            type="button"
                            @click="addFromBuyModal()"
                            :disabled="! selectedId || ! purchasable || cartState === 'loading'"
                            :class="cartState === 'success' ? 'border-emerald-500 bg-emerald-500 text-white' : 'border-blue-600 bg-transparent text-blue-600 hover:bg-blue-50 dark:hover:bg-blue-500/10'"
                            class="flex h-12 flex-1 items-center justify-center rounded-full border-2 px-6 text-sm font-semibold transition-colors disabled:cursor-not-allowed disabled:opacity-50"
                        >
                            <span x-show="cartState !== 'success'">{{ __('products.add_to_cart') }}</span>
                            <span x-show="cartState === 'success'" style="display:none;">{{ __('products.detail_added') }}</span>
                        </button>

                        <button
                            type="button"
                            @click="buyNow()"
                            :disabled="! selectedId || ! purchasable || $store.cart.loading"
                            class="flex h-12 flex-1 items-center justify-center rounded-full bg-blue-600 px-7 text-base font-semibold text-white shadow-lg shadow-blue-600/25 transition-colors hover:bg-blue-700 disabled:cursor-not-allowed disabled:opacity-50"
                        >
                            {{ __('products.buy_now') }}
                        </button>
                    </div>
                </div>
            </div>
        </div>

        {{-- Networks modal --}}
        <div x-show="showNetworks" style="display:none;" class="fixed inset-0 z-[70] flex items-end justify-center px-3 pb-3 sm:items-center sm:p-4" role="dialog" aria-modal="true" aria-labelledby="networks-title">
            <div x-show="showNetworks" @click="showNetworks = false" x-transition.opacity class="absolute inset-0 bg-zinc-900/50 dark:bg-zinc-950/70"></div>
            <div x-show="showNetworks" x-transition class="relative max-h-[85vh] w-full max-w-[760px] overflow-y-auto p-6 rounded-[12px] bg-[#eff6ff] shadow-2xl ring-1 ring-zinc-200 dark:ring-[#24364f]">
                <div class="flex items-start justify-between gap-4">
                    <h2 id="networks-title" class="text-lg font-bold text-zinc-900 dark:text-white">{{ $coverageCount > 1 ? __('esim.countries_networks') : __('esim.networks') }}</h2>
                    <button type="button" @click="showNetworks = false" aria-label="{{ __('common.close') }}" class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-[#dde3ee] text-zinc-600 transition-colors hover:bg-[#cdd5e4] hover:text-zinc-900 dark:bg-white/10 dark:text-zinc-300 dark:hover:bg-white/20 dark:hover:text-white"><svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg></button>
                </div>
                @if ($coverageCount > 1)
                    <p class="mt-3 text-sm leading-relaxed text-zinc-600 dark:text-white">{{ __('esim.networks_across', ['count' => $coverageCount]) }}</p>
                    @if ($countryNetworks->isNotEmpty())
                        {{-- Per-country networks straight from the supplier's plan
                             details: flag + country left, its carriers and speeds
                             right. Searchable, scrolls inside the modal. --}}
                        <div class="mt-4" x-data="{ netQ: '' }">
                            <div class="relative">
                                <svg class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-zinc-500 dark:text-zinc-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg>
                                <input x-model="netQ" type="text" placeholder="{{ __('esim.search_country') }}" aria-label="{{ __('esim.search_country') }}" class="w-full rounded-full border-2 border-edge bg-white py-2.5 pl-9 pr-3 text-sm text-zinc-900 outline-none transition-colors focus:ring-2 focus:ring-edge/30 dark:border-edge dark:bg-[#26416b] dark:text-white dark:placeholder:text-zinc-400">
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
                    <p class="mt-3 text-sm leading-relaxed text-zinc-600 dark:text-white">{{ __('esim.networks_switch') }}</p>
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
                                <li class="text-sm text-zinc-600 dark:text-white">{{ __('esim.networks_auto', ['region' => $regionLabel]) }}</li>
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
                    <h2 id="compat-title" class="text-lg font-bold text-zinc-900 dark:text-white">{{ __('esim.check_compatibility') }}</h2>
                    <button type="button" @click="showCompat = false" aria-label="{{ __('common.close') }}" class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-[#dde3ee] text-zinc-600 transition-colors hover:bg-[#cdd5e4] hover:text-zinc-900 dark:bg-white/10 dark:text-zinc-300 dark:hover:bg-white/20 dark:hover:text-white">
                        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg>
                    </button>
                </div>

                <div class="px-6 pt-5 text-sm leading-relaxed text-zinc-600 dark:text-zinc-300">
                    <p>{{ __('esim.compat_intro') }}</p>
                    <ul class="mt-2 list-disc space-y-1 pl-5">
                        <li>{{ __('esim.compat_supports') }}</li>
                        <li>{{ __('esim.compat_unlocked') }}</li>
                        <li>{{ __('esim.compat_not_jailbroken') }}</li>
                    </ul>
                    <p class="mt-3">
                        <span class="font-semibold text-zinc-900 dark:text-white">{{ __('esim.compat_missing_title') }}</span>
                        {{ __('esim.compat_missing_body') }}
                    </p>
                </div>

                {{-- Find your device. Both figures are counted off the list below,
                     so they cannot drift away from what is actually shown. --}}
                <div class="mt-5 flex flex-wrap items-baseline justify-between gap-x-4 gap-y-1 border-t border-zinc-200 px-6 pt-4 dark:border-zinc-700/60">
                    <p class="text-sm font-bold text-zinc-900 dark:text-white">{{ __('esim.compat_find') }}</p>
                    <p class="text-xs text-zinc-500 dark:text-zinc-400">{{ __('esim.compat_counts', ['models' => number_format($compatModelCount), 'brands' => number_format($compatBrands->count())]) }}</p>
                </div>

                <div class="px-6 pb-0 pt-3">
                    <div class="relative">
                        <svg class="pointer-events-none absolute left-4 top-1/2 h-4 w-4 -translate-y-1/2 text-zinc-500 dark:text-zinc-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-4.35-4.35M17 11a6 6 0 11-12 0 6 6 0 0112 0z"/></svg>
                        <input
                            x-model="compatSearch"
                            type="text"
                            placeholder="{{ __('esim.compat_search') }}"
                            aria-label="{{ __('esim.compat_search') }}"
                            class="w-full rounded-full border-2 border-edge bg-white py-3 pl-11 pr-10 text-sm text-zinc-900 placeholder:text-zinc-400 outline-none transition-colors focus:ring-2 focus:ring-edge/30 dark:border-edge dark:bg-white/5 dark:text-white dark:placeholder:text-zinc-500"
                        >
                        <button
                            type="button"
                            x-show="compatSearch !== ''"
                            x-cloak
                            @click="compatSearch = ''"
                            aria-label="{{ __('esim.clear_search') }}"
                            class="absolute right-3 top-1/2 flex h-7 w-7 -translate-y-1/2 items-center justify-center rounded-full text-zinc-500 transition-colors hover:bg-[#f4f6f5] hover:text-zinc-900 dark:hover:bg-white/10 dark:hover:text-white"
                        >
                            <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg>
                        </button>
                    </div>
                    {{-- Shown only while the query is too short to act on, so it
                         reads as a hint rather than an error. --}}
                    <p x-show="compatSearch.trim().length > 0 && compatSearch.trim().length < 3" x-cloak class="mt-2 pl-4 text-xs text-zinc-500 dark:text-zinc-400">{{ __('esim.compat_keep_typing') }}</p>
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
                                    <span class="shrink-0 text-xs text-zinc-500 dark:text-zinc-400">{{ trans_choice('esim.compat_models', count($models), ['count' => count($models)]) }}</span>
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
                    >{{ __('esim.compat_no_match') }}</p>
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
                    <button type="button" @click="showDetails = false" aria-label="{{ __('common.close') }}" class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-[#dde3ee] text-zinc-600 transition-colors hover:bg-[#cdd5e4] hover:text-zinc-900 dark:bg-white/10 dark:text-zinc-300 dark:hover:bg-white/20 dark:hover:text-white"><svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg></button>
                </div>
                <div class="mt-5">
                    <p class="text-sm font-bold text-zinc-900 dark:text-white">{{ __('esim.details_package') }}</p>
                    <div class="mt-3 flex flex-wrap gap-2">
                        <span class="inline-flex flex-col rounded-[12px] bg-[#f4f6f5] px-3 py-2 ring-1 ring-zinc-100 dark:bg-[#26416b] dark:ring-zinc-700/60">
                            <span class="text-[11px] font-medium uppercase tracking-wide text-zinc-500 dark:text-zinc-400">{{ __('esim.coverage') }}</span>
                            <span class="text-sm font-semibold text-zinc-900 dark:text-white">{{ $regionLabel }}</span>
                        </span>
                        <span class="inline-flex flex-col rounded-[12px] bg-[#f4f6f5] px-3 py-2 ring-1 ring-zinc-100 dark:bg-[#26416b] dark:ring-zinc-700/60">
                            <span class="text-[11px] font-medium uppercase tracking-wide text-zinc-500 dark:text-zinc-400">{{ __('esim.chip_data') }}</span>
                            <span class="text-sm font-semibold text-zinc-900 dark:text-white" x-text="detailsPlan()?.data || '-'"></span>
                        </span>
                        <span x-show="detailsPlan()?.is_voice" x-cloak class="inline-flex flex-col rounded-[12px] bg-[#f4f6f5] px-3 py-2 ring-1 ring-zinc-100 dark:bg-[#26416b] dark:ring-zinc-700/60">
                            <span class="text-[11px] font-medium uppercase tracking-wide text-zinc-500 dark:text-zinc-400">{{ __('esim.badge_calls') }}</span>
                            <span class="text-sm font-semibold text-zinc-900 dark:text-white" x-text="detailsPlan()?.voice || '-'"></span>
                        </span>
                        <span x-show="detailsPlan()?.is_voice" x-cloak class="inline-flex flex-col rounded-[12px] bg-[#f4f6f5] px-3 py-2 ring-1 ring-zinc-100 dark:bg-[#26416b] dark:ring-zinc-700/60">
                            <span class="text-[11px] font-medium uppercase tracking-wide text-zinc-500 dark:text-zinc-400">{{ __('esim.details_texts') }}</span>
                            <span class="text-sm font-semibold text-zinc-900 dark:text-white" x-text="detailsPlan()?.sms || '-'"></span>
                        </span>
                        <span class="inline-flex flex-col rounded-[12px] bg-[#f4f6f5] px-3 py-2 ring-1 ring-zinc-100 dark:bg-[#26416b] dark:ring-zinc-700/60">
                            <span class="text-[11px] font-medium uppercase tracking-wide text-zinc-500 dark:text-zinc-400">{{ __('esim.details_validity') }}</span>
                            <span class="text-sm font-semibold text-zinc-900 dark:text-white" x-text="(detailsPlan()?.days > 0 ? @js(__('esim.days_other')).replace(':count', detailsPlan().days) : @js(__('esim.details_flexible')))"></span>
                        </span>
                    </div>

                    {{-- iMessage / Apple + verification features - voice plans only. --}}
                    <div x-show="detailsPlan()?.is_voice" x-cloak class="mt-5">
                        <p class="text-sm font-bold text-zinc-900 dark:text-white">{{ __('esim.details_apple_services') }}</p>
                        <div class="mt-3 grid grid-cols-2 gap-2.5">
                            @foreach ([
                                ['t' => __('esim.voice_imessage_title'), 'd' => __('esim.details_imessage_desc'), 'icon' => 'M2.25 12.76c0 1.6 1.123 2.994 2.707 3.227 1.087.16 2.185.283 3.293.369V21l4.184-4.183a1.14 1.14 0 01.778-.332 48.294 48.294 0 005.83-.498c1.585-.233 2.708-1.626 2.708-3.228V6.741c0-1.602-1.123-2.995-2.707-3.228A48.394 48.394 0 0012 3c-2.392 0-4.744.175-7.043.513C3.373 3.746 2.25 5.14 2.25 6.741v6.018z'],
                                ['t' => __('esim.details_imessage_calls'), 'd' => __('esim.details_imessage_calls_desc'), 'icon' => 'M2.25 6.75c0 8.284 6.716 15 15 15h2.25a2.25 2.25 0 002.25-2.25v-1.372c0-.516-.351-.966-.852-1.091l-4.423-1.106c-.44-.11-.902.055-1.173.417l-.97 1.293c-.282.376-.769.542-1.21.38a12.035 12.035 0 01-7.143-7.143c-.162-.441.004-.928.38-1.21l1.293-.97c.363-.271.527-.734.417-1.173L6.963 3.102a1.125 1.125 0 00-1.091-.852H4.5A2.25 2.25 0 002.25 4.5v2.25z'],
                                ['t' => __('esim.details_imessage_text'), 'd' => __('esim.details_imessage_text_desc'), 'icon' => 'M8.625 9.75a.375.375 0 11-.75 0 .375.375 0 01.75 0zm0 0H8.25m4.125 0a.375.375 0 11-.75 0 .375.375 0 01.75 0zm0 0H12m4.125 0a.375.375 0 11-.75 0 .375.375 0 01.75 0zm0 0h-.375M21 12c0 4.556-4.03 8.25-9 8.25a9.764 9.764 0 01-2.555-.337A5.972 5.972 0 015.41 20.97a5.969 5.969 0 01-.474-.065 4.48 4.48 0 00.978-2.025c.09-.457-.133-.901-.467-1.226C3.93 16.178 3 14.189 3 12c0-4.556 4.03-8.25 9-8.25s9 3.694 9 8.25z'],
                                ['t' => 'FaceTime', 'd' => __('esim.details_facetime_desc'), 'icon' => 'M15.75 10.5l4.72-4.72a.75.75 0 011.28.53v11.38a.75.75 0 01-1.28.53l-4.72-4.72M4.5 18.75h9a2.25 2.25 0 002.25-2.25v-9a2.25 2.25 0 00-2.25-2.25h-9A2.25 2.25 0 002.25 7.5v9a2.25 2.25 0 002.25 2.25z'],
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
                            {{ __('esim.details_topups_title') }}
                        </p>
                        <p class="mt-1 text-sm leading-relaxed text-zinc-600 dark:text-white">{{ __('esim.details_topups_body') }}</p>
                    </div>

                    {{-- Data-only note: pure connectivity, no calls/texts/number. --}}
                    <div x-show="detailsPlan() && ! detailsPlan().is_voice" x-cloak class="mt-4 rounded-[12px] bg-[#f4f6f5] p-4 ring-1 ring-zinc-100 dark:bg-[#26416b] dark:ring-zinc-700/60">
                        <p class="flex items-center gap-2 text-sm font-bold text-zinc-900 dark:text-white">
                            <svg class="h-4 w-4 text-zinc-500 dark:text-zinc-300" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M8.288 15.038a5.25 5.25 0 017.424 0M5.106 11.856c3.807-3.808 9.98-3.808 13.788 0M1.924 8.674c5.565-5.565 14.587-5.565 20.152 0M12.53 18.22l-.53.53-.53-.53a.75.75 0 011.06 0z"/></svg>
                            {{ __('esim.chip_data_only') }}
                        </p>
                        <p class="mt-1 text-sm leading-relaxed text-zinc-600 dark:text-white">{{ __('esim.details_data_only_body') }}</p>
                    </div>

                    {{-- Supplier plan policies - every section below renders only
                         when the synced plan data carries it (no invented copy). --}}
                    <div class="mt-5 space-y-4 border-t border-zinc-100 pt-5 dark:border-zinc-700/60">
                        @if ($activationText)
                            <div>
                                <p class="flex items-center gap-2 text-sm font-bold text-zinc-900 dark:text-white">
                                    <svg class="h-4 w-4 text-zinc-500 dark:text-zinc-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6h4.5m4.5 0a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                                    {{ __('esim.details_activation') }}
                                </p>
                                <p class="mt-1 text-sm leading-relaxed text-zinc-600 dark:text-white">{{ $activationText }}</p>
                            </div>
                        @endif

                        @if ($planPolicy['rechargeable'] !== null)
                            <div>
                                <p class="flex items-center gap-2 text-sm font-bold text-zinc-900 dark:text-white">
                                    <svg class="h-4 w-4 text-zinc-500 dark:text-zinc-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M3 16.5v2.25A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75V16.5m-13.5-9L12 3m0 0l4.5 4.5M12 3v13.5"/></svg>
                                    {{ __('esim.details_topup_option') }}
                                </p>
                                <p class="mt-1 text-sm leading-relaxed text-zinc-600 dark:text-white">
                                    @if ($planPolicy['rechargeable'])
                                        {{ __('esim.details_rechargeable') }}
                                    @else
                                        {{ __('esim.details_not_rechargeable') }}
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
                                    {{ __('esim.details_other_info') }}
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
                        <p class="text-sm leading-relaxed text-zinc-700 dark:text-white">{{ __('esim.details_after_allowance') }}</p>
                    </div>

                    <p class="mt-5 text-xs leading-relaxed text-zinc-500 dark:text-zinc-400">{{ __('esim.details_confirm_device') }}</p>
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
                {{-- The purchase modal. "Get eSIM" used to add the plan to the
                     cart and leave for checkout in the same click, with a
                     banner pinned to the top of the page as the only pause in
                     between. This is that pause, made explicit: what the plan
                     covers, what currency it is charged in, and then the two
                     ways to commit. --}}
                showBuy: false,
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

                    // Freeze the page behind any of this page's four overlays.
                    // A $watch, not an x-effect: an effect re-runs on every
                    // dependency change, so a sheet handing over to another
                    // (showBuy = false; showNetworks = true) re-entered the
                    // still-true branch and locked a SECOND time against the
                    // shared reference counter. The count never returned to
                    // zero, so the page stayed frozen after everything closed
                    // and no later open/close could recover it. A watcher fires
                    // only on a real transition, so one lock is held no matter
                    // how many sheets trade places under it.
                    this.$watch('showBuy || showNetworks || showCompat || showDetails', (isOpen) => {
                        if (isOpen) {
                            window.rshopScrollLock?.lock();
                        } else {
                            window.rshopScrollLock?.unlock();
                        }
                    });
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
                // Adding from the purchase sheet. The cart panel that a
                // successful add opens sits at z-[56], below this sheet at
                // z-[70], so leaving the sheet up would hide the only
                // confirmation the buyer gets. It holds just long enough for
                // the button's own tick to register, then steps aside.
                async addFromBuyModal() {
                    const ok = await this.addToCart();
                    if (ok) {
                        setTimeout(() => { this.showBuy = false; }, 900);
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

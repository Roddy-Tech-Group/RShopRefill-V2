<?php

use App\Domain\Cart\Services\CartManager;
use App\Domain\Cart\Services\CartPricingService;
use App\Domain\Communication\Services\AdminNotificationService;
use App\Http\Controllers\Admin\AdminCustomerController;
use App\Http\Controllers\Api\PushSubscriptionController;
use App\Http\Controllers\Auth\GuestClaimController;
use App\Http\Controllers\BlogController;
use App\Http\Controllers\CartWebController;
use App\Http\Controllers\CheckoutController;
use App\Http\Controllers\ContactController;
use App\Http\Controllers\EmailPreviewController;
use App\Http\Controllers\EsimStoreController;
use App\Http\Controllers\EsimTopupController;
use App\Http\Controllers\GoogleMerchantFeedController;
use App\Http\Controllers\KycController;
use App\Http\Controllers\PressController;
use App\Http\Controllers\RcoinConvertController;
use App\Http\Controllers\RcoinWithdrawalController;
use App\Http\Controllers\ReviewController;
use App\Http\Controllers\Security\DeviceVerificationController;
use App\Http\Controllers\SuspensionController;
use App\Http\Controllers\ThemeController;
use App\Http\Controllers\Web\RecoveryController;
use App\Models\BlogPost;
use App\Models\Category;
use App\Models\Coupon;
use App\Models\CurrencyRate;
use App\Models\PressArticle;
use App\Models\Product;
use App\Support\TaggedCache;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Livewire\Volt\Volt;

Route::get('/', function () {
    return view('welcome');
})->name('home');

// Storefront catalog - accessible to guests AND authed users.
// The dashboard sidebar/menu links to these same URLs (no duplicate dashboard.gift-cards page).
Route::view('gift-cards', 'shop.gift-cards')->name('shop.gift-cards');

// Global live-search JSON endpoint used by the nav + dashboard search bars.
// Searches every product category (gift cards, mobile top-ups, bill payments,
// eSIMs) grouped by brand_key, then a small set of key pages - each result
// carries a ready-to-use `url` + a `type` label so the UI links correctly per
// type. Legacy `slug`/`country` fields are kept for backward compatibility.
Route::get('api/search/brands', function (Request $request) {
    $q = trim((string) $request->query('q', ''));
    if (mb_strlen($q) < 2) {
        return response()->json([]);
    }

    // Category slug -> URL segment + human label for the result row.
    $segByCat = ['gift-cards' => 'gift-cards', 'mobile-airtime' => 'topups', 'bill-payments' => 'bills', 'esims' => 'esims'];
    $labelByCat = ['gift-cards' => 'Gift card', 'mobile-airtime' => 'Mobile top-up', 'bill-payments' => 'Bill payment', 'esims' => 'eSIM'];

    $brandIds = Product::query()
        ->where('is_active', true)
        ->whereNotNull('brand_key')
        ->where(function ($qq) use ($q) {
            $qq->where('name', 'like', "%{$q}%")
                ->orWhere('brand_key', 'like', "%{$q}%");
        })
        ->select('brand_key', DB::raw('MIN(id) as id'))
        ->groupBy('brand_key')
        ->limit(8)
        ->pluck('id');

    $products = Product::with('category')
        ->whereIn('id', $brandIds)
        ->get(['id', 'brand_key', 'country_code', 'logo_url', 'name', 'category_id']);

    $items = $products->map(function ($p) use ($segByCat, $labelByCat) {
        $cat = $p->category?->slug ?? 'gift-cards';
        $seg = $segByCat[$cat] ?? 'gift-cards';
        $slug = Product::brandSlug($p->brand_key);

        return [
            'type' => $labelByCat[$cat] ?? 'Product',
            'name' => Product::brandDisplayName($p->brand_key),
            'logo' => Product::brandLogoUrl($p->brand_key, $p->logo_url),
            'url' => '/'.$seg.'/'.$slug,
            // Legacy fields (older UI builds /gift-cards/{slug} from these).
            'slug' => $slug,
            'country' => $p->country_code,
        ];
    });

    // eSIMs are country / region products with no brand_key, so the brand query
    // above skips them entirely. Search them separately by name and dedupe to one
    // result per country (mirroring the eSIM catalog grouping) so a search for a
    // country or region surfaces its eSIM.
    $esimItems = collect();
    if ($esimCat = Category::where('slug', 'esims')->first()) {
        $esimItems = Product::query()
            ->where('is_active', true)
            ->where('category_id', $esimCat->id)
            ->where('name', 'like', "%{$q}%")
            ->orderBy('name')
            ->get(['slug', 'name', 'country_code'])
            ->groupBy(function ($p) {
                $cc = strtoupper((string) $p->country_code);

                return (strlen($cc) === 2 && $cc !== 'WW') ? $cc : 'slug:'.$p->slug;
            })
            ->map(function ($group) {
                $p = $group->first();
                $cc = strtoupper((string) $p->country_code);
                $isLocal = strlen($cc) === 2 && $cc !== 'WW';

                return [
                    'type' => 'eSIM',
                    'name' => EsimStoreController::regionMeta($p->country_code, $p->slug, $p->name)['name'],
                    'logo' => $isLocal ? Product::flagUrl($cc) : EsimStoreController::globalFlag(),
                    'url' => '/esims/'.$p->slug,
                    'slug' => $p->slug,
                    'country' => $p->country_code,
                ];
            })
            ->take(6)
            ->values();
    }

    // Key pages, matched by title - so a global search also surfaces sections.
    $pages = collect([
        ['name' => 'Buy gift cards', 'url' => route('shop.gift-cards')],
        ['name' => 'Sell gift cards', 'url' => route('shop.sell-gift-cards')],
        ['name' => 'GoMarket', 'url' => route('shop.gomarket')],
        ['name' => 'eSIMs', 'url' => route('shop.esims')],
        ['name' => 'Mobile top-ups', 'url' => route('shop.topups')],
        ['name' => 'Bill payments', 'url' => route('shop.bills')],
        ['name' => 'Help center', 'url' => route('shop.help')],
        ['name' => 'FAQ', 'url' => route('shop.faq')],
    ])->filter(fn ($pg) => str_contains(strtolower($pg['name']), strtolower($q)))
        ->map(fn ($pg) => ['type' => 'Page', 'name' => $pg['name'], 'logo' => null, 'url' => $pg['url'], 'slug' => null, 'country' => null])
        ->take(4)
        ->values();

    return response()->json($items->concat($esimItems)->concat($pages)->values());
})->name('api.search.brands');

// Brand-level detail page. The URL slug is a kebab-cased brand_key
// ("apple" → brand_key "Apple", "mobile-legends" → "MobileLegends"). The page is
// locked to ONE country - whichever the user selected in the locale modal, passed
// through as `?country=XX`. If the brand isn't sold in that country the page 404s
// (the listing already only links to countries that have stock).
// When a brand slug no longer maps to an ACTIVE brand, decide how to respond:
// if the brand once existed in that category it was removed from the catalogue,
// so 301 to the listing (keeps removed-brand URLs out of Google's "not found"
// bucket and lands the visitor on a live page); if it never existed at all, 404.
// Context-aware so a logged-in user stays inside the dashboard storefront.
$brandGoneResponse = function (string $brandSlug, string $categorySlug) {
    $everExisted = TaggedCache::for(['catalog'])->remember("brand_existed_{$categorySlug}_{$brandSlug}", 3600, function () use ($brandSlug, $categorySlug) {
        return Product::query()
            ->whereNotNull('brand_key')
            ->whereHas('category', fn ($q) => $q->where('slug', $categorySlug))
            ->distinct()
            ->pluck('brand_key')
            ->contains(fn ($key) => Str::kebab($key) === $brandSlug);
    });

    abort_unless($everExisted, 404);

    $inDashboard = request()->routeIs('dashboard.*');
    $listing = match ($categorySlug) {
        'mobile-airtime' => $inDashboard ? 'dashboard.shop.topups' : 'shop.topups',
        'bill-payments' => $inDashboard ? 'dashboard.shop.bills' : 'shop.bills',
        default => $inDashboard ? 'dashboard.shop.gift-cards' : 'shop.gift-cards',
    };

    return redirect()->route($listing, [], 301);
};

$resolveGiftCardBrand = function (string $brandSlug) use ($brandGoneResponse) {
    $brandSlug = strtolower($brandSlug);

    $brandKey = TaggedCache::for(['catalog'])->remember("brand_slug_{$brandSlug}", 3600, function () use ($brandSlug) {
        return Product::query()
            ->whereNotNull('brand_key')
            ->where('is_active', true)
            ->distinct()
            ->pluck('brand_key')
            ->first(fn ($key) => Str::kebab($key) === $brandSlug);
    });

    if (! $brandKey) {
        return $brandGoneResponse($brandSlug, 'gift-cards');
    }

    $requested = strtoupper((string) (request()->attributes->get('region') ?: 'US'));

    $product = TaggedCache::for(['catalog'])->remember("brand_product_{$brandKey}_{$requested}", 3600, function () use ($brandKey, $requested) {
        $p = Product::query()
            ->where('brand_key', $brandKey)
            ->where('country_code', $requested)
            ->where('is_active', true)
            ->with([
                'subcategory:id,name,slug',
                'category:id,name,slug',
                'variants' => fn ($q) => $q->where('is_available', true)->orderBy('face_value'),
            ])
            ->first();

        if (! $p) {
            $p = Product::query()
                ->where('brand_key', $brandKey)
                ->where('is_active', true)
                ->orderByRaw("country_code = 'US' DESC")
                ->with([
                    'subcategory:id,name,slug',
                    'category:id,name,slug',
                    'variants' => fn ($q) => $q->where('is_available', true)->orderBy('face_value'),
                ])
                ->first();
        }

        return $p;
    });

    abort_if(! $product, 404);

    return view('shop.product', ['product' => $product, 'brandKey' => $brandKey]);
};

Route::get('gift-cards/{brandSlug}', $resolveGiftCardBrand)->name('shop.brand');

// eSIM storefront - a single store page per country. Each Product in the `esims`
// category is one supplier's coverage for a region; the controller MERGES all
// suppliers' plans for a country so data + voice show together. The `esims` entry
// point resolves a default country; the slug route opens a specific region.
Route::get('esims', [EsimStoreController::class, 'index'])->name('shop.esims');
Route::get('esims/country/{code}', [EsimStoreController::class, 'country'])->name('shop.esim.country');
Route::get('esims/{slug}', [EsimStoreController::class, 'show'])->name('shop.esim');

// Mobile top-up - mirrors the gift-card flow exactly: a brand listing + a
// brand-level detail page. Operators are the Products in the `mobile-airtime`
// category; their variants are the airtime amounts. The detail page reuses the
// shared `shop.product` view.
Route::view('topups', 'shop.topups')->name('shop.topups');

$resolveTopupBrand = function (string $brandSlug) use ($brandGoneResponse) {
    $brandSlug = strtolower($brandSlug);

    // Resolve the kebab-cased URL slug back to the actual brand_key, scoped to
    // the mobile-airtime category so it never collides with a gift-card brand.
    $brandKey = TaggedCache::for(['catalog'])->remember("topup_brand_{$brandSlug}", 3600, function () use ($brandSlug) {
        return Product::query()
            ->whereNotNull('brand_key')
            ->where('is_active', true)
            ->whereHas('category', fn ($q) => $q->where('slug', 'mobile-airtime'))
            ->distinct()
            ->pluck('brand_key')
            ->first(fn ($key) => Str::kebab($key) === $brandSlug);
    });

    if (! $brandKey) {
        return $brandGoneResponse($brandSlug, 'mobile-airtime');
    }

    $requested = strtoupper((string) (request()->attributes->get('region') ?: 'US'));

    $product = TaggedCache::for(['catalog'])->remember("topup_product_{$brandKey}_{$requested}", 3600, function () use ($brandKey, $requested) {
        $base = fn () => Product::query()
            ->where('brand_key', $brandKey)
            ->where('is_active', true)
            ->whereHas('category', fn ($q) => $q->where('slug', 'mobile-airtime'))
            ->with([
                'subcategory:id,name,slug',
                'category:id,name,slug',
                'variants' => fn ($q) => $q->where('is_available', true)->orderBy('face_value'),
            ]);

        return $base()->where('country_code', $requested)->first()
            ?: $base()->orderByRaw("country_code = 'US' DESC")->first();
    });

    abort_if(! $product, 404);

    return view('shop.product', ['product' => $product, 'brandKey' => $brandKey]);
};

Route::get('topups/{brandSlug}', $resolveTopupBrand)->name('shop.topup');

// Bill payments - prepaid utilities (electricity, water, etc.). These ride
// Zendit's /vouchers/offers feed and are split into the `bill-payments`
// category by ZenditNormalizer. Mirrors the gift-card flow: a brand listing +
// a brand-level detail page reusing the shared `shop.product` view.
Route::view('bills', 'shop.bills')->name('shop.bills');

$resolveBillBrand = function (string $brandSlug) use ($brandGoneResponse) {
    $brandSlug = strtolower($brandSlug);

    $brandKey = TaggedCache::for(['catalog'])->remember("bill_brand_{$brandSlug}", 3600, function () use ($brandSlug) {
        return Product::query()
            ->whereNotNull('brand_key')
            ->where('is_active', true)
            ->whereHas('category', fn ($q) => $q->where('slug', 'bill-payments'))
            ->distinct()
            ->pluck('brand_key')
            ->first(fn ($key) => Str::kebab($key) === $brandSlug);
    });

    if (! $brandKey) {
        return $brandGoneResponse($brandSlug, 'bill-payments');
    }

    $requested = strtoupper((string) (request()->attributes->get('region') ?: 'US'));

    $product = TaggedCache::for(['catalog'])->remember("bill_product_{$brandKey}_{$requested}", 3600, function () use ($brandKey, $requested) {
        $base = fn () => Product::query()
            ->where('brand_key', $brandKey)
            ->where('is_active', true)
            ->whereHas('category', fn ($q) => $q->where('slug', 'bill-payments'))
            ->with([
                'subcategory:id,name,slug',
                'category:id,name,slug',
                'variants' => fn ($q) => $q->where('is_available', true)->orderBy('face_value'),
            ]);

        return $base()->where('country_code', $requested)->first()
            ?: $base()->orderByRaw("country_code = 'US' DESC")->first();
    });

    abort_if(! $product, 404);

    return view('shop.product', ['product' => $product, 'brandKey' => $brandKey]);
};

Route::get('bills/{brandSlug}', $resolveBillBrand)->name('shop.bill');

// Legacy URL redirects. Old path shapes Google still has indexed (surfacing as
// 404s in Search Console) folded onto the current structure with a 301, so their
// crawl equity lands on a live page instead of dying on a not-found. The
// top-ups/{brand} wildcard catches the old hyphenated brand pages too.
Route::redirect('giftcards', '/gift-cards', 301);
Route::redirect('top-ups', '/topups', 301);
Route::get('top-ups/{brand}', fn (string $brand) => redirect('/topups/'.$brand, 301))->where('brand', '.*');
Route::redirect('en', '/', 301);

// Flights & Stays - branded "coming soon" pages until the booking catalog ships.
// One shared view, the `service` data flag drives the per-service copy + art.
Route::view('flights', 'shop.coming-soon', ['service' => 'flights'])->name('shop.flights');
Route::view('stays', 'shop.coming-soon', ['service' => 'stays'])->name('shop.stays');

// Sell Gift Cards & GoMarket - branded "coming soon" pages until each flow ships.
// Same shared view + service flag as Flights/Stays; ungated like them.
Route::view('sell-gift-cards', 'shop.coming-soon', ['service' => 'sell-gift-cards'])->name('shop.sell-gift-cards');
Route::view('gomarket', 'shop.coming-soon', ['service' => 'gomarket'])->name('shop.gomarket');

// Help Center - static FAQ, topic filters and support contact details.
Route::view('help', 'shop.help')->name('shop.help');

// How It Works - marketing walkthrough of the buying flow.
Route::view('how-it-works', 'shop.how-it-works')->name('shop.how-it-works');

// Contact - storefront contact page + message submission (stored + admin-notified).
Route::get('contact', [ContactController::class, 'index'])->name('shop.contact');
Route::post('contact', [ContactController::class, 'store'])
    ->middleware(['throttle:5,1', 'verify-turnstile:contact'])
    ->name('contact.send');

// Partnerships + Suppliers - dedicated inquiry forms that funnel into the
// same ContactMessage / admin notification flow with a category-tagged
// subject. The contact.url_partnerships_form / contact.url_suppliers_form
// SiteSettings default to these routes when an admin hasn't set a URL.
Route::get('partnerships', [ContactController::class, 'partnerships'])->name('shop.partnerships');
Route::post('partnerships', fn (Request $r, AdminNotificationService $a) => app(ContactController::class)->storeInquiry($r, $a, 'partnership'))
    ->middleware(['throttle:5,1', 'verify-turnstile:contact'])
    ->name('partnerships.send');

Route::get('suppliers', [ContactController::class, 'suppliers'])->name('shop.suppliers');
Route::post('suppliers', fn (Request $r, AdminNotificationService $a) => app(ContactController::class)->storeInquiry($r, $a, 'supplier'))
    ->middleware(['throttle:5,1', 'verify-turnstile:contact'])
    ->name('suppliers.send');

// Refund and Cancellation Policy.
Route::view('refund-policy', 'shop.refund-policy')->name('shop.refund-policy');

// Privacy Policy.
Route::view('privacy', 'shop.privacy')->name('shop.privacy');

// Cookie Policy.
Route::view('cookie-policy', 'shop.cookie-policy')->name('shop.cookie-policy');

// Compliance and Regulatory Framework.
Route::view('compliance', 'shop.compliance')->name('shop.compliance');

// About.
Route::view('about', 'shop.about')->name('shop.about');

// Mobile app - "in development" landing page.
Route::view('mobile-app', 'shop.mobile-app')->name('shop.mobile-app');

// Earn Points - Rcoin rewards programme.
Route::view('earn-points', 'shop.earn-points')->name('shop.earn-points');

// FAQ - comprehensive frequently asked questions.
Route::view('faq', 'shop.faq')->name('shop.faq');

// Press and Media - newsroom grid + single post view.
Route::get('press', [PressController::class, 'index'])->name('shop.press');
Route::get('press/{slug}', [PressController::class, 'show'])->name('shop.press.show');

// Blog - articles grid + single post view.
Route::get('blog', [BlogController::class, 'index'])->name('shop.blog');
Route::get('blog/{slug}', [BlogController::class, 'show'])->name('shop.blog.show');

// Reviews - curated customer reviews + Trustpilot review-collector CTA.
Route::view('reviews', 'shop.reviews')->name('shop.reviews');

// Terms of Service.
Route::view('terms', 'shop.terms')->name('shop.terms');

// Accessibility statement.
Route::view('accessibility', 'shop.accessibility')->name('shop.accessibility');

// HTML sitemap - a human-friendly index of every section.
Route::view('sitemap', 'shop.sitemap')->name('shop.sitemap');

// robots.txt (dynamic so the Sitemap line uses the correct host on any domain).
// Crawlers may index the whole public storefront; private surfaces are blocked.
Route::get('robots.txt', function () {
    $lines = [
        'User-agent: *',
        'Allow: /',
        'Disallow: /admin',
        'Disallow: /dashboard',
        'Disallow: /api',
        'Disallow: /cart',
        'Disallow: /checkout',
        'Disallow: /order',
        // Internal endpoints Googlebot kept hitting (showing up as 404s / crawl
        // waste in Search Console): the ajax brand-search backend and the region
        // switch action. Neither is a page. (Brand pages like /gift-cards/ajax -
        // the football club - are unaffected: they don't start with /ajax.)
        'Disallow: /ajax',
        'Disallow: /country',
        // Faceted-navigation params. sort= (same products, reordered) and
        // subcategory= filters generate endless duplicate listing URLs that all
        // canonicalise back to the base page - Google reports them as "Alternate
        // page with proper canonical tag". Block them from crawl so Googlebot
        // spends its budget on real pages; every clean canonical URL is already
        // listed in sitemap.xml, so indexing is unaffected. (?country= stays
        // crawlable - it's the region switch, not a duplicate filter.)
        'Disallow: /*sort=',
        'Disallow: /*subcategory=',
        '',
        'Sitemap: '.url('/sitemap.xml'),
        '',
    ];

    return response(implode("\n", $lines), 200, ['Content-Type' => 'text/plain']);
})->name('robots');

// Google Merchant Center product feed (XML). Scoped to eSIMs - the digital-goods
// category Shopping approves. Register as a scheduled-fetch feed in Merchant
// Center (XML = no column-delimiter issues). Self-caches for 6h.
Route::get('feeds/google-merchant.xml', [GoogleMerchantFeedController::class, 'esims'])
    ->name('feeds.google-merchant');

// XML sitemap for search engines. Lists every public, rankable page: marketing
// pages + one URL per product brand (gift cards / top-ups / bill payments).
// Cached for 6 hours so the brand query never runs on a hot crawl.
Route::get('sitemap.xml', function () {
    $xml = Cache::remember('sitemap.xml.v3', now()->addHours(6), function () {
        $urls = [];
        $push = function (string $loc, string $priority = '0.7', string $freq = 'weekly') use (&$urls) {
            $urls[$loc] = ['loc' => $loc, 'priority' => $priority, 'freq' => $freq];
        };

        $push(route('home'), '1.0', 'daily');

        $staticNames = [
            'shop.gift-cards' => '0.9', 'shop.esims' => '0.9', 'shop.topups' => '0.9', 'shop.bills' => '0.9',
            'shop.sell-gift-cards' => '0.6', 'shop.gomarket' => '0.6',
            'shop.flights' => '0.6', 'shop.stays' => '0.6', 'shop.reviews' => '0.6', 'shop.blog' => '0.6',
            'shop.how-it-works' => '0.5', 'shop.help' => '0.5', 'shop.faq' => '0.5', 'shop.contact' => '0.5',
            'shop.about' => '0.5', 'shop.mobile-app' => '0.5', 'shop.earn-points' => '0.5',
            'shop.partnerships' => '0.4', 'shop.suppliers' => '0.4', 'shop.press' => '0.4',
            'shop.privacy' => '0.3', 'shop.terms' => '0.3', 'shop.cookie-policy' => '0.3',
            'shop.refund-policy' => '0.3', 'shop.compliance' => '0.3', 'shop.accessibility' => '0.3',
            'shop.sitemap' => '0.3',
        ];
        foreach ($staticNames as $name => $priority) {
            if (Route::has($name)) {
                $push(route($name), $priority);
            }
        }

        // One URL per brand_key, routed by its category.
        $routeByCategory = [
            'gift-cards' => 'shop.brand',
            'mobile-airtime' => 'shop.topup',
            'bill-payments' => 'shop.bill',
        ];
        Product::query()
            ->where('products.is_active', true)
            ->whereNotNull('products.brand_key')
            ->join('categories', 'categories.id', '=', 'products.category_id')
            ->select('products.brand_key', 'categories.slug as category_slug')
            ->distinct()
            ->get()
            ->each(function ($row) use ($push, $routeByCategory) {
                $name = $routeByCategory[$row->category_slug] ?? null;
                if ($name && Route::has($name)) {
                    $push(route($name, ['brandSlug' => Product::brandSlug($row->brand_key)]), '0.8');
                }
            });

        // eSIMs don't key off brand_key like the catalogue above - each country /
        // region is its own store page (esims/{slug}). Reuse the same deduped
        // summary the storefront renders so the sitemap covers every sold location.
        EsimStoreController::catalogSummary()->each(function ($entry) use ($push) {
            if (! empty($entry['slug'])) {
                $push(route('shop.esim', $entry['slug']), '0.8');
            }
        });

        // Blog posts + press releases: prime content for organic ranking, so
        // every published article gets its own sitemap entry.
        BlogPost::published()->get(['slug'])->each(function ($post) use ($push) {
            $push(route('shop.blog.show', $post->slug), '0.7', 'monthly');
        });
        PressArticle::published()->get(['slug'])->each(function ($article) use ($push) {
            $push(route('shop.press.show', $article->slug), '0.6', 'monthly');
        });

        $out = '<?xml version="1.0" encoding="UTF-8"?>'."\n";
        $out .= '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'."\n";
        foreach ($urls as $u) {
            $out .= '  <url><loc>'.e($u['loc']).'</loc><changefreq>'.$u['freq'].'</changefreq><priority>'.$u['priority'].'</priority></url>'."\n";
        }

        return $out.'</urlset>';
    });

    return response($xml, 200, ['Content-Type' => 'application/xml']);
})->name('sitemap.xml');

// Machine-readable API catalogue (RFC 9727 / RFC 9264 linkset). The AdvertiseDiscoveryLinks
// middleware advertises this URL via a `Link` header on every HTML page so agents and
// crawlers can discover the storefront's navigable surfaces without scraping. We expose no
// public REST API, so the catalogue points at the human/machine sitemaps that index every
// rankable page.
Route::get('.well-known/api-catalog', function () {
    $linkset = [
        'linkset' => [
            [
                'anchor' => url('/'),
                'service-doc' => [
                    [
                        'href' => route('shop.sitemap'),
                        'type' => 'text/html',
                        'title' => 'Site index of every public section',
                    ],
                ],
                'describedby' => [
                    [
                        'href' => route('sitemap.xml'),
                        'type' => 'application/xml',
                        'title' => 'XML sitemap of every rankable page',
                    ],
                ],
            ],
        ],
    ];

    return response()->json($linkset, 200, [
        'Content-Type' => 'application/linkset+json',
    ]);
})->name('well-known.api-catalog');

// Cart page (HTML). Store-driven - it hydrates from the /cart/data JSON endpoint.
Route::get('cart', [CartWebController::class, 'page'])->name('shop.cart');

// Storefront cart - web routes (session-authenticated) wrapping the backend CartManager.
// Returns compact JSON the Alpine cart store consumes. See CartWebController.
Route::prefix('cart')->name('cart.')->group(function () {
    Route::get('data', [CartWebController::class, 'show'])->name('data');
    // not-suspended is a no-op for guests; only kicks in for an authenticated suspended user.
    Route::post('items', [CartWebController::class, 'add'])->middleware(['not-suspended', 'maintenance-guard'])->name('items.add');
    Route::patch('items/{item}', [CartWebController::class, 'update'])->middleware(['not-suspended', 'maintenance-guard'])->name('items.update');
    Route::delete('items/{item}', [CartWebController::class, 'remove'])->middleware(['not-suspended', 'maintenance-guard'])->name('items.remove');
});

// Checkout. Resolves the active cart (CartManager - same path the global CartComposer uses)
// and renders the checkout page. Order creation + payment-gateway initiation are backend
// territory; the POST below is a validation-only stub the backend replaces with a
// CheckoutController that builds the Order/OrderItem rows and kicks off the gateway.
// Shared by the storefront route below and the dashboard mirror (dashboard.shop.checkout)
// so logged-in users can check out without leaving the dashboard/PWA chrome.
$renderCheckout = function (CartManager $cartManager, CartPricingService $pricing) {
    $userId = auth()->id();
    $guestToken = request()->cookie('guest_token') ?? request()->header('X-Guest-Token');

    $cart = null;
    $totals = ['currency' => 'USD', 'subtotal' => 0, 'total' => 0];

    if ($userId || $guestToken) {
        try {
            $cart = $cartManager->resolveCart($userId, $guestToken);
            $cart->load('items.product', 'items.variant');
            $totals = $pricing->calculateCartTotals($cart->items);
        } catch (Throwable $e) {
            $cart = null;
        }
    }

    // Display currency: the customer's chosen currency, passed through as ?currency=
    // (the cart popup's Checkout link appends it). The view converts the USD totals
    // with this rate; falls back to USD when absent.
    $rate = CurrencyRate::resolve(request('currency'));

    return view('shop.checkout', ['cart' => $cart, 'totals' => $totals, 'rate' => $rate]);
};
Route::get('checkout', $renderCheckout)->name('shop.checkout');

// Customer review submission. Open to guests too (they supply a name); signed-in
// customers review under their account name. Stored unpublished for admin
// approval, then it appears on the storefront reviews wall.
Route::post('reviews', [ReviewController::class, 'store'])
    ->middleware(['throttle:6,1'])
    ->name('reviews.store');

// Coupon live preview. Validates a code against the active cart and returns the
// USD + display-currency discount, computed EXACTLY like CheckoutService applies
// it at order time (per-variant, per-unit x qty), so the checkout total the
// customer sees matches what they're charged. No state is changed here.
Route::post('coupon/preview', function (Request $request, CartManager $cartManager) {
    $code = Str::upper(trim((string) $request->input('code', '')));
    $currency = strtoupper(trim((string) $request->input('currency', 'USD'))) ?: 'USD';

    if ($code === '') {
        return response()->json(['valid' => false, 'message' => 'Enter a coupon code.']);
    }

    $userId = auth()->id();
    $guestToken = $request->cookie('guest_token') ?? $request->header('X-Guest-Token');

    try {
        $cart = $cartManager->resolveCart($userId, $guestToken);
        $cart->load('items');
    } catch (Throwable $e) {
        $cart = null;
    }

    if (! $cart || $cart->items->isEmpty()) {
        return response()->json(['valid' => false, 'message' => 'Your cart is empty.']);
    }

    $coupon = Coupon::where('code', $code)->first();
    if ($coupon === null) {
        return response()->json(['valid' => false, 'message' => 'This coupon code is not valid.']);
    }
    if (! $coupon->isRedeemable()) {
        return response()->json(['valid' => false, 'message' => 'This coupon has expired or is no longer available.']);
    }

    $item = $cart->items->firstWhere('product_variant_id', $coupon->product_variant_id);
    if ($item === null) {
        return response()->json(['valid' => false, 'message' => 'This coupon does not apply to anything in your cart.']);
    }

    $unitPriceUsd = (float) $item->subtotal_snapshot / max(1, (int) $item->quantity);
    $discountedUnit = $coupon->applyTo($unitPriceUsd);
    $discountUsd = round(($unitPriceUsd - $discountedUnit) * max(1, (int) $item->quantity), 2);

    if ($discountUsd <= 0) {
        return response()->json(['valid' => false, 'message' => 'This coupon gives no discount on your cart.']);
    }

    // Convert the USD discount into the customer's display currency for the
    // on-screen line (CurrencyRate::resolve returns the rate row + a convert()).
    $discountDisplay = round(CurrencyRate::resolve($currency)->convert($discountUsd), 2);

    return response()->json([
        'valid' => true,
        'code' => $coupon->code,
        'discount_usd' => $discountUsd,
        'discount_display' => $discountDisplay,
        'currency' => $currency,
        'message' => 'Coupon applied.',
    ]);
})->middleware(['not-suspended', 'maintenance-guard', 'throttle:20,1'])->name('coupon.preview');

// Checkout submission - creates the Order + OrderItems + a pending Payment from the
// cart. Auth is enforced inside the controller instead of here: guests are
// allowed through when features.guest_checkout_enabled is on (they order under
// a shadow account keyed by their delivery email, with per-IP/per-email rate
// limits on top of this route throttle), and get a 403 asking them to sign in
// when it is off. The dashboard mirror route keeps its auth middleware.
Route::post('checkout', [CheckoutController::class, 'process'])
    ->middleware(['not-suspended', 'maintenance-guard', 'throttle:10,1'])
    ->name('checkout.process');

// Guest account claim. The signed link (7 days) arrives by email after a
// guest order is fulfilled; the guest sets a password + transaction PIN and
// the shadow account becomes theirs, guest orders included. `signed`
// validates the URL signature; the POST uses its own short-lived signature
// minted by the show() page.
Route::get('claim-account/{user}', [GuestClaimController::class, 'show'])
    ->middleware(['guest', 'signed', 'throttle:12,1'])
    ->name('account.claim');
Route::post('claim-account/{user}', [GuestClaimController::class, 'store'])
    ->middleware(['guest', 'signed', 'throttle:8,1'])
    ->name('account.claim.store');

// Flutterwave hosted-checkout return URL. Customers land here after USSD,
// Pay With Bank, Bank QR, or Mobile Wallet payments complete (or cancel).
// We verify by tx_ref against Flutterwave so a tampered query string can't
// fake a success.
Route::get('checkout/return/{session}', [CheckoutController::class, 'hostedReturn'])
    ->middleware('auth')
    ->name('shop.checkout.return');

// Order confirmation page. No auth middleware: the controller scopes the
// lookup to the owning user, or - for guest checkout - to order numbers this
// browser session placed (anything else 404s, so orders stay un-enumerable).
Route::get('order/{orderNumber}', [CheckoutController::class, 'order'])
    ->name('shop.order');

// Status probe polled by the order page while fulfillment is in flight.
// Same viewer scoping as the confirmation page above.
Route::get('order/{orderNumber}/status.json', [CheckoutController::class, 'orderStatus'])
    ->middleware('throttle:60,1')
    ->name('shop.order.status');

// Downloadable receipts for the order success view.
Route::get('order/{orderNumber}/codes.csv', [CheckoutController::class, 'codesCsv'])
    ->middleware('auth')
    ->name('shop.order.codes.csv');
Route::get('order/{orderNumber}/codes.pdf', [CheckoutController::class, 'codesPdf'])
    ->middleware('auth')
    ->name('shop.order.codes.pdf');

// End an admin impersonation ("login as customer") session. Lives here, not in
// routes/admin.php, because the active web user during impersonation is the
// customer; the admin guard stays authenticated so the operator lands back in
// the panel. See AdminCustomerController::leaveImpersonation.
Route::post('impersonation/leave', [AdminCustomerController::class, 'leaveImpersonation'])
    ->middleware('auth')
    ->name('impersonation.leave');

// Customer dashboard - gated by web guard. Admin operators have their own area at /admin/* via routes/admin.php.
// NOTE: 'verified' middleware intentionally NOT applied - verification stays a SOFT requirement so users
// can use the app immediately after registration. The profile page surfaces the verification status badge
// and the sidebar has a Verify Identity entry, so users have multiple paths to verify when they choose.
// Re-add 'verified' here only if/when CTO wants to hard-gate the dashboard behind a confirmed email.
Route::middleware(['auth'])->group(function () {
    Route::view('dashboard', 'dashboard')->name('dashboard');

    Route::view('dashboard/rewards', 'dashboard.rewards')->name('dashboard.rewards');

    // Rcoin withdrawal request - posts from the form on the rewards page.
    // Admins review at /admin/content/rewards-withdrawals.
    Route::post('dashboard/rewards/withdraw', [RcoinWithdrawalController::class, 'store'])
        ->middleware('maintenance-guard')->name('dashboard.rewards.withdraw');

    // Instant Rcoin → wallet (USD) conversion. No admin approval. Capped by
    // wallet_conversion_min_usd setting (default $2.00).
    Route::post('dashboard/rewards/convert-to-wallet', [RcoinConvertController::class, 'toWallet'])
        ->middleware('maintenance-guard')->name('dashboard.rewards.convert-to-wallet');

    Route::view('dashboard/kyc', 'dashboard.kyc')->name('dashboard.kyc');

    Volt::route('dashboard/orders', 'dashboard.orders')->name('dashboard.orders');

    // Native eSIM top-up - refill an existing Airalo eSIM from the dashboard
    // without leaving the site. The controller hits Airalo's /sims/{iccid}/topups
    // for the available packages, debits the wallet on purchase, and dispatches
    // the standard fulfilment job which routes to /orders/topups via the
    // parent_iccid metadata flag.
    Route::get('dashboard/esims/{orderItem}/top-up', [EsimTopupController::class, 'show'])->name('dashboard.esim.topup');
    Route::post('dashboard/esims/{orderItem}/top-up', [EsimTopupController::class, 'purchase'])->middleware(['not-suspended', 'maintenance-guard'])->name('dashboard.esim.topup.purchase');

    Volt::route('dashboard/transactions', 'dashboard.transactions')->name('dashboard.transactions');

    Volt::route('dashboard/notifications', 'dashboard.notifications')->name('dashboard.notifications');

    // Gift Card Trading Routes
    Volt::route('dashboard/gift-cards/submit', 'user.gift-cards.submit')->name('dashboard.gift-cards.submit');
    Volt::route('dashboard/gift-cards/history', 'user.gift-cards.history')->name('dashboard.gift-cards.history');
    Volt::route('dashboard/gift-cards/trades/{trade}', 'user.gift-cards.trades.show')->name('dashboard.gift-cards.trades.show');
    Volt::route('dashboard/gift-cards/trades/{trade}/receipt', 'user.gift-cards.trades.receipt')->name('dashboard.gift-cards.trades.receipt');

    // Saved Cards hidden until the card-vault / gateway tokenisation backend ships.
    // The view is only an empty-state shell today; the nav links are also commented
    // out in components/layouts/dashboard.blade.php. Uncomment both to re-enable.
    // Route::view('dashboard/saved-cards', 'dashboard.saved-cards')->name('dashboard.saved-cards');

    Route::view('dashboard/wallet', 'dashboard.wallet')->name('dashboard.wallet');

    // TESTING ROUTES - Functional UI for backend testing
    Volt::route('dashboard/virtual-cards', 'dashboard.virtual-cards.index')->name('dashboard.virtual-cards');
    Volt::route('dashboard/transfers', 'dashboard.transfers.index')->name('dashboard.transfers');
    Volt::route('dashboard/withdrawals', 'dashboard.withdrawals.index')->name('dashboard.withdrawals');
    Volt::route('dashboard/exchange', 'dashboard.exchange.index')->name('dashboard.exchange');

    Volt::route('dashboard/profile', 'settings.profile')->name('dashboard.profile');
    Volt::route('dashboard/security', 'settings.security')->name('dashboard.security');

    // Alias for main branch compatibility where views still call route('dashboard.password')
    Volt::route('dashboard/password', 'settings.security')->name('dashboard.password');

    Volt::route('dashboard/security/pin-reset', 'settings.pin-reset')->name('dashboard.security.pin-reset');
    Volt::route('dashboard/appearance', 'settings.appearance')->name('dashboard.appearance');

    // Persists the customer's light/dark/system preference (theme engine posts here).
    Route::post('preferences/theme', [ThemeController::class, 'update'])->name('preferences.theme');

    // KYC identity-verification submission (documents stored on the private disk).
    Route::post('dashboard/kyc', [KycController::class, 'store'])->name('kyc.submit');

    // Suspension review request - submitted from the banner the customer
    // sees on their dashboard when their account is suspended. Lands in the
    // shared admin notification feed.
    Route::post('dashboard/suspension/request-review', [SuspensionController::class, 'requestReview'])
        ->name('suspension.request-review');

    // Web Push subscriptions for web users
    Route::prefix('push')->name('push.')->group(function () {
        Route::post('subscribe', [PushSubscriptionController::class, 'subscribe'])->name('subscribe');
        Route::post('unsubscribe', [PushSubscriptionController::class, 'unsubscribe'])->name('unsubscribe');
    });
});

// Dashboard shop chrome - mirrors the public storefront URLs under /dashboard/shop/*
// so logged-in users can browse the catalog without leaving their dashboard. The
// catalog views auto-detect this prefix via <x-shop.layout> and render the
// dashboard sidebar + header instead of the storefront chrome. Same Blade files,
// same controllers, same query-string filters - only the surrounding chrome
// differs. The public storefront URLs above stay open to everyone (guests AND
// authed users), so users can shop on either side.
Route::middleware(['auth'])->prefix('dashboard/shop')->name('dashboard.shop.')
    ->group(function () use ($resolveGiftCardBrand, $resolveTopupBrand, $resolveBillBrand, $renderCheckout) {
        Route::view('gift-cards', 'shop.gift-cards')->name('gift-cards');
        Route::get('gift-cards/{brandSlug}', $resolveGiftCardBrand)->name('brand');

        Route::get('esims', [EsimStoreController::class, 'index'])->name('esims');
        Route::get('esims/country/{code}', [EsimStoreController::class, 'country'])->name('esim.country');
        Route::get('esims/{slug}', [EsimStoreController::class, 'show'])->name('esim');

        Route::view('topups', 'shop.topups')->name('topups');
        Route::get('topups/{brandSlug}', $resolveTopupBrand)->name('topup');

        Route::view('bills', 'shop.bills')->name('bills');
        Route::get('bills/{brandSlug}', $resolveBillBrand)->name('bill');

        Route::view('flights', 'shop.coming-soon', ['service' => 'flights'])->name('flights');
        Route::view('stays', 'shop.coming-soon', ['service' => 'stays'])->name('stays');

        Route::view('sell-gift-cards', 'shop.coming-soon', ['service' => 'sell-gift-cards'])->name('sell-gift-cards');
        Route::view('gomarket', 'shop.coming-soon', ['service' => 'gomarket'])->name('gomarket');

        // Transactional flow mirrored under the dashboard so the cart -> checkout
        // -> order journey keeps the customer inside the dashboard/PWA chrome
        // instead of bouncing out to the public storefront. Same controllers and
        // views; the views auto-detect the dashboard URL via <x-shop.layout>.
        Route::get('cart', [CartWebController::class, 'page'])->name('cart');
        Route::get('checkout', $renderCheckout)->name('checkout');
        Route::post('checkout', [CheckoutController::class, 'process'])
            ->middleware(['not-suspended', 'maintenance-guard', 'throttle:10,1'])
            ->name('checkout.process');
        Route::get('checkout/return/{session}', [CheckoutController::class, 'hostedReturn'])->name('checkout.return');
        Route::get('order/{orderNumber}', [CheckoutController::class, 'order'])->name('order');
    });

// Legacy /settings/* URLs redirect to the new /dashboard/* paths so old bookmarks keep working.
Route::middleware(['auth'])->group(function () {
    Route::redirect('settings', 'dashboard/profile');
    Route::redirect('settings/profile', 'dashboard/profile');
    Route::redirect('settings/password', 'dashboard/security');
    Route::redirect('settings/security', 'dashboard/security');
    Route::redirect('settings/appearance', 'dashboard/appearance');
});

// Local-only email template previews - design the transactional emails in the
// browser (no events fired, no mail sent). Never registered outside local.
if (app()->environment('local')) {
    Route::get('dev/emails', [EmailPreviewController::class, 'index'])->name('dev.emails.index');
    Route::get('dev/emails/{key}', [EmailPreviewController::class, 'show'])->name('dev.emails.show');
}

// Transaction Recovery Routes (Public)
Route::get('/recovery/thanks', [RecoveryController::class, 'thanks'])->name('recovery.thanks');
Route::get('/recovery/{token}', [RecoveryController::class, 'show'])->name('recovery.show');
Route::post('/recovery/{token}/feedback', [RecoveryController::class, 'feedback'])->name('recovery.feedback');
Route::post('/recovery/{token}/retry', [RecoveryController::class, 'retry'])->name('recovery.retry');

// Security & Verification
Route::middleware(['auth'])->prefix('security')->name('security.')->group(function () {
    Route::get('verify-device', [DeviceVerificationController::class, 'show'])->name('verify-device');
    Route::post('verify-device/send', [DeviceVerificationController::class, 'sendOtp'])->name('verify-device.send');
    Route::post('verify-device', [DeviceVerificationController::class, 'verifyOtp'])->name('verify-device.verify');

    // Unauthenticated route for the NO IT WASN'T ME flow
    Route::get('compromise', [DeviceVerificationController::class, 'handleCompromise'])
        ->withoutMiddleware(['auth'])
        ->name('compromise');

    // Account recovery flow for Protected Mode
    Volt::route('recover', 'security.recover')->name('recover');
});

require __DIR__.'/admin.php';

if (file_exists(__DIR__.'/debug_browser.php')) {
    require __DIR__.'/debug_browser.php';
}

require __DIR__.'/auth.php';

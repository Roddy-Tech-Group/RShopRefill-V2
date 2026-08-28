<?php

namespace App\Models;

use App\Domain\Shared\Enums\ComplianceCategory;
use App\Enums\PricingBasis;
use App\Models\Concerns\HasTranslations;
use App\Support\DisplayCurrency;
use App\Support\FeatureFlag;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class Product extends Model
{
    use HasTranslations;

    /**
     * Prose the admin can translate. The column stays the SOURCE text;
     * a locale with no row here reads straight through to it.
     *
     * @var array<int, string>
     */
    protected array $translatable = ['name', 'description', 'redeem_instructions', 'terms_and_conditions'];

    use HasFactory, SoftDeletes;

    /**
     * Customer-facing display label for a brand. Reads config/brand_display_names.php
     * for overrides ("Apple" → "Everything Apple") and falls back to a humanised
     * version of the raw brand_key ("MobileLegends" → "Mobile Legends").
     */
    public static function brandDisplayName(?string $brandKey): string
    {
        if (! $brandKey) {
            return '';
        }

        $alias = config("brand_display_names.aliases.{$brandKey}");
        if ($alias) {
            return $alias;
        }

        // CamelCase / PascalCase → "Spaced Words". Single-word keys unchanged.
        return preg_replace('/(?<=[a-z])(?=[A-Z])/', ' ', $brandKey);
    }

    /**
     * URL-safe slug derived from brand_key for the brand-level detail page.
     * "MobileLegends" → "mobile-legends", "Apple" → "apple", "GooglePlay" → "google-play".
     */
    public static function brandSlug(string $brandKey): string
    {
        return Str::kebab($brandKey);
    }

    /**
     * Best logo URL for a brand. Prefers a handcrafted local asset (mapped in
     * config/brand_assets.php) over Zendit's `logo_url`, which is sometimes a
     * wide gray placeholder. Falls back to the raw `logo_url` then to null.
     */
    public static function brandLogoUrl(?string $brandKey, ?string $fallback = null): ?string
    {
        if ($brandKey) {
            $local = config("brand_assets.logos.{$brandKey}");
            if ($local) {
                return asset('assets/'.$local);
            }
        }

        return $fallback ?: null;
    }

    /**
     * Card background colour for a brand. Prefers a hand-picked override from
     * config/brand_colors.php, then the brand_color synced from Zendit, else null
     * (the view renders white). Lets the gift-card tiles show the real brand colour.
     */
    public static function brandColor(?string $brandKey, ?string $fallback = null): ?string
    {
        if ($brandKey) {
            $override = config("brand_colors.colors.{$brandKey}");
            if ($override) {
                return $override;
            }
        }

        return $fallback ?: null;
    }

    /**
     * Whether this brand's logo art already carries the brand name (so the
     * delivered-card caption under it would just repeat it).
     */
    public static function logoCarriesName(?string $brandKey): bool
    {
        return $brandKey !== null
            && in_array($brandKey, config('brand_colors.logo_includes_name', []), true);
    }

    /**
     * Whether a hex colour is dark enough to need white foreground text.
     * Accepts #rgb and #rrggbb; anything unparsable counts as light so the
     * caller falls back to its default dark-on-light styling.
     */
    public static function colorIsDark(?string $hex): bool
    {
        $hex = ltrim((string) $hex, '#');
        if (strlen($hex) === 3) {
            $hex = preg_replace('/(.)/', '$1$1', $hex);
        }
        if (strlen($hex) !== 6 || ! ctype_xdigit($hex)) {
            return false;
        }

        [$r, $g, $b] = [hexdec(substr($hex, 0, 2)), hexdec(substr($hex, 2, 2)), hexdec(substr($hex, 4, 2))];

        // Perceived luminance (ITU-R BT.601); < 150 reads as a dark surface.
        return (0.299 * $r + 0.587 * $g + 0.114 * $b) < 150;
    }

    /**
     * Deterministic solid tile colour for a brand/operator that has no logo,
     * derived from its key so the same brand always renders the same colour.
     * Used as a branded fallback — mobile-airtime operators carry no logo, so
     * their cards become a coloured name tile instead of an empty box.
     */
    public static function tileColor(?string $key): string
    {
        $palette = [
            '#2563eb', '#dc2626', '#16a34a', '#d97706', '#7c3aed', '#0891b2',
            '#db2777', '#4f46e5', '#ca8a04', '#0d9488', '#e11d48', '#9333ea',
        ];

        return $palette[abs(crc32((string) $key)) % count($palette)];
    }

    /**
     * Real flag image URL for an ISO-3166 country code, via flagcdn.com.
     * Country-flag emoji don't render on Windows desktop, so we use images
     * for consistent flags on every OS. Returns null for an invalid code.
     */
    public static function flagUrl(?string $iso): ?string
    {
        if (! $iso || strlen($iso) !== 2) {
            return null;
        }

        // w160 keeps flags crisp on retina at the sizes we render them; flagcdn is a
        // CDN and the PNGs are only a few KB, so it still loads fast (and we lazy-load).
        return 'https://flagcdn.com/w160/'.strtolower($iso).'.png';
    }

    /**
     * Symbol for a currency code. Falls back to the raw code + space so an
     * unmapped currency still renders something sensible.
     */
    public static function currencySymbol(?string $code): string
    {
        $code = strtoupper((string) $code);

        return [
            'USD' => '$', 'EUR' => '€', 'GBP' => '£', 'NGN' => '₦', 'XAF' => 'XAF ', 'XOF' => 'CFA',
            'ZAR' => 'R', 'KES' => 'KSh', 'GHS' => '₵', 'EGP' => 'E£', 'MAD' => 'DH', 'CAD' => 'CA$',
            'AUD' => 'A$', 'JPY' => '¥', 'CNY' => '¥', 'INR' => '₹', 'BRL' => 'R$', 'AED' => 'AED',
            'SAR' => 'SAR', 'TRY' => '₺', 'CHF' => 'Fr', 'MXN' => 'MX$', 'KRW' => '₩', 'SGD' => 'S$',
            'HKD' => 'HK$', 'TWD' => 'NT$', 'THB' => '฿', 'IDR' => 'Rp', 'PHP' => '₱', 'VND' => '₫',
            'MYR' => 'RM', 'PLN' => 'zł', 'SEK' => 'kr', 'NOK' => 'kr', 'DKK' => 'kr', 'CZK' => 'Kč',
        ][$code] ?? ($code !== '' ? $code.' ' : '');
    }

    /**
     * Lowest + highest spendable amount across this product's available variants.
     * Fixed variants contribute their retail_price; variable variants contribute
     * their min_amount and max_amount. Requires `variants` to be loaded.
     *
     * @return array{0: float|null, 1: float|null} [min, max]
     */
    public function priceRange(): array
    {
        $min = null;
        $max = null;

        foreach ($this->variants as $v) {
            if (! $v->is_available) {
                continue;
            }

            $values = [];
            if ($v->is_variable) {
                if ($v->min_amount !== null) {
                    $values[] = (float) $v->min_amount;
                }
                if ($v->max_amount !== null) {
                    $values[] = (float) $v->max_amount;
                }
            } elseif ($v->retail_price !== null) {
                $values[] = (float) $v->retail_price;
            }

            foreach ($values as $val) {
                $min = $min === null ? $val : min($min, $val);
                $max = $max === null ? $val : max($max, $val);
            }
        }

        return [$min, $max];
    }

    /**
     * Human-readable price range string, e.g. "$5 - $500" or "$10".
     * Uses this product's currency_code for the symbol.
     */
    public function priceRangeLabel(): ?string
    {
        [$min, $max] = $this->priceRange();
        if ($min === null) {
            return null;
        }

        // In the customer's chosen display currency when one is set and the
        // pair is priceable; the product's own currency otherwise. See
        // App\Support\DisplayCurrency for the resolution and fallbacks.
        [$min, $code] = DisplayCurrency::convert((float) $min, $this->currency_code);
        if ($max !== null) {
            [$max] = DisplayCurrency::convert((float) $max, $this->currency_code);
        }

        $sym = self::currencySymbol($code);
        $fmt = fn ($v) => $sym.rtrim(rtrim(number_format($v, 2), '0'), '.');

        return ($max !== null && $max > $min)
            ? $fmt($min).' - '.$fmt($max)
            : $fmt($min);
    }

    protected $fillable = [
        'category_id',
        'subcategory_id',
        'compliance_category',
        'provider_name',
        'provider_reference',
        'brand_key',
        'country_code',
        'currency_code',
        'pricing_basis',
        'name',
        'slug',
        'description',
        'redeem_instructions',
        'terms_and_conditions',
        'logo_url',
        'featured_image',
        'brand_color',
        'is_featured',
        'is_popular',
        'is_active',
        'is_out_of_stock',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'pricing_basis' => PricingBasis::class,
            'compliance_category' => ComplianceCategory::class,
            'is_featured' => 'boolean',
            'is_popular' => 'boolean',
            'is_active' => 'boolean',
            'is_out_of_stock' => 'boolean',
            'metadata' => 'array',
        ];
    }

    /**
     * The compliance category the payment eligibility gate reads. Prefers the
     * stored column; when it is somehow unset (a product created before this
     * field, or without an explicit value) it falls back to deriving from the
     * storefront category slug, so the gate never silently treats an untagged
     * gift card as an ordinary digital good.
     */
    public function complianceCategory(): ComplianceCategory
    {
        return $this->compliance_category
            ?? ComplianceCategory::fromCategorySlug($this->category?->slug);
    }

    /**
     * Feature-flag key for this product's category (or null when the category has
     * no gate). Resolves through the one canonical map on FeatureFlag.
     */
    public function categoryFeatureKey(): ?string
    {
        return FeatureFlag::forCategory($this->category?->slug);
    }

    /**
     * Is this product's category feature turned on? Drives the "grey out when the
     * category is off" behaviour. Ungated categories are always on.
     */
    public function isCategoryEnabled(): bool
    {
        return FeatureFlag::categoryEnabled($this->category?->slug);
    }

    /**
     * The single shared "can a customer buy this right now?" predicate. Both the
     * greyed-out UI and the server-side purchase guard read this so they always
     * agree: buyable only if the category feature is on, the product is active and
     * in stock, and at least one variant is available. Requires `variants` (and
     * ideally `category`) to be loaded to avoid lazy queries in a loop.
     */
    public function isBuyable(): bool
    {
        if (! $this->is_active || $this->is_out_of_stock || ! $this->isCategoryEnabled()) {
            return false;
        }

        return $this->variants->contains(fn (ProductVariant $v) => $v->is_available);
    }

    /**
     * The curated Popular row for a region. Reads config/popular_brands.php (ordered
     * brand keys) and returns one representative Product per brand: the visitor's own
     * region row if the brand has one, else the USA row, else any country. Products
     * native to $region are floated to the top (config order kept within each group),
     * so a Cameroon visitor sees their local top-ups first, then all the USA gift cards.
     *
     * @return Collection<int, Product>
     */
    public static function popularForRegion(string $region): Collection
    {
        $region = strtoupper($region);
        $keys = (array) config('popular_brands.keys', []);

        if (empty($keys)) {
            return collect();
        }

        $byBrand = static::query()
            ->where('is_active', true)
            ->whereIn('brand_key', $keys)
            ->with('variants:id,product_id,retail_price,is_variable,is_available')
            ->get()
            ->groupBy('brand_key');

        return collect($keys)
            ->map(function (string $key) use ($byBrand, $region) {
                $rows = $byBrand->get($key);
                if (! $rows) {
                    return null;
                }

                // Prefer the visitor's own region, then USA, then any country.
                return $rows->firstWhere('country_code', $region)
                    ?? $rows->firstWhere('country_code', 'US')
                    ?? $rows->first();
            })
            ->filter()
            // Own-region brands first; config order preserved within each group.
            ->sortBy(fn (Product $p) => $p->country_code === $region ? 0 : 1)
            ->values();
    }

    public function category()
    {
        return $this->belongsTo(Category::class);
    }

    public function subcategory()
    {
        return $this->belongsTo(Subcategory::class);
    }

    public function variants()
    {
        return $this->hasMany(ProductVariant::class);
    }
}

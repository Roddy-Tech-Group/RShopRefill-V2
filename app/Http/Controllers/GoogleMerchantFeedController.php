<?php

namespace App\Http\Controllers;

use App\Domain\Cart\Services\CartPricingService;
use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Google Merchant Center product feed (RSS 2.0 + the g: namespace).
 *
 * Scoped to eSIMs on purpose: a digital-goods category Google Shopping
 * approves, unlike gift cards (a restricted category that gets disapproved
 * regardless of feed quality). One <item> per coverage region, priced from the
 * cheapest available plan so the feed price genuinely appears on the landing
 * page (a price mismatch is itself a disapproval cause). Cached 6h.
 *
 * Register it in Merchant Center as a "scheduled fetch" XML feed pointing at
 * route('feeds.google-merchant') - XML has no column delimiter, so the
 * "delimiter detection failed" TSV error cannot occur.
 */
class GoogleMerchantFeedController extends Controller
{
    public function __construct(private readonly CartPricingService $pricing) {}

    public function esims(): Response
    {
        $xml = Cache::remember('google-merchant-feed-esims', now()->addHours(6), fn () => $this->build());

        return response($xml, 200)->header('Content-Type', 'application/xml; charset=UTF-8');
    }

    private function build(): string
    {
        $products = Product::query()
            ->whereHas('category', fn ($q) => $q->where('slug', 'esims'))
            ->where('is_active', true)
            ->get(['id', 'slug', 'brand_key', 'country_code', 'logo_url', 'featured_image', 'name', 'description']);

        $items = '';

        foreach ($products as $product) {
            // Cheapest available plan: its marked-up USD price is the "from" price,
            // and it is one of the prices shown on the region's landing page.
            $variant = ProductVariant::query()
                ->where('product_id', $product->id)
                ->where('is_available', true)
                ->where('cost_price', '>', 0)
                ->orderBy('cost_price')
                ->first();

            if (! $variant) {
                continue;
            }

            $priceUsd = round((float) ($this->pricing->calculatePricing($variant, 1)['unit_price_snapshot'] ?? 0), 2);
            if ($priceUsd <= 0) {
                continue;
            }

            $image = $this->imageFor($product);
            if (! $image) {
                continue; // Google requires image_link.
            }

            $name = trim((string) $product->name) ?: 'Travel';
            $title = Str::contains(Str::lower($name), 'esim') ? $name : "{$name} eSIM";

            $desc = trim(strip_tags((string) ($product->description ?? '')));
            if ($desc === '') {
                $desc = "Travel data eSIM for {$name}. Instant delivery, install by QR code with no physical SIM, plans from \$".number_format($priceUsd, 2).'.';
            }

            $items .= $this->item([
                'id' => 'esim-'.$product->slug,
                'title' => Str::limit($title, 145, ''),
                'description' => Str::limit($desc, 4900, ''),
                'link' => route('shop.esim', $product->slug),
                'image_link' => $image,
                'availability' => 'in_stock',
                'price' => number_format($priceUsd, 2, '.', '').' USD',
                'condition' => 'new',
                'brand' => config('app.name', 'RshopRefill'),
                'google_product_category' => 'Electronics > Communications > Telephony > Mobile Phone Accessories > SIM Cards',
                'identifier_exists' => 'no',
            ]);
        }

        $name = $this->xml(config('app.name', 'RshopRefill'));
        $home = $this->xml(url('/'));

        return <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<rss version="2.0" xmlns:g="http://base.google.com/ns/1.0">
  <channel>
    <title>{$name} - Travel eSIMs</title>
    <link>{$home}</link>
    <description>Travel data eSIMs with instant delivery, from {$name}.</description>
{$items}  </channel>
</rss>
XML;
    }

    /**
     * Resolve an absolute image URL for a region: the admin image first, then
     * the country flag (per-region visual) as a fallback. Null = skip the item.
     */
    private function imageFor(Product $product): ?string
    {
        $img = $product->featured_image ?: $product->logo_url ?: Product::flagUrl($product->country_code);

        if (! $img) {
            return null;
        }

        return Str::startsWith($img, ['http://', 'https://']) ? $img : url($img);
    }

    /**
     * @param  array<string, string>  $attributes
     */
    private function item(array $attributes): string
    {
        $fields = '';
        foreach ($attributes as $key => $value) {
            $fields .= "      <g:{$key}>".$this->xml($value)."</g:{$key}>\n";
        }

        return "    <item>\n{$fields}    </item>\n";
    }

    /**
     * Strip characters illegal in XML 1.0, then entity-encode.
     */
    private function xml(string $value): string
    {
        $value = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $value);

        return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }
}

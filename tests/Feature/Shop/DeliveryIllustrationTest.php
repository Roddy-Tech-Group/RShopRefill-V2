<?php

namespace Tests\Feature\Shop;

use App\Models\Category;
use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/** Step three previews the completed mobile order using public catalogue data. */
class DeliveryIllustrationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
    }

    private function product(array $attributes = []): Product
    {
        $category = Category::firstOrCreate(
            ['slug' => 'gift-cards'],
            ['name' => 'Gift Cards', 'type' => 'catalog', 'is_active' => true],
        );

        return Product::factory()->create(array_merge([
            'category_id' => $category->id,
            'brand_key' => 'Amazon',
            'country_code' => 'US',
            'currency_code' => 'USD',
        ], $attributes));
    }

    public function test_step_three_uses_the_delivery_scene(): void
    {
        $steps = file_get_contents(resource_path('views/components/home/how-it-works.blade.php'));

        $this->assertStringContainsString('<x-illo name="delivery"', $steps);
        $this->assertStringNotContainsString('<x-illo name="payout"', $steps);
    }

    public function test_the_phone_shows_the_completed_order_labels_and_animation_parts(): void
    {
        $svg = Blade::render('<x-illo name="delivery" />');

        foreach (['idl-phone', 'idl-badge', 'idl-check', 'idl-heading', 'idl-download', 'idl-card', 'idl-credentials', 'idl-actions'] as $id) {
            $this->assertStringContainsString('id="'.$id.'"', $svg, '#'.$id.' is missing.');
            $this->assertDoesNotMatchRegularExpression('/<[^>]+id="'.$id.'"[^>]+\sopacity="0"/', $svg);
        }

        $this->assertSame(1, substr_count($svg, 'class="idl-copy"'));
        $this->assertStringContainsString('class="idl-char"', $svg);

        foreach (['success_heading', 'success_thanks', 'download_csv', 'credential_code', 'redeem_instructions', 'terms_and_conditions'] as $key) {
            $this->assertStringContainsString(e(__('orders.'.$key)), $svg);
        }

        foreach (['idl-route', 'idl-parcel', 'idl-note', 'idl-bolt'] as $id) {
            $this->assertStringNotContainsString('id="'.$id.'"', $svg);
        }
    }

    public function test_the_animation_registry_matches_the_scene(): void
    {
        $js = file_get_contents(resource_path('js/app.js'));

        $this->assertSame(1, preg_match('/    delivery: \{(.*?)\r?\n};/s', $js, $matches));
        $delivery = $matches[1];

        foreach (['#idl-phone', '#idl-badge', '#idl-check', '#idl-card', '#idl-credentials', '#idl-actions'] as $selector) {
            $this->assertStringContainsString($selector, $delivery);
        }

        foreach (['#idl-route', '#idl-parcel', '#idl-note', '#idl-bolt', 'motionPath'] as $retired) {
            $this->assertStringNotContainsString($retired, $delivery);
        }

        $this->assertStringContainsString("window.matchMedia('(prefers-reduced-motion: reduce)').matches", $js);
        $this->assertStringNotContainsString('payout: {', $js);
        $this->assertStringNotContainsString('#isx-', $js);
    }

    public function test_the_preview_uses_available_catalogue_artwork_color_and_face_value(): void
    {
        $product = $this->product(['brand_color' => '#123456']);
        ProductVariant::factory()->for($product)->create(['face_value' => 5, 'is_available' => false]);
        ProductVariant::factory()->for($product)->create(['face_value' => 10, 'is_variable' => true]);
        ProductVariant::factory()->for($product)->create(['face_value' => 15, 'currency' => 'EUR']);
        ProductVariant::factory()->for($product)->create(['face_value' => 27.55, 'retail_price' => 29.99]);

        $svg = Blade::render('<x-illo name="delivery" />');

        $this->assertStringContainsString('href="'.Product::brandLogoUrl('Amazon').'"', $svg);
        $this->assertStringContainsString('fill="#123456"', $svg);
        $this->assertStringContainsString('$27.55', $svg);
        $this->assertStringNotContainsString('$29.99', $svg);
        $this->assertStringContainsString(e(array_flip(config('countries.codes'))['US']), $svg);
    }

    public function test_the_preview_and_its_cache_respect_the_visitors_region(): void
    {
        $us = $this->product();
        ProductVariant::factory()->for($us)->create(['face_value' => 27.55]);
        $germany = $this->product(['brand_key' => 'Netflix', 'country_code' => 'DE', 'currency_code' => 'EUR']);
        ProductVariant::factory()->for($germany)->create(['face_value' => 34.75, 'currency' => 'EUR']);

        $usSvg = Blade::render('<x-illo name="delivery" />');
        request()->attributes->set('region', 'DE');
        $deSvg = Blade::render('<x-illo name="delivery" />');

        $this->assertStringContainsString('href="'.Product::brandLogoUrl('Amazon').'"', $usSvg);
        $this->assertStringContainsString('href="'.Product::brandLogoUrl('Netflix').'"', $deSvg);
        $this->assertStringContainsString(Product::currencySymbol('EUR').'34.75', $deSvg);
        $this->assertStringContainsString(e(array_flip(config('countries.codes'))['DE']), $deSvg);
        $this->assertStringNotContainsString('amazon.webp', $deSvg);
        $this->assertStringNotContainsString('$27.55', $deSvg);
    }

    public function test_a_card_without_currency_keeps_its_artwork_but_omits_the_amount(): void
    {
        $product = $this->product(['currency_code' => '']);
        ProductVariant::factory()->for($product)->create(['face_value' => 27.55, 'currency' => '']);

        $svg = Blade::render('<x-illo name="delivery" />');

        $this->assertStringContainsString('href="'.Product::brandLogoUrl('Amazon').'"', $svg);
        $this->assertStringContainsString('fill="#f4f4f5"', $svg);
        $this->assertStringNotContainsString('27.55', $svg);
    }

    public function test_an_empty_catalogue_shows_a_neutral_card_without_an_invented_price(): void
    {
        $svg = Blade::render('<x-illo name="delivery" />');

        $this->assertStringContainsString('id="idl-card"', $svg);
        $this->assertStringContainsString('id="idl-credentials"', $svg);
        $this->assertStringContainsString('class="idl-char"', $svg);
        $this->assertStringNotContainsString('<image', $svg);
        $this->assertStringNotContainsString('$100', $svg);
        $this->assertDoesNotMatchRegularExpression('/[$€£]\s*\d/', $svg);
    }

    public function test_ineligible_products_do_not_supply_the_preview(): void
    {
        $unavailable = $this->product(['brand_key' => 'Netflix']);
        foreach ([
            ['face_value' => 31, 'is_available' => false],
            ['face_value' => 32, 'is_variable' => true],
            ['face_value' => 0],
            ['face_value' => 33, 'currency' => 'EUR'],
        ] as $variant) {
            ProductVariant::factory()->for($unavailable)->create($variant);
        }

        $topups = Category::factory()->create(['slug' => 'mobile-airtime']);
        foreach ([
            ['brand_key' => 'Spotify', 'is_active' => false],
            ['brand_key' => 'MTN', 'category_id' => $topups->id],
            ['brand_key' => 'RemoteBrand', 'logo_url' => 'https://example.com/remote-brand.png'],
        ] as $attributes) {
            ProductVariant::factory()->for($this->product($attributes))->create(['face_value' => 35]);
        }

        $svg = Blade::render('<x-illo name="delivery" />');

        $this->assertStringNotContainsString('<image', $svg);
        $this->assertDoesNotMatchRegularExpression('/[$€£]\s*\d/', $svg);
        $this->assertStringContainsString('id="idl-credentials"', $svg);
    }
}

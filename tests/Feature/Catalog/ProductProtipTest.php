<?php

namespace Tests\Feature\Catalog;

use App\Domain\Catalog\Services\ZenditNormalizer;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * The warning a buyer needs before paying, above "How to redeem".
 *
 * A brand with something of its own to say says it; every other gift card
 * carries the one warning that is true of all of them, because buying against
 * the wrong country is the common way a customer ends up with a code they
 * cannot use, and by then the order is final.
 *
 * It lives in its own column because the brand sync rewrites
 * redeem_instructions and terms_and_conditions wholesale every few hours, so a
 * note kept in either of those would disappear on its own.
 */
class ProductProtipTest extends TestCase
{
    use RefreshDatabase;

    private function giftCard(array $extra = []): Product
    {
        $category = Category::create(['name' => 'Gift Cards', 'slug' => 'gift-cards', 'type' => 'digital']);

        $product = Product::create(array_merge([
            'category_id' => $category->id,
            'provider_name' => 'zendit',
            'brand_key' => 'Acme',
            'country_code' => 'US',
            'currency_code' => 'USD',
            'name' => 'Acme Gift Card',
            'slug' => 'acme-us-zendit',
            'is_active' => true,
        ], $extra));

        ProductVariant::factory()->for($product)->create([
            'currency' => 'USD',
            'face_value' => 10,
            'retail_price' => 10,
            'manual_retail_price_usd' => 10,
            'is_available' => true,
            'is_variable' => false,
        ]);

        return $product;
    }

    private function page(): string
    {
        return $this->withoutVite()->get(route('shop.brand', ['brandSlug' => 'acme']))->assertOk()->getContent();
    }

    public function test_a_gift_card_warns_about_the_country_before_it_says_how_to_redeem(): void
    {
        $this->giftCard();

        $html = $this->page();

        $this->assertStringContainsString('Before you buy', $html);
        $this->assertStringContainsString('may only work in the country it was issued for', $html);
        $this->assertStringContainsString('check that country is United States before you pay', $html);

        // It does not assume an account: most of this catalogue is redeemed in a shop.
        $this->assertStringNotContainsString('the account you redeem it on', $html);

        // It is read before the steps, not after them.
        $this->assertLessThan(strpos($html, 'How to redeem'), strpos($html, 'Before you buy'));

        // And it points at the policy that makes it matter.
        $this->assertStringContainsString('href="'.e(route('shop.refund-policy')).'"', $html);
    }

    /** A brand with its own warning shows that instead, and drops the general one. */
    public function test_a_brands_own_note_replaces_the_general_warning(): void
    {
        $this->giftCard(['protip' => 'Only works on accounts created before 2019.']);

        $html = $this->page();

        $this->assertStringContainsString('Only works on accounts created before 2019.', $html);
        $this->assertStringNotContainsString('may only work in the country it was issued for', $html);
        $this->assertStringContainsString('Before you buy', $html);
    }

    /** Top-ups and bills are not country-locked codes, so they carry no such note. */
    public function test_the_other_kinds_carry_no_protip(): void
    {
        $topup = $this->otherKind('mobile-airtime', 'Mobile Airtime', 'shop.topup');
        $this->assertStringNotContainsString('Before you buy', $topup);
        $this->assertStringNotContainsString('may only work in the country it was issued for', $topup);

        $bill = $this->otherKind('bill-payments', 'Bill Payments', 'shop.bill');
        $this->assertStringNotContainsString('Before you buy', $bill);
    }

    /** A brand of another kind, rendered on its own page. */
    private function otherKind(string $categorySlug, string $name, string $route): string
    {
        $category = Category::firstOrCreate(['slug' => $categorySlug], ['name' => $name, 'type' => 'digital']);

        $product = Product::create([
            'category_id' => $category->id,
            'provider_name' => 'zendit',
            'brand_key' => 'Beta'.ucfirst($categorySlug),
            'country_code' => 'US',
            'currency_code' => 'USD',
            'name' => $name.' Brand',
            'slug' => 'beta-'.$categorySlug.'-us-zendit',
            'is_active' => true,
        ]);

        ProductVariant::factory()->for($product)->create([
            'currency' => 'USD',
            'face_value' => 5,
            'retail_price' => 5,
            'manual_retail_price_usd' => 5,
            'is_available' => true,
            'is_variable' => false,
        ]);

        return $this->withoutVite()
            ->get(route($route, ['brandSlug' => Product::brandSlug($product->brand_key)]))
            ->assertOk()
            ->getContent();
    }

    /**
     * The whole reason for a dedicated column: a catalog re-sync must not be
     * able to erase what an admin wrote.
     */
    public function test_a_catalog_resync_leaves_a_written_note_alone(): void
    {
        $product = $this->giftCard(['protip' => 'Check your account country first.']);

        Queue::fake();

        app(ZenditNormalizer::class)->normalizeAndSave([
            'brand' => 'Acme',
            'brandName' => 'Acme',
            'country' => 'US',
            'offerId' => 'acme-us-10',
            'priceType' => 'FIXED',
            'enabled' => true,
            'send' => ['currency' => 'USD', 'currencyDivisor' => 100, 'fixed' => 1000],
            'price' => ['currency' => 'USD', 'currencyDivisor' => 100, 'fixed' => 1000],
            'cost' => ['currency' => 'USD', 'currencyDivisor' => 100, 'fixed' => 900],
            'redeem_instructions' => 'Supplier steps that arrive on every sync.',
            'terms' => 'Supplier terms that arrive on every sync.',
        ], 'zendit');

        $this->assertSame('Check your account country first.', $product->fresh()->protip);
    }

    /**
     * The brand sync is the pass that actually overwrites prose. It names two
     * columns; this one is not among them, and must never be.
     */
    public function test_no_sync_writer_names_the_column(): void
    {
        $writers = [
            'app/Domain/Catalog/Services/ZenditNormalizer.php',
            'app/Domain/Catalog/Services/ZenditBrandSyncService.php',
            'app/Domain/Catalog/Services/ZenditTopupNormalizer.php',
        ];

        foreach ($writers as $writer) {
            $path = base_path($writer);

            // Not a skip: a moved writer must fail this guard rather than
            // silently turn it into an assertion about nothing.
            $this->assertFileExists($path, "{$writer} moved; point this guard at its new path.");

            $this->assertStringNotContainsString(
                'protip',
                file_get_contents($path),
                "{$writer} names the protip column; omission from sync payloads is what keeps a written note alive."
            );
        }
    }

    /** Written once, it is translatable like the rest of the product's prose. */
    public function test_the_note_is_translatable(): void
    {
        $this->assertContains('protip', (new Product)->translatableFields());
    }
}

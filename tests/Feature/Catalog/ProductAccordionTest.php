<?php

namespace Tests\Feature\Catalog;

use App\Models\Category;
use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The product page's long-form sections: accordion rows that are a card each,
 * with a round toggle and one topic per row rather than a single block holding
 * every answer, and the three buying steps as their own cards.
 */
class ProductAccordionTest extends TestCase
{
    use RefreshDatabase;

    private function page(array $extra = []): string
    {
        $category = Category::create(['name' => 'Gift Cards', 'slug' => 'gift-cards', 'type' => 'digital']);

        $product = Product::create(array_merge([
            'category_id' => $category->id,
            'provider_name' => 'zendit',
            'brand_key' => 'Acme',
            'country_code' => 'US',
            'currency_code' => 'USD',
            'name' => 'Acme Gift Card',
            'slug' => 'acme-gift-card',
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

        return $this->withoutVite()->get(route('shop.brand', ['brandSlug' => 'acme']))->assertOk()->getContent();
    }

    /**
     * A gift card answers at least five questions. The supplier sends none of
     * these, so a brand page is never left with one or two.
     */
    public function test_a_gift_card_answers_at_least_five_questions(): void
    {
        $html = $this->page();

        preg_match_all('/<summary[^>]*>\s*([^<]{6,95}?)\s*<span/', $html, $rows);
        $questions = array_filter($rows[1], fn (string $row) => str_contains($row, '?'));

        $this->assertGreaterThanOrEqual(5, count($questions), 'A gift card should answer at least five questions.');

        foreach ([
            'How will I receive my code?',
            'How can I pay for it?',
            'Does the code expire?',
        ] as $question) {
            $this->assertStringContainsString(e($question), $html);
        }

        // The expiry answer names the brand, because the brand sets it.
        $this->assertStringContainsString('Any expiry is set by Acme, not by us', $html);
    }

    /**
     * Suppliers send redemption steps for some brands and nothing for others.
     * A gift card without them falls back to the steps that are true of every
     * gift card, so the first question a buyer has is always answered.
     */
    public function test_a_gift_card_with_no_supplier_steps_still_says_how_to_redeem(): void
    {
        $html = $this->page();

        $this->assertStringContainsString('How to redeem', $html);
        $this->assertStringContainsString('Open the email we send you', $html);
        $this->assertStringContainsString('Sign in at Acme', $html);
    }

    /** A brand whose supplier does send steps shows those, not the fallback. */
    public function test_supplier_steps_win_over_the_fallback(): void
    {
        $html = $this->page(['redeem_instructions' => '<p>Go to acme.example/redeem and enter the code.</p>']);

        $this->assertStringContainsString('Go to acme.example/redeem and enter the code.', $html);
        $this->assertStringNotContainsString('Open the email we send you', $html);
    }

    public function test_each_question_is_its_own_row(): void
    {
        $html = $this->page();

        $this->assertStringContainsString('Frequently asked questions', $html);

        foreach ([
            'How fast will I receive my gift card?',
            'Can I redeem this Acme gift card in my country?',
            "What happens if the code doesn't work?",
        ] as $question) {
            $this->assertStringContainsString(e($question), $html);
            // Each one is a summary of its own, not a paragraph inside one block.
            $this->assertMatchesRegularExpression(
                '/<summary[^>]*>\s*'.preg_quote(e($question), '/').'/',
                $html,
                'Not its own accordion row: '.$question
            );
        }

        // The old shape, one accordion holding three bolded paragraphs, is gone.
        $this->assertStringNotContainsString('<strong class="text-zinc-900">How fast', $html);
    }

    public function test_a_row_is_a_card_with_a_round_toggle_that_reads_plus_then_minus(): void
    {
        $html = $this->page();

        $this->assertStringContainsString('group pure-card rounded-[12px] border border-zinc-200 bg-white px-5', $html);
        $this->assertStringContainsString('flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-zinc-100', $html);

        // The upright stroke folds away when the row opens, so + becomes -.
        $this->assertStringContainsString('origin-center transition-transform duration-200 group-open:scale-y-0', $html);

        // The browser's own marker is hidden on both engines.
        $this->assertStringContainsString("marker:content-['']", $html);
        $this->assertStringContainsString('[&::-webkit-details-marker]:hidden', $html);
    }

    public function test_the_rows_are_spaced_and_the_redeem_row_opens_first(): void
    {
        $html = $this->page(['redeem_instructions' => 'Go to acme.example and enter the code.']);

        $this->assertStringContainsString('<section class="mt-6 space-y-3">', $html);
        $this->assertMatchesRegularExpression('/<details\s+open/', $html);
        $this->assertStringContainsString('Go to acme.example and enter the code.', $html);
    }

    /** How to buy is three numbered cards, not a bare numbered list. */
    public function test_the_buying_steps_are_three_numbered_cards(): void
    {
        $html = $this->page();

        $this->assertStringContainsString('How to buy Acme gift card', $html);
        $this->assertStringContainsString('<ol class="mt-4 grid gap-4 sm:grid-cols-3">', $html);
        $this->assertStringNotContainsString('mt-3 list-decimal space-y-2 pl-5', $html);

        // A heading and its own sentence per step, numbered 1 to 3.
        foreach (['Enter the amount', 'Pay how you like', 'That is it, ready to use'] as $title) {
            $this->assertStringContainsString($title, $html);
        }
        $this->assertStringContainsString('Pay securely with card, mobile money or crypto.', $html);
        $this->assertSame(3, substr_count($html, 'rounded-full bg-blue-600 text-sm font-bold text-white'));
    }

    /** The step headings go through the translator like the rest of the page. */
    public function test_the_step_headings_are_translated(): void
    {
        $this->page();

        $french = $this->withSession(['locale' => 'fr'])
            ->get(route('shop.brand', ['brandSlug' => 'acme']))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString(e(__('products.detail_step_title_amount', [], 'fr')), $french);
        $this->assertStringNotContainsString('Enter the amount', $french);
    }

    /** One component, so every row on the page is built the same way. */
    public function test_the_rows_come_from_one_component(): void
    {
        $source = file_get_contents(resource_path('views/shop/product.blade.php'));

        $this->assertStringNotContainsString('<details class="group"', $source);
        $this->assertGreaterThanOrEqual(2, substr_count($source, '<x-accordion'));
        $this->assertFileExists(resource_path('views/components/accordion.blade.php'));
    }
}

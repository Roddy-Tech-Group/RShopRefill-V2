<?php

namespace Tests\Feature\Localization;

use App\Models\Category;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Subcategory;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * The order page's own copy goes through the translator.
 *
 * The page a customer lands on after paying was written straight into the
 * blade, so a French buyer who checked out in French was greeted with
 * "Payment received" and "What happens next", and the delivered gift card
 * read "For Acme". The order number, codes and PINs are identifiers: they must
 * come through untouched whatever the language.
 */
class OrderPageTranslationTest extends TestCase
{
    use RefreshDatabase;

    private const CODE = 'ACME-CODE-1234-XYZ';

    private const PIN = 'PIN-5555-7777';

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
    }

    /**
     * One gift card line, built the way the other order page tests build
     * theirs. The snapshot names a gift card category so the redeem panel
     * renders its gift card steps. "Acme" has no configured logo, so the card
     * prints the "For :brand" line.
     */
    private function orderWithGiftCard(User $user, bool $completed): Order
    {
        $order = Order::create([
            'user_id' => $user->id,
            'order_number' => 'RSR-'.uniqid(),
            'settlement_currency' => 'USD',
            'display_currency' => 'USD',
            'subtotal_amount' => 25.00,
            'markup_amount' => 2.00,
            'total_amount' => 27.00,
            'payment_method' => 'wallet',
            'payment_status' => 'paid',
            'fulfillment_status' => $completed ? 'fulfilled' : 'processing',
            'order_status' => $completed ? 'completed' : 'processing',
            'placed_at' => now(),
            'metadata' => ['exchange_rate' => 1.0],
        ]);

        $category = Category::factory()->create();
        $subcategory = Subcategory::factory()->create(['category_id' => $category->id]);
        $product = Product::factory()->create(['category_id' => $category->id, 'brand_key' => 'Acme']);
        $variant = ProductVariant::factory()->create(['product_id' => $product->id]);

        OrderItem::create([
            'order_id' => $order->id,
            'product_id' => $product->id,
            'product_variant_id' => $variant->id,
            'category_id' => $category->id,
            'subcategory_id' => $subcategory->id,
            'provider_name' => 'test',
            'quantity' => 1,
            'display_currency' => 'USD',
            'display_amount' => 25.00,
            'provider_cost_usd' => 22.00,
            'markup_amount' => 2.00,
            'subtotal_amount' => 25.00,
            'product_snapshot' => ['brand_key' => 'Acme', 'name' => 'Acme Gift Card', 'country_code' => 'US', 'category' => ['slug' => 'gift-cards']],
            'variant_snapshot' => ['face_value' => 25, 'currency' => 'USD'],
            'fulfillment_payload' => $completed ? ['code' => self::CODE, 'pin' => self::PIN] : null,
            'fulfillment_status' => $completed ? 'fulfilled' : 'processing',
        ]);

        return $order;
    }

    /**
     * The owner's view of their order in French. SetLocale reads the session
     * on every request (the owner has no saved locale), so the session is what
     * a real language switch writes.
     */
    private function french(User $user, Order $order): string
    {
        return $this->withSession(['locale' => 'fr'])
            ->actingAs($user)
            ->get(route('shop.order', $order->order_number))
            ->assertOk()
            ->getContent();
    }

    private function english(User $user, Order $order): string
    {
        return $this->actingAs($user)
            ->get(route('shop.order', $order->order_number))
            ->assertOk()
            ->getContent();
    }

    /** Blade escapes what it prints, so compare against the escaped form. */
    private function fr(string $key, array $replace = []): string
    {
        return e(__($key, $replace, 'fr'));
    }

    // ------------------------------------ completed order and delivered card

    public function test_a_completed_order_renders_french_copy(): void
    {
        $user = User::factory()->create();
        $html = $this->french($user, $this->orderWithGiftCard($user, completed: true));

        $this->assertStringContainsString($this->fr('orders.success_heading'), $html);
        $this->assertStringContainsString($this->fr('orders.success_thanks'), $html);
        $this->assertStringContainsString($this->fr('orders.download_csv'), $html);
        $this->assertStringContainsString($this->fr('orders.card_for_brand', ['brand' => 'Acme']), $html);
        $this->assertStringContainsString($this->fr('orders.redeem_instructions'), $html);
        $this->assertStringContainsString($this->fr('orders.redeem_gift_cards_step_3'), $html);
        $this->assertStringContainsString($this->fr('orders.view_all_orders'), $html);

        // The credential label and its copy button's accessible name.
        $this->assertStringContainsString('aria-label="'.$this->fr('orders.copy_credential', ['label' => __('orders.credential_pin', [], 'fr')]).'"', $html);

        // The terms sentence keeps its link: the translator carries a
        // placeholder, the page supplies the markup.
        $link = '<a href="'.route('shop.terms').'" wire:navigate class="text-blue-700 underline-offset-2 hover:underline dark:text-blue-300">'
            .$this->fr('orders.terms_full_legal_link').'</a>';
        $this->assertStringContainsString(__('orders.terms_full_legal', ['link' => $link], 'fr'), $html);
    }

    public function test_a_completed_order_drops_its_english_copy_in_french(): void
    {
        $user = User::factory()->create();
        $html = $this->french($user, $this->orderWithGiftCard($user, completed: true));

        $this->assertStringNotContainsString('Order completed', $html);
        $this->assertStringNotContainsString('Download as CSV', $html);
        $this->assertStringNotContainsString('>For Acme</p>', $html);
        $this->assertStringNotContainsString('aria-label="Copy Pin"', $html);
        $this->assertStringNotContainsString('Redeem instructions', $html);
        $this->assertStringNotContainsString('View all my orders', $html);
        $this->assertStringNotContainsString('For the full legal terms see our', $html);
        // Blade printed the apostrophe as &#039; before the move, and still
        // would now if the English line leaked through.
        $this->assertStringNotContainsString('Visit the brand&#039;s redemption page', $html);
        $this->assertStringNotContainsString("Visit the brand's redemption page", $html);
    }

    /** The English source reads exactly as it did before extraction. */
    public function test_a_completed_order_still_reads_in_english_for_an_english_visitor(): void
    {
        $user = User::factory()->create();
        $html = $this->english($user, $this->orderWithGiftCard($user, completed: true));

        $this->assertStringContainsString('Order completed', $html);
        $this->assertStringContainsString('Thank you for your purchase', $html);
        $this->assertStringContainsString('Download as CSV', $html);
        $this->assertStringContainsString('>For Acme</p>', $html);
        $this->assertStringContainsString('aria-label="Copy Pin"', $html);
        $this->assertStringContainsString('View all my orders', $html);
        $this->assertStringContainsString('Codes are equivalent to cash.', $html);
        $this->assertStringContainsString('For the full legal terms see our <a href="'.route('shop.terms').'"', $html);
        $this->assertStringContainsString(e("Visit the brand's redemption page (e.g. apple.com/redeem for Apple, amazon.com/gc for Amazon)."), $html);
    }

    /**
     * Resellers screenshot this card for their own customers. The words
     * around the code change language; the code and PIN never do.
     */
    public function test_codes_and_pins_are_shown_verbatim_in_french(): void
    {
        $user = User::factory()->create();
        $order = $this->orderWithGiftCard($user, completed: true);
        $html = $this->french($user, $order);

        $this->assertStringContainsString('>'.self::CODE.'</span>', $html);
        $this->assertStringContainsString('>'.self::PIN.'</span>', $html);
        $this->assertStringContainsString("writeText('".self::CODE."')", $html);
        $this->assertStringContainsString($order->order_number, $html);

        // The label beside each value is the translated one.
        $this->assertStringContainsString('>'.$this->fr('orders.credential_pin').'</span>', $html);
    }

    // ------------------------------------------ pending order, status view

    public function test_a_pending_order_renders_french_copy(): void
    {
        $user = User::factory()->create();
        $order = $this->orderWithGiftCard($user, completed: false);
        $html = $this->french($user, $order);

        $this->assertStringContainsString($this->fr('orders.hero_processing_title'), $html);
        $this->assertStringContainsString($this->fr('orders.hero_processing_line'), $html);
        $this->assertStringContainsString($this->fr('checkout.order_summary'), $html);
        $this->assertStringContainsString($this->fr('orders.status_processing'), $html);
        $this->assertStringContainsString($this->fr('orders.date_placed'), $html);
        $this->assertStringContainsString($this->fr('orders.line_qty', ['qty' => 1]), $html);
        $this->assertStringContainsString($this->fr('orders.pending_code'), $html);
        $this->assertStringContainsString($this->fr('orders.total_paid'), $html);
        $this->assertStringContainsString($this->fr('orders.next_heading'), $html);
        $this->assertStringContainsString($this->fr('orders.continue_shopping'), $html);

        // French copy uses the typographic apostrophe, which Blade leaves as
        // it is rather than turning into &#039;.
        $this->assertStringContainsString(__('orders.next_confirm_body', [], 'fr'), $html);
        $this->assertStringContainsString('’', __('orders.next_confirm_body', [], 'fr'));

        // The delivery address sits inside markup the page owns.
        $this->assertStringContainsString(
            __('orders.confirmation_sent_to', ['email' => '<span class="font-semibold text-zinc-900">'.e($user->email).'</span>'], 'fr'),
            $html
        );

        // The order number is shown verbatim inside the translated sentence.
        $this->assertStringContainsString($this->fr('orders.need_help', ['reference' => $order->order_number]), $html);
        $this->assertStringContainsString('tabular-nums text-zinc-900">'.$order->order_number.'</span>', $html);
    }

    public function test_a_pending_order_drops_its_english_copy_in_french(): void
    {
        $user = User::factory()->create();
        $html = $this->french($user, $this->orderWithGiftCard($user, completed: false));

        $this->assertStringNotContainsString('We are fulfilling your order.', $html);
        $this->assertStringNotContainsString('What happens next', $html);
        $this->assertStringNotContainsString('Date placed', $html);
        $this->assertStringNotContainsString('Code emailed once payment clears', $html);
        $this->assertStringNotContainsString('Total paid', $html);
        $this->assertStringNotContainsString('A confirmation has been sent to', $html);
        $this->assertStringNotContainsString('Need help with this order?', $html);
        $this->assertStringNotContainsString('We verify your payment with the provider.', $html);
    }

    public function test_a_pending_order_still_reads_in_english_for_an_english_visitor(): void
    {
        $user = User::factory()->create();
        $order = $this->orderWithGiftCard($user, completed: false);
        $html = $this->english($user, $order);

        $this->assertStringContainsString('Payment received', $html);
        $this->assertStringContainsString('We are fulfilling your order. Codes land in your email the moment each item is ready.', $html);
        $this->assertStringContainsString('Order summary', $html);
        $this->assertStringContainsString('Processing', $html);
        $this->assertStringContainsString('Qty 1 &middot; ', $html);
        $this->assertStringContainsString('Code emailed once payment clears', $html);
        $this->assertStringContainsString('Total paid', $html);
        $this->assertStringContainsString('What happens next', $html);
        $this->assertStringContainsString('Codes arrive at '.e($user->email).' and stay available on this page.', $html);
        $this->assertStringContainsString('A confirmation has been sent to <span class="font-semibold text-zinc-900">'.e($user->email).'</span>', $html);
        $this->assertStringContainsString('Continue shopping', $html);
        $this->assertStringContainsString('Need help with this order? Contact support with reference '.$order->order_number.'.', $html);
    }

    // ---------------------------------------- script copy and the lang files

    /**
     * The payment panel's messages are assigned in Alpine, inside a
     * double-quoted attribute. A raw translation there breaks the script on
     * the first quote or apostrophe, so each one goes through @js.
     */
    public function test_the_payment_panel_passes_its_script_messages_through_js(): void
    {
        $blade = File::get(resource_path('views/shop/order.blade.php'));

        foreach ([
            'pay_momo_authorize', 'pay_otp_prompt', 'pay_error_server', 'pay_error_initiation',
            'pay_error_network', 'pay_error_incomplete', 'pay_otp_sent', 'pay_momo_accept_prompt',
            'pay_error_failed', 'pay_copied_alert', 'pay_crypto_network',
        ] as $key) {
            $this->assertStringContainsString("@js(__('orders.{$key}'))", $blade);
        }

        foreach ([
            "'Copied to clipboard!'",
            "'Transaction failed.'",
            "'Server error ('",
            "'Network: '",
        ] as $literal) {
            $this->assertStringNotContainsString($literal, $blade);
        }
    }

    /** Every key the English orders file defines exists in French, and back. */
    public function test_the_english_and_french_order_files_define_the_same_keys(): void
    {
        $en = require lang_path('en/orders.php');
        $fr = require lang_path('fr/orders.php');

        $this->assertSame([], array_keys(array_diff_key($en, $fr)), 'French is missing orders keys');
        $this->assertSame([], array_keys(array_diff_key($fr, $en)), 'French has orders keys English does not');
    }
}

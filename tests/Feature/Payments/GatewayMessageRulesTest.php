<?php

namespace Tests\Feature\Payments;

use App\Domain\Order\Services\CheckoutService;
use App\Domain\Payment\Support\CustomerFailureCopy;
use App\Domain\PaymentInsights\Enums\FailureCategory;
use App\Domain\PaymentInsights\Enums\OutcomeType;
use App\Domain\PaymentInsights\Jobs\RecordPaymentOutcomeJob;
use App\Domain\PaymentInsights\Services\FailureReasonClassifier;
use App\Domain\PaymentInsights\Support\DeclineVerdict;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Category;
use App\Models\PaymentOutcome;
use App\Models\PaymentSession;
use App\Models\PricingRule;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Subcategory;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Gateway words that production met before any rule did.
 *
 * Every phrase here is copied from the payment outcomes production recorded
 * as "unrecognised" up to 2026-10-03. Each one used to be filed under "add a
 * rule" and, on the card rails, told the customer their bank had declined the
 * payment, which for half of them was false: two were our own integration and
 * two were the gateway. Each now has a verdict, and a phrase nobody has
 * written a rule for yet no longer blames the bank.
 */
class GatewayMessageRulesTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<string, array{0: string, 1: FailureCategory, 2: string, 3: bool, 4: string}>
     */
    public static function productionPhrases(): array
    {
        return [
            'card not enrolled for 3-D Secure' => [
                'Sorry, your card is not enrolled for 3-D Secure Payments',
                FailureCategory::Customer, 'card_not_enrolled', false, 'card_not_enrolled',
            ],
            'currency not supported by the hosted checkout' => [
                'The currency for this payment is currently not supported',
                FailureCategory::System, 'gateway_config_error', false, 'gateway_config_error',
            ],
            'payment option under maintenance' => [
                'Payment option currently undergoing maintenance updates. Please try another',
                FailureCategory::Provider, 'provider_maintenance', true, 'provider_error',
            ],
            'issuer policy error' => [
                'Policy error, please contact your bank',
                FailureCategory::Customer, 'bank_declined', true, 'bank_declined',
            ],
            'gateway busy' => [
                'We are currently unable to process your request. Please try again after some time',
                FailureCategory::Provider, 'provider_error', true, 'provider_error',
            ],
            'charge sent without a phone number' => [
                'phonenumber is required',
                FailureCategory::System, 'gateway_config_error', false, 'gateway_config_error',
            ],
            // Flutterwave's USD card route, 2026-10-06 to 09: the processor
            // refusing the gateway's own credentials. Ours or the gateway's,
            // never the card; no retry can change it.
            'processor rejecting the gateway credentials' => [
                'Invalid credentials.',
                FailureCategory::System, 'gateway_config_error', false, 'gateway_config_error',
            ],
        ];
    }

    #[DataProvider('productionPhrases')]
    public function test_every_phrase_production_met_has_a_verdict(string $words, FailureCategory $category, string $code, bool $retryable, string $customerCode): void
    {
        $verdict = app(FailureReasonClassifier::class)->classify(
            'flutterwave',
            'Payment failed',
            'awaiting_payment',
            ['provider_code' => $words, 'provider_message' => $words, 'http_status' => null],
        );

        $this->assertSame($code, $verdict['code']);
        $this->assertSame($category, $verdict['category']);
        $this->assertSame($retryable, $verdict['retryable']);
        $this->assertNotSame('unrecognised_gateway_message', $verdict['code']);

        // And the customer is told the sentence that matches, not a guess.
        $this->assertSame(
            __('checkout.failure_reason_'.$customerCode),
            CustomerFailureCopy::forVerdict($verdict, mobileMoney: false),
        );
    }

    #[DataProvider('productionPhrases')]
    public function test_every_code_the_rules_produce_has_a_dashboard_label(string $words, FailureCategory $category, string $code): void
    {
        $this->assertArrayHasKey($code, FailureReasonClassifier::LABELS);
    }

    /**
     * THE one. A card refusal in words no rule knows yet says the payment did
     * not go through. It used to say the bank declined it.
     */
    public function test_an_unrecognised_card_refusal_never_blames_the_bank(): void
    {
        $verdict = app(FailureReasonClassifier::class)->classify(
            'flutterwave',
            'Payment failed',
            'awaiting_payment',
            ['provider_code' => 'ZZ-91', 'provider_message' => 'Something the gateway has never said before', 'http_status' => null],
        );

        $this->assertSame('unrecognised_gateway_message', $verdict['code']);

        $sentence = CustomerFailureCopy::forVerdict($verdict, mobileMoney: false);

        $this->assertSame(__('checkout.failure_reason_payment_not_completed'), $sentence);
        $this->assertNotSame(__('checkout.failure_reason_bank_declined'), $sentence);
        $this->assertStringNotContainsStringIgnoringCase('bank', $sentence);
    }

    /**
     * The new rules are tied to their exact wording. A mobile money "customer
     * not enrolled" is not a card without 3-D Secure, and "this card type is
     * currently not supported" is not our currency configuration.
     */
    public function test_the_new_rules_do_not_catch_neighbouring_phrases(): void
    {
        $classify = fn (string $words) => app(FailureReasonClassifier::class)->classify(
            'flutterwave',
            'Payment failed',
            'awaiting_confirmation',
            ['provider_code' => $words, 'provider_message' => $words, 'http_status' => null],
        )['code'];

        $this->assertNotSame('card_not_enrolled', $classify('Customer not enrolled for mobile money'));
        $this->assertNotSame('gateway_config_error', $classify('This card type is currently not supported'));
    }

    /** The help panel under a failed payment gives the 3-D Secure customer card tips. */
    public function test_the_failure_help_panel_files_the_new_code_with_the_card_tips(): void
    {
        $source = file_get_contents(resource_path('views/shop/_payment-failure-help.blade.php'));

        $this->assertMatchesRegularExpression("/\\[[^\\]]*'card_not_enrolled'[^\\]]*\\]\\.includes\\(code\\)\\) return momo \\? 'mobile_money' : 'card'/", $source);
    }

    /**
     * The seventh phrase, found after the first reclassify in production: the
     * operator's one underscored code for low balance, limit reached or not
     * allowed. Two mobile money customers had been told their bank declined.
     */
    public function test_the_operators_low_balance_or_limit_code_is_a_mobile_money_refusal(): void
    {
        $words = 'Transaction Failed Reason: LOW_BALANCE_OR_PAYEE_LIMIT_REACHED_OR_NOT_ALLOWED';

        $verdict = app(FailureReasonClassifier::class)->classify(
            'flutterwave',
            'Your bank declined this payment. Please check with your bank or try another card.',
            'awaiting_confirmation',
            ['provider_code' => $words, 'provider_message' => $words, 'http_status' => null],
        );

        $this->assertSame('mobile_money_refused', $verdict['code']);
        $this->assertSame(FailureCategory::Customer, $verdict['category']);
        $this->assertTrue($verdict['retryable']);
        $this->assertArrayHasKey('mobile_money_refused', FailureReasonClassifier::LABELS);

        $sentence = CustomerFailureCopy::forVerdict($verdict, mobileMoney: true);
        $this->assertSame(__('checkout.failure_reason_mobile_money_unknown'), $sentence);
        $this->assertStringNotContainsStringIgnoringCase('bank', $sentence);
    }

    /** Mobile money keeps its own advice: balance and limits are the usual cause there. */
    public function test_an_unrecognised_mobile_money_refusal_keeps_its_own_sentence(): void
    {
        $this->assertSame(
            __('checkout.failure_reason_mobile_money_unknown'),
            CustomerFailureCopy::forVerdict(['code' => 'unrecognised_gateway_message'], mobileMoney: true),
        );
    }

    public function test_the_new_sentences_exist_in_every_language(): void
    {
        foreach (['en', 'fr', 'de', 'es', 'it', 'pt', 'ar', 'ha', 'sw', 'zh'] as $locale) {
            foreach (['card_not_enrolled', 'payment_not_completed'] as $code) {
                $key = 'checkout.failure_reason_'.$code;

                $this->assertNotSame($key, __($key, [], $locale), "{$locale} is missing {$code}");
            }
        }
    }

    /**
     * A bank that never switched on online verification, and a rail down for
     * maintenance, say nothing about who holds the card. Neither may count
     * toward the card-testing lock, whatever words the gateway used.
     */
    public function test_the_new_codes_never_count_toward_the_card_testing_lock(): void
    {
        $this->assertSame(DeclineVerdict::CLASS_IGNORED, DeclineVerdict::classify('card_not_enrolled', ''));
        $this->assertSame(DeclineVerdict::CLASS_IGNORED, DeclineVerdict::classify('provider_maintenance', ''));
    }

    // ------------------------------------------------- successful payments

    /**
     * A payment that went through has no failure to describe. Every successful
     * payment used to be stamped 'unknown_error', which is why production
     * showed 71 wallet "failures" with no diagnostic.
     */
    public function test_a_successful_payment_is_recorded_without_a_failure_verdict(): void
    {
        Queue::fake();
        $session = $this->sessionForANewOrder();

        (new RecordPaymentOutcomeJob($session->id, OutcomeType::Confirmed))->handle(app(FailureReasonClassifier::class));

        $outcome = PaymentOutcome::where('payment_session_id', $session->id)->sole();

        $this->assertSame(OutcomeType::Confirmed, $outcome->outcome);
        $this->assertNull($outcome->failure_reason_code);
        $this->assertNull($outcome->failure_category);
        $this->assertNull($outcome->failure_stage);
        $this->assertNull($outcome->retryable);
    }

    public function test_a_failed_payment_is_still_graded(): void
    {
        Queue::fake();
        $session = $this->sessionForANewOrder();

        (new RecordPaymentOutcomeJob($session->id, OutcomeType::Failed, 'Insufficient Funds'))->handle(app(FailureReasonClassifier::class));

        $this->assertSame('insufficient_funds', PaymentOutcome::where('payment_session_id', $session->id)->sole()->failure_reason_code);
    }

    /**
     * The history: reclassifying regrades the unrecognised phrases with the
     * new rules and empties the failure fields successful payments carried.
     */
    public function test_reclassifying_corrects_history_both_ways(): void
    {
        $failedSession = $this->sessionForANewOrder();
        $paidSession = $this->sessionForANewOrder(quantity: 2);

        $failed = PaymentOutcome::create([
            'payment_session_id' => $failedSession->id,
            'product_line' => 'checkout',
            'outcome' => 'failed',
            'provider' => 'flutterwave',
            'failure_stage' => 'processing',
            'failure_category' => 'system',
            'failure_reason_code' => 'unrecognised_gateway_message',
            'retryable' => true,
            'metadata' => [
                'raw_reason' => 'Your bank declined this payment. Please check with your bank or try another card.',
                'failure_details' => [
                    'provider_code' => 'The currency for this payment is currently not supported',
                    'provider_message' => 'The currency for this payment is currently not supported',
                    'http_status' => null,
                ],
            ],
        ]);

        $paid = PaymentOutcome::create([
            'payment_session_id' => $paidSession->id,
            'product_line' => 'checkout',
            'outcome' => 'confirmed',
            'provider' => 'wallet',
            'failure_stage' => 'processing',
            'failure_category' => 'system',
            'failure_reason_code' => 'unknown_error',
            'retryable' => true,
            'metadata' => ['raw_reason' => 'unknown'],
        ]);

        $this->artisan('insights:reclassify')->assertSuccessful();

        $failed->refresh();
        $this->assertSame('gateway_config_error', $failed->failure_reason_code);
        $this->assertSame(FailureCategory::System, $failed->failure_category);
        $this->assertFalse($failed->retryable);

        $paid->refresh();
        $this->assertNull($paid->failure_reason_code);
        $this->assertNull($paid->failure_category);
        $this->assertNull($paid->failure_stage);
        $this->assertNull($paid->retryable);
    }

    public function test_a_dry_run_changes_nothing(): void
    {
        $paidSession = $this->sessionForANewOrder();

        $paid = PaymentOutcome::create([
            'payment_session_id' => $paidSession->id,
            'product_line' => 'checkout',
            'outcome' => 'confirmed',
            'provider' => 'wallet',
            'failure_reason_code' => 'unknown_error',
            'failure_category' => 'system',
        ]);

        $this->artisan('insights:reclassify', ['--dry-run' => true])->assertSuccessful();

        $this->assertSame('unknown_error', $paid->fresh()->failure_reason_code);
    }

    // ---------------------------------------------------------------- fixture

    private function sessionForANewOrder(int $quantity = 1): PaymentSession
    {
        config([
            'services.zendit.api_key' => 'ZENDIT_API_KEY_MOCK',
            'services.flutterwave.secret_key' => 'FLW_SECRET_KEY_MOCK',
            'pricing.safety_markup_percent' => 10.0,
            'pricing.min_margin_percent' => 1.0,
            'pricing.floor_mode' => 'legacy',
        ]);
        Cache::flush();

        $user = User::factory()->create(['email_verified_at' => now()]);

        $category = Category::firstOrCreate(['slug' => 'gift-cards'], ['name' => 'Gift Cards', 'type' => 'digital']);
        $subcategory = Subcategory::firstOrCreate(['slug' => 'gaming'], ['category_id' => $category->id, 'name' => 'Gaming']);
        $product = Product::firstOrCreate(['slug' => 'steam-gift-card'], [
            'category_id' => $category->id,
            'subcategory_id' => $subcategory->id,
            'provider_name' => 'zendit',
            'country_code' => 'US',
            'currency_code' => 'USD',
            'name' => 'Steam Gift Card',
        ]);
        $variant = ProductVariant::firstOrCreate(['provider_offer_id' => 'steam-offer-10'], [
            'product_id' => $product->id,
            'sku' => 'STEAM10',
            'currency' => 'USD',
            'face_value' => 10.00,
            'cost_price' => 9.50,
            'retail_price' => 10.50,
            'is_available' => true,
        ]);

        if (PricingRule::count() === 0) {
            PricingRule::create(['markup_type' => 'fixed', 'markup_value' => 1.00, 'is_active' => true]);
        }

        $cart = Cart::create(['user_id' => $user->id, 'status' => 'active', 'last_activity_at' => now()]);
        CartItem::create([
            'cart_id' => $cart->id,
            'product_id' => $product->id,
            'product_variant_id' => $variant->id,
            'quantity' => $quantity,
            'display_currency' => 'USD',
            'display_amount' => 11.00 * $quantity,
            'provider_cost_usd' => 9.50,
            'markup_amount' => 1.00,
            'unit_price_snapshot' => 11.00,
            'subtotal_snapshot' => 11.00 * $quantity,
        ]);

        $order = app(CheckoutService::class)->placeOrder(
            user: $user,
            cart: $cart->load('items.product', 'items.variant'),
            paymentMethod: 'flutterwave',
            displayCurrency: 'USD',
        );

        return $order->paymentAttempts->first()->paymentSession;
    }
}

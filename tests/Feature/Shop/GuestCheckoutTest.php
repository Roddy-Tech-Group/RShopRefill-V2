<?php

namespace Tests\Feature\Shop;

use App\Domain\Communication\Mail\Orders\GuestAccountClaimMail;
use App\Domain\Communication\Providers\MailProviderInterface;
use App\Domain\Order\Events\FulfillmentSucceeded;
use App\Models\Category;
use App\Models\CommunicationLog;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Subcategory;
use App\Models\User;
use Illuminate\Contracts\Mail\Mailable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

/**
 * Guest purchase is REMOVED: checkout is signed-in-only and no shadow
 * account is ever minted. What survives is the legacy tail: shadow accounts
 * that already exist from the old flow keep their claim path (the signed
 * link that sets a password + transaction PIN and converts the account), and
 * any still-fulfilling legacy guest order still triggers its claim email.
 */
class GuestCheckoutTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.flutterwave.secret_key' => 'FLW_SECRET_KEY_MOCK',
            'services.nowpayments.api_key' => 'NOWPAYMENTS_KEY_MOCK',
        ]);

        // The notification pipeline must never hit the real mail provider.
        $this->app->instance(MailProviderInterface::class, new class implements MailProviderInterface
        {
            public function send(string $to, string $from, string $replyTo, string $subject, Mailable $mailable, array $metadata = []): array
            {
                return ['id' => 'spy'];
            }
        });
    }

    // ── Guest purchase is refused outright ──────────────────────

    public function test_an_unauthenticated_checkout_is_refused_and_mints_no_shadow_account(): void
    {
        $response = $this->post(route('checkout.process'), [
            'delivery_email' => 'guest@example.com',
            'payment_method' => 'card',
            'currency' => 'USD',
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('checkout_status', 'Please sign in to complete checkout.');
        $this->assertDatabaseMissing('users', ['email' => 'guest@example.com']);
    }

    public function test_an_unauthenticated_json_checkout_gets_a_401(): void
    {
        $this->postJson(route('checkout.process'), [
            'delivery_email' => 'guest@example.com',
            'payment_method' => 'card',
            'currency' => 'USD',
        ])->assertUnauthorized();

        $this->assertDatabaseMissing('users', ['email' => 'guest@example.com']);
    }

    public function test_an_unauthenticated_visitor_cannot_view_any_order_page(): void
    {
        $owner = User::factory()->create();
        $item = $this->makeFulfilledItem($owner);

        $this->get(route('shop.order', $item->order->order_number))->assertNotFound();
    }

    // ── Claim email on fulfillment (legacy guest orders) ────────

    public function test_fulfillment_sends_the_claim_email_to_guests_once(): void
    {
        $guest = User::factory()->create(['email' => 'guest@example.com', 'is_guest' => true]);
        $item = $this->makeFulfilledItem($guest);

        event(new FulfillmentSucceeded($item));
        event(new FulfillmentSucceeded($item)); // second item of the same order

        $this->assertSame(
            1,
            CommunicationLog::where('user_id', $guest->id)
                ->where('template_key', GuestAccountClaimMail::class)
                ->where('channel', 'email')
                ->count()
        );
        $this->assertNotNull($guest->refresh()->guest_claim_last_sent_at);
    }

    public function test_fulfillment_sends_no_claim_email_to_registered_users(): void
    {
        $user = User::factory()->create();
        $item = $this->makeFulfilledItem($user);

        event(new FulfillmentSucceeded($item));

        $this->assertSame(
            0,
            CommunicationLog::where('template_key', GuestAccountClaimMail::class)->count()
        );
    }

    // ── Claiming the account ────────────────────────────────────

    public function test_guest_can_claim_their_account_with_password_and_pin(): void
    {
        $guest = User::factory()->create(['email' => 'guest@example.com', 'is_guest' => true]);

        $showUrl = URL::temporarySignedRoute('account.claim', now()->addDays(7), ['user' => $guest->id]);
        $this->withoutVite()->get($showUrl)->assertOk()->assertSee('Claim your account');

        $storeUrl = URL::temporarySignedRoute('account.claim.store', now()->addHour(), ['user' => $guest->id]);
        $response = $this->post($storeUrl, [
            'password' => 'SuperSecret123!',
            'password_confirmation' => 'SuperSecret123!',
            'pin' => '8461',
            'pin_confirmation' => '8461',
        ]);

        $response->assertRedirect(route('dashboard.orders'));
        $this->assertAuthenticatedAs($guest->fresh());

        $guest->refresh();
        $this->assertFalse($guest->is_guest);
        $this->assertNotNull($guest->email_verified_at);
        $this->assertTrue(Hash::check('SuperSecret123!', $guest->password));
        $this->assertTrue($guest->hasTransactionPin());
    }

    public function test_claim_link_requires_a_valid_signature(): void
    {
        $guest = User::factory()->create(['is_guest' => true]);

        $this->get(route('account.claim', ['user' => $guest->id]))->assertForbidden();
    }

    public function test_already_claimed_accounts_are_bounced_to_sign_in(): void
    {
        $user = User::factory()->create(['is_guest' => false]);

        $showUrl = URL::temporarySignedRoute('account.claim', now()->addDays(7), ['user' => $user->id]);
        $this->get($showUrl)->assertRedirect(route('home'));
    }

    /** Minimal fulfilled order item for firing FulfillmentSucceeded. */
    private function makeFulfilledItem(User $buyer): OrderItem
    {
        $order = Order::create([
            'user_id' => $buyer->id,
            'order_number' => 'RSR-'.uniqid(),
            'settlement_currency' => 'USD',
            'display_currency' => 'USD',
            'subtotal_amount' => 10.00,
            'markup_amount' => 2.00,
            'total_amount' => 12.00,
            'payment_method' => 'card',
            'payment_status' => 'paid',
            'fulfillment_status' => 'fulfilled',
            'order_status' => 'completed',
            'placed_at' => now(),
            'metadata' => ['exchange_rate' => 1.0, 'delivery_email' => $buyer->email],
        ]);

        $category = Category::factory()->create();
        $subcategory = Subcategory::factory()->create(['category_id' => $category->id]);
        $product = Product::factory()->create(['category_id' => $category->id]);
        $variant = ProductVariant::factory()->create(['product_id' => $product->id]);

        return OrderItem::create([
            'order_id' => $order->id,
            'product_id' => $product->id,
            'product_variant_id' => $variant->id,
            'category_id' => $category->id,
            'subcategory_id' => $subcategory->id,
            'provider_name' => 'test',
            'quantity' => 1,
            'display_currency' => 'USD',
            'display_amount' => 10.00,
            'provider_cost_usd' => 8.00,
            'markup_amount' => 2.00,
            'subtotal_amount' => 10.00,
            'product_snapshot' => ['name' => 'Test gift card'],
            'fulfillment_status' => 'fulfilled',
        ]);
    }
}

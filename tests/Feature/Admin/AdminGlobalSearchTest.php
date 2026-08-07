<?php

namespace Tests\Feature\Admin;

use App\Domain\Admin\Enums\AdminRole;
use App\Models\Admin;
use App\Models\Category;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\PaymentAttempt;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Subcategory;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The header command bar searches the whole back office, not just customers and
 * products: orders, eSIMs by ICCID, transactions by any gateway reference,
 * customers by their numeric ID, and the admin pages themselves.
 *
 * The permission tests matter most - search must never surface a record the
 * signed-in admin is blocked from opening, or the bar leaks the contents of
 * pages the role was deliberately denied.
 */
class AdminGlobalSearchTest extends TestCase
{
    use RefreshDatabase;

    private function admin(AdminRole $role = AdminRole::SuperAdmin, ?array $permissions = null): Admin
    {
        return Admin::create([
            'name' => 'Test Admin',
            'email' => 'admin'.Str::random(6).'@example.test',
            'password' => 'password',
            'role' => $role,
            'permissions' => $permissions,
            'is_active' => true,
        ]);
    }

    /**
     * Build an eSIM order item carrying the given fulfillment payload. The
     * catalog factories do not populate every non-nullable column, so the
     * product and variant are created explicitly rather than left to defaults.
     *
     * @param  array<string, mixed>  $payload
     */
    private function esimOrder(array $payload): Order
    {
        $category = Category::factory()->create();
        $subcategory = Subcategory::factory()->create(['category_id' => $category->id]);
        $product = Product::factory()->create(['category_id' => $category->id]);
        $variant = ProductVariant::factory()->create(['product_id' => $product->id]);

        $order = Order::factory()->create();

        OrderItem::factory()->create([
            'order_id' => $order->id,
            'product_id' => $product->id,
            'product_variant_id' => $variant->id,
            'category_id' => $category->id,
            'subcategory_id' => $subcategory->id,
            'provider_name' => 'airalo',
            'fulfillment_payload' => $payload,
        ]);

        return $order;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function search(Admin $admin, string $query): array
    {
        return $this->actingAs($admin, 'admin')
            ->getJson(route('admin.search-suggest', ['q' => $query]))
            ->assertOk()
            ->json();
    }

    // ────────────────────────────────────────────────────────────
    //  Transactions
    // ────────────────────────────────────────────────────────────

    public function test_search_finds_a_transaction_by_gateway_reference(): void
    {
        $user = User::factory()->create(['name' => 'Payer One']);
        $order = Order::factory()->for($user)->create();

        PaymentAttempt::create([
            'order_id' => $order->id,
            'user_id' => $user->id,
            'gateway' => 'flutterwave',
            'gateway_reference' => 'FLW-REF-99881',
            'idempotency_key' => 'idem-99881',
            'currency' => 'USD',
            'amount' => 25.00,
            'payment_status' => 'paid',
        ]);

        $rows = collect($this->search($this->admin(), 'FLW-REF-99881'));
        $hit = $rows->firstWhere('type', 'transaction');

        $this->assertNotNull($hit, 'Expected a transaction row for the gateway reference.');
        $this->assertSame('FLW-REF-99881', $hit['title']);
        $this->assertSame(route('admin.order', $order), $hit['url']);
    }

    public function test_transaction_search_matches_the_idempotency_key_and_crypto_references(): void
    {
        $user = User::factory()->create();

        PaymentAttempt::create([
            'user_id' => $user->id,
            'gateway' => 'nowpayments',
            'gateway_reference' => 'NP-12345',
            'idempotency_key' => 'idem-key-555',
            'purchase_id' => 'purchase-abc-777',
            'pay_address' => 'bc1qexampleaddress0001',
            'currency' => 'USD',
            'amount' => 10.00,
            'payment_status' => 'pending',
        ]);

        foreach (['idem-key-555', 'purchase-abc-777', 'bc1qexampleaddress0001'] as $needle) {
            $rows = collect($this->search($this->admin(), $needle));

            $this->assertNotNull(
                $rows->firstWhere('type', 'transaction'),
                "Expected a transaction row when searching {$needle}."
            );
        }
    }

    public function test_wallet_funding_attempt_falls_back_to_the_filtered_transactions_page(): void
    {
        // No order_id: a wallet top-up has no order page to land on, so the row
        // must point at the transactions list pre-filtered to its reference.
        PaymentAttempt::create([
            'user_id' => User::factory()->create()->id,
            'gateway' => 'flutterwave',
            'gateway_reference' => 'TOPUP-REF-4242',
            'idempotency_key' => 'idem-4242',
            'currency' => 'USD',
            'amount' => 50.00,
            'payment_status' => 'paid',
        ]);

        $hit = collect($this->search($this->admin(), 'TOPUP-REF-4242'))->firstWhere('type', 'transaction');

        $this->assertNotNull($hit);
        $this->assertSame(route('admin.transactions', ['q' => 'TOPUP-REF-4242']), $hit['url']);
    }

    // ────────────────────────────────────────────────────────────
    //  eSIM ICCID
    // ────────────────────────────────────────────────────────────

    /**
     * @return array<string, array{0: array<string, mixed>}>
     */
    public static function iccidPayloadProvider(): array
    {
        return [
            'flat iccid key' => [['iccid' => '8944500123456789012']],
            'nested esim object' => [['esim' => ['iccid' => '8944500123456789012']]],
            'zendit esim_iccid key' => [['esim_iccid' => '8944500123456789012']],
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    #[DataProvider('iccidPayloadProvider')]
    public function test_search_finds_an_esim_by_iccid_in_every_payload_shape(array $payload): void
    {
        $order = $this->esimOrder($payload);

        $hit = collect($this->search($this->admin(), '8944500123456789012'))->firstWhere('type', 'esim');

        $this->assertNotNull($hit, 'Expected an eSIM row for the ICCID.');
        $this->assertSame('8944500123456789012', $hit['title']);
        $this->assertSame(route('admin.order', $order), $hit['url']);
    }

    public function test_partial_iccid_matches(): void
    {
        $this->esimOrder(['iccid' => '8944500123456789012']);

        $hit = collect($this->search($this->admin(), '456789012'))->firstWhere('type', 'esim');

        $this->assertNotNull($hit, 'A trailing fragment of an ICCID should still find the eSIM.');
    }

    public function test_iccid_search_is_skipped_for_very_short_queries(): void
    {
        $this->esimOrder(['iccid' => '8944500123456789012']);

        // Two characters would match nearly every eSIM ever sold, so the branch
        // is deliberately skipped rather than returning noise.
        $rows = collect($this->search($this->admin(), '89'));

        $this->assertNull($rows->firstWhere('type', 'esim'));
    }

    // ────────────────────────────────────────────────────────────
    //  Customers
    // ────────────────────────────────────────────────────────────

    public function test_search_finds_a_customer_by_their_numeric_id(): void
    {
        $user = User::factory()->create(['name' => 'Numeric Lookup']);

        $rows = collect($this->search($this->admin(), (string) $user->id));
        $hit = $rows->firstWhere('type', 'customer');

        $this->assertNotNull($hit, 'A bare customer ID should resolve to that account.');
        $this->assertSame('Numeric Lookup', $hit['title']);
        $this->assertSame(route('admin.customer', $user), $hit['url']);
    }

    public function test_exact_customer_id_outranks_other_matches(): void
    {
        // Push the target past a single digit so the query is long enough to
        // fan out across every table - a one-character query short-circuits to
        // the ID branch alone and would not prove anything about ranking.
        User::factory()->count(11)->create();
        $target = User::factory()->create(['name' => 'Exact Target']);
        $this->assertGreaterThan(9, $target->id);

        // A second account whose phone merely contains the same digits.
        User::factory()->create(['name' => 'Noise Account', 'phone' => '+2376'.$target->id.'00000']);

        $rows = collect($this->search($this->admin(), (string) $target->id));

        $this->assertSame('Exact Target', $rows->first()['title']);
    }

    public function test_search_finds_a_customer_by_phone_and_referral_code(): void
    {
        User::factory()->create([
            'name' => 'Phone Person',
            'phone' => '+237670112233',
            'referral_code' => 'REFCODE77',
        ]);

        foreach (['670112233', 'REFCODE77'] as $needle) {
            $hit = collect($this->search($this->admin(), $needle))->firstWhere('type', 'customer');

            $this->assertNotNull($hit, "Expected a customer row when searching {$needle}.");
            $this->assertSame('Phone Person', $hit['title']);
        }
    }

    // ────────────────────────────────────────────────────────────
    //  Orders
    // ────────────────────────────────────────────────────────────

    public function test_search_finds_an_order_by_its_order_number(): void
    {
        $order = Order::factory()->create(['order_number' => 'ORD-SEARCHME1']);

        $hit = collect($this->search($this->admin(), 'SEARCHME1'))->firstWhere('type', 'order');

        $this->assertNotNull($hit);
        $this->assertSame('ORD-SEARCHME1', $hit['title']);
        $this->assertSame(route('admin.order', $order), $hit['url']);
    }

    // ────────────────────────────────────────────────────────────
    //  Page navigation
    // ────────────────────────────────────────────────────────────

    public function test_search_jumps_to_admin_pages(): void
    {
        $hit = collect($this->search($this->admin(), 'settings'))->firstWhere('type', 'page');

        $this->assertNotNull($hit, 'Typing a page name should offer that page.');
        $this->assertSame(route('admin.settings'), $hit['url']);
    }

    public function test_page_keywords_resolve_synonyms(): void
    {
        // "airtime" is nobody's page title - it only reaches Mobile Top-ups
        // through the keyword list.
        $rows = collect($this->search($this->admin(), 'airtime'))->where('type', 'page');

        $this->assertTrue(
            $rows->contains(fn (array $row) => str_contains($row['title'], 'Mobile Top-ups')),
            'Expected the keyword list to reach the Mobile Top-ups page.'
        );
    }

    // ────────────────────────────────────────────────────────────
    //  Permissions
    // ────────────────────────────────────────────────────────────

    public function test_an_admin_without_the_orders_section_gets_no_order_or_esim_rows(): void
    {
        Order::factory()->create(['order_number' => 'ORD-HIDDEN1']);
        $this->esimOrder(['iccid' => '8944500999888777666']);

        $restricted = $this->admin(AdminRole::Admin, ['customers']);

        $this->assertEmpty(collect($this->search($restricted, 'ORD-HIDDEN1'))->where('type', 'order'));
        $this->assertEmpty(collect($this->search($restricted, '8944500999888777666'))->where('type', 'esim'));
    }

    public function test_an_admin_without_the_transactions_section_gets_no_transaction_rows(): void
    {
        PaymentAttempt::create([
            'user_id' => User::factory()->create()->id,
            'gateway' => 'flutterwave',
            'gateway_reference' => 'HIDDEN-REF-1',
            'idempotency_key' => 'idem-hidden-1',
            'currency' => 'USD',
            'amount' => 5.00,
            'payment_status' => 'paid',
        ]);

        $restricted = $this->admin(AdminRole::Admin, ['customers']);

        $this->assertEmpty(collect($this->search($restricted, 'HIDDEN-REF-1'))->where('type', 'transaction'));
    }

    public function test_a_moderator_never_sees_customer_rows(): void
    {
        User::factory()->create(['name' => 'Invisible Customer']);

        // Moderators are blocked from individual customer profiles, so the bar
        // must not hand them customer names either.
        $moderator = $this->admin(AdminRole::Moderator, ['customers']);

        $this->assertEmpty(collect($this->search($moderator, 'Invisible'))->where('type', 'customer'));
    }

    public function test_pages_an_admin_cannot_open_are_not_offered(): void
    {
        $restricted = $this->admin(AdminRole::Admin, ['customers']);

        $rows = collect($this->search($restricted, 'settings'))->where('type', 'page');

        $this->assertFalse(
            $rows->contains(fn (array $row) => $row['url'] === route('admin.settings')),
            'System Settings must not appear for an admin without that section.'
        );
    }
}

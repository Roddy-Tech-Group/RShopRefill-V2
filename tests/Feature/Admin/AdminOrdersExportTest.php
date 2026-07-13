<?php

namespace Tests\Feature\Admin;

use App\Domain\Admin\Enums\AdminRole;
use App\Models\Admin;
use App\Models\Order;
use App\Models\User;
use Database\Seeders\AdminSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminOrdersExportTest extends TestCase
{
    use RefreshDatabase;

    private function asAdmin(): self
    {
        $this->seed(AdminSeeder::class);
        $admin = Admin::firstOrCreate(
            ['email' => 'test-orders-export@example.test'],
            ['name' => 'Export Admin', 'password' => 'password', 'role' => AdminRole::SuperAdmin, 'is_active' => true],
        );
        $this->actingAs($admin, 'admin');

        return $this;
    }

    /** @param array<string, mixed> $attrs */
    private function order(array $attrs): Order
    {
        $user = User::factory()->create();

        return Order::create(array_merge([
            'user_id' => $user->id,
            'order_number' => 'RSR-'.uniqid(),
            'settlement_currency' => 'USD',
            'display_currency' => 'USD',
            'subtotal_amount' => 12.34,
            'markup_amount' => 1.66,
            'total_amount' => 14.00,
            'payment_method' => 'wallet',
            'payment_status' => 'paid',
            'fulfillment_status' => 'fulfilled',
            'order_status' => 'completed',
            'placed_at' => now(),
            'metadata' => ['exchange_rate' => 1.0, 'settlement_total_usd' => 14.00, 'settlement_subtotal_usd' => 12.34],
        ], $attrs));
    }

    public function test_export_streams_csv_with_completed_orders(): void
    {
        $this->asAdmin();
        $this->order(['order_number' => 'RSR-PAID-1', 'order_status' => 'completed']);

        $response = $this->get(route('admin.api.commerce.orders.export'));

        $response->assertOk();
        $this->assertStringContainsString('text/csv', (string) $response->headers->get('content-type'));
        $this->assertStringContainsString('attachment', (string) $response->headers->get('content-disposition'));

        $csv = $response->streamedContent();
        $this->assertStringContainsString('Order Number', $csv);
        $this->assertStringContainsString('RSR-PAID-1', $csv);
    }

    public function test_export_excludes_never_paid_orders_by_default(): void
    {
        $this->asAdmin();
        $this->order(['order_number' => 'RSR-DONE-2', 'order_status' => 'completed']);
        $this->order(['order_number' => 'RSR-PENDING-2', 'order_status' => 'pending']);

        $csv = $this->get(route('admin.api.commerce.orders.export'))->streamedContent();

        $this->assertStringContainsString('RSR-DONE-2', $csv);
        $this->assertStringNotContainsString('RSR-PENDING-2', $csv);
    }

    public function test_export_status_filter_includes_requested_status(): void
    {
        $this->asAdmin();
        $this->order(['order_number' => 'RSR-PENDING-3', 'order_status' => 'pending']);

        $csv = $this->get(route('admin.api.commerce.orders.export', ['status' => 'pending']))->streamedContent();

        $this->assertStringContainsString('RSR-PENDING-3', $csv);
    }

    public function test_export_requires_admin_auth(): void
    {
        $response = $this->get(route('admin.api.commerce.orders.export'));

        $response->assertNotFound();
    }
}

<?php

namespace Tests\Feature\Admin;

use App\Domain\Admin\Enums\AdminRole;
use App\Domain\Order\Enums\OrderStatus;
use App\Domain\Payment\Enums\PaymentStatus;
use App\Models\Admin;
use App\Models\Order;
use App\Models\User;
use Database\Seeders\AdminSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminOrdersPaginationTest extends TestCase
{
    use RefreshDatabase;

    private function asAdmin(): self
    {
        $this->seed(AdminSeeder::class);
        $admin = Admin::firstOrCreate(
            ['email' => 'test-orders-pagination@example.test'],
            ['name' => 'Orders Admin', 'password' => 'password', 'role' => AdminRole::SuperAdmin, 'is_active' => true],
        );
        $this->actingAs($admin, 'admin');

        return $this;
    }

    public function test_orders_list_paginates_at_fifty_and_keeps_the_true_total(): void
    {
        $this->withoutVite()->asAdmin();

        Order::factory()->count(55)->for(User::factory()->create())->create([
            'payment_status' => PaymentStatus::Paid,
            'order_status' => OrderStatus::Completed,
        ]);

        $this->get(route('admin.orders', ['tab' => 'completed']))
            ->assertOk()
            ->assertSee('55 orders total.')
            ->assertSee('Showing 1-50.');

        // Rows 51..55 were unreachable before pagination: the old limit(50)
        // dropped everything past the newest 50 off the end of every tab.
        $this->get(route('admin.orders', ['tab' => 'completed', 'page' => 2]))
            ->assertOk()
            ->assertSee('55 orders total.')
            ->assertSee('Showing 51-55.');
    }

    public function test_pagination_links_carry_the_active_tab(): void
    {
        $this->withoutVite()->asAdmin();

        Order::factory()->count(51)->for(User::factory()->create())->create([
            'payment_status' => PaymentStatus::Pending,
            'order_status' => OrderStatus::Pending,
        ]);

        $this->get(route('admin.orders', ['tab' => 'unpaid']))
            ->assertOk()
            ->assertSee('tab=unpaid&amp;page=2', false);
    }

    public function test_each_tab_still_scopes_the_list_and_its_total(): void
    {
        $this->withoutVite()->asAdmin();

        $user = User::factory()->create();

        Order::factory()->for($user)->create([
            'order_number' => 'RSR-DONE-A',
            'payment_status' => PaymentStatus::Paid,
            'order_status' => OrderStatus::Completed,
        ]);
        Order::factory()->for($user)->create([
            'order_number' => 'RSR-DONE-B',
            'payment_status' => PaymentStatus::Paid,
            'order_status' => OrderStatus::Completed,
        ]);
        Order::factory()->for($user)->create([
            'order_number' => 'RSR-ABANDONED',
            'payment_status' => PaymentStatus::Pending,
            'order_status' => OrderStatus::Pending,
        ]);

        $this->get(route('admin.orders', ['tab' => 'unpaid']))
            ->assertOk()
            ->assertSee('1 orders total.')
            ->assertSee('RSR-ABANDONED')
            ->assertDontSee('RSR-DONE-A');

        $this->get(route('admin.orders', ['tab' => 'completed']))
            ->assertOk()
            ->assertSee('2 orders total.')
            ->assertSee('RSR-DONE-A')
            ->assertDontSee('RSR-ABANDONED');
    }

    /**
     * A refunded order used to fall through the status map and read Pending;
     * a partly refunded one read Completed and was listed as unpaid.
     */
    public function test_refunded_orders_say_so_and_sit_with_the_paid_ones(): void
    {
        $this->withoutVite()->asAdmin();

        $user = User::factory()->create();

        Order::factory()->for($user)->create([
            'order_number' => 'RSR-FULLY-REFUNDED',
            'payment_status' => PaymentStatus::Refunded,
            'order_status' => OrderStatus::Refunded,
        ]);
        Order::factory()->for($user)->create([
            'order_number' => 'RSR-PART-REFUNDED',
            'payment_status' => PaymentStatus::PartiallyRefunded,
            'order_status' => OrderStatus::Completed,
        ]);

        $this->get(route('admin.orders', ['tab' => 'all-paid']))
            ->assertOk()
            ->assertSee('RSR-FULLY-REFUNDED')
            ->assertSee('RSR-PART-REFUNDED')
            ->assertSeeInOrder(['RSR-PART-REFUNDED', 'Partly refunded'])
            ->assertSee('Refunded')
            ->assertDontSee('Pending');

        $this->get(route('admin.orders', ['tab' => 'unpaid']))
            ->assertOk()
            ->assertDontSee('RSR-PART-REFUNDED')
            ->assertDontSee('RSR-FULLY-REFUNDED');
    }
}

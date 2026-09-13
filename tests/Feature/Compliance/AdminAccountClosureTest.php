<?php

namespace Tests\Feature\Compliance;

use App\Domain\Admin\Enums\AdminRole;
use App\Domain\Communication\Services\CommunicationManager;
use App\Domain\Shared\Enums\Currency;
use App\Models\Admin;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery\MockInterface;
use Tests\TestCase;

/**
 * The written deletion-request path: a customer who cannot sign in emails the
 * DPO, and an admin closes the account on their behalf under the same rules
 * as the self-serve flow, with the request and its actor in the audit log.
 */
class AdminAccountClosureTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->mock(CommunicationManager::class, function (MockInterface $mock): void {
            $mock->shouldReceive('send');
        });
    }

    private function admin(): Admin
    {
        return Admin::create([
            'name' => 'Test Admin',
            'email' => 'admin@example.test',
            'password' => 'password',
            'role' => AdminRole::SuperAdmin,
            'is_active' => true,
        ]);
    }

    public function test_an_admin_can_request_closure_on_a_customers_behalf_and_it_is_audited(): void
    {
        $user = User::factory()->create();

        $this->actingAs($this->admin(), 'admin')
            ->post(route('admin.customer.close', $user))
            ->assertRedirect()
            ->assertSessionHas('status');

        $this->assertTrue($user->fresh()->hasRequestedClosure());
        $this->assertDatabaseHas('audit_logs', ['action' => 'admin.account_closure_requested']);
    }

    public function test_the_same_wallet_rule_applies_when_an_admin_does_it(): void
    {
        $user = User::factory()->create();
        Wallet::create(['user_id' => $user->id, 'currency' => Currency::USD, 'balance' => 25]);

        $this->actingAs($this->admin(), 'admin')
            ->post(route('admin.customer.close', $user))
            ->assertSessionHasErrors('closure');

        $this->assertFalse($user->fresh()->hasRequestedClosure());
    }

    public function test_a_second_click_cancels_a_pending_closure(): void
    {
        $user = User::factory()->create(['closure_requested_at' => now()->subDays(3)]);

        $this->actingAs($this->admin(), 'admin')
            ->post(route('admin.customer.close', $user))
            ->assertSessionHas('status');

        $this->assertFalse($user->fresh()->hasRequestedClosure());
        $this->assertDatabaseHas('audit_logs', ['action' => 'admin.account_closure_cancelled']);
    }

    public function test_a_closed_account_cannot_be_reopened_from_the_admin_panel(): void
    {
        $user = User::factory()->create(['anonymised_at' => now()]);

        $this->actingAs($this->admin(), 'admin')
            ->post(route('admin.customer.close', $user))
            ->assertSessionHasErrors('closure');
    }
}

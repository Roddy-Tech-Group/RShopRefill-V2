<?php

namespace Tests\Feature\Admin;

use App\Domain\Admin\Enums\AdminRole;
use App\Domain\Audit\Models\AuditLog;
use App\Domain\Kyc\Services\KycOutcomeNotifier;
use App\Models\Admin;
use App\Models\AdminNotification;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminCustomerActionsTest extends TestCase
{
    use RefreshDatabase;

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

    public function test_admin_can_edit_a_customer(): void
    {
        $user = User::factory()->create(['name' => 'Old Name', 'email' => 'old@example.test']);

        $this->actingAs($this->admin(), 'admin')
            ->patch(route('admin.customer.update', $user), [
                'name' => 'New Name',
                'email' => 'new@example.test',
                'phone' => '237600000000',
                'gender' => 'male',
            ])->assertRedirect();

        $fresh = $user->fresh();
        $this->assertSame('New Name', $fresh->name);
        $this->assertSame('new@example.test', $fresh->email);
        $this->assertSame('male', $fresh->gender);
    }

    public function test_admin_can_ban_and_unban(): void
    {
        $user = User::factory()->create(['banned_at' => null]);
        $admin = $this->admin();

        $this->actingAs($admin, 'admin')->post(route('admin.customer.ban', $user))->assertRedirect();
        $this->assertNotNull($user->fresh()->banned_at);

        $this->actingAs($admin, 'admin')->post(route('admin.customer.ban', $user))->assertRedirect();
        $this->assertNull($user->fresh()->banned_at);
    }

    public function test_a_banned_customer_is_signed_out_and_blocked(): void
    {
        $user = User::factory()->create(['banned_at' => now()]);

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertRedirect(route('login'));
    }

    public function test_admin_can_hold_and_release_funds(): void
    {
        $user = User::factory()->create();
        Wallet::create([
            'user_id' => $user->id,
            'currency' => 'USD',
            'balance' => 50,
            'locked_balance' => 0,
            'is_active' => true,
        ]);
        $admin = $this->admin();

        $this->actingAs($admin, 'admin')->post(route('admin.customer.funds', $user))->assertRedirect();
        $this->assertFalse((bool) $user->wallets()->first()->is_active);

        $this->actingAs($admin, 'admin')->post(route('admin.customer.funds', $user))->assertRedirect();
        $this->assertTrue((bool) $user->wallets()->first()->is_active);
    }

    public function test_admin_can_debit_a_wallet_down_to_exactly_zero(): void
    {
        $user = User::factory()->create();
        $wallet = Wallet::create([
            'user_id' => $user->id,
            'currency' => 'USD',
            'balance' => 10.51,
            'locked_balance' => 0,
            'is_active' => true,
        ]);

        $this->actingAs($this->admin(), 'admin')
            ->post(route('admin.customer.wallet-adjust', $user), [
                'currency' => 'USD',
                'direction' => 'debit',
                'amount' => '10.51',
                'reason' => 'Card refunded instead of wallet credit',
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors()
            ->assertSessionHas('status', 'Debited 10.51 USD.');

        $this->assertSame(0.0, (float) $wallet->fresh()->balance);
    }

    public function test_debiting_more_than_a_sub_cent_balance_names_the_exact_balance(): void
    {
        $user = User::factory()->create();
        $wallet = Wallet::create([
            'user_id' => $user->id,
            'currency' => 'USD',
            'balance' => 0.0099,
            'locked_balance' => 0,
            'is_active' => true,
        ]);

        $admin = $this->admin();

        // A 2-decimal message said "only has 0.01" while refusing 0.01.
        $this->actingAs($admin, 'admin')
            ->post(route('admin.customer.wallet-adjust', $user), [
                'currency' => 'USD',
                'direction' => 'debit',
                'amount' => '0.01',
                'reason' => 'Clear the leftover',
            ])
            ->assertSessionHasErrors(['amount' => "Customer only has 0.0099 USD - can't debit more than that."]);

        $this->actingAs($admin, 'admin')
            ->post(route('admin.customer.wallet-adjust', $user), [
                'currency' => 'USD',
                'direction' => 'debit',
                'amount' => '0.0099',
                'reason' => 'Clear the leftover',
            ])
            ->assertSessionHasNoErrors()
            ->assertSessionHas('status', 'Debited 0.0099 USD.');

        $this->assertSame(0.0, (float) $wallet->fresh()->balance);
    }

    // ────────────────────────────────────────────────────────────
    //  Suspend / Lift suspension
    // ────────────────────────────────────────────────────────────

    public function test_admin_can_suspend_a_customer_with_a_reason(): void
    {
        $user = User::factory()->create();

        $this->actingAs($this->admin(), 'admin')
            ->post(route('admin.customer.suspend', $user), ['reason' => 'Unusual activity flagged for review.'])
            ->assertRedirect();

        $fresh = $user->fresh();
        $this->assertNotNull($fresh->suspended_at);
        $this->assertSame('Unusual activity flagged for review.', $fresh->suspension_reason);
        $this->assertTrue($fresh->isSuspended());
    }

    public function test_admin_can_lift_a_suspension_and_clears_pending_review(): void
    {
        $user = User::factory()->create([
            'suspended_at' => now()->subDay(),
            'suspension_reason' => 'Was suspended',
            'suspension_review_requested_at' => now()->subHours(2),
        ]);

        $this->actingAs($this->admin(), 'admin')
            ->post(route('admin.customer.suspend', $user))
            ->assertRedirect();

        $fresh = $user->fresh();
        $this->assertNull($fresh->suspended_at);
        $this->assertNull($fresh->suspension_reason);
        $this->assertNull($fresh->suspension_review_requested_at);
    }

    // ────────────────────────────────────────────────────────────
    //  Suspended customer cannot perform write actions
    // ────────────────────────────────────────────────────────────

    public function test_suspended_user_cart_write_returns_json_403_with_suspension_payload(): void
    {
        $user = User::factory()->create(['suspended_at' => now(), 'suspension_reason' => 'On hold']);

        $this->actingAs($user)
            ->postJson(route('api.storefront.cart.items.add'), [])
            ->assertStatus(403)
            ->assertJsonStructure(['message', 'suspension' => ['reason', 'suspended_at', 'review_requested_at']]);
    }

    // ────────────────────────────────────────────────────────────
    //  Suspension review request
    // ────────────────────────────────────────────────────────────

    public function test_suspended_user_can_request_review_and_creates_admin_notification(): void
    {
        $user = User::factory()->create(['suspended_at' => now()->subDay()]);

        $this->actingAs($user)
            ->post(route('suspension.request-review'))
            ->assertRedirect();

        $this->assertNotNull($user->fresh()->suspension_review_requested_at);
        $this->assertSame(1, AdminNotification::where('type', 'suspension.review_requested')->count());
    }

    public function test_repeat_review_requests_do_not_spam_the_admin_feed(): void
    {
        $user = User::factory()->create(['suspended_at' => now()->subDay()]);

        $this->actingAs($user);
        $this->post(route('suspension.request-review'))->assertRedirect();
        $this->post(route('suspension.request-review'))->assertRedirect();

        $this->assertSame(1, AdminNotification::where('type', 'suspension.review_requested')->count());
    }

    public function test_unsuspended_user_review_request_is_a_noop(): void
    {
        $user = User::factory()->create(['suspended_at' => null]);

        $this->actingAs($user)
            ->post(route('suspension.request-review'))
            ->assertRedirect();

        $this->assertSame(0, AdminNotification::where('type', 'suspension.review_requested')->count());
    }

    // ────────────────────────────────────────────────────────────
    //  Verify email / verify KYC
    // ────────────────────────────────────────────────────────────

    public function test_admin_can_manually_verify_a_users_email(): void
    {
        $user = User::factory()->create(['email_verified_at' => null]);

        $this->actingAs($this->admin(), 'admin')
            ->post(route('admin.customer.verify-email', $user))
            ->assertRedirect();

        $this->assertNotNull($user->fresh()->email_verified_at);
    }

    public function test_admin_can_unverify_an_email(): void
    {
        $user = User::factory()->create(['email_verified_at' => now()]);

        $this->actingAs($this->admin(), 'admin')
            ->post(route('admin.customer.verify-email', $user))
            ->assertRedirect();

        $this->assertNull($user->fresh()->email_verified_at);
    }

    public function test_admin_can_set_kyc_status_to_verified(): void
    {
        $this->mock(KycOutcomeNotifier::class, function ($mock) {
            $mock->shouldReceive('verified')->once();
        });
        $user = User::factory()->create(['kyc_status' => 'pending']);
        $admin = $this->admin();

        $this->actingAs($admin, 'admin')
            ->post(route('admin.customer.kyc-status', $user), ['status' => 'verified', 'kyc_reason' => 'Passport checked on a video call'])
            ->assertRedirect();

        $this->assertSame('verified', $user->fresh()->kyc_status);

        // Marking someone verified lets them past every identity check, so who
        // did it, from what, and why is always on record.
        $log = AuditLog::query()->where('action', 'admin.kyc_status_changed')->sole();
        $this->assertSame(['kyc_status' => 'pending'], $log->before_state);
        $this->assertSame(['kyc_status' => 'verified'], $log->after_state);
        $this->assertSame('Passport checked on a video call', $log->metadata['reason']);
    }

    public function test_a_kyc_status_change_without_a_reason_changes_nothing(): void
    {
        $user = User::factory()->create(['kyc_status' => 'pending']);

        $this->actingAs($this->admin(), 'admin')
            ->post(route('admin.customer.kyc-status', $user), ['status' => 'verified'])
            ->assertSessionHasErrors('kyc_reason');

        $this->assertSame('pending', $user->fresh()->kyc_status);
        $this->assertSame(0, AuditLog::query()->where('action', 'admin.kyc_status_changed')->count());
    }

    public function test_admin_can_mark_kyc_under_review(): void
    {
        $this->mock(KycOutcomeNotifier::class, function ($mock) {
            $mock->shouldReceive('verified')->never();
            $mock->shouldReceive('notVerified')->never();
        });
        $user = User::factory()->create(['kyc_status' => 'unsubmitted']);

        $this->actingAs($this->admin(), 'admin')
            ->post(route('admin.customer.kyc-status', $user), ['status' => 'pending', 'kyc_reason' => 'Waiting on a clearer document'])
            ->assertRedirect();

        $this->assertSame('pending', $user->fresh()->kyc_status);
    }

    public function test_admin_can_reject_kyc(): void
    {
        $this->mock(KycOutcomeNotifier::class, function ($mock) {
            // The admin's note is internal: the customer gets the generic email.
            $mock->shouldReceive('notVerified')->once();
        });
        $user = User::factory()->create(['kyc_status' => 'verified']);

        $this->actingAs($this->admin(), 'admin')
            ->post(route('admin.customer.kyc-status', $user), ['status' => 'rejected', 'kyc_reason' => 'Document found to be forged'])
            ->assertRedirect();

        $this->assertSame('rejected', $user->fresh()->kyc_status);
    }

    public function test_kyc_status_setter_rejects_unknown_values(): void
    {
        $user = User::factory()->create(['kyc_status' => 'pending']);

        $this->actingAs($this->admin(), 'admin')
            ->post(route('admin.customer.kyc-status', $user), ['status' => 'whatever', 'kyc_reason' => 'Testing an invalid value'])
            ->assertSessionHasErrors('status');

        $this->assertSame('pending', $user->fresh()->kyc_status);
    }
}

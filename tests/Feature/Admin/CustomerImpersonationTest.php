<?php

namespace Tests\Feature\Admin;

use App\Domain\Admin\Enums\AdminRole;
use App\Domain\Admin\Support\CustomerImpersonation;
use App\Domain\Security\Services\LoginRiskEvaluator;
use App\Listeners\EvaluateLoginRiskListener;
use App\Models\Admin;
use App\Models\User;
use Illuminate\Auth\Events\Login;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Signing in as a customer is a Super Admin's tool, and the things that follow
 * from it (the "Viewing as" banner, the return switch, the device-verification
 * bypass) follow from an impersonation actually having been started, never
 * from an admin merely being signed into the panel in the same browser.
 */
class CustomerImpersonationTest extends TestCase
{
    use RefreshDatabase;

    private function admin(AdminRole $role): Admin
    {
        return Admin::create([
            'name' => ucfirst($role->value),
            'email' => $role->value.'-'.uniqid().'@example.test',
            'password' => 'password',
            'role' => $role,
            'is_active' => true,
        ]);
    }

    /** @return array<string, array{0: AdminRole}> */
    public static function scopedRoles(): array
    {
        return ['admin' => [AdminRole::Admin], 'moderator' => [AdminRole::Moderator]];
    }

    #[DataProvider('scopedRoles')]
    public function test_only_a_super_admin_can_log_in_as_a_customer(AdminRole $role): void
    {
        $user = User::factory()->create();

        $this->actingAs($this->admin($role), 'admin')
            ->post(route('admin.customer.login-as', $user))
            ->assertNotFound();

        $this->assertGuest('web');
        $this->assertFalse(CustomerImpersonation::active());
        $this->assertDatabaseMissing('audit_logs', ['action' => 'admin.impersonation_started']);
    }

    public function test_a_super_admin_impersonation_shows_the_banner_and_can_be_left(): void
    {
        $user = User::factory()->create(['name' => 'Ama Customer']);
        $super = $this->admin(AdminRole::SuperAdmin);

        $this->actingAs($super, 'admin')
            ->post(route('admin.customer.login-as', $user))
            ->assertRedirect(route('dashboard'));

        $this->assertAuthenticatedAs($user, 'web');
        $this->assertTrue(CustomerImpersonation::active());

        // actingAs(..., 'admin') made admin the default guard; a real
        // request to the customer side runs on web.
        $this->app['auth']->shouldUse('web');
        $this->withoutVite()->get('/dashboard')->assertOk()->assertSee('Viewing as Ama Customer');

        $this->post(route('impersonation.leave'))->assertRedirect(route('admin.customer', $user));

        $this->assertGuest('web');
        $this->assertFalse(session()->has(CustomerImpersonation::SESSION_KEY));
        $this->assertDatabaseHas('audit_logs', ['action' => 'admin.impersonation_ended', 'model_id' => $user->id]);
    }

    /** An admin signed into their own customer account is a customer, not an impersonation. */
    public function test_an_admin_in_their_own_customer_account_sees_no_banner_and_is_not_logged_out_by_leave(): void
    {
        $user = User::factory()->create(['name' => 'Kofi Staff']);

        $this->actingAs($this->admin(AdminRole::Admin), 'admin')->actingAs($user, 'web');

        $this->assertFalse(CustomerImpersonation::active());
        $this->withoutVite()->get('/dashboard')->assertOk()->assertDontSee('Viewing as');

        $this->post(route('impersonation.leave'))->assertRedirect(route('dashboard'));
        $this->assertAuthenticatedAs($user, 'web');
        $this->assertDatabaseMissing('audit_logs', ['action' => 'admin.impersonation_ended']);
    }

    public function test_device_verification_is_not_bypassed_just_because_an_admin_is_signed_in(): void
    {
        $user = User::factory()->create();
        $this->actingAs($this->admin(AdminRole::Admin), 'admin');

        $this->mock(LoginRiskEvaluator::class)
            ->shouldReceive('evaluate')
            ->once()
            ->andThrow(new \RuntimeException('risk was evaluated'));

        $this->expectExceptionMessage('risk was evaluated');
        app(EvaluateLoginRiskListener::class)->handle(new Login('web', $user, false));
    }

    public function test_device_verification_is_bypassed_during_a_real_impersonation(): void
    {
        $user = User::factory()->create();
        $super = $this->admin(AdminRole::SuperAdmin);
        $this->actingAs($super, 'admin')->actingAs($user, 'web');
        CustomerImpersonation::start($super, $user);

        $this->mock(LoginRiskEvaluator::class)->shouldNotReceive('evaluate');

        app(EvaluateLoginRiskListener::class)->handle(new Login('web', $user, false));
        $this->assertTrue(CustomerImpersonation::active());
    }

    public function test_the_log_in_as_customer_button_is_hidden_from_a_regular_admin(): void
    {
        $user = User::factory()->create();

        $this->actingAs($this->admin(AdminRole::Admin), 'admin')->withoutVite()
            ->get(route('admin.customer', $user))->assertOk()
            ->assertDontSee(route('admin.customer.login-as', $user), false);
    }

    public function test_the_log_in_as_customer_button_is_shown_to_a_super_admin(): void
    {
        $user = User::factory()->create();

        $this->actingAs($this->admin(AdminRole::SuperAdmin), 'admin')->withoutVite()
            ->get(route('admin.customer', $user))->assertOk()
            ->assertSee(route('admin.customer.login-as', $user), false);
    }
}

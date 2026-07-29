<?php

namespace Tests\Feature\Admin;

use App\Domain\Admin\Enums\AdminRole;
use App\Models\Admin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Volt\Volt;
use Tests\TestCase;

/**
 * The admin management component is Super Admin only. Livewire publishes every
 * public method as a client-callable endpoint on /livewire/update, which does
 * not re-run the admin route middleware, so authorising in mount() alone is not
 * enough: anyone holding a snapshot of the component (an admin demoted while
 * the page was open, a replayed snapshot, a shared browser) could still call
 * save(), toggleActive() and loginAs() - the last one being full account
 * impersonation. Every entry point must re-check.
 */
class AdminsComponentAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    private function admin(AdminRole $role, ?array $permissions = null): Admin
    {
        return Admin::create([
            'name' => ucfirst($role->value).' '.uniqid(),
            'email' => $role->value.'-'.uniqid().'@example.test',
            'password' => 'password',
            'role' => $role,
            'permissions' => $permissions,
            'is_active' => true,
        ]);
    }

    /**
     * Mount the component as a Super Admin (the only way to obtain a snapshot),
     * optionally prime the form, then hand the live component to a
     * lower-privileged admin. That is exactly what a demotion mid-session, or a
     * replayed snapshot, looks like from the server's point of view.
     *
     * Only one round-trip may follow the handover: once a request 404s there is
     * no snapshot left for the harness to send again.
     */
    private function componentHijackedBy(Admin $attacker, ?callable $prepare = null)
    {
        $this->withoutVite();

        $superAdmin = $this->admin(AdminRole::SuperAdmin);
        $this->actingAs($superAdmin, 'admin');

        $component = Volt::test('admin.admins');

        if ($prepare !== null) {
            $prepare($component);
        }

        $this->actingAs($attacker, 'admin');

        return $component;
    }

    public function test_non_super_admin_cannot_call_save_directly(): void
    {
        // Granted the 'admins' section, so this admin passes the route
        // middleware and only the component gate stands between them and the
        // admin table.
        $attacker = $this->admin(AdminRole::Admin, ['admins']);

        $component = $this->componentHijackedBy($attacker, function ($component) {
            $component->set('name', 'Backdoor')
                ->set('email', 'backdoor@example.test')
                ->set('password', 'password123')
                ->set('role', AdminRole::SuperAdmin->value);
        });

        $component->call('save')->assertStatus(404);

        $this->assertDatabaseMissing('admins', ['email' => 'backdoor@example.test']);
        $this->assertSame(2, Admin::count(), 'save() created an admin for a non-super-admin.');
    }

    public function test_non_super_admin_cannot_escalate_an_existing_admin_via_save(): void
    {
        $attacker = $this->admin(AdminRole::Admin, ['admins']);

        $component = $this->componentHijackedBy($attacker, function ($component) use ($attacker) {
            $component->call('edit', $attacker->id)
                ->set('role', AdminRole::SuperAdmin->value);
        });

        $component->call('save')->assertStatus(404);

        $this->assertSame(AdminRole::Admin, $attacker->fresh()->role, 'save() promoted a non-super-admin to Super Admin.');
    }

    public function test_non_super_admin_cannot_call_login_as_directly(): void
    {
        $attacker = $this->admin(AdminRole::Admin, ['admins']);
        $victim = $this->admin(AdminRole::SuperAdmin);

        $component = $this->componentHijackedBy($attacker);

        $component->call('loginAs', $victim->id)->assertStatus(404);

        $this->assertSame($attacker->id, auth()->guard('admin')->id(), 'loginAs() impersonated an admin for a non-super-admin.');
        $this->assertFalse(session()->has('impersonator_admin_id'));
    }

    public function test_non_super_admin_cannot_call_toggle_active_directly(): void
    {
        $attacker = $this->admin(AdminRole::Admin, ['admins']);
        $victim = $this->admin(AdminRole::SuperAdmin);

        $component = $this->componentHijackedBy($attacker);

        $component->call('toggleActive', $victim->id)->assertStatus(404);

        $this->assertTrue($victim->fresh()->is_active, 'toggleActive() deactivated an admin for a non-super-admin.');
    }

    public function test_non_super_admin_cannot_call_edit_directly(): void
    {
        $attacker = $this->admin(AdminRole::Admin, ['admins']);
        $victim = $this->admin(AdminRole::SuperAdmin);

        $component = $this->componentHijackedBy($attacker);

        $component->call('edit', $victim->id)->assertStatus(404);
    }

    public function test_non_super_admin_cannot_call_new_admin_directly(): void
    {
        $attacker = $this->admin(AdminRole::Admin, ['admins']);

        $component = $this->componentHijackedBy($attacker);

        $component->call('newAdmin')->assertStatus(404);
    }

    public function test_editing_id_cannot_be_repointed_from_the_client(): void
    {
        $this->withoutVite();
        $superAdmin = $this->admin(AdminRole::SuperAdmin);
        $victim = $this->admin(AdminRole::Admin);

        $this->actingAs($superAdmin, 'admin');
        $component = Volt::test('admin.admins');

        $this->expectException(CannotUpdateLockedPropertyException::class);
        $component->set('editingId', $victim->id);
    }

    public function test_super_admin_can_still_manage_admins(): void
    {
        $this->withoutVite();
        $superAdmin = $this->admin(AdminRole::SuperAdmin);
        $target = $this->admin(AdminRole::Moderator);

        $this->actingAs($superAdmin, 'admin');

        Volt::test('admin.admins')
            ->call('newAdmin')
            ->set('name', 'New Person')
            ->set('email', 'new-person@example.test')
            ->set('password', 'password123')
            ->set('role', AdminRole::Admin->value)
            ->call('save')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('admins', ['email' => 'new-person@example.test']);

        Volt::test('admin.admins')->call('edit', $target->id)->call('toggleActive', $target->id);
        $this->assertFalse($target->fresh()->is_active);
    }
}

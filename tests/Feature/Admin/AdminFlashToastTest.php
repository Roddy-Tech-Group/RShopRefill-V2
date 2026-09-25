<?php

namespace Tests\Feature\Admin;

use App\Domain\Admin\Enums\AdminRole;
use App\Models\Admin;
use App\Models\User;
use Database\Seeders\AdminSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Admin action flashes ("Returned to your admin account.", etc.) render as a
 * floating auto-dismissing toast from the layout rather than a static inline
 * banner that lingers until the next navigation.
 */
class AdminFlashToastTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): Admin
    {
        $this->seed(AdminSeeder::class);

        return Admin::firstOrCreate(
            ['email' => 'test-toast@example.test'],
            ['name' => 'Toast Admin', 'password' => 'password', 'role' => AdminRole::SuperAdmin, 'is_active' => true],
        );
    }

    public function test_flashed_status_renders_as_an_auto_dismissing_toast(): void
    {
        $this->withoutVite()->actingAs($this->admin(), 'admin');
        $user = User::factory()->create();

        $response = $this->withSession(['status' => 'Returned to your admin account.'])
            ->get(route('admin.customer', $user))
            ->assertOk();

        $response->assertSee('Returned to your admin account.');
        // The floating toast's distinctive top-right positioning + auto-dismiss
        // init; a static inline banner would carry neither.
        // Top centre at every width, squared to the 5px radius - it used to park
        // in the top-right corner on sm+ as a pill, over the header actions.
        $response->assertSee('fixed inset-x-4 top-4 z-[100] mx-auto flex w-fit max-w-sm', false);
        $response->assertSee('rounded-[5px]', false);
        $response->assertDontSee('sm:right-5', false);
        $response->assertDontSee('rounded-full px-5 py-3', false);
        $response->assertSee('show = false', false);
    }

    public function test_toast_host_stays_dormant_without_a_flash(): void
    {
        $this->withoutVite()->actingAs($this->admin(), 'admin');
        $user = User::factory()->create();

        // The toast host is always mounted (it also receives window `toast`
        // events from Livewire actions), but with no flash it must start
        // hidden with an empty message — nothing visible to the admin.
        $this->get(route('admin.customer', $user))
            ->assertOk()
            ->assertSee("message: ''", false)
            ->assertSee('style="display:none;"', false);
    }
}

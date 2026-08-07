<?php

namespace Tests\Feature\Admin;

use App\Domain\Admin\Enums\AdminRole;
use App\Models\Admin;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * CSV export of customer names and emails from the /admin/customers page.
 *
 * The authorization tests carry the weight: a bulk download of every
 * customer's name and email is strictly more access than opening a single
 * customer profile, so any role blocked from the profile page must be blocked
 * from this too.
 */
class AdminCustomersExportTest extends TestCase
{
    use RefreshDatabase;

    private function admin(AdminRole $role = AdminRole::SuperAdmin, ?array $permissions = null): Admin
    {
        return Admin::create([
            'name' => 'Export Admin',
            'email' => 'export-'.Str::random(6).'@example.test',
            'password' => 'password',
            'role' => $role,
            'permissions' => $permissions,
            'is_active' => true,
        ]);
    }

    private function download(Admin $admin): string
    {
        $response = $this->actingAs($admin, 'admin')
            ->get(route('admin.customers.export'))
            ->assertOk();

        $this->assertStringContainsString('text/csv', (string) $response->headers->get('content-type'));
        $this->assertStringContainsString('attachment', (string) $response->headers->get('content-disposition'));

        return $response->streamedContent();
    }

    public function test_export_streams_customer_names_and_emails(): void
    {
        User::factory()->create(['name' => 'Ada Lovelace', 'email' => 'ada@example.test']);
        User::factory()->create(['name' => 'Grace Hopper', 'email' => 'grace@example.test']);

        $csv = $this->download($this->admin());

        // fputcsv quotes any field containing whitespace, so a two-word name
        // arrives enclosed - that is valid CSV, not an artefact to strip.
        $this->assertStringStartsWith('Name,Email', $csv);
        $this->assertStringContainsString('"Ada Lovelace",ada@example.test', $csv);
        $this->assertStringContainsString('"Grace Hopper",grace@example.test', $csv);
    }

    public function test_export_covers_every_customer_not_just_the_first_page(): void
    {
        // The page itself paginates at 50; the export must not inherit that.
        User::factory()->count(60)->create();
        $last = User::factory()->create(['name' => 'Final Customer', 'email' => 'final@example.test']);

        $csv = $this->download($this->admin());

        $this->assertStringContainsString('final@example.test', $csv);
        // 61 customers + the header row.
        $this->assertSame(62, count(array_filter(explode("\n", trim($csv)))));
        $this->assertSame(61, User::count());
        $this->assertNotNull($last->id);
    }

    public function test_names_containing_commas_stay_in_one_column(): void
    {
        User::factory()->create(['name' => 'Doe, John', 'email' => 'comma@example.test']);

        $csv = $this->download($this->admin());

        $this->assertStringContainsString('"Doe, John",comma@example.test', $csv);
    }

    public function test_the_customers_page_links_to_the_export(): void
    {
        $this->actingAs($this->admin(), 'admin')
            ->get(route('admin.customers'))
            ->assertOk()
            ->assertSee(route('admin.customers.export'));
    }

    public function test_an_admin_without_the_customers_section_cannot_export(): void
    {
        $restricted = $this->admin(AdminRole::Admin, ['orders']);

        $this->actingAs($restricted, 'admin')
            ->get(route('admin.customers.export'))
            ->assertNotFound();
    }

    /**
     * Moderators are blocked from individual customer profiles, so they must
     * not be able to download every profile at once instead.
     */
    public function test_a_moderator_cannot_export_customers(): void
    {
        User::factory()->create(['name' => 'Hidden Person', 'email' => 'hidden@example.test']);

        $moderator = $this->admin(AdminRole::Moderator, ['customers']);

        $this->actingAs($moderator, 'admin')
            ->get(route('admin.customers.export'))
            ->assertForbidden();
    }

    public function test_the_export_requires_admin_authentication(): void
    {
        $this->get(route('admin.customers.export'))->assertNotFound();
    }
}

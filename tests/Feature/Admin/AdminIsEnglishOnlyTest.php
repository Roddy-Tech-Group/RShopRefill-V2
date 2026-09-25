<?php

namespace Tests\Feature\Admin;

use App\Domain\Admin\Enums\AdminRole;
use App\Models\Admin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\App;
use Tests\TestCase;

/**
 * The admin panel renders in English whatever language is selected.
 *
 * Admin routes ride the `web` group, so SetLocale used to apply the visitor's
 * locale to them as well. The copy there is written in English rather than
 * translated, so a different language changed nothing it said - but the layout
 * takes `dir` from the locale, and an RTL language mirrored the entire panel:
 * sidebar on the right, every row reversed, around English text.
 *
 * The other half of this is that the visitor's own choice has to survive the
 * trip. Forcing the locale through the normal path would write `en` into the
 * session and silently reset an operator who was reading the shop in French.
 */
class AdminIsEnglishOnlyTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): Admin
    {
        return Admin::create([
            'name' => 'Locale Tester',
            'email' => 'locale@example.test',
            'password' => 'password',
            'role' => AdminRole::SuperAdmin,
            'is_active' => true,
        ]);
    }

    public function test_the_admin_panel_renders_english_when_arabic_is_selected(): void
    {
        $page = $this->actingAs($this->admin(), 'admin')
            ->withSession(['locale' => 'ar'])
            ->withoutVite()
            ->get('/admin/dashboard')
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('lang="en"', $page);
        $this->assertStringContainsString('dir="ltr"', $page);
        $this->assertStringNotContainsString('dir="rtl"', $page);
    }

    /** Applies to the whole panel, including the signed-out login page. */
    public function test_the_admin_login_page_is_english_too(): void
    {
        $page = $this->withSession(['locale' => 'ar'])
            ->withoutVite()
            ->get('/admin/rshopadmins')
            ->assertOk()
            ->getContent();

        $this->assertStringNotContainsString('dir="rtl"', $page);
    }

    /**
     * The visitor's language survives the admin visit. If the admin request
     * wrote its forced locale to the session, the next storefront page would
     * come back English.
     */
    public function test_visiting_admin_does_not_reset_the_stored_language(): void
    {
        $this->actingAs($this->admin(), 'admin')
            ->withSession(['locale' => 'ar'])
            ->withoutVite()
            ->get('/admin/dashboard')
            ->assertOk()
            ->assertSessionHas('locale', 'ar');
    }

    /** The storefront is untouched: it still honours the chosen language. */
    public function test_the_storefront_still_translates(): void
    {
        $this->withSession(['locale' => 'ar'])
            ->withoutVite()
            ->get('/')
            ->assertOk();

        $this->assertSame('ar', App::getLocale());
    }
}

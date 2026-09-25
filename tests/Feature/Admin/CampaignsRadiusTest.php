<?php

namespace Tests\Feature\Admin;

use App\Domain\Admin\Enums\AdminRole;
use App\Models\Admin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Radius conventions for the push-campaign pair.
 *
 * Both views were authored in stock-Tailwind idiom the day before the
 * project-wide 12px re-standardisation, and that sweep was a mechanical
 * rounded-[10px] -> rounded-[12px] string replacement, so it could not see
 * named utilities and skipped them. The trap is that the named classes read as
 * correct: resources/css/app.css redefines the radius scale, so rounded-xl is
 * 20px here (not Tailwind's 12px), rounded-lg is 16px, and rounded-sm is 8px.
 *
 * These tests pin the corrected values the same way TradesIndexBorderTest pins
 * the sibling page it was copied from.
 */
class CampaignsRadiusTest extends TestCase
{
    use RefreshDatabase;

    /**
     * The table wrapper gained a nested element, so this renders the real page
     * and asserts against the output rather than the source: a source-only
     * check would still pass if the restructure broke the view.
     */
    public function test_the_page_renders_with_the_corrected_wrapper(): void
    {
        $this->actingAs(Admin::create([
            'name' => 'Radius',
            'email' => 'radius-'.uniqid().'@example.test',
            'password' => 'password',
            'role' => AdminRole::SuperAdmin,
            'is_active' => true,
        ]), 'admin');

        $html = $this->withoutVite()->get(route('admin.campaigns'))->assertOk()->getContent();

        $this->assertStringContainsString('overflow-hidden rounded-[12px] bg-white shadow-sm shadow-zinc-900/[0.04] dark:bg-[#1d3252]', $html);
        $this->assertStringContainsString('<div class="overflow-x-auto p-3">', $html);
        $this->assertStringContainsString('rounded-[12px] bg-white p-5 shadow-sm', $html);
    }

    public function test_the_index_uses_the_house_12px_radius_not_the_20px_rounded_xl(): void
    {
        $index = file_get_contents(resource_path('views/livewire/admin/campaigns.blade.php'));

        // KPI cards (shadow only, no border - the admin card rule) + the per-row icon tile.
        $this->assertStringContainsString('rounded-[12px] bg-white p-5 shadow-sm', $index);
        $this->assertStringContainsString('h-10 w-10 shrink-0 items-center justify-center rounded-[12px] bg-blue-50', $index);

        // The stock-assumption classes are gone. rounded-lg would render the
        // 40px icon tile at 16px, wider than the 12px curve of the table row
        // it sits in (app.css sets border-radius 12px on .admin-table tbody tr).
        $this->assertStringNotContainsString('rounded-xl', $index);
        $this->assertStringNotContainsString('rounded-lg', $index);
    }

    public function test_the_index_table_uses_the_standard_admin_card_wrapper(): void
    {
        $index = file_get_contents(resource_path('views/livewire/admin/campaigns.blade.php'));

        // Same wrapper as the other admin tables: shadow only, no ring and no border.
        $this->assertStringContainsString('overflow-hidden rounded-[12px] bg-white shadow-sm shadow-zinc-900/[0.04] dark:bg-[#1d3252]', $index);
        $this->assertStringNotContainsString('ring-1 ring-zinc-200', $index);

        // The inner p-3 is load-bearing, not decoration: .admin-table carries
        // margin-top -6px to cancel it, so without the padding the header pill
        // is pulled above the content box and clipped by the scroll container.
        $this->assertStringContainsString('<div class="overflow-x-auto p-3">', $index);
    }

    /** True pills and circles are left alone; only the mis-scaled named classes moved. */
    public function test_the_index_keeps_its_genuine_pills_and_circles(): void
    {
        $index = file_get_contents(resource_path('views/livewire/admin/campaigns.blade.php'));

        // Trend chip inside each KPI card, and the empty-state icon circle.
        $this->assertStringContainsString('inline-flex items-center rounded-full bg-emerald-50', $index);
        $this->assertStringContainsString('h-12 w-12 items-center justify-center rounded-full bg-blue-50', $index);
    }

    /**
     * The editor's preview pane imitates a Windows 11 toast, so its radii track
     * WinUI (8px OverlayCornerRadius outside, 4px ControlCornerRadius within),
     * not the app scale. Literal px keeps the simulation stable if the app
     * scale is ever retuned.
     */
    public function test_the_editor_preview_imitates_windows_chrome_not_our_tokens(): void
    {
        $editor = file_get_contents(resource_path('views/livewire/admin/campaign-editor.blade.php'));

        $this->assertStringContainsString('rounded-[8px] bg-[#2b2b2b]', $editor);
        $this->assertStringContainsString('w-16 h-16 shrink-0 bg-white/5 rounded-[4px]', $editor);

        // rounded-sm is 8px here. On the 16px attribution favicon that is
        // exactly half the box, so the icon clamped to a full circle - and
        // icon-192.png has no alpha, so it rendered as a white disc on the
        // near-black toast where Windows draws a square tile.
        $this->assertStringNotContainsString('rounded-sm', $editor);

        // Bare `rounded` resolves to 4px from Tailwind's deprecated --radius,
        // which this project's @theme cannot reach; the value is pinned now.
        $this->assertStringNotContainsString('bg-white/5 rounded ', $editor);
    }

    /** The tinted note beside the preview is app chrome, so it follows the house radius. */
    public function test_the_editor_callout_follows_the_admin_convention(): void
    {
        $editor = file_get_contents(resource_path('views/livewire/admin/campaign-editor.blade.php'));

        // A tinted callout, no border: the fill alone marks it, as on every admin card.
        $this->assertStringContainsString('bg-blue-50 dark:bg-blue-500/10 p-4 rounded-[12px]"', $editor);
        $this->assertStringNotContainsString('border border-blue-100', $editor);
        $this->assertStringNotContainsString('rounded-xl', $editor);
    }
}

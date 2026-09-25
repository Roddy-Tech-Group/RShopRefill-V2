<?php

namespace Tests\Feature\Admin;

use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * Admin feedback goes through the shared surfaces, not ad-hoc ones.
 *
 * Two things had drifted:
 *
 *  - Status messages. The admin layout carries <x-flash-toast /> (top of the
 *    screen, dismiss button), but it only reads the session on a full page
 *    render. A `session()->flash()` set inside a Livewire action never reached
 *    it, so every Livewire admin page grew its own full-width banner instead.
 *    Livewire pages now `$this->dispatch('toast', ...)`, which is the event the
 *    toast already listens for; plain pages that redirect keep flashing and
 *    the layout toast shows it, so their inline copies were duplicates.
 *
 *  - Confirmations. `wire:confirm` and `window.confirm()` are the browser's
 *    own dialog. The app has <x-confirm-modal />, driven by `data-confirm`
 *    attributes and intercepted in app.js, which replays the click on Confirm
 *    so `wire:click` fires exactly as before.
 */
class AdminFeedbackSurfacesTest extends TestCase
{
    /** Livewire admin pages that used to flash + render their own banner. */
    private const LIVEWIRE_PAGES = [
        'livewire/admin/admins.blade.php',
        'livewire/admin/newsletter.blade.php',
        'livewire/admin/pricing-rules.blade.php',
        'livewire/admin/rates.blade.php',
        'livewire/admin/support-tickets.blade.php',
        'livewire/admin/content/blog.blade.php',
        'livewire/admin/content/faqs.blade.php',
        'livewire/admin/content/hero-cards.blade.php',
        'livewire/admin/content/hero-sections.blade.php',
        'livewire/admin/content/press.blade.php',
        'livewire/admin/content/reviews.blade.php',
    ];

    /** Plain pages inside a layout that already shows the toast. */
    private const PLAIN_PAGES = [
        'admin/risk/dashboard.blade.php',
        'admin/risk/review.blade.php',
        'admin/risk/reviews.blade.php',
        'dashboard/data-export.blade.php',
    ];

    private function viewSource(string $rel): string
    {
        return File::get(resource_path('views/'.$rel));
    }

    public function test_livewire_admin_pages_toast_instead_of_flashing(): void
    {
        foreach (self::LIVEWIRE_PAGES as $rel) {
            $view = $this->viewSource($rel);

            $this->assertStringNotContainsString(
                "session()->flash('status'",
                $view,
                "{$rel} still flashes a status the layout toast cannot see from a Livewire action"
            );
            $this->assertStringNotContainsString("session()->flash('error'", $view, "{$rel} still flashes an error");
            $this->assertStringContainsString(
                "\$this->dispatch('toast', message:",
                $view,
                "{$rel} should dispatch to the shared toast"
            );
        }
    }

    public function test_no_page_with_the_layout_toast_renders_its_own_banner(): void
    {
        foreach (array_merge(self::LIVEWIRE_PAGES, self::PLAIN_PAGES) as $rel) {
            $view = $this->viewSource($rel);

            $this->assertStringNotContainsString("@if (session('status'))", $view, "{$rel} still renders an inline status banner");
            $this->assertStringNotContainsString("@if (session('error'))", $view, "{$rel} still renders an inline error banner");
        }
    }

    /** The one place error toasts are raised must flag them as errors. */
    public function test_error_toasts_carry_the_error_flag(): void
    {
        foreach (['livewire/admin/admins.blade.php', 'livewire/admin/newsletter.blade.php'] as $rel) {
            $this->assertMatchesRegularExpression(
                '/dispatch\(\'toast\', message: .+, isError: true\);/',
                $this->viewSource($rel),
                "{$rel} should raise its error toasts with isError: true"
            );
        }
    }

    public function test_admin_never_uses_the_browser_confirm_dialog(): void
    {
        $offenders = [];

        foreach (['views/admin', 'views/livewire/admin'] as $dir) {
            foreach (File::allFiles(resource_path($dir)) as $file) {
                $src = File::get($file->getPathname());

                if (str_contains($src, 'wire:confirm')) {
                    $offenders[] = $file->getFilename().' (wire:confirm)';
                }
                // window.confirm('...') - not data-confirm, not the modal's own confirm().
                if (preg_match('/(?<![\w.-])confirm\(\s*[\'"]/', $src)) {
                    $offenders[] = $file->getFilename().' (window.confirm)';
                }
            }
        }

        $this->assertSame([], $offenders, 'these views still use the browser dialog instead of <x-confirm-modal />');
    }

    /**
     * Every gated Livewire action carries a tone and a label, so the modal
     * never falls back to a generic "Confirm" on a destructive delete.
     */
    public function test_every_gated_action_names_its_tone_and_label(): void
    {
        $bare = [];

        foreach (File::allFiles(resource_path('views/livewire/admin')) as $file) {
            $src = File::get($file->getPathname());
            preg_match_all('/<button[^>]*data-confirm="[^"]*"[^>]*>/', $src, $m);
            foreach ($m[0] as $tag) {
                if (! str_contains($tag, 'data-confirm-tone=') || ! str_contains($tag, 'data-confirm-text=')) {
                    $bare[] = $file->getFilename();
                }
            }
        }

        $this->assertSame([], array_values(array_unique($bare)), 'these gated buttons are missing data-confirm-tone or data-confirm-text');
    }
}

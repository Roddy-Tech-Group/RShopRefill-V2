<?php

namespace Tests\Feature\Storefront;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * No page is covered by a full-screen loader.
 *
 * A branded overlay used to sit over the storefront, the dashboard and the
 * admin on every hard reload. The page under it was already server-rendered,
 * but the overlay only began to hide once the whole script bundle had run,
 * then held for a fixed half second and faded for another 300ms. Content that
 * was ready stayed hidden for no reason. The theme is resolved on the server,
 * so there was no flash left for it to mask.
 *
 * Loading indicators now belong to genuinely asynchronous work: the
 * navigation progress bar and the pull-to-refresh spinner.
 */
class StorefrontLoaderTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_storefront_renders_without_a_covering_loader(): void
    {
        $html = $this->withoutVite()->get('/')->assertOk()->getContent();

        $this->assertStringNotContainsString('id="app-loader"', $html);
        $this->assertStringNotContainsString('app-loader--hidden', $html);
    }

    public function test_no_layout_mounts_the_loader(): void
    {
        foreach (['layouts/app/header', 'layouts/dashboard', 'layouts/admin'] as $layout) {
            $blade = File::get(resource_path("views/components/{$layout}.blade.php"));

            $this->assertStringNotContainsString('x-ui.app-loader', $blade, "{$layout} still mounts the loader");
        }

        $this->assertFileDoesNotExist(resource_path('views/components/ui/app-loader.blade.php'));
    }

    /**
     * Nor does navigation flash the progress bar: it read as a stutter on
     * fast page changes (NavigationProgressBarTest). The colour stays
     * configured so bringing it back is a one-word change.
     */
    public function test_navigation_does_not_flash_the_progress_bar(): void
    {
        $this->assertFalse(config('livewire.navigate.show_progress_bar'));
        $this->assertNotEmpty(config('livewire.navigate.progress_bar_color'));
    }
}

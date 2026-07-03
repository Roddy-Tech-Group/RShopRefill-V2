<?php

namespace Tests\Feature\Settings;

use Tests\TestCase;

/**
 * The user settings (profile) page no longer flashes a skeleton overlay during
 * wire:navigate - the `x-show="navigating"` skeleton block and its trigger were
 * removed from the view.
 */
class ProfileNoSkeletonTest extends TestCase
{
    public function test_profile_settings_view_has_no_skeleton_overlay(): void
    {
        $blade = file_get_contents(resource_path('views/livewire/settings/profile.blade.php'));

        $this->assertStringNotContainsString('skeleton-stagger', $blade, 'Skeleton overlay markup should be gone.');
        $this->assertStringNotContainsString('x-show="navigating"', $blade, 'Navigate skeleton toggle should be gone.');
        $this->assertStringNotContainsString('navigating', $blade, 'The navigating trigger should be fully removed.');
    }
}

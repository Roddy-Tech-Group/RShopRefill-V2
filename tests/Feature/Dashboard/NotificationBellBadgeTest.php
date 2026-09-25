<?php

namespace Tests\Feature\Dashboard;

use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * The unread count on the notification bell is the red disc alone. It used
 * to wear a two-pixel ring in the surface colour, which on the dark header
 * chip showed as a white border around the red.
 */
class NotificationBellBadgeTest extends TestCase
{
    public function test_the_unread_count_has_no_ring(): void
    {
        $view = File::get(resource_path('views/livewire/notifications-menu.blade.php'));

        $at = strpos($view, 'rounded-full bg-red-500');
        $this->assertNotFalse($at, 'the unread count badge should still be the red disc');

        $badge = substr($view, strrpos(substr($view, 0, $at), '<span'), strpos($view, '>', $at) - strrpos(substr($view, 0, $at), '<span'));

        $this->assertStringNotContainsString('ring-', $badge, 'the red count must not carry a ring');
        $this->assertStringContainsString('text-white', $badge);
    }
}

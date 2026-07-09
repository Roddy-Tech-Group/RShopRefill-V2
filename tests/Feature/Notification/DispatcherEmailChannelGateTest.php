<?php

namespace Tests\Feature\Notification;

use App\Domain\Notification\Services\NotificationDispatcher;
use App\Mail\GuestAccountClaimMail;
use App\Models\NotificationDelivery;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The dispatcher only enlists the email channel when a Mailable exists.
 * In-app-only dispatches used to enlist it anyway, making EmailChannel log a
 * "called without a Mailable object" warning on every one.
 */
class DispatcherEmailChannelGateTest extends TestCase
{
    use RefreshDatabase;

    public function test_no_email_channel_without_a_mailable(): void
    {
        $user = User::factory()->create();

        app(NotificationDispatcher::class)->dispatch(
            user: $user,
            title: 'In-app only',
            message: 'No mailable attached.',
            category: 'order',
        );

        // Sync queue ran the job: the in-app notification exists, but no
        // email delivery was even attempted.
        $this->assertDatabaseHas('notifications', ['user_id' => $user->id, 'title' => 'In-app only']);
        $this->assertSame(0, NotificationDelivery::where('channel', 'EMAIL')->count() + NotificationDelivery::where('channel', 'email')->count());
    }

    public function test_email_channel_still_runs_with_a_mailable(): void
    {
        $user = User::factory()->create(['is_guest' => true]);

        app(NotificationDispatcher::class)->dispatch(
            user: $user,
            title: 'With mailable',
            message: 'Claim your account.',
            category: 'order',
            mailable: new GuestAccountClaimMail($user),
        );

        // The email channel attempted a delivery (recorded regardless of
        // provider outcome).
        $this->assertGreaterThan(0, NotificationDelivery::count());
    }
}

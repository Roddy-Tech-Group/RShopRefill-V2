<?php

namespace Tests\Feature\Notification;

use App\Domain\Communication\Enums\CommunicationCategory;
use App\Domain\Communication\Mail\Orders\GuestAccountClaimMail;
use App\Domain\Communication\Services\CommunicationManager;
use App\Models\CommunicationLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The channel resolver only enlists the email channel when a Mailable exists.
 * In-app-only dispatches must never attempt (or log) an email delivery.
 */
class DispatcherEmailChannelGateTest extends TestCase
{
    use RefreshDatabase;

    public function test_no_email_channel_without_a_mailable(): void
    {
        $user = User::factory()->create();

        app(CommunicationManager::class)->send(
            recipient: $user,
            category: CommunicationCategory::OrderPlaced,
            title: 'In-app only',
            message: 'No mailable attached.',
        );

        // Sync queue ran the job: the in-app notification exists, but no
        // email delivery was even attempted.
        $this->assertDatabaseHas('notifications', ['user_id' => $user->id, 'title' => 'In-app only']);
        $this->assertSame(0, CommunicationLog::where('user_id', $user->id)->where('channel', 'email')->count());
    }

    public function test_email_channel_still_runs_with_a_mailable(): void
    {
        $user = User::factory()->create(['is_guest' => true]);

        app(CommunicationManager::class)->send(
            recipient: $user,
            category: CommunicationCategory::AccountClaim,
            title: 'With mailable',
            message: 'Claim your account.',
            mailable: new GuestAccountClaimMail($user),
        );

        // The email channel attempted a delivery (recorded regardless of
        // provider outcome).
        $this->assertGreaterThan(
            0,
            CommunicationLog::where('user_id', $user->id)->where('channel', 'email')->count()
        );
    }
}

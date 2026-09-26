<?php

namespace Tests\Feature\Notification;

use App\Domain\Communication\Enums\CommunicationCategory;
use App\Domain\Communication\Enums\CommunicationChannel;
use App\Domain\Communication\Services\ChannelResolver;
use App\Domain\Communication\Services\PreferenceManager;
use App\Models\Admin;
use App\Models\NewsletterSubscriber;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Push is only resolved for a recipient that could hold a subscription.
 *
 * The resolver takes a Model, not a User, so anything Eloquent can be may
 * arrive. It guarded the bell feed with a recipient-type check and then added
 * push for absolutely anything, on preferences alone.
 *
 * A newsletter broadcast sends to NewsletterSubscriber rows, which have no push
 * relation and never will. Each one therefore resolved a push channel, queued a
 * job, and had it suppressed as an unsupported recipient. Production had 1504
 * such rows against 257 real sends, so the push metrics were mostly the sound of
 * the newsletter going out. The email was never affected.
 */
class PushRecipientCapabilityTest extends TestCase
{
    use RefreshDatabase;

    private function channelsFor(object $recipient, CommunicationCategory $category, bool $hasMailable = true): array
    {
        return app(ChannelResolver::class)->resolve(
            recipient: $recipient,
            category: $category,
            hasMailable: $hasMailable,
            hasOverrideEmail: false,
            preferenceManager: app(PreferenceManager::class),
        );
    }

    /** The regression itself. */
    public function test_a_newsletter_subscriber_is_not_offered_push(): void
    {
        $subscriber = NewsletterSubscriber::create([
            'email' => 'reader@example.com',
            'status' => 'active',
        ]);

        // The premise: this recipient genuinely cannot hold a subscription.
        $this->assertFalse(method_exists($subscriber, 'pushSubscriptions'));

        $channels = $this->channelsFor($subscriber, CommunicationCategory::Newsletter);

        $this->assertNotContains(CommunicationChannel::Push, $channels);
    }

    /**
     * And the email still goes, which is the whole point of the send. A guard
     * that quietly cost the newsletter its only channel would be far worse than
     * the waste it replaced.
     */
    public function test_the_newsletter_still_resolves_email(): void
    {
        $subscriber = NewsletterSubscriber::create([
            'email' => 'reader@example.com',
            'status' => 'active',
        ]);

        $channels = $this->channelsFor($subscriber, CommunicationCategory::Newsletter);

        $this->assertSame([CommunicationChannel::Email], $channels);
    }

    /** A real customer is unaffected: they keep push and the bell feed. */
    public function test_a_user_still_gets_push(): void
    {
        $channels = $this->channelsFor(User::factory()->create(), CommunicationCategory::OrderFulfilled);

        $this->assertContains(CommunicationChannel::Push, $channels);
        $this->assertContains(CommunicationChannel::InApp, $channels);
    }

    /**
     * A user with no device registered still resolves push. Whether a device
     * exists is the channel's business at send time; the resolver only asks
     * whether this kind of recipient could ever have one. Conflating the two
     * would stop a notification the moment a subscription lapsed, and it would
     * never restart.
     */
    public function test_a_user_without_a_device_still_resolves_push(): void
    {
        $user = User::factory()->create();

        $this->assertCount(0, $user->pushSubscriptions);
        $this->assertContains(
            CommunicationChannel::Push,
            $this->channelsFor($user, CommunicationCategory::OrderFulfilled)
        );
    }

    /** Admins hold subscriptions too, so alerts still reach a phone. */
    public function test_an_admin_still_gets_push(): void
    {
        // No AdminFactory exists in this application; admins are built inline,
        // as the admin feature tests do.
        $admin = Admin::create([
            'name' => 'Push Admin',
            'email' => 'admin-push@example.test',
            'password' => 'password',
            'role' => 'admin',
            'is_active' => true,
        ]);

        $this->assertTrue(method_exists($admin, 'pushSubscriptions'));
        $this->assertContains(
            CommunicationChannel::Push,
            $this->channelsFor($admin, CommunicationCategory::OrderFulfilled)
        );
    }

    /**
     * The guard is a capability question, not a preference one, so it must not
     * have replaced the preference check. A customer who switched push off still
     * gets no push.
     */
    public function test_the_guard_did_not_replace_the_preference_check(): void
    {
        $user = User::factory()->create();

        app(PreferenceManager::class)->updatePreferences($user, ['orders_push' => false]);

        $this->assertNotContains(
            CommunicationChannel::Push,
            $this->channelsFor($user, CommunicationCategory::OrderFulfilled)
        );
    }

    /**
     * And it must not have widened the one-time-code rule, which returns email
     * alone before any of this is reached.
     */
    public function test_one_time_codes_are_still_email_only(): void
    {
        $user = User::factory()->create();

        $codeCategories = array_filter(
            CommunicationCategory::cases(),
            fn (CommunicationCategory $category) => $category->carriesOneTimeCode(),
        );

        $this->assertNotEmpty($codeCategories, 'no category carries a one-time code any more');

        foreach ($codeCategories as $category) {
            $this->assertSame(
                [CommunicationChannel::Email],
                $this->channelsFor($user, $category),
                $category->value.' should be email only'
            );
        }
    }
}

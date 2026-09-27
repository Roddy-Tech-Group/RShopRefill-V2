<?php

namespace Tests\Feature\Notification;

use App\Domain\Communication\Enums\CommunicationCategory;
use App\Domain\Communication\Enums\CommunicationChannel;
use App\Domain\Communication\Services\PreferenceManager;
use App\Livewire\Dashboard\Settings\NotificationPreferences;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The per-topic notification switches.
 *
 * This component used to offer a switch per category and write a `channels`
 * array onto a `category` row. communication_preferences has neither column, so
 * every toggle raised an unknown column error, and the read path silently showed
 * everything as on because the filter it used could never match. Nothing mounted
 * it, which is the only reason that never reached a customer.
 *
 * The storage is one {topic}_email / {topic}_push column pair, and several
 * categories share a topic, so the page is keyed by topic. Both directions go
 * through PreferenceManager, the same service the dispatch path consults, so the
 * page cannot promise something the sender ignores.
 */
class NotificationPreferencePageTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_offers_a_switch_for_every_topic_a_customer_may_turn_off(): void
    {
        $user = User::factory()->create();

        $component = Livewire::actingAs($user)->test(NotificationPreferences::class);

        $topics = array_keys($component->get('preferences'));

        // Every disableable category's topic is represented, and nothing else.
        $expected = collect(CommunicationCategory::cases())
            ->filter(fn (CommunicationCategory $category) => $category->isUserDisableable())
            ->map(fn (CommunicationCategory $category) => $category->preferenceGroup())
            ->filter()
            ->unique()
            ->sort()
            ->values()
            ->all();

        sort($topics);

        $this->assertSame($expected, $topics);
        $this->assertNotEmpty($topics);
    }

    /** Opt-out: a customer who has never touched the page has everything on. */
    public function test_everything_starts_switched_on(): void
    {
        $user = User::factory()->create();

        $preferences = Livewire::actingAs($user)
            ->test(NotificationPreferences::class)
            ->get('preferences');

        foreach ($preferences as $topic => $channels) {
            $this->assertTrue($channels['email'], $topic.' email should default on');
            $this->assertTrue($channels['push'], $topic.' push should default on');
        }
    }

    /**
     * The assertion that would have caught the original bug: a toggle has to
     * land in the column the dispatcher reads, not just in the component state.
     */
    public function test_switching_push_off_is_what_the_dispatcher_then_reads(): void
    {
        $user = User::factory()->create();
        $manager = app(PreferenceManager::class);

        $this->assertTrue($manager->isAllowed($user, CommunicationCategory::OrderFulfilled, CommunicationChannel::Push));

        Livewire::actingAs($user)
            ->test(NotificationPreferences::class)
            ->call('togglePreference', 'orders', 'push')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('communication_preferences', [
            'user_id' => $user->id,
            'orders_push' => false,
        ]);

        // The real proof: the send path now refuses it.
        $this->assertFalse(
            app(PreferenceManager::class)->isAllowed($user, CommunicationCategory::OrderFulfilled, CommunicationChannel::Push)
        );
    }

    /** Email and push are independent, so one must not carry the other. */
    public function test_turning_off_push_leaves_email_alone(): void
    {
        $user = User::factory()->create();

        Livewire::actingAs($user)
            ->test(NotificationPreferences::class)
            ->call('togglePreference', 'orders', 'push');

        $this->assertTrue(
            app(PreferenceManager::class)->isAllowed($user, CommunicationCategory::OrderFulfilled, CommunicationChannel::Email)
        );
    }

    /** A toggle is reversible, and the page reflects it. */
    public function test_a_switch_goes_back_on(): void
    {
        $user = User::factory()->create();

        $component = Livewire::actingAs($user)->test(NotificationPreferences::class)
            ->call('togglePreference', 'wallet', 'push')
            ->call('togglePreference', 'wallet', 'push');

        $this->assertTrue($component->get('preferences')['wallet']['push']);
        $this->assertTrue(
            app(PreferenceManager::class)->isAllowed($user, CommunicationCategory::WalletFunding, CommunicationChannel::Push)
        );
    }

    /**
     * A topic or channel we do not offer is refused rather than written. An
     * unchecked name here would have been an arbitrary column update.
     */
    public function test_an_unknown_topic_or_channel_is_refused(): void
    {
        $user = User::factory()->create();

        Livewire::actingAs($user)->test(NotificationPreferences::class)
            ->call('togglePreference', 'security', 'push')
            ->call('togglePreference', 'orders', 'carrier_pigeon')
            ->assertHasNoErrors();

        // Nothing was written, so the defaults still stand.
        $this->assertTrue(
            app(PreferenceManager::class)->isAllowed($user, CommunicationCategory::OrderFulfilled, CommunicationChannel::Push)
        );
    }

    /** Mandatory topics are not on the page at all. */
    public function test_security_and_verification_are_not_offered(): void
    {
        $user = User::factory()->create();

        $topics = array_keys(
            Livewire::actingAs($user)->test(NotificationPreferences::class)->get('preferences')
        );

        foreach (['security', 'verify', 'compliance'] as $mandatory) {
            $this->assertNotContains($mandatory, $topics);
        }
    }

    /** It renders, which the old pairing could not do without erroring. */
    public function test_the_page_renders(): void
    {
        $user = User::factory()->create();

        Livewire::actingAs($user)->test(NotificationPreferences::class)
            ->assertOk()
            ->assertSee('Notification preferences')
            ->assertSee('Push notifications');
    }
}

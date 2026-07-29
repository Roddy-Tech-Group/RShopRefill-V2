<?php

namespace Tests\Feature\Pagination;

use App\Enums\TradeStatus;
use App\Models\GiftCardBrand;
use App\Models\GiftCardRate;
use App\Models\GiftCardTrade;
use App\Models\TradeMessage;
use App\Models\TrustedDevice;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Volt\Volt;
use Tests\TestCase;

/**
 * Customer-facing lists that were reading a whole table per render.
 *
 * 1. The trade detail page eager-loaded every trade_message on the trade and
 *    the chat pane polls every 2 seconds, so a long-running support thread was
 *    re-read from the database 30 times a minute for the life of the page.
 *    Only the newest slice may be read.
 *
 * 2. The security centre listed every trusted_devices row an account had ever
 *    collected, and re-saved each of them from inside the render path. It must
 *    show only the most recently seen devices.
 */
class CustomerListBoundsTest extends TestCase
{
    use RefreshDatabase;

    private function tradeFor(User $user): GiftCardTrade
    {
        $brand = GiftCardBrand::create(['name' => 'Test Brand', 'currency' => 'USD']);

        $rate = GiftCardRate::create([
            'brand_id' => $brand->id,
            'country_code' => 'US',
            'currency' => 'NGN',
            'min_value' => 10,
            'rate' => 1200,
        ]);

        return GiftCardTrade::create([
            'user_id' => $user->id,
            'rate_id' => $rate->id,
            'payout_method' => 'wallet',
            'declared_value' => 100,
            'calculated_payout' => 120000,
            'payout_currency' => 'NGN',
            'status' => TradeStatus::PendingReview,
        ]);
    }

    public function test_the_trade_chat_reads_only_the_newest_messages_on_render_and_on_every_poll(): void
    {
        $user = User::factory()->create();
        $trade = $this->tradeFor($user);

        foreach (range(1, 60) as $i) {
            TradeMessage::create([
                'trade_id' => $trade->id,
                'sender_type' => User::class,
                'sender_id' => $user->id,
                'message' => sprintf('msg-%03d', $i),
            ]);
        }

        $this->actingAs($user);

        $queries = [];
        DB::listen(function ($query) use (&$queries) {
            $queries[] = $query->sql;
        });

        $component = Volt::test('user.gift-cards.trades.show', ['trade' => $trade]);

        // Newest messages stay on screen, the oldest fall outside the window.
        $component->assertSee('msg-060')
            ->assertSee('msg-011')
            ->assertDontSee('msg-001')
            ->assertSee('Showing the 50 most recent messages.');

        // A poll tick must not turn back into a full-thread read.
        $component->call('refreshData')
            ->assertSee('msg-060')
            ->assertDontSee('msg-001');

        $unbounded = collect($queries)->filter(
            fn ($sql) => str_contains($sql, 'trade_messages')
                && ! str_contains(strtolower($sql), 'count(')
                && ! str_contains(strtolower($sql), 'limit')
        );

        $this->assertTrue(
            $unbounded->isEmpty(),
            "The trade chat must read a bounded slice of trade_messages. Offending SQL:\n".$unbounded->implode("\n")
        );
    }

    public function test_the_trade_chat_still_shows_a_message_the_customer_just_sent(): void
    {
        $user = User::factory()->create();
        $trade = $this->tradeFor($user);

        $this->actingAs($user);

        Volt::test('user.gift-cards.trades.show', ['trade' => $trade])
            ->set('new_message', 'Any update on this please')
            ->call('sendMessage')
            ->assertHasNoErrors()
            ->assertSee('Any update on this please');

        $this->assertDatabaseHas('trade_messages', [
            'trade_id' => $trade->id,
            'message' => 'Any update on this please',
        ]);
    }

    public function test_the_security_centre_lists_only_the_most_recent_trusted_devices(): void
    {
        $user = User::factory()->create();

        foreach (range(1, 15) as $i) {
            TrustedDevice::create([
                'user_id' => $user->id,
                'device_uuid' => 'uuid-'.$i,
                'fingerprint' => 'fingerprint-'.$i,
                'browser' => 'Chrome',
                'os' => 'Windows',
                'device_type' => 'desktop',
                'ip_address' => '198.51.100.7',
                'city' => sprintf('CityAAA%02d', $i),
                'user_agent' => 'Mozilla/5.0',
                'trusted_since' => now()->subDays(30),
                'last_seen_at' => now()->subMinutes($i),
            ]);
        }

        $this->actingAs($user);

        Volt::test('settings.security')
            ->assertSee('CityAAA01')
            ->assertSee('CityAAA10')
            ->assertDontSee('CityAAA11')
            ->assertDontSee('CityAAA15')
            ->assertSee('Showing your 10 most recently used devices.');
    }
}

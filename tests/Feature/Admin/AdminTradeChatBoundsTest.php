<?php

namespace Tests\Feature\Admin;

use App\Domain\Admin\Enums\AdminRole;
use App\Enums\TradeStatus;
use App\Models\Admin;
use App\Models\GiftCardBrand;
use App\Models\GiftCardRate;
use App\Models\GiftCardTrade;
use App\Models\TradeMessage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Volt\Volt;
use Tests\TestCase;

/**
 * The admin side of the trade detail page eager-loaded every trade_message on
 * the trade, and its chat pane polls every 2 seconds, so a long support thread
 * was re-read in full from the database 30 times a minute for as long as an
 * admin left the tab open. Only the newest slice may be read - same bound the
 * customer-facing copy of this component already carries.
 */
class AdminTradeChatBoundsTest extends TestCase
{
    use RefreshDatabase;

    private function asAdmin(): Admin
    {
        $admin = Admin::firstOrCreate(
            ['email' => 'trade-chat-admin@example.test'],
            ['name' => 'Trade Chat Admin', 'password' => 'password', 'role' => AdminRole::SuperAdmin, 'is_active' => true],
        );
        $this->actingAs($admin, 'admin');

        return $admin;
    }

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

    public function test_the_admin_trade_chat_reads_only_the_newest_messages_on_render_and_on_every_poll(): void
    {
        $this->withoutVite()->asAdmin();

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

        $queries = [];
        DB::listen(function ($query) use (&$queries) {
            $queries[] = $query->sql;
        });

        $component = Volt::test('admin.gift-cards.trades.show', ['trade' => $trade]);

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
            "The admin trade chat must read a bounded slice of trade_messages. Offending SQL:\n".$unbounded->implode("\n")
        );
    }

    public function test_the_admin_trade_chat_still_shows_a_message_the_admin_just_sent(): void
    {
        $this->withoutVite()->asAdmin();

        $user = User::factory()->create();
        $trade = $this->tradeFor($user);

        Volt::test('admin.gift-cards.trades.show', ['trade' => $trade])
            ->set('new_message', 'Please re-upload a clearer photo')
            ->call('sendMessage')
            ->assertHasNoErrors()
            ->assertSee('Please re-upload a clearer photo');

        $this->assertDatabaseHas('trade_messages', [
            'trade_id' => $trade->id,
            'message' => 'Please re-upload a clearer photo',
        ]);
    }

    public function test_a_need_more_info_status_change_still_surfaces_its_message_in_the_thread(): void
    {
        $this->withoutVite()->asAdmin();

        $user = User::factory()->create();
        $trade = $this->tradeFor($user);

        Volt::test('admin.gift-cards.trades.show', ['trade' => $trade])
            ->set('new_status', 'need_more_info')
            ->set('status_reason', 'The receipt photo is unreadable, please resend.')
            ->call('updateStatus')
            ->assertHasNoErrors()
            ->assertSee('The receipt photo is unreadable, please resend.');
    }
}

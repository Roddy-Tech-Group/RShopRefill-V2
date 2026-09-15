<?php

namespace Tests\Feature\Wallet;

use App\Models\PaymentDecline;
use App\Models\SiteSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Livewire\Volt\Volt;
use Tests\TestCase;

/**
 * What the top-up modal is told when the card-testing limiter holds a funding.
 *
 * The modal decides everything the customer sees from this one event: a short
 * pause counts the button down and stays out of the way, and only the six hour
 * lock replaces the step with a notice and a way to reach support. So the flag
 * that separates them has to arrive, and the wording has to be the server's.
 */
class FundingBackoffNoticeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.flutterwave.secret_key' => 'FLW_SECRET_KEY_MOCK']);
        SiteSetting::put('features.payment_backoff_enforce_enabled', 'on', 'features');
        Cache::flush();
    }

    private function refuseCards(User $user, int $count): void
    {
        foreach (range(1, $count) as $n) {
            PaymentDecline::create([
                'user_id' => $user->id,
                'product_line' => 'wallet_funding',
                'gateway' => 'flutterwave',
                'gateway_transaction_id' => 'fund-'.Str::random(10),
                'tx_ref' => 'ref-'.$user->id,
                'payment_method' => 'card',
                'card_fingerprint' => 'card-'.$n,
                'verdict' => 'Payment declined. Suspected Fraud',
                'verdict_class' => 'fraud',
                'amount' => 25,
                'currency' => 'USD',
                'declined_at' => now()->subSeconds(5),
            ]);
        }
    }

    public function test_a_locked_device_gets_the_lock_notice_inside_the_modal(): void
    {
        $user = User::factory()->create(['email_verified_at' => now()]);
        $this->refuseCards($user, 5);
        $this->actingAs($user);

        Volt::test('dashboard.fund-wallet', ['currency' => 'USD'])
            ->set('amount', '100')
            ->call('fund')
            ->assertDispatched('payment-backoff-triggered', function (string $event, array $params) {
                return $params['locked'] === true
                    && $params['seconds'] > 3600
                    && str_contains($params['message'], 'contact support');
            });
    }

    /**
     * A locked customer who reloads the dashboard gets the countdown on the top
     * up button immediately: the hold rides into the component on render.
     */
    public function test_the_top_up_sheet_renders_with_the_lock_already_known(): void
    {
        $user = User::factory()->create(['email_verified_at' => now()]);
        $this->refuseCards($user, 5);
        $this->actingAs($user);

        $hold = Volt::test('dashboard.fund-wallet', ['currency' => 'USD'])->instance()->paymentHold();

        $this->assertNotNull($hold);
        $this->assertTrue($hold['locked']);
        $this->assertGreaterThan(3600, $hold['wait_seconds']);
    }

    public function test_a_short_pause_is_not_flagged_as_a_lock(): void
    {
        $user = User::factory()->create(['email_verified_at' => now()]);
        $this->refuseCards($user, 3);
        $this->actingAs($user);

        Volt::test('dashboard.fund-wallet', ['currency' => 'USD'])
            ->set('amount', '100')
            ->call('fund')
            ->assertDispatched('payment-backoff-triggered', function (string $event, array $params) {
                return $params['locked'] === false
                    && $params['seconds'] <= 60
                    && str_starts_with($params['message'], 'Try again in');
            });
    }
}

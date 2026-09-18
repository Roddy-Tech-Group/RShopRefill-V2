<?php

namespace Tests\Feature\Rewards;

use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The rewards page says when Rcoin becomes available, and says what the code
 * does: immediately when the order completes, or after the admin fraud hold
 * when that is switched on. It used to promise a 48-hour wait that did not exist.
 */
class RcoinAvailabilityCopyTest extends TestCase
{
    use RefreshDatabase;

    private function rewardsPage(): string
    {
        $this->withoutVite();

        return $this->actingAs(User::factory()->create())
            ->get(route('dashboard.rewards'))
            ->assertOk()
            ->getContent();
    }

    public function test_rcoin_is_available_immediately_by_default(): void
    {
        Setting::set('fraud_hold_enabled', false, 'boolean');

        $html = $this->rewardsPage();

        $this->assertStringContainsString('becomes available immediately after the order completes.', $html);
        $this->assertStringNotContainsString('48 hours', $html);
    }

    public function test_the_fraud_hold_is_stated_when_it_is_on(): void
    {
        Setting::set('fraud_hold_enabled', true, 'boolean');
        Setting::set('fraud_hold_days', 3, 'integer');

        $this->assertStringContainsString('becomes available 3 days after the order completes.', $this->rewardsPage());
    }
}

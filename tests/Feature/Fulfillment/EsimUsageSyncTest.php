<?php

namespace Tests\Feature\Fulfillment;

use App\Domain\Fulfillment\Enums\EsimStatus;
use App\Domain\Fulfillment\Services\EsimRefundService;
use App\Domain\Fulfillment\Services\EsimUsageSync;
use App\Models\Esim;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * eSIM status and data, refreshed from the supplier's usage endpoint.
 * Airalo is always faked; nothing here reaches the real API.
 */
class EsimUsageSyncTest extends TestCase
{
    use RefreshDatabase;

    private const BASE = 'https://airalo.test/v2';

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.airalo.base_url' => self::BASE, 'app.timezone' => 'Africa/Douala']);
        Cache::put('airalo_access_token', 'test-token', now()->addHour());
        Http::preventStrayRequests();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function fakeUsage(string $iccid, array $data, int $status = 200): void
    {
        Http::fake([self::BASE."/sims/{$iccid}/usage" => Http::response(['data' => $data, 'meta' => ['message' => 'success']], $status)]);
    }

    public function test_an_active_esim_takes_the_suppliers_status_data_and_expiry(): void
    {
        $esim = Esim::factory()->create();
        $this->fakeUsage($esim->iccid, ['status' => 'ACTIVE', 'remaining' => 3000, 'total' => 5120, 'is_unlimited' => false, 'expired_at' => '2026-10-01 10:00:00']);

        $this->assertTrue(app(EsimUsageSync::class)->sync($esim));

        $esim->refresh();
        $this->assertSame(EsimStatus::Active, $esim->status);
        $this->assertSame('ACTIVE', $esim->provider_status);
        $this->assertSame(3000, $esim->data_remaining_mb);
        $this->assertSame(5120, $esim->data_total_mb);
        // 10:00 UTC is 11:00 in Douala.
        $this->assertSame('2026-10-01 11:00:00', $esim->expires_at->format('Y-m-d H:i:s'));
        $this->assertNotNull($esim->activated_at);
        $this->assertNotNull($esim->last_synced_at);
        $this->assertNull($esim->sync_error);
        Http::assertSent(fn ($request) => $request->hasHeader('Authorization', 'Bearer test-token'));
    }

    public function test_an_esim_with_a_quarter_of_its_data_left_is_running_low(): void
    {
        $esim = Esim::factory()->create();
        $this->fakeUsage($esim->iccid, ['status' => 'ACTIVE', 'remaining' => 1000, 'total' => 5120, 'is_unlimited' => false]);

        app(EsimUsageSync::class)->sync($esim);

        $this->assertSame(EsimStatus::DataLow, $esim->refresh()->status);
    }

    /**
     * What Airalo returned for three real eSIMs on 2026-09-22, whose dashboard
     * said Not active, Active and Refunded. The API has no refunded status: a
     * refund takes the data package away, leaving NOT_ACTIVE with 0 MB.
     */
    public function test_the_three_airalo_states_read_as_they_do_on_airalos_dashboard(): void
    {
        $unused = Esim::factory()->create(['iccid' => '89852350726130140712']);
        $active = Esim::factory()->create(['iccid' => '89852350726129316398']);
        $refunded = Esim::factory()->create(['iccid' => '89852350726130230174']);

        Http::fake([
            self::BASE.'/sims/89852350726130140712/usage' => Http::response(['data' => ['remaining' => 1024, 'total' => 1024, 'expired_at' => null, 'is_unlimited' => false, 'status' => 'NOT_ACTIVE']]),
            self::BASE.'/sims/89852350726129316398/usage' => Http::response(['data' => ['remaining' => 19611, 'total' => 20480, 'expired_at' => '2027-09-10 20:09:22', 'is_unlimited' => false, 'status' => 'ACTIVE']]),
            self::BASE.'/sims/89852350726130230174/usage' => Http::response(['data' => ['remaining' => 0, 'total' => 0, 'expired_at' => null, 'is_unlimited' => false, 'status' => 'NOT_ACTIVE']]),
        ]);

        $sync = app(EsimUsageSync::class);
        $sync->sync($unused);
        $sync->sync($active);
        $sync->sync($refunded);

        $this->assertSame(EsimStatus::Inactive, $unused->refresh()->status);
        $this->assertSame(EsimStatus::Active, $active->refresh()->status);
        $this->assertSame(EsimStatus::Refunded, $refunded->refresh()->status);

        // Refunded at the provider: never switched on, and never offered again.
        $this->assertNull($refunded->activated_at);
        $this->assertSame('This eSIM was already refunded.', app(EsimRefundService::class)->blockedReason($refunded));
        $this->assertNull(app(EsimRefundService::class)->blockedReason($unused));
    }

    /**
     * An eSIM whose refund we requested stays Refund pending while the
     * provider processes it, even once the provider has taken the package.
     */
    public function test_a_pending_refund_stays_pending_when_the_provider_removes_the_package(): void
    {
        $esim = Esim::factory()->create(['status' => EsimStatus::RefundPending, 'refund_status' => Esim::REFUND_PENDING]);
        $this->fakeUsage($esim->iccid, ['status' => 'NOT_ACTIVE', 'remaining' => 0, 'total' => 0, 'is_unlimited' => false]);

        app(EsimUsageSync::class)->sync($esim);

        $this->assertSame(EsimStatus::RefundPending, $esim->refresh()->status);
    }

    public function test_an_esim_not_yet_installed_stays_inactive_with_no_activation_time(): void
    {
        $esim = Esim::factory()->create();
        $this->fakeUsage($esim->iccid, ['status' => 'NOT_ACTIVE', 'remaining' => 5120, 'total' => 5120, 'is_unlimited' => false]);

        app(EsimUsageSync::class)->sync($esim);

        $esim->refresh();
        $this->assertSame(EsimStatus::Inactive, $esim->status);
        $this->assertNull($esim->activated_at);
    }

    /**
     * The first time we saw it active stays the activation time; later syncs
     * never move it forward.
     */
    public function test_the_activation_time_is_set_once(): void
    {
        $firstSeen = now()->subDays(3)->startOfSecond();
        $esim = Esim::factory()->active()->create(['activated_at' => $firstSeen]);
        $this->fakeUsage($esim->iccid, ['status' => 'ACTIVE', 'remaining' => 2000, 'total' => 5120, 'is_unlimited' => false]);

        app(EsimUsageSync::class)->sync($esim);

        $this->assertTrue($esim->refresh()->activated_at->equalTo($firstSeen));
    }

    public function test_a_refunded_esim_stays_refunded_whatever_the_supplier_says(): void
    {
        $esim = Esim::factory()->refunded()->create();
        $this->fakeUsage($esim->iccid, ['status' => 'NOT_ACTIVE', 'remaining' => 5120, 'total' => 5120, 'is_unlimited' => false]);

        app(EsimUsageSync::class)->sync($esim);

        $esim->refresh();
        $this->assertSame(EsimStatus::Refunded, $esim->status);
        $this->assertSame('NOT_ACTIVE', $esim->provider_status);
    }

    /**
     * A supplier outage never turns a known state into a guess.
     */
    public function test_a_failed_read_keeps_the_known_state(): void
    {
        $esim = Esim::factory()->active(2048)->create();
        $this->fakeUsage($esim->iccid, ['message' => 'server error'], 500);

        $this->assertFalse(app(EsimUsageSync::class)->sync($esim));

        $esim->refresh();
        $this->assertSame(EsimStatus::Active, $esim->status);
        $this->assertSame(2048, $esim->data_remaining_mb);
        $this->assertNotNull($esim->sync_error);
    }

    public function test_an_esim_from_another_supplier_is_left_as_recorded(): void
    {
        $esim = Esim::factory()->create(['provider' => 'zendit']);

        $this->assertFalse(app(EsimUsageSync::class)->sync($esim));

        Http::assertNothingSent();
    }

    // ---------------------------------------------------------- command

    public function test_the_command_refreshes_the_least_recently_checked_and_skips_settled_esims(): void
    {
        $never = Esim::factory()->create(['last_synced_at' => null]);
        $stale = Esim::factory()->create(['last_synced_at' => now()->subDay()]);
        $fresh = Esim::factory()->create(['last_synced_at' => now()->subMinute()]);
        $expired = Esim::factory()->create(['status' => EsimStatus::Expired, 'last_synced_at' => null]);
        $refunded = Esim::factory()->refunded()->create(['last_synced_at' => null]);

        Http::fake([self::BASE.'/sims/*/usage' => Http::response(['data' => ['status' => 'ACTIVE', 'remaining' => 3000, 'total' => 5120, 'is_unlimited' => false]])]);

        $this->artisan('esims:sync-status', ['--limit' => 2])
            ->expectsOutputToContain('2 eSIMs refreshed; 0 the supplier did not answer for.')
            ->assertSuccessful();

        $this->assertSame(EsimStatus::Active, $never->refresh()->status);
        $this->assertSame(EsimStatus::Active, $stale->refresh()->status);
        $this->assertSame(EsimStatus::Inactive, $fresh->refresh()->status);
        Http::assertNotSent(fn ($request) => str_contains($request->url(), $expired->iccid) || str_contains($request->url(), $refunded->iccid));
    }

    public function test_the_command_can_refresh_one_esim_by_iccid(): void
    {
        $target = Esim::factory()->create();
        Esim::factory()->create();
        $this->fakeUsage($target->iccid, ['status' => 'FINISHED', 'remaining' => 0, 'total' => 5120, 'is_unlimited' => false]);

        $this->artisan('esims:sync-status', ['--iccid' => $target->iccid])
            ->expectsOutputToContain('1 eSIMs refreshed')
            ->assertSuccessful();

        $this->assertSame(EsimStatus::DataUsedUp, $target->refresh()->status);
    }

    // ---------------------------------------------------------- webhook record

    public function test_a_low_data_webhook_marks_the_record_running_low(): void
    {
        $esim = Esim::factory()->active()->create();
        $esim->orderItem->update(['fulfillment_payload' => ['iccid' => $esim->iccid]]);
        $this->withoutMiddleware();

        $this->postJson(route('api.webhooks.airalo'), [
            'iccid' => $esim->iccid,
            'event_type' => 'DATA_LOW',
            'usage_percentage' => 75,
            'expire_at' => '2026-10-01T08:30:00Z',
        ])->assertOk();

        $esim->refresh();
        $this->assertSame(EsimStatus::DataLow, $esim->status);
        $this->assertSame('2026-10-01 09:30:00', $esim->expires_at->format('Y-m-d H:i:s'));
    }
}

<?php

namespace Tests\Feature\Risk;

use App\Domain\Payment\Enums\PaymentStatus;
use App\Domain\Risk\Contracts\RiskSignal;
use App\Domain\Risk\DTOs\RiskContext;
use App\Domain\Risk\Signals\ChargebackHistorySignal;
use App\Domain\Risk\Signals\ChargebackRepeatSignal;
use App\Domain\Risk\Signals\DeviceSharedSignal;
use App\Domain\Risk\Signals\EmailPhoneSimilaritySignal;
use App\Domain\Risk\Signals\GeoVelocitySignal;
use App\Domain\Risk\Signals\IpSharedSignal;
use App\Domain\Risk\Signals\PaymentInstrumentReuseSignal;
use App\Models\Order;
use App\Models\PaymentAttempt;
use App\Models\RiskAssessment;
use App\Models\RiskLabel;
use App\Models\TrustedDevice;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Stage 3 linkage/graph + feedback signals in isolation: each fires on the multi-
 * account / historical shape it targets and stays silent otherwise.
 */
class RiskLinkageSignalsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Pin the production linkage thresholds so these tests document the
        // policy: family cards and office IPs stay silent, farms fire. Devices
        // are stricter since the first production card-testing pair was two
        // accounts on one phone.
        config([
            'risk.thresholds.device_shared_max_accounts' => 1,
            'risk.thresholds.ip_shared_max_accounts' => 5,
            'risk.thresholds.ip_shared_window_hours' => 24,
            'risk.thresholds.instrument_reuse_max_accounts' => 2,
            'risk.thresholds.instrument_reuse_window_days' => 30,
            'risk.thresholds.similarity_max_accounts' => 1,
            'risk.thresholds.geo_velocity_window_hours' => 6,
            'risk.thresholds.chargeback_history_lookback_days' => 365,
            'risk.thresholds.chargeback_repeat_min' => 2,
        ]);
    }

    private function ctx(array $o = []): RiskContext
    {
        return new RiskContext(
            user: $o['user'] ?? null,
            context: 'order',
            amountUsd: $o['amountUsd'] ?? 0.0,
            ip: $o['ip'] ?? null,
            deviceUuid: $o['deviceUuid'] ?? null,
        );
    }

    private function assertFires(RiskSignal $s, RiskContext $c): void
    {
        $this->assertNotNull($s->evaluate($c), $s->code().' should fire');
    }

    private function assertSilent(RiskSignal $s, RiskContext $c): void
    {
        $this->assertNull($s->evaluate($c), $s->code().' should stay silent');
    }

    private function device(int $userId, string $uuid, array $extra = []): void
    {
        TrustedDevice::create(array_merge([
            'user_id' => $userId,
            'device_uuid' => $uuid,
            'fingerprint' => 'fp-'.$userId.'-'.$uuid,
        ], $extra));
    }

    public function test_device_shared_fires_on_a_second_account_but_never_on_one(): void
    {
        $signal = app(DeviceSharedSignal::class);

        // A device with a single account does not fire.
        $solo = User::factory()->create();
        $this->device($solo->id, 'solo-dev');
        $this->assertSilent($signal, $this->ctx(['user' => $solo, 'deviceUuid' => 'solo-dev']));

        // Two accounts on one phone: the exact shape of the first card-testing
        // pair caught in production (users 400 and 428, seven refused cards
        // between them). It adds weight; at 25 points it holds nobody alone.
        $a = User::factory()->create();
        $b = User::factory()->create();
        $this->device($a->id, 'shared-dev');
        $this->device($b->id, 'shared-dev');
        $this->assertFires($signal, $this->ctx(['user' => $a, 'deviceUuid' => 'shared-dev']));

        // A farm keeps firing.
        $c = User::factory()->create();
        $this->device($c->id, 'shared-dev');
        $this->assertFires($signal, $this->ctx(['user' => $a, 'deviceUuid' => 'shared-dev']));
    }

    public function test_ip_shared_fires_on_bulk_accounts_but_spares_cgnat_scale_sharing(): void
    {
        $signal = app(IpSharedSignal::class);

        $seed = function (string $ip, int $accounts): void {
            foreach (range(1, $accounts) as $_) {
                RiskAssessment::create([
                    'user_id' => User::factory()->create()->id,
                    'context' => 'order', 'score' => 0, 'band' => 'LOW', 'outcome' => 'allow',
                    'enforced' => false, 'amount_usd' => 0, 'ip_address' => $ip,
                ]);
            }
        };

        // Five distinct accounts on one IP in a day: normal carrier NAT, silent.
        $seed('198.51.100.50', 5);
        $this->assertSilent($signal, $this->ctx(['ip' => '198.51.100.50']));

        // Six or more: fires.
        $seed('198.51.100.60', 6);
        $this->assertFires($signal, $this->ctx(['ip' => '198.51.100.60']));

        $this->assertSilent($signal, $this->ctx(['ip' => '198.51.100.99']));
    }

    public function test_email_similarity_via_plus_aliasing(): void
    {
        $signal = app(EmailPhoneSimilaritySignal::class);
        $a = User::factory()->create(['email' => 'farmer@gmail.com']);
        User::factory()->create(['email' => 'farmer+1@gmail.com']);

        $this->assertFires($signal, $this->ctx(['user' => $a]));

        $lonely = User::factory()->create(['email' => 'unique-person@gmail.com', 'phone' => '+15550000001']);
        $this->assertSilent($signal, $this->ctx(['user' => $lonely]));
    }

    public function test_phone_similarity_across_accounts(): void
    {
        $signal = app(EmailPhoneSimilaritySignal::class);
        $a = User::factory()->create(['phone' => '+2348030000000', 'email' => 'a-distinct@x.com']);
        User::factory()->create(['phone' => '+2348030000000', 'email' => 'b-distinct@x.com']);

        $this->assertFires($signal, $this->ctx(['user' => $a]));
    }

    public function test_payment_instrument_reuse_fires_at_three_accounts_not_two(): void
    {
        $signal = app(PaymentInstrumentReuseSignal::class);
        $a = User::factory()->create();
        $b = User::factory()->create();
        $this->attemptWithFingerprint($a->id, 'card-fp-9');
        $this->attemptWithFingerprint($b->id, 'card-fp-9');

        // Two accounts on one card: a family/business card, silent.
        $this->assertSilent($signal, $this->ctx(['user' => $a]));

        // Three accounts on one card: fires.
        $c = User::factory()->create();
        $this->attemptWithFingerprint($c->id, 'card-fp-9');
        $this->assertFires($signal, $this->ctx(['user' => $a]));

        // A user with no stored fingerprint abstains (typed slot).
        $clean = User::factory()->create();
        $this->assertSilent($signal, $this->ctx(['user' => $clean]));
    }

    /**
     * The signal compares ISO codes, not country names: names are the
     * provider's own spelling, so a provider swap would read as travel.
     */
    public function test_geo_velocity_two_countries_in_window(): void
    {
        $signal = app(GeoVelocitySignal::class);
        $u = User::factory()->create();
        $this->device($u->id, 'dev-ng', ['country' => 'Nigeria', 'country_code' => 'NG', 'last_seen_at' => now()->subMinutes(10)]);
        $this->device($u->id, 'dev-gb', ['country' => 'United Kingdom', 'country_code' => 'GB', 'last_seen_at' => now()->subMinutes(5)]);

        $this->assertFires($signal, $this->ctx(['user' => $u]));

        $single = User::factory()->create();
        $this->device($single->id, 'dev-one', ['country' => 'Nigeria', 'country_code' => 'NG', 'last_seen_at' => now()]);
        $this->assertSilent($signal, $this->ctx(['user' => $single]));
    }

    /**
     * A device resolved before the code column existed has a name and no code.
     * It is skipped rather than guessed at, so the signal can only ever be
     * quieter while devices:backfill-countries catches history up, never wrong.
     */
    public function test_geo_velocity_skips_devices_with_no_resolved_code(): void
    {
        $signal = app(GeoVelocitySignal::class);
        $u = User::factory()->create();
        $this->device($u->id, 'dev-old', ['country' => 'Nigeria', 'country_code' => null, 'last_seen_at' => now()->subMinutes(10)]);
        $this->device($u->id, 'dev-new', ['country' => 'United Kingdom', 'country_code' => 'GB', 'last_seen_at' => now()->subMinutes(5)]);

        $this->assertSilent($signal, $this->ctx(['user' => $u]));
    }

    public function test_chargeback_history_is_scored_not_override(): void
    {
        $signal = app(ChargebackHistorySignal::class);
        $u = User::factory()->create();
        RiskLabel::create(['user_id' => $u->id, 'label' => RiskLabel::LABEL_CHARGEBACK, 'source' => 'admin']);

        $this->assertFires($signal, $this->ctx(['user' => $u]));
        $this->assertSilent($signal, $this->ctx(['user' => User::factory()->create()]));
    }

    public function test_chargeback_repeat_fires_only_on_a_pattern(): void
    {
        $signal = app(ChargebackRepeatSignal::class);
        $u = User::factory()->create();

        // One dispute: History fires (elsewhere), Repeat stays silent.
        RiskLabel::create(['user_id' => $u->id, 'label' => RiskLabel::LABEL_CHARGEBACK, 'source' => 'admin']);
        $this->assertSilent($signal, $this->ctx(['user' => $u]));

        // A second dispute: the pattern fires.
        RiskLabel::create(['user_id' => $u->id, 'label' => RiskLabel::LABEL_CHARGEBACK, 'source' => 'webhook']);
        $this->assertFires($signal, $this->ctx(['user' => $u]));
    }

    private function attemptWithFingerprint(int $userId, string $fingerprint): void
    {
        $order = Order::factory()->create(['user_id' => $userId]);

        PaymentAttempt::create([
            'order_id' => $order->id,
            'user_id' => $userId,
            'gateway' => 'flutterwave',
            'idempotency_key' => 'pa-'.$userId.'-'.$fingerprint,
            'currency' => 'USD',
            'amount' => 10.00,
            'payment_status' => PaymentStatus::Pending,
            'metadata' => ['card_fingerprint' => $fingerprint],
        ]);
    }
}

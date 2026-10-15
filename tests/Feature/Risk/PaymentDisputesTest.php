<?php

namespace Tests\Feature\Risk;

use App\Domain\Admin\Enums\AdminRole;
use App\Domain\Risk\Enums\DisputeStatus;
use App\Domain\Shared\Services\OperationalAlerter;
use App\Enums\AlertSeverity;
use App\Jobs\VerifyPaymentJob;
use App\Models\Admin;
use App\Models\Order;
use App\Models\PaymentAttempt;
use App\Models\PaymentDispute;
use App\Models\RiskLabel;
use App\Models\SiteSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Disputes and chargebacks from Flutterwave, Kora and Paystack: each lands as
 * one payment_disputes row matched to our payment, labels the customer for the
 * risk engine, and pages with its deadline, whether it came by webhook or by
 * the hourly chargeback-list sync. Nothing is answered, refunded or blocked.
 */
class PaymentDisputesTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<array<string, mixed>> */
    private array $alerts = [];

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.flutterwave.webhook_hash' => 'FLW_SECURE_HASH',
            'services.flutterwave.secret_key' => 'FLWSECK-live-disputes-test',
            'services.korapay.secret_key' => 'sk_live_korapay_realish',
            'services.paystack.secret_key' => 'sk_test_realish',
        ]);
        Cache::flush();

        $alerts = &$this->alerts;
        $this->app->instance(OperationalAlerter::class, new class($alerts) extends OperationalAlerter
        {
            public function __construct(private array &$sent) {}

            public function send(AlertSeverity $severity, string $key, string $title, string $message, array $context = [], ?string $runbook = null): bool
            {
                $this->sent[] = compact('severity', 'key', 'title', 'message', 'context');

                return true;
            }
        });
    }

    /** @param  array<string, mixed>  $overrides */
    private function paidAttempt(string $gateway, array $overrides = []): PaymentAttempt
    {
        $user = User::factory()->create();
        $order = Order::factory()->create(['user_id' => $user->id]);

        return PaymentAttempt::create(array_merge([
            'order_id' => $order->id,
            'user_id' => $user->id,
            'gateway' => $gateway,
            'gateway_reference' => (string) random_int(10000000, 99999999),
            'idempotency_key' => 'PAY-'.Str::lower(Str::random(12)),
            'currency' => 'USD',
            'amount' => 25,
            'payment_status' => 'paid',
        ], $overrides));
    }

    /** @param  array<string, mixed>  $data */
    private function flutterwaveWebhook(string $event, array $data): TestResponse
    {
        return $this->postJson('/api/webhooks/flutterwave', ['event' => $event, 'data' => $data], ['verif-hash' => 'FLW_SECURE_HASH']);
    }

    /** @param  array<string, mixed>  $payload */
    private function korapayWebhook(array $payload): TestResponse
    {
        return $this->call('POST', '/api/webhooks/korapay', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_KORAPAY_SIGNATURE' => hash_hmac('sha256', json_encode($payload['data']), 'sk_live_korapay_realish'),
        ], json_encode($payload));
    }

    /** @param  array<string, mixed>  $payload */
    private function paystackWebhook(array $payload): TestResponse
    {
        $body = json_encode($payload);

        return $this->call('POST', '/api/webhooks/paystack', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_PAYSTACK_SIGNATURE' => hash_hmac('sha512', $body, 'sk_test_realish'),
        ], $body);
    }

    /** @return array<string, mixed> */
    private function flutterwaveChargeback(int $id, string $flwRef, string $status = 'initiated'): array
    {
        return [
            'id' => $id,
            'flw_ref' => $flwRef,
            'amount' => 25,
            'comment' => 'Product/service not received',
            'status' => $status,
            'stage' => 'new',
            'due_date' => now()->addDays(2)->utc()->format('Y-m-d\TH:i:s.000\Z'),
            'type' => 'local',
            'created_at' => now()->utc()->format('Y-m-d\TH:i:s.000\Z'),
            'account_id' => 1092727,
        ];
    }

    private function setGateway(string $gateway, bool $on): void
    {
        SiteSetting::put("features.gateway_{$gateway}_enabled", $on ? 'on' : 'off', 'features');
    }

    // --- Flutterwave webhook ------------------------------------------------------

    /**
     * Flutterwave's chargeback webhook names the payment only by flw_ref, which
     * we hold inside the charge's verification payload.
     */
    public function test_a_flutterwave_chargeback_is_matched_by_flw_ref_labelled_and_paged(): void
    {
        Bus::fake();
        $attempt = $this->paidAttempt('flutterwave', ['verification_payload' => ['status' => 'success', 'data' => ['flw_ref' => 'FLW-REF-77', 'id' => 10389272]]]);

        $this->flutterwaveWebhook('chargeback.initiated', $this->flutterwaveChargeback(22221, 'FLW-REF-77'))->assertOk();

        $dispute = PaymentDispute::sole();
        $this->assertSame('flutterwave', $dispute->gateway);
        $this->assertSame('22221', $dispute->gateway_dispute_id);
        $this->assertSame($attempt->id, $dispute->payment_attempt_id);
        $this->assertSame($attempt->order_id, $dispute->order_id);
        $this->assertSame($attempt->user_id, $dispute->user_id);
        $this->assertSame(DisputeStatus::Open, $dispute->status);
        $this->assertSame('USD', $dispute->currency, 'the webhook gives no currency; ours fills it');
        $this->assertSame('webhook', $dispute->source);

        $label = RiskLabel::sole();
        $this->assertSame(RiskLabel::LABEL_CHARGEBACK, $label->label);
        $this->assertSame($attempt->user_id, $label->user_id);
        $this->assertSame('flutterwave', $label->source);

        $this->assertCount(1, $this->alerts);
        $this->assertSame(AlertSeverity::Sev1, $this->alerts[0]['severity']);
        $this->assertStringContainsString('New Flutterwave dispute: USD 25.00', $this->alerts[0]['title']);
        $this->assertStringContainsString('Answer by', $this->alerts[0]['message']);

        // A chargeback is not a payment to verify.
        Bus::assertNotDispatched(VerifyPaymentJob::class);
        $this->assertDatabaseHas('payment_webhooks', ['event_type' => 'chargeback.initiated', 'processing_status' => 'processed', 'reference' => '22221']);
    }

    /**
     * The same chargeback id comes back as each step happens. The replay guard
     * used to key Flutterwave events on data.id alone, which would have
     * dropped "lost" as a repeat of "initiated".
     */
    public function test_each_later_step_updates_the_same_dispute_and_pages_once_on_the_outcome(): void
    {
        Bus::fake();
        $attempt = $this->paidAttempt('flutterwave', ['verification_payload' => ['data' => ['flw_ref' => 'FLW-REF-88']]]);

        $this->flutterwaveWebhook('chargeback.initiated', $this->flutterwaveChargeback(5001, 'FLW-REF-88'))->assertOk();
        $this->flutterwaveWebhook('chargeback.declined', $this->flutterwaveChargeback(5001, 'FLW-REF-88', 'declined'))->assertOk();
        $this->flutterwaveWebhook('chargeback.lost', $this->flutterwaveChargeback(5001, 'FLW-REF-88', 'lost'))->assertOk();

        $dispute = PaymentDispute::sole();
        $this->assertSame(DisputeStatus::Lost, $dispute->status);
        $this->assertSame('lost', $dispute->gateway_status);
        $this->assertNotNull($dispute->resolved_at);
        $this->assertSame(1, RiskLabel::count(), 'one dispute, one label');

        $this->assertSame(['dispute:opened:'.$dispute->id, 'dispute:resolved:'.$dispute->id], array_column($this->alerts, 'key'));
        $this->assertStringContainsString('decided for the customer', $this->alerts[1]['message']);
        $this->assertSame($attempt->id, $dispute->payment_attempt_id);
    }

    public function test_a_won_dispute_keeps_its_label_marked_won(): void
    {
        Bus::fake();
        $this->paidAttempt('flutterwave', ['verification_payload' => ['data' => ['flw_ref' => 'FLW-REF-99']]]);

        $this->flutterwaveWebhook('chargeback.initiated', $this->flutterwaveChargeback(6001, 'FLW-REF-99'))->assertOk();
        $this->flutterwaveWebhook('chargeback.won', $this->flutterwaveChargeback(6001, 'FLW-REF-99', 'won'))->assertOk();

        $this->assertSame(DisputeStatus::Won, PaymentDispute::sole()->status);
        $this->assertStringEndsWith('(won)', RiskLabel::sole()->note);
        $this->assertSame(RiskLabel::LABEL_CHARGEBACK, RiskLabel::sole()->label);
    }

    public function test_a_late_stale_notice_does_not_reopen_a_decided_dispute(): void
    {
        Bus::fake();
        $this->paidAttempt('flutterwave', ['verification_payload' => ['data' => ['flw_ref' => 'FLW-REF-11']]]);

        $this->flutterwaveWebhook('chargeback.lost', $this->flutterwaveChargeback(7001, 'FLW-REF-11', 'lost'))->assertOk();
        $this->flutterwaveWebhook('chargeback.initiated', $this->flutterwaveChargeback(7001, 'FLW-REF-11'))->assertOk();

        $this->assertSame(DisputeStatus::Lost, PaymentDispute::sole()->status);
    }

    public function test_a_dispute_on_no_payment_of_ours_is_kept_and_flagged_for_a_person(): void
    {
        Bus::fake();

        $this->flutterwaveWebhook('chargeback.initiated', $this->flutterwaveChargeback(8001, 'FLW-UNKNOWN'))->assertOk();

        $dispute = PaymentDispute::sole();
        $this->assertNull($dispute->user_id);
        $this->assertSame('FLW-UNKNOWN', $dispute->gateway_payment_reference);
        $this->assertSame(0, RiskLabel::count());
        $this->assertStringContainsString('trace it by hand', $this->alerts[0]['message']);
    }

    // --- Kora and Paystack webhooks -----------------------------------------------

    public function test_a_kora_chargeback_is_matched_and_its_utc_deadline_kept_in_app_time(): void
    {
        $attempt = $this->paidAttempt('korapay', ['currency' => 'XAF', 'amount' => 6560, 'gateway_reference' => 'KPY-CM-bGYRoeXuQd9G25x']);

        $this->korapayWebhook([
            'event' => 'chargeback.pending',
            'data' => [
                'status' => 'pending',
                'payment' => ['status' => 'success', 'reference' => 'KPY-CM-bGYRoeXuQd9G25x', 'amount_paid' => 6560],
                'currency' => 'XAF',
                'deadline' => '2026-10-20T10:00:00.000Z',
                'reference' => 'KPY-CHG-xYvpQYHw7wYIfzs',
                'created_at' => '2026-10-20T08:00:00.000Z',
                'payment_method' => 'mobile_money',
                'chargeback_amount' => 6560,
                'chargeback_reason' => 'Customer says the airtime never arrived',
            ],
        ])->assertOk();

        $dispute = PaymentDispute::sole();
        $this->assertSame('korapay', $dispute->gateway);
        $this->assertSame('KPY-CHG-xYvpQYHw7wYIfzs', $dispute->gateway_dispute_id);
        $this->assertSame($attempt->id, $dispute->payment_attempt_id);
        $this->assertSame('XAF 6,560.00', $dispute->amountLabel());
        $this->assertSame('Customer says the airtime never arrived', $dispute->reason);

        $expected = Carbon::parse('2026-10-20T10:00:00Z')->setTimezone(config('app.timezone'))->format('Y-m-d H:i:s');
        $this->assertSame($expected, DB::table('payment_disputes')->value('due_at'), 'stored in app time, not as raw UTC');
        $this->assertSame(1, RiskLabel::count());
    }

    public function test_a_paystack_dispute_is_matched_by_reference_and_resolved_by_its_resolution(): void
    {
        $attempt = $this->paidAttempt('paystack', ['currency' => 'NGN', 'amount' => 15000]);
        $dispute = fn (string $status, ?string $resolution) => [
            'id' => 358950,
            'refund_amount' => 1500000,
            'currency' => 'NGN',
            'status' => $status,
            'resolution' => $resolution,
            'category' => 'chargeback',
            'transaction' => ['id' => 1, 'reference' => $attempt->idempotency_key, 'amount' => 1500000, 'currency' => 'NGN'],
            'dueAt' => '2026-10-21T18:00:00.000Z',
            'createdAt' => '2026-10-20T18:00:00.000Z',
        ];

        $this->paystackWebhook(['event' => 'charge.dispute.create', 'data' => $dispute('awaiting-merchant-feedback', null)])->assertOk();

        $row = PaymentDispute::sole();
        $this->assertSame($attempt->id, $row->payment_attempt_id);
        $this->assertSame('NGN 15,000.00', $row->amountLabel(), 'Paystack amounts arrive in kobo');
        $this->assertSame(DisputeStatus::Open, $row->status);

        $this->paystackWebhook(['event' => 'charge.dispute.resolve', 'data' => $dispute('resolved', 'declined')])->assertOk();

        $this->assertSame(DisputeStatus::Won, $row->fresh()->status);
        $this->assertSame('resolved:declined', $row->fresh()->gateway_status);
    }

    // --- Hourly sync --------------------------------------------------------------

    public function test_the_sync_reads_flutterwaves_list_and_a_later_webhook_updates_the_same_row(): void
    {
        Bus::fake();
        $this->setGateway('korapay', false);
        $attempt = $this->paidAttempt('flutterwave');

        Http::fake([
            'api.flutterwave.com/v3/chargebacks*' => Http::response([
                'status' => 'success',
                'message' => 'Chargebacks fetched',
                'meta' => ['page_info' => ['total' => 1, 'current_page' => 1, 'total_pages' => 1, 'page_size' => 20]],
                'data' => [[
                    'id' => 1603,
                    'amount' => 25,
                    'flw_ref' => 'FLW-MOCK-05239b31',
                    'status' => 'initiated',
                    'stage' => 'new',
                    'comment' => 'Fraud dispute',
                    'due_date' => now()->addDays(5)->utc()->format('Y-m-d\TH:i:s.000\Z'),
                    'created_at' => now()->utc()->format('Y-m-d\TH:i:s.000\Z'),
                    'transaction_id' => (int) $attempt->gateway_reference,
                    'tx_ref' => $attempt->idempotency_key,
                ]],
            ]),
        ]);

        $this->artisan('disputes:sync')
            ->expectsOutputToContain('flutterwave: 1 dispute(s)')
            ->expectsOutputToContain('korapay: skipped (not live).')
            ->assertSuccessful();

        $dispute = PaymentDispute::sole();
        $this->assertSame($attempt->id, $dispute->payment_attempt_id);
        $this->assertSame('sync', $dispute->source);
        Http::assertNotSent(fn (Request $request) => str_contains($request->url(), 'korapay'));

        // Running again, and the webhook arriving after, change the one row.
        $this->artisan('disputes:sync')->assertSuccessful();
        $this->flutterwaveWebhook('chargeback.lost', $this->flutterwaveChargeback(1603, 'FLW-MOCK-05239b31', 'lost'))->assertOk();

        $this->assertSame(1, PaymentDispute::count());
        $this->assertSame(DisputeStatus::Lost, PaymentDispute::sole()->status);
        $this->assertSame(1, RiskLabel::count());
        $this->assertSame(1, collect($this->alerts)->where('key', 'dispute:opened:'.$dispute->id)->count(), 'paged once, not every hour');
    }

    public function test_the_sync_reads_kora_only_once_its_gateway_is_on(): void
    {
        $this->setGateway('korapay', true);
        $attempt = $this->paidAttempt('korapay', ['currency' => 'XAF']);

        Http::fake([
            'api.flutterwave.com/*' => Http::response(['status' => 'success', 'data' => [], 'meta' => ['page_info' => ['total_pages' => 1]]]),
            'api.korapay.com/api/v1/chargebacks*' => Http::response([
                'status' => true,
                'message' => 'Chargebacks retrieved successfully',
                'data' => ['data' => [[
                    'reference' => 'KPY-CHG-9w905W7QY2A9jzw',
                    'payment_reference' => 'KPY-PAY-ZKEUshaeZcC1pzLB',
                    'transaction_reference' => $attempt->idempotency_key,
                    'status' => 'pending',
                    'currency' => 'XAF',
                    'amount' => '6560.00',
                    'deadline' => now()->addHours(10)->utc()->format('Y-m-d\TH:i:s.000\Z'),
                    'reason' => 'testing',
                    'created_at' => now()->utc()->format('Y-m-d H:i:s'),
                ]]],
            ]),
        ]);

        $this->artisan('disputes:sync')->expectsOutputToContain('korapay: 1 dispute(s)')->assertSuccessful();

        $this->assertSame($attempt->id, PaymentDispute::sole()->payment_attempt_id);
    }

    public function test_an_unreadable_list_records_nothing_and_fails_the_run(): void
    {
        $this->setGateway('korapay', false);
        Http::fake(['api.flutterwave.com/*' => Http::response(['status' => 'error'], 503)]);

        $this->artisan('disputes:sync')
            ->expectsOutputToContain('flutterwave: chargeback list could not be read.')
            ->assertFailed();

        $this->assertSame(0, PaymentDispute::count());
    }

    public function test_without_live_keys_nothing_is_asked(): void
    {
        config(['services.flutterwave.secret_key' => '']);
        $this->setGateway('korapay', false);
        Http::fake();

        $this->artisan('disputes:sync')->expectsOutputToContain('flutterwave: skipped (not live).')->assertSuccessful();

        Http::assertNothingSent();
    }

    public function test_an_open_dispute_near_its_deadline_pages_again_once(): void
    {
        $this->setGateway('korapay', false);
        Http::fake(['api.flutterwave.com/*' => Http::response(['status' => 'success', 'data' => [], 'meta' => ['page_info' => ['total_pages' => 1]]])]);

        $soon = PaymentDispute::factory()->create(['due_at' => now()->addHours(3)]);
        PaymentDispute::factory()->create(['due_at' => now()->addDays(2)]);
        PaymentDispute::factory()->create(['due_at' => now()->addHours(2), 'status' => DisputeStatus::Lost, 'gateway_status' => 'lost']);
        PaymentDispute::factory()->create(['due_at' => now()->subHour()]);

        $this->artisan('disputes:sync')->expectsOutputToContain('1 deadline alert(s) sent.')->assertSuccessful();
        $this->artisan('disputes:sync')->expectsOutputToContain('0 deadline alert(s) sent.')->assertSuccessful();

        $this->assertSame(['dispute:deadline:'.$soon->id], array_column($this->alerts, 'key'));
        $this->assertStringContainsString('under 6 hours', $this->alerts[0]['title']);
    }

    // --- Admin --------------------------------------------------------------------

    public function test_admins_see_open_disputes_with_their_evidence_links(): void
    {
        $attempt = $this->paidAttempt('flutterwave');
        $open = PaymentDispute::factory()->create([
            'gateway_dispute_id' => '4242',
            'payment_attempt_id' => $attempt->id,
            'order_id' => $attempt->order_id,
            'user_id' => $attempt->user_id,
        ]);
        PaymentDispute::factory()->create(['gateway_dispute_id' => '4343', 'status' => DisputeStatus::Won, 'gateway_status' => 'won']);

        $admin = Admin::create(['name' => 'Risk Admin', 'email' => 'risk@example.test', 'password' => 'password', 'role' => AdminRole::SuperAdmin, 'is_active' => true]);

        $this->withoutVite()->actingAs($admin, 'admin')
            ->get(route('admin.risk.disputes'))
            ->assertOk()
            ->assertSee('data-dispute="4242"', false)
            ->assertDontSee('data-dispute="4343"', false)
            ->assertSee(route('admin.order.report', $open->order_id), false)
            ->assertSee(route('admin.transaction.report', $attempt), false)
            ->assertSee('USD 25.00');

        $this->withoutVite()->actingAs($admin, 'admin')
            ->get(route('admin.risk.disputes', ['status' => 'won']))
            ->assertSee('data-dispute="4343"', false);

        $this->withoutVite()->actingAs($admin, 'admin')
            ->get(route('admin.risk.dashboard'))
            ->assertSee(route('admin.risk.disputes'), false);
    }

    /**
     * The panel answers anyone without access with a 404: a guest, and an
     * admin whose role has no Customers section (risk lives under it).
     */
    public function test_the_disputes_page_is_for_admins_with_customer_access_only(): void
    {
        $this->get(route('admin.risk.disputes'))->assertNotFound();

        $moderator = Admin::create(['name' => 'Mod', 'email' => 'mod@example.test', 'password' => 'password', 'role' => AdminRole::Moderator, 'is_active' => true]);

        $this->withoutVite()->actingAs($moderator, 'admin')->get(route('admin.risk.disputes'))->assertNotFound();
    }
}

<?php

namespace Tests\Feature\Payment;

use App\Domain\Payment\Enums\PaymentStatus;
use App\Domain\Reconciliation\Models\ReconciliationReport;
use App\Jobs\VerifyPaymentJob;
use App\Models\Order;
use App\Models\PaymentAttempt;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Kora's book against ours. The command asks Kora for every recent Kora
 * attempt by our reference and reports each disagreement; it settles nothing
 * on its own, and only re-runs our verification when told to.
 */
class KorapayReconcileCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // A live-looking key takes the provider out of mock mode so the faked
        // HTTP layer is what answers.
        config(['services.korapay.secret_key' => 'sk_live_reconcile_test']);
    }

    /** @param  array<string, mixed>  $overrides */
    private function attempt(array $overrides = []): PaymentAttempt
    {
        $user = User::factory()->create();
        $order = Order::create([
            'user_id' => $user->id,
            'order_number' => 'ORD-'.Str::upper(Str::random(8)),
            'payment_method' => 'korapay',
        ]);

        return PaymentAttempt::create(array_merge([
            'order_id' => $order->id,
            'user_id' => $user->id,
            'gateway' => 'korapay',
            'gateway_reference' => $ref = 'PAY-'.Str::lower(Str::random(12)),
            'idempotency_key' => $ref,
            'currency' => 'XAF',
            'amount' => 6560,
        ], $overrides));
    }

    /**
     * Kora answers each reference from the map; anything else is "not found",
     * the answer Kora gives for a reference it never saw.
     *
     * @param  array<string, array<string, mixed>>  $byReference
     */
    private function koraHolds(array $byReference): void
    {
        Http::fake(function (Request $request) use ($byReference) {
            $reference = rawurldecode(basename(parse_url($request->url(), PHP_URL_PATH)));

            if (! isset($byReference[$reference])) {
                return Http::response(['status' => false, 'message' => 'Charge not found', 'data' => null], 404);
            }

            return Http::response([
                'status' => true,
                'message' => 'Charge retrieved successfully',
                'data' => array_merge(['reference' => $reference, 'currency' => 'XAF', 'amount' => '6560.00', 'amount_paid' => '6560.00'], $byReference[$reference]),
            ], 200);
        });
    }

    public function test_a_payment_kora_holds_as_paid_that_we_have_not_settled_is_reported_not_settled(): void
    {
        Bus::fake();
        $owed = $this->attempt();
        $this->koraHolds([$owed->idempotency_key => ['status' => 'success']]);

        $this->artisan('korapay:reconcile')
            ->expectsOutputToContain('paid_at_gateway_not_settled '.$owed->idempotency_key)
            ->expectsOutputToContain('1 disagreement(s)')
            ->assertExitCode(1);

        Bus::assertNotDispatchedSync(VerifyPaymentJob::class);

        $report = ReconciliationReport::where('type', 'korapay')->sole();
        $this->assertSame('anomalies_found', $report->status);
        $this->assertSame('paid_at_gateway_not_settled', $report->anomalies_found[0]['type']);
        $this->assertSame($owed->idempotency_key, $report->anomalies_found[0]['reference']);
    }

    public function test_settle_re_runs_our_own_verification_instead_of_marking_paid_itself(): void
    {
        Bus::fake();
        $owed = $this->attempt();
        $this->koraHolds([$owed->idempotency_key => ['status' => 'success']]);

        $this->artisan('korapay:reconcile --settle')->run();

        Bus::assertDispatchedSync(VerifyPaymentJob::class, 1);
        $this->assertNotSame(PaymentStatus::Paid, $owed->fresh()->payment_status, 'the command itself never marks an attempt paid');
    }

    public function test_an_attempt_we_settled_that_kora_holds_as_failed_is_the_urgent_kind(): void
    {
        $given = $this->attempt(['payment_status' => PaymentStatus::Paid]);
        $this->koraHolds([$given->idempotency_key => ['status' => 'failed', 'amount_paid' => '0.00']]);

        $this->artisan('korapay:reconcile')
            ->expectsOutputToContain('settled_but_failed_at_gateway '.$given->idempotency_key)
            ->assertExitCode(1);

        $this->assertSame('settled_but_failed_at_gateway', ReconciliationReport::sole()->anomalies_found[0]['type']);
    }

    public function test_a_short_payment_is_an_amount_mismatch(): void
    {
        $short = $this->attempt(['payment_status' => PaymentStatus::Paid]);
        $this->koraHolds([$short->idempotency_key => ['status' => 'success', 'amount_paid' => '6000.00', 'amount' => '6000.00']]);

        $this->artisan('korapay:reconcile')
            ->expectsOutputToContain('amount_mismatch '.$short->idempotency_key)
            ->assertExitCode(1);
    }

    public function test_agreement_pending_prompts_and_never_charged_attempts_are_clean(): void
    {
        $agreed = $this->attempt(['payment_status' => PaymentStatus::Paid]);
        $pending = $this->attempt();
        $abandoned = $this->attempt();
        $this->koraHolds([
            $agreed->idempotency_key => ['status' => 'success'],
            $pending->idempotency_key => ['status' => 'processing', 'amount_paid' => '0.00'],
            // $abandoned is not in the map: Kora never saw a charge for it.
        ]);

        $this->artisan('korapay:reconcile')
            ->expectsOutputToContain('Checked 3 Kora attempt(s): 1 agree, 1 still pending, 1 never charged, 0 settled now, 0 unreached, 0 disagreement(s).')
            ->assertSuccessful();

        $this->assertSame('clean', ReconciliationReport::sole()->status);
    }

    public function test_an_attempt_kora_cannot_be_asked_about_is_counted_not_judged(): void
    {
        $this->attempt();
        Http::fake(['api.korapay.com/*' => Http::response(['status' => false, 'message' => 'upstream'], 503)]);

        $this->artisan('korapay:reconcile')
            ->expectsOutputToContain('1 unreached, 0 disagreement(s)')
            ->assertSuccessful();
    }

    public function test_old_attempts_and_other_gateways_are_outside_the_window(): void
    {
        $this->attempt(['gateway' => 'flutterwave']);
        $old = $this->attempt();
        $old->forceFill(['created_at' => now()->subHours(72)])->save();
        Http::fake();

        $this->artisan('korapay:reconcile --hours=48')
            ->expectsOutputToContain('No Kora attempts in the last 48 hour(s).')
            ->assertSuccessful();

        Http::assertNothingSent();
    }

    public function test_without_kora_keys_there_is_nothing_to_reconcile(): void
    {
        config(['services.korapay.secret_key' => '']);
        $this->attempt();
        Http::fake();

        $this->artisan('korapay:reconcile')
            ->expectsOutputToContain('Kora keys are not configured')
            ->assertSuccessful();

        Http::assertNothingSent();
    }
}

<?php

namespace Tests\Feature\Payments;

use App\Domain\Payment\Services\PaymentSessionService;
use App\Models\Order;
use App\Models\PaymentAttempt;
use App\Models\PaymentOutcome;
use App\Models\PaymentSession;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * The payment-outcome stream reaching Telegram on its own. On 2026-09-09 five
 * failures by one customer were recorded, graded and drawn on the dashboard,
 * and nobody knew for hours. Three signals now interrupt a human as SEV-2:
 * platform-side faults, a customer failing repeatedly, and a reason code
 * spiking against its own baseline.
 */
class PaymentFailureAlertsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'alerting.enabled' => true,
            'alerting.channels.telegram.bot_token' => 'bot-token',
            'alerting.channels.telegram.chat_id' => '-100',
            'alerting.channels.mail.to' => null,
            'alerting.channels.slack.enabled' => false,
            'alerting.routing.sev2' => ['telegram'],
            'alerting.throttle_minutes' => 60,
        ]);

        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true])]);
    }

    private function failedSession(User $user): PaymentSession
    {
        $order = Order::factory()->create(['user_id' => $user->id]);

        $attempt = PaymentAttempt::create([
            'order_id' => $order->id,
            'user_id' => $user->id,
            'gateway' => 'flutterwave',
            'idempotency_key' => 'alert_'.Str::random(12),
            'currency' => 'GHS',
            'amount' => 1187.00,
            'payment_status' => 'pending',
        ]);

        return app(PaymentSessionService::class)->createForOrder($order, $attempt, []);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function outcome(User $user, array $overrides = []): PaymentOutcome
    {
        return PaymentOutcome::create(array_merge([
            'payment_session_id' => $this->failedSession($user)->id,
            'user_id' => $user->id,
            'outcome' => 'failed',
            'product_line' => 'checkout',
            'provider' => 'flutterwave',
            'payment_method' => 'mobile_money',
            'failure_stage' => 'initialization',
            'failure_category' => 'customer',
            'failure_reason_code' => 'bank_declined',
            'retryable' => true,
            'consecutive_failure_count' => 1,
            'metadata' => ['raw_reason' => 'declined'],
        ], $overrides));
    }

    /**
     * @return list<string>
     */
    private function telegramMessages(): array
    {
        return Http::recorded(fn (Request $request) => str_contains($request->url(), 'api.telegram.org'))
            ->map(fn (array $pair) => (string) $pair[0]['text'])
            ->values()
            ->all();
    }

    public function test_a_platform_fault_reaches_telegram_with_the_gateways_own_words(): void
    {
        $user = User::factory()->create();

        $this->outcome($user, [
            'failure_category' => 'system',
            'failure_reason_code' => 'gateway_config_error',
            'retryable' => false,
            'metadata' => [
                'raw_reason' => 'Failed to initialize mobile money: This payment option is not allowed for your currency',
                'failure_details' => ['provider_message' => 'This payment option is not allowed for your currency', 'http_status' => 400],
            ],
        ]);

        $this->artisan('insights:alert-failures')->assertExitCode(0);

        $messages = $this->telegramMessages();
        $this->assertCount(1, $messages);
        $this->assertStringContainsString('SEV-2', $messages[0]);
        $this->assertStringContainsString('Payments failing on our side', $messages[0]);
        $this->assertStringContainsString('This payment option is not allowed for your currency', $messages[0]);
        $this->assertStringContainsString('cannot fix by retrying', $messages[0]);
    }

    public function test_a_customer_failing_three_times_is_flagged_for_a_human(): void
    {
        $user = User::factory()->create();

        foreach (range(1, 3) as $attempt) {
            $this->outcome($user);
        }

        $this->artisan('insights:alert-failures')->assertExitCode(0);

        $messages = $this->telegramMessages();
        $this->assertCount(1, $messages);
        $this->assertStringContainsString("Customer #{$user->id} cannot pay", $messages[0]);
        $this->assertStringContainsString('3 unsuccessful payment attempts', $messages[0]);
        $this->assertStringContainsString('Bank declined x3', $messages[0]);
        $this->assertStringContainsString(route('admin.customer', $user->id), $messages[0]);
    }

    public function test_two_failures_and_walk_aways_alone_stay_quiet(): void
    {
        $user = User::factory()->create();

        $this->outcome($user);
        $this->outcome($user, ['outcome' => 'expired', 'failure_reason_code' => 'session_expired']);

        // The sweeper already alerts on webhook loss; this command must not
        // give the same problem a second voice.
        $this->outcome(User::factory()->create(), [
            'failure_category' => 'system',
            'failure_reason_code' => 'orphaned_timeout',
        ]);

        $this->artisan('insights:alert-failures')->assertExitCode(0);

        $this->assertSame([], $this->telegramMessages());
    }

    public function test_a_reason_code_spiking_against_a_quiet_baseline_is_reported_once(): void
    {
        foreach (range(1, 5) as $i) {
            $this->outcome(User::factory()->create(), ['failure_reason_code' => 'authentication_failed']);
        }

        $this->artisan('insights:alert-failures')->assertExitCode(0);
        // A second run inside the throttle window must not repeat itself.
        $this->artisan('insights:alert-failures')->assertExitCode(0);

        $spikes = array_values(array_filter($this->telegramMessages(), fn (string $m) => str_contains($m, 'Payment failure spike')));
        $this->assertCount(1, $spikes);
        $this->assertStringContainsString('OTP / 3DS authentication failed', $spikes[0]);
        $this->assertStringContainsString('5 "OTP / 3DS authentication failed" failures in the last 30 minutes', $spikes[0]);
    }

    public function test_the_same_volume_against_a_busy_baseline_is_not_a_spike(): void
    {
        // The previous three hours ran at the same rate: five per half hour.
        $this->travel(-90)->minutes();
        foreach (range(1, 30) as $i) {
            $this->outcome(User::factory()->create(), ['failure_reason_code' => 'authentication_failed']);
        }
        $this->travelBack();

        foreach (range(1, 5) as $i) {
            $this->outcome(User::factory()->create(), ['failure_reason_code' => 'authentication_failed']);
        }

        $this->artisan('insights:alert-failures')->assertExitCode(0);

        $spikes = array_filter($this->telegramMessages(), fn (string $m) => str_contains($m, 'Payment failure spike'));
        $this->assertSame([], array_values($spikes));
    }

    public function test_outcomes_older_than_the_window_are_ignored(): void
    {
        $user = User::factory()->create();

        $this->travel(-2)->hours();
        foreach (range(1, 3) as $attempt) {
            $this->outcome($user);
        }
        $this->travelBack();

        $this->artisan('insights:alert-failures')->assertExitCode(0);

        $this->assertSame([], $this->telegramMessages());
    }
}

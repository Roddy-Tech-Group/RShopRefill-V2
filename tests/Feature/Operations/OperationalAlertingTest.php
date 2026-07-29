<?php

namespace Tests\Feature\Operations;

use App\Domain\Shared\Services\OperationalAlerter;
use App\Enums\AlertSeverity;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Alerting is the control that makes every other control real: a safety check
 * that detects a problem and tells nobody has not prevented anything.
 *
 * These tests assert the delivery path itself, including the failure modes that
 * would silently disarm it — an unconfigured channel, a dead webhook, or a
 * five-minute check spamming the same message all day.
 */
class OperationalAlertingTest extends TestCase
{
    /**
     * Replace every registered HTTP stub.
     *
     * `Http::fake()` appends to the stub list rather than replacing it, and the
     * first matching stub wins — so a test that re-fakes `*` with an error
     * response silently keeps receiving the success stub from setUp. Resolving a
     * fresh factory is the only way to actually override it.
     *
     * @param  array<string, mixed>  $stubs
     */
    private function refakeHttp(array $stubs): void
    {
        $this->app->forgetInstance(Factory::class);
        Http::clearResolvedInstances();
        Http::preventStrayRequests();
        Http::fake($stubs);
    }

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        Http::fake(['*' => Http::response(['ok' => true])]);

        config([
            'alerting.enabled' => true,
            'alerting.throttle_minutes' => 60,
            'alerting.channels.telegram.bot_token' => 'test-token',
            'alerting.channels.telegram.chat_id' => '12345',
            'alerting.channels.mail.to' => 'ops@rshoprefill.test',
            // Slack is opt-in; the default routing below must not include it.
            'alerting.channels.slack.enabled' => false,
            'alerting.channels.slack.webhook_url' => 'https://hooks.slack.test/webhook',
            'alerting.routing' => [
                'sev1' => ['telegram', 'mail'],
                'sev2' => ['telegram'],
                'sev3' => ['mail'],
            ],
        ]);
    }

    public function test_a_sev1_reaches_telegram_first_then_mail(): void
    {
        Mail::fake();

        $alerter = app(OperationalAlerter::class);

        $alerter->send(
            severity: AlertSeverity::Sev1,
            key: 'test-sev1',
            title: 'Ledger integrity violation',
            message: 'Freeze wallet operations.',
        );

        Http::assertSent(fn ($request): bool => str_contains($request->url(), 'api.telegram.org'));

        // Order matters: the phone-interrupting channel must be attempted before
        // the slow one, so a partial outage still wakes somebody.
        $this->assertSame(['telegram', 'mail'], array_keys($alerter->lastDeliveryResults()));
    }

    public function test_slack_is_not_used_unless_explicitly_enabled(): void
    {
        app(OperationalAlerter::class)->send(
            severity: AlertSeverity::Sev1,
            key: 'test-no-slack',
            title: 'Site down',
            message: 'Everything is on fire.',
        );

        Http::assertNotSent(fn ($request): bool => str_contains($request->url(), 'hooks.slack.test'));
    }

    public function test_a_stale_slack_webhook_does_not_count_as_an_escalation_path(): void
    {
        // A URL left in the environment after the team stopped using Slack must
        // not make `canReachAHumanUrgently()` optimistic.
        config([
            'alerting.channels.telegram.bot_token' => null,
            'alerting.channels.telegram.chat_id' => null,
            'alerting.channels.mail.to' => null,
        ]);

        $alerter = app(OperationalAlerter::class);

        $this->assertSame([], $alerter->configuredChannels());
        $this->assertFalse($alerter->canReachAHumanUrgently());
    }

    public function test_slack_can_be_switched_back_on_without_a_code_change(): void
    {
        config([
            'alerting.channels.slack.enabled' => true,
            'alerting.routing.sev2' => ['telegram', 'slack'],
        ]);

        app(OperationalAlerter::class)->send(AlertSeverity::Sev2, 'test-slack-on', 'Queue backlog', 'x');

        Http::assertSent(fn ($request): bool => str_contains($request->url(), 'hooks.slack.test'));
    }

    public function test_a_sev3_does_not_page_a_phone(): void
    {
        Mail::fake();

        app(OperationalAlerter::class)->send(
            severity: AlertSeverity::Sev3,
            key: 'test-sev3',
            title: 'Slow checkout',
            message: 'p95 latency elevated.',
        );

        // Telegram is the phone-interrupting channel; a SEV-3 must never use it.
        Http::assertNotSent(fn ($request): bool => str_contains($request->url(), 'api.telegram.org'));
    }

    public function test_a_telegram_alert_carries_no_markdown_that_could_be_rejected(): void
    {
        // Telegram 400s the whole message on an unbalanced markdown entity, and
        // alert bodies routinely contain exception text and file paths.
        app(OperationalAlerter::class)->send(
            AlertSeverity::Sev2,
            'test-markdown',
            'Unbalanced * asterisk',
            'Path: /var/www/app_name/file.php',
        );

        Http::assertSent(function ($request): bool {
            $payload = $request->data();

            return str_contains($request->url(), 'api.telegram.org')
                && ! str_contains($payload['text'], '*')
                && ! array_key_exists('parse_mode', $payload);
        });
    }

    public function test_the_alert_body_carries_the_runbook_so_the_reader_knows_what_to_do(): void
    {
        app(OperationalAlerter::class)->send(
            severity: AlertSeverity::Sev2,
            key: 'test-runbook',
            title: 'Backup failure',
            message: 'Nightly backup did not complete.',
            context: ['disk' => 'backup-remote'],
            runbook: 'docs/runbooks/database-restore.md',
        );

        Http::assertSent(function ($request): bool {
            $text = $request->data()['text'] ?? '';

            return str_contains($text, 'docs/runbooks/database-restore.md')
                && str_contains($text, 'backup-remote')
                && str_contains($text, 'SEV-2');
        });
    }

    public function test_a_failed_delivery_is_reported_rather_than_counted_as_success(): void
    {
        // Telegram answers 400 for a bad chat id or a revoked token. Without
        // ->throw() that response looks identical to a delivered alert, which
        // would make every fire drill pass while reaching nobody.
        $this->refakeHttp(['*' => Http::response(['ok' => false, 'description' => 'chat not found'], 400)]);

        $alerter = app(OperationalAlerter::class);
        $alerter->send(AlertSeverity::Sev2, 'test-rejected', 'Queue backlog', 'x');

        $this->assertFalse($alerter->lastDeliveryResults()['telegram']);
        $this->assertNotEmpty($alerter->lastDeliveryErrors()['telegram']);
    }

    public function test_the_test_command_fails_when_every_channel_fails(): void
    {
        $this->refakeHttp(['*' => Http::response('gateway timeout', 504)]);

        config(['alerting.channels.mail.to' => null]);

        $this->artisan('alerting:test sev2')->assertExitCode(1);
    }

    public function test_repeated_alerts_with_the_same_key_are_throttled(): void
    {
        $alerter = app(OperationalAlerter::class);

        $first = $alerter->send(AlertSeverity::Sev2, 'same-key', 'Queue backlog', 'Still backed up.');
        $second = $alerter->send(AlertSeverity::Sev2, 'same-key', 'Queue backlog', 'Still backed up.');

        $this->assertTrue($first, 'The first alert should be delivered.');
        $this->assertFalse($second, 'A repeat within the throttle window should be suppressed.');
    }

    public function test_a_different_key_is_not_throttled(): void
    {
        $alerter = app(OperationalAlerter::class);

        $alerter->send(AlertSeverity::Sev2, 'key-a', 'Problem A', 'x');

        $this->assertTrue($alerter->send(AlertSeverity::Sev2, 'key-b', 'Problem B', 'y'));
    }

    public function test_a_throttled_alert_is_still_written_to_the_log(): void
    {
        // The forensic record of what the platform knew, and when, must have no
        // gaps even while delivery is being suppressed.
        Log::shouldReceive('log')->twice();

        $alerter = app(OperationalAlerter::class);
        $alerter->send(AlertSeverity::Sev2, 'logged-key', 'Problem', 'x');
        $alerter->send(AlertSeverity::Sev2, 'logged-key', 'Problem', 'x');
    }

    public function test_a_dead_channel_does_not_break_the_caller(): void
    {
        // An alert channel failing must never turn one incident into two by
        // taking down the process that was trying to report the first.
        $this->refakeHttp(['*' => Http::response('gateway timeout', 504)]);

        $delivered = app(OperationalAlerter::class)->send(
            AlertSeverity::Sev1,
            'dead-channel',
            'Site down',
            'Everything is on fire.',
        );

        $this->assertTrue($delivered);
    }

    public function test_it_reports_when_no_channel_can_reach_a_human_urgently(): void
    {
        config([
            'alerting.channels.telegram.bot_token' => null,
            'alerting.channels.telegram.chat_id' => null,
            'alerting.channels.slack.webhook_url' => null,
            'alerting.channels.mail.to' => null,
        ]);

        $alerter = app(OperationalAlerter::class);

        $this->assertSame([], $alerter->configuredChannels());
        $this->assertFalse($alerter->canReachAHumanUrgently());
    }

    public function test_the_test_command_fails_loudly_when_nothing_is_configured(): void
    {
        config([
            'alerting.channels.telegram.bot_token' => null,
            'alerting.channels.telegram.chat_id' => null,
            'alerting.channels.slack.webhook_url' => null,
            'alerting.channels.mail.to' => null,
        ]);

        $this->artisan('alerting:test')->assertExitCode(1);
    }

    public function test_the_test_command_dispatches_through_the_real_path(): void
    {
        $this->artisan('alerting:test sev2')->assertExitCode(0);

        Http::assertSent(fn ($request): bool => str_contains($request->url(), 'api.telegram.org'));
    }
}

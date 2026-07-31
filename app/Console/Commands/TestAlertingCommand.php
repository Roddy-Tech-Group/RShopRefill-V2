<?php

namespace App\Console\Commands;

use App\Domain\Shared\Services\OperationalAlerter;
use App\Enums\AlertSeverity;
use Illuminate\Console\Command;

class TestAlertingCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'alerting:test {severity=sev2 : sev1, sev2 or sev3}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Fire a test alert through the real delivery path to prove a human would actually be reached.';

    /**
     * A safety system is not done until it has been seen to fire. This command is
     * how that gets proven — on install, after a credential rotation, and during
     * the quarterly game day.
     */
    public function handle(OperationalAlerter $alerter): int
    {
        $severity = AlertSeverity::tryFrom((string) $this->argument('severity'));

        if ($severity === null) {
            $this->error('Severity must be one of: sev1, sev2, sev3.');

            return self::FAILURE;
        }

        $configured = $alerter->configuredChannels();

        $this->info('Configured channels: '.($configured === [] ? 'NONE' : implode(', ', $configured)));

        if ($configured === []) {
            $this->error('No alert channel is configured. Set OPS_TELEGRAM_BOT_TOKEN + OPS_TELEGRAM_CHAT_ID');
            $this->error('or OPS_ALERT_EMAIL. Until then the platform cannot tell anyone anything.');

            return self::FAILURE;
        }

        if (! $alerter->canReachAHumanUrgently()) {
            $this->warn('No SEV-1 channel is configured: a 3 a.m. outage would reach nobody until morning.');
        }

        // Throttling is bypassed with a unique key so repeated tests always send.
        $delivered = $alerter->send(
            severity: $severity,
            key: 'alerting-test:'.now()->timestamp,
            title: 'Test alert',
            message: 'This is a deliberate test of the operational alert path. No action is required.',
            context: ['triggered_by' => 'alerting:test'],
            runbook: 'docs/OPERATIONS.md',
        );

        if (! $delivered) {
            $this->error('Alert was not delivered — alerting is disabled (ALERTING_ENABLED=false).');

            return self::FAILURE;
        }

        $results = $alerter->lastDeliveryResults();
        $errors = $alerter->lastDeliveryErrors();

        $this->newLine();
        $this->table(
            ['Channel', 'Result', 'Detail'],
            array_map(
                fn (string $channel): array => [
                    $channel,
                    $results[$channel] ? 'DELIVERED' : 'FAILED',
                    $results[$channel] ? '' : ($errors[$channel] ?? 'unknown error'),
                ],
                array_keys($results),
            ),
        );

        // Reporting success when every channel threw would reproduce, in the
        // alerting system itself, the exact failure this whole phase exists to
        // eliminate: a control that exits zero without having done anything.
        if ($results !== [] && ! in_array(true, $results, true)) {
            $this->error('Every channel failed. The platform currently cannot reach anyone.');

            return self::FAILURE;
        }

        $this->info("A {$severity->label()} test alert was dispatched.");
        $this->line('Confirm it arrived. An alert path you have not watched arrive is a hypothesis, not a control.');

        return self::SUCCESS;
    }
}

<?php

namespace App\Domain\Shared\Services;

use App\Enums\AlertSeverity;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * The single path by which the platform interrupts a human.
 *
 * Before this existed, the only way the system could tell anyone something was
 * wrong was a Sentry exception or an admin web-push notification seen when
 * somebody happened to look. Failures that throw nothing — a backup that stopped
 * running, a payment success rate sliding downward, a queue filling up — reached
 * nobody at all, which meant customers were the monitoring.
 *
 * Every scheduled safety check in Phase 0 reports through here, so there is one
 * place to change when the on-call route changes, and one place to test.
 */
class OperationalAlerter
{
    /** @var array<string, bool> */
    private array $lastDeliveryResults = [];

    /** @var array<string, string> */
    private array $lastDeliveryErrors = [];

    /**
     * Send an operational alert.
     *
     * @param  string  $key  Stable identifier used for throttling. Two alerts about
     *                       the same ongoing problem must share a key so a
     *                       five-minute check does not send 288 messages a day.
     * @param  array<string, mixed>  $context
     * @return bool Whether the alert was delivered (false when throttled or disabled).
     */
    public function send(
        AlertSeverity $severity,
        string $key,
        string $title,
        string $message,
        array $context = [],
        ?string $runbook = null,
    ): bool {
        // The log and exception tracker always receive the alert, even when
        // delivery is throttled or disabled — the forensic record of what the
        // platform knew, and when, must never have gaps.
        Log::log($severity->logLevel(), "[{$severity->label()}] {$title}", array_merge($context, [
            'alert_key' => $key,
            'message' => $message,
            'runbook' => $runbook,
        ]));

        $this->reportToSentry($severity, $title, $message, $context);

        if (! config('alerting.enabled', true)) {
            return false;
        }

        if ($this->isThrottled($key, $severity)) {
            return false;
        }

        $body = $this->format($severity, $title, $message, $context, $runbook);

        $this->lastDeliveryResults = [];

        foreach ($this->channelsFor($severity) as $channel) {
            try {
                match ($channel) {
                    'telegram' => $this->sendTelegram($body, $severity),
                    'slack' => $this->sendSlack($body),
                    'mail' => $this->sendMail($severity, $title, $body),
                    default => null,
                };

                $this->lastDeliveryResults[$channel] = true;
            } catch (Throwable $exception) {
                // A dead alert channel must never take down the process that was
                // trying to report a problem — that turns one incident into two.
                $this->lastDeliveryResults[$channel] = false;
                $this->lastDeliveryErrors[$channel] = $exception->getMessage();

                Log::error("Alert delivery failed on channel [{$channel}]", [
                    'alert_key' => $key,
                    'error' => $exception->getMessage(),
                ]);
            }
        }

        return true;
    }

    /**
     * Per-channel outcome of the most recent send, keyed by channel name.
     *
     * `send()` deliberately returns true even when every channel failed, because
     * its caller is usually mid-incident and must not be derailed by the alerting
     * system's own problems. That makes a separate way to ask "did it actually
     * arrive?" necessary — otherwise a fire drill that silently delivered nothing
     * would report success, which is the same class of bug as a backup that
     * writes an empty file and exits zero.
     *
     * @return array<string, bool>
     */
    public function lastDeliveryResults(): array
    {
        return $this->lastDeliveryResults;
    }

    /**
     * Failure reason per channel from the most recent send.
     *
     * @return array<string, string>
     */
    public function lastDeliveryErrors(): array
    {
        return $this->lastDeliveryErrors;
    }

    /**
     * Channels that are configured well enough to actually deliver. Used by the
     * health endpoint and `alerting:test` so an unconfigured alert path shows up
     * as a visible gap rather than silent failure.
     *
     * @return array<int, string>
     */
    public function configuredChannels(): array
    {
        $configured = [];

        if (filled(config('alerting.channels.telegram.bot_token')) && filled(config('alerting.channels.telegram.chat_id'))) {
            $configured[] = 'telegram';
        }

        if (filled(config('alerting.channels.mail.to'))) {
            $configured[] = 'mail';
        }

        // A webhook URL alone is not enough: Slack must also be switched on, or a
        // stale URL left in the environment would count as a live escalation path.
        if (config('alerting.channels.slack.enabled', false) && filled(config('alerting.channels.slack.webhook_url'))) {
            $configured[] = 'slack';
        }

        return $configured;
    }

    /**
     * True when a SEV-1 could actually wake somebody. If this is false, the
     * platform has no out-of-hours alerting at all, however healthy it looks.
     */
    public function canReachAHumanUrgently(): bool
    {
        return array_intersect($this->channelsFor(AlertSeverity::Sev1), $this->configuredChannels()) !== [];
    }

    /**
     * @return array<int, string>
     */
    private function channelsFor(AlertSeverity $severity): array
    {
        /** @var array<int, string> $routed */
        $routed = (array) config('alerting.routing.'.$severity->value, []);

        return array_values(array_intersect($routed, $this->configuredChannels()));
    }

    private function isThrottled(string $key, AlertSeverity $severity): bool
    {
        $minutes = (int) config('alerting.throttle_minutes', 60);

        if ($minutes <= 0) {
            return false;
        }

        $cacheKey = 'alert:throttle:'.$severity->value.':'.sha1($key);

        if (Cache::has($cacheKey)) {
            return true;
        }

        Cache::put($cacheKey, true, now()->addMinutes($minutes));

        return false;
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function format(AlertSeverity $severity, string $title, string $message, array $context, ?string $runbook): string
    {
        $lines = [
            $severity->emoji().' *'.$severity->label().'* — '.$title,
            '',
            $message,
            '',
            'Environment: '.config('alerting.environment'),
            'Time: '.now()->toDateTimeString().' ('.config('app.timezone', 'UTC').')',
        ];

        foreach ($context as $label => $value) {
            $rendered = is_scalar($value) || $value === null
                ? (string) $value
                : (string) json_encode($value);

            $lines[] = ucfirst(str_replace('_', ' ', (string) $label)).': '.$rendered;
        }

        if ($runbook !== null) {
            $lines[] = '';
            // An alert that does not say what to do next is half an alert.
            $lines[] = 'Runbook: '.$runbook;
        }

        return implode("\n", $lines);
    }

    private function sendSlack(string $body): void
    {
        $webhook = (string) config('alerting.channels.slack.webhook_url');

        Http::timeout(10)->throw()->post($webhook, ['text' => $body]);
    }

    private function sendTelegram(string $body, AlertSeverity $severity): void
    {
        $token = (string) config('alerting.channels.telegram.bot_token');
        $chatId = (string) config('alerting.channels.telegram.chat_id');

        Http::timeout(10)->throw()->post("https://api.telegram.org/bot{$token}/sendMessage", [
            'chat_id' => $chatId,
            // Sent as plain text on purpose. With parse_mode set, Telegram rejects
            // the entire message with a 400 if the body contains an unbalanced `*`
            // or `_` — and alert bodies carry exception messages, file paths and
            // SQL, which routinely do. An unstyled alert that always arrives beats
            // a formatted one that vanishes on the day it matters most.
            'text' => str_replace('*', '', $body),
            // A SEV-3 digest must not buzz a phone; that is how a team learns to
            // silence the channel that later has to carry a SEV-1.
            'disable_notification' => $severity === AlertSeverity::Sev3,
        ]);
    }

    private function sendMail(AlertSeverity $severity, string $title, string $body): void
    {
        $to = (string) config('alerting.channels.mail.to');

        Mail::raw($body, function ($mail) use ($to, $severity, $title): void {
            $mail->to($to)->subject("[{$severity->label()}] {$title}");
        });
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function reportToSentry(AlertSeverity $severity, string $title, string $message, array $context): void
    {
        if (! $severity->reportsToSentry() || ! app()->bound('sentry')) {
            return;
        }

        try {
            app('sentry')->captureMessage("[{$severity->label()}] {$title}: {$message}", null, [
                'extra' => $context,
            ]);
        } catch (Throwable) {
            // Sentry being unreachable must not break alerting.
        }
    }
}

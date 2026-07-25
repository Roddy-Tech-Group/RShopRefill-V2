<?php

namespace App\Domain\Communication\Jobs;

use App\Domain\Communication\Channels\EmailChannel;
use App\Domain\Communication\Channels\InAppChannel;
use App\Domain\Communication\Channels\WebPushChannel;
use App\Domain\Communication\DTOs\CommunicationPayload;
use App\Domain\Communication\Enums\CommunicationChannel;
use App\Domain\Communication\Enums\DeliveryStatus;
use App\Domain\Communication\Services\CommunicationLogger;
use App\Models\CommunicationLog;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Handles asynchronous delivery of communications across all resolved channels.
 */
class DispatchCommunicationJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Delete the job if its models no longer exist.
     */
    public $deleteWhenMissingModels = true;

    /**
     * Number of times the job may be attempted.
     */
    public $tries = 3;

    /**
     * Backoff sequence (in seconds) between retry attempts.
     */
    public $backoff = [30, 120, 300];

    public function __construct(
        public readonly CommunicationPayload $payload
    ) {}

    public function handle(
        EmailChannel $emailChannel,
        InAppChannel $inAppChannel,
        WebPushChannel $webPushChannel,
        CommunicationLogger $logger
    ): void {
        $failures = [];

        foreach ($this->payload->channels as $channel) {
            $log = $this->findRetryableLog($channel);

            if (! $log) {
                // Already sent/suppressed, or processed by a parallel retry
                continue;
            }

            try {
                $result = match ($channel) {
                    CommunicationChannel::Email => $emailChannel->send($this->payload),
                    CommunicationChannel::InApp => $inAppChannel->send($this->payload),
                    CommunicationChannel::Push => $webPushChannel->send($this->payload),
                    default => throw new \InvalidArgumentException("Channel not implemented: {$channel->value}"),
                };

                if ($result->succeeded()) {
                    $logger->logSent($log, $result->providerMessageId, $result->providerResponse);
                } elseif ($result->status === DeliveryStatus::Suppressed) {
                    // Expected skip (no push subscriptions, suppression list),
                    // never a job failure
                    $logger->markSuppressed($log, $result->errorMessage ?? 'Delivery intentionally skipped.');
                } else {
                    $logger->logFailed($log, $result->errorMessage ?? 'Unknown channel failure.');
                    $failures[] = $channel->value.': '.($result->errorMessage ?? 'unknown failure');
                }
            } catch (\Throwable $e) {
                $logger->logFailed($log, $e->getMessage());
                $failures[] = $channel->value.': '.$e->getMessage();
            }
        }

        if ($failures !== []) {
            // Throwing (instead of $this->fail()) lets $tries/$backoff retry the
            // failed channels; sent/suppressed logs are skipped on the next attempt.
            throw new \RuntimeException('Channel delivery failed - '.implode('; ', $failures));
        }
    }

    /**
     * Locate the log entry for this channel that still needs delivery.
     * Sent/suppressed entries are excluded so a retry never re-sends them.
     */
    private function findRetryableLog(CommunicationChannel $channel): ?CommunicationLog
    {
        return CommunicationLog::where('idempotency_key', $this->payload->idempotencyKey)
            ->where('channel', $channel->value)
            ->whereIn('status', [DeliveryStatus::Queued->value, DeliveryStatus::Failed->value])
            ->latest('id')
            ->first();
    }
}

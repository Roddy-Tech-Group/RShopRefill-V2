<?php

namespace App\Domain\Communication\Jobs;

use App\Domain\Communication\Channels\EmailChannel;
use App\Domain\Communication\Channels\InAppChannel;
use App\Domain\Communication\Channels\WebPushChannel;
use App\Domain\Communication\DTOs\CommunicationPayload;
use App\Domain\Communication\Enums\CommunicationChannel;
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
        foreach ($this->payload->channels as $channel) {
            $log = $this->findQueuedLog($channel);

            if (! $log) {
                // If it's missing, it might have been processed by a parallel retry
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
                } else {
                    $logger->logFailed($log, $result->errorMessage ?? 'Unknown channel failure.');
                    $this->fail(new \Exception($result->errorMessage));
                }
            } catch (\Throwable $e) {
                $logger->logFailed($log, $e->getMessage());
                $this->fail($e);
            }
        }
    }

    /**
     * Locate the 'queued' log entry created by the manager for this specific channel.
     */
    private function findQueuedLog(CommunicationChannel $channel): ?CommunicationLog
    {
        return CommunicationLog::where('idempotency_key', $this->payload->idempotencyKey)
            ->where('channel', $channel->value)
            ->latest('id')
            ->first();
    }
}

<?php

namespace App\Domain\Communication\Services;

use App\Domain\Communication\DTOs\CommunicationPayload;
use App\Domain\Communication\Enums\CommunicationChannel;
use App\Domain\Communication\Enums\DeliveryStatus;
use App\Models\CommunicationLog;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Unified audit logger for all communications.
 *
 * Every outgoing communication — regardless of channel or category —
 * is recorded in the communication_logs table. This replaces the
 * fragmented notification_deliveries table and the various Log::info()
 * calls scattered across the old notification domain.
 */
class CommunicationLogger
{
    /**
     * Log a communication as queued (before dispatch).
     */
    public function logQueued(CommunicationPayload $payload, CommunicationChannel $channel): CommunicationLog
    {
        return CommunicationLog::create([
            'uuid' => Str::uuid()->toString(),
            'user_id' => $payload->recipient instanceof User ? $payload->recipient->id : null,
            'mail_domain' => $payload->mailDomain->value,
            'category' => $payload->category->value,
            'channel' => $channel->value,
            'recipient' => $this->resolveRecipient($payload, $channel),
            'sender' => $payload->mailDomain->senderAddress(),
            'subject' => $payload->title,
            'template_key' => $payload->mailable ? get_class($payload->mailable) : null,
            'status' => DeliveryStatus::Queued,
            'provider' => $this->resolveProvider($channel),
            'priority' => $payload->priority->value,
            'correlation_id' => $payload->correlationId,
            'idempotency_key' => $payload->idempotencyKey,
            'metadata' => ! empty($payload->metadata) ? $payload->metadata : null,
            'queued_at' => now(),
        ]);
    }

    /**
     * Update a log entry to sent status.
     */
    public function logSent(
        CommunicationLog $log,
        ?string $providerMessageId = null,
        array $providerResponse = [],
    ): void {
        $log->update([
            'status' => DeliveryStatus::Sent,
            'provider_message_id' => $providerMessageId,
            'sent_at' => now(),
            'metadata' => array_merge(
                (array) ($log->metadata ?? []),
                ! empty($providerResponse) ? ['provider_response' => $providerResponse] : []
            ),
        ]);
    }

    /**
     * Update a log entry to delivered status (from webhook).
     */
    public function logDelivered(CommunicationLog $log): void
    {
        $log->update([
            'status' => DeliveryStatus::Delivered,
            'delivered_at' => now(),
        ]);
    }

    /**
     * Update a log entry to failed status.
     */
    public function logFailed(CommunicationLog $log, string $errorMessage): void
    {
        $attemptCount = ($log->attempt_count ?? 1);

        $log->update([
            'status' => DeliveryStatus::Failed,
            'failed_at' => now(),
            'error_message' => $errorMessage,
            'attempt_count' => $attemptCount,
            'next_retry_at' => $attemptCount < ($log->max_attempts ?? 3)
                ? now()->addMinutes($attemptCount * 5)
                : null,
        ]);
    }

    /**
     * Update a log entry to bounced status (from webhook).
     */
    public function logBounced(CommunicationLog $log): void
    {
        $log->update([
            'status' => DeliveryStatus::Bounced,
            'bounced_at' => now(),
        ]);
    }

    /**
     * Update a log entry to complained status (from webhook).
     */
    public function logComplained(CommunicationLog $log): void
    {
        $log->update([
            'status' => DeliveryStatus::Complained,
        ]);
    }

    /**
     * Mark as opened (from webhook).
     */
    public function logOpened(CommunicationLog $log): void
    {
        // Only update if not already set (first open).
        if (! $log->opened_at) {
            $log->update(['opened_at' => now()]);
        }
    }

    /**
     * Mark as clicked (from webhook).
     */
    public function logClicked(CommunicationLog $log): void
    {
        if (! $log->clicked_at) {
            $log->update(['clicked_at' => now()]);
        }
    }

    /**
     * Mark an existing log entry suppressed (delivery intentionally skipped,
     * e.g. no push subscriptions). Not a failure: never retried, never
     * counted in failure metrics.
     */
    public function markSuppressed(CommunicationLog $log, string $reason): void
    {
        $log->update([
            'status' => DeliveryStatus::Suppressed,
            'error_message' => $reason,
        ]);
    }

    /**
     * Log a suppressed communication (bounced address, rate limited).
     */
    public function logSuppressed(CommunicationPayload $payload, CommunicationChannel $channel, string $reason): CommunicationLog
    {
        return CommunicationLog::create([
            'uuid' => Str::uuid()->toString(),
            'user_id' => $payload->recipient instanceof User ? $payload->recipient->id : null,
            'mail_domain' => $payload->mailDomain->value,
            'category' => $payload->category->value,
            'channel' => $channel->value,
            'recipient' => $this->resolveRecipient($payload, $channel),
            'sender' => $payload->mailDomain->senderAddress(),
            'subject' => $payload->title,
            'status' => DeliveryStatus::Suppressed,
            'provider' => $this->resolveProvider($channel),
            'priority' => $payload->priority->value,
            'correlation_id' => $payload->correlationId,
            'idempotency_key' => $payload->idempotencyKey,
            'error_message' => $reason,
            'queued_at' => now(),
        ]);
    }

    /**
     * Resolve the recipient identifier for logging.
     */
    private function resolveRecipient(CommunicationPayload $payload, CommunicationChannel $channel): string
    {
        return match ($channel) {
            CommunicationChannel::Email => $payload->emailRecipient(),
            CommunicationChannel::InApp => (string) $payload->recipient->id,
            CommunicationChannel::Push => 'browser-endpoints',
            default => (string) $payload->recipient->id,
        };
    }

    /**
     * Resolve the provider name for a channel.
     */
    private function resolveProvider(CommunicationChannel $channel): string
    {
        return match ($channel) {
            CommunicationChannel::Email => 'resend',
            CommunicationChannel::InApp => 'database',
            CommunicationChannel::Push => 'web-push',
            CommunicationChannel::Sms => 'sms-provider',
            CommunicationChannel::WhatsApp => 'whatsapp-provider',
        };
    }
}

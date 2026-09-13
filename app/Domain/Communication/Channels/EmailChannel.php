<?php

namespace App\Domain\Communication\Channels;

use App\Domain\Communication\DTOs\CommunicationPayload;
use App\Domain\Communication\DTOs\DeliveryResult;
use App\Domain\Communication\Enums\CommunicationChannel;
use App\Domain\Communication\Enums\DeliveryStatus;
use App\Domain\Communication\Providers\MailProviderInterface;
use App\Domain\Communication\Services\UndeliverableDomains;

/**
 * Orchestrates email delivery via the injected MailProvider.
 * Extracts HTML, text, and inline attachments (CIDs) from the Mailable.
 */
class EmailChannel implements ChannelInterface
{
    public function __construct(
        private readonly MailProviderInterface $provider,
    ) {}

    public function send(CommunicationPayload $payload): DeliveryResult
    {
        if (! $payload->mailable) {
            return new DeliveryResult(
                channel: CommunicationChannel::Email,
                status: DeliveryStatus::Failed,
                errorMessage: 'No mailable provided for email channel.',
            );
        }

        // Pre-flight: suppress sends to domains that can never receive mail
        // (RFC 2606 reserved names; Resend 422s them, which would retry, fill
        // failed_jobs and page Sentry for nothing).
        if (UndeliverableDomains::blocks($payload->emailRecipient())) {
            return new DeliveryResult(
                channel: CommunicationChannel::Email,
                status: DeliveryStatus::Suppressed,
                errorMessage: "Suppressed: undeliverable recipient domain on '{$payload->emailRecipient()}'.",
            );
        }

        try {
            $response = $this->provider->send(
                to: $payload->emailRecipient(),
                from: $payload->fromHeader(),
                replyTo: $payload->replyTo(),
                subject: $payload->title,
                mailable: $payload->mailable,
                // The log's idempotency key rides along so the provider can
                // tell Resend "this exact email, once": an HTTP retry or a
                // job retry after a lost response can never deliver it twice.
                metadata: $payload->metadata + ['idempotency_key' => $payload->idempotencyKey]
            );

            // Resend returns an array with 'id' on success
            if (isset($response['id'])) {
                return new DeliveryResult(
                    channel: CommunicationChannel::Email,
                    status: DeliveryStatus::Sent,
                    providerMessageId: $response['id'],
                    providerResponse: $response,
                );
            }

            return new DeliveryResult(
                channel: CommunicationChannel::Email,
                status: DeliveryStatus::Failed,
                errorMessage: $response['message'] ?? 'Unknown provider error.',
                providerResponse: $response,
            );

        } catch (\Throwable $e) {
            return new DeliveryResult(
                channel: CommunicationChannel::Email,
                status: DeliveryStatus::Failed,
                errorMessage: $e->getMessage(),
            );
        }
    }
}

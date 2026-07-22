<?php

namespace App\Domain\Communication\Channels;

use App\Domain\Communication\DTOs\CommunicationPayload;
use App\Domain\Communication\DTOs\DeliveryResult;
use App\Domain\Communication\Enums\CommunicationChannel;
use App\Domain\Communication\Enums\DeliveryStatus;
use App\Domain\Communication\Providers\MailProviderInterface;

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

        try {
            $response = $this->provider->send(
                to: $payload->emailRecipient(),
                from: $payload->fromHeader(),
                replyTo: $payload->replyTo(),
                subject: $payload->title,
                mailable: $payload->mailable,
                metadata: $payload->metadata
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

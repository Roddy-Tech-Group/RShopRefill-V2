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

    /**
     * RFC 2606 reserved domains and other well-known undeliverable suffixes.
     * Resend rejects these with a 422 which, without this guard, retries
     * three times, fills the failed_jobs table, and fires a Sentry alert —
     * all for an address that can never receive mail.
     */
    private const UNDELIVERABLE_DOMAINS = [
        'example.com', 'example.org', 'example.net',
        'test.com', 'test.org', 'test.net',
        'invalid', 'localhost',
    ];

    public function send(CommunicationPayload $payload): DeliveryResult
    {
        if (! $payload->mailable) {
            return new DeliveryResult(
                channel: CommunicationChannel::Email,
                status: DeliveryStatus::Failed,
                errorMessage: 'No mailable provided for email channel.',
            );
        }

        // Pre-flight: suppress sends to domains that can never receive mail.
        $recipientDomain = strtolower(substr(strrchr($payload->emailRecipient(), '@'), 1));
        if (in_array($recipientDomain, self::UNDELIVERABLE_DOMAINS, true)) {
            return new DeliveryResult(
                channel: CommunicationChannel::Email,
                status: DeliveryStatus::Suppressed,
                errorMessage: "Suppressed: undeliverable domain '{$recipientDomain}'.",
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

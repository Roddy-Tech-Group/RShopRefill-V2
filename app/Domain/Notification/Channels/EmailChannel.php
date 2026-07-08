<?php

namespace App\Domain\Notification\Channels;

use App\Domain\Notification\DTOs\NotificationPayload;
use App\Domain\Notification\Enums\DeliveryStatus;
use App\Domain\Notification\Enums\NotificationChannel;
use App\Domain\Notification\Providers\MailProviderInterface;
use App\Models\Notification;
use App\Models\NotificationDelivery;
use Illuminate\Contracts\Support\Renderable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class EmailChannel implements NotificationChannelInterface
{
    public function __construct(
        private readonly MailProviderInterface $mailProvider
    ) {}

    public function send(NotificationPayload $payload, ?Notification $dbNotification = null): void
    {
        if (! $payload->mailable) {
            Log::warning('EmailChannel called without a Mailable object.');

            return;
        }

        try {
            // Render through the real Mailer pipeline (array transport), NOT
            // mailable->render(): only the pipeline injects $message into the
            // views, which is what lets templates embed inline CID images
            // (the brand logo, the eSIM QR). The resulting attachment parts
            // are forwarded to the HTTP provider alongside the HTML.
            ['html' => $htmlBody, 'attachments' => $attachments] = $this->renderWithInlineParts($payload);

            // Deliver email via provider
            $response = $this->mailProvider->send(
                to: $payload->emailRecipient(),
                subject: $payload->title,
                htmlBody: $htmlBody,
                attachments: $attachments,
            );

            // Update database notification if provided
            if ($dbNotification) {
                $dbNotification->update([
                    'status' => DeliveryStatus::Sent,
                    'sent_at' => now(),
                ]);
            }

            // Record successful audit trail
            NotificationDelivery::create([
                'notification_id' => $dbNotification?->id,
                'provider' => 'resend',
                'channel' => NotificationChannel::Email,
                'recipient' => $payload->emailRecipient(),
                'status' => DeliveryStatus::Sent,
                'response_payload' => $response,
                'attempted_at' => now(),
            ]);

        } catch (\Throwable $e) {
            Log::error('EmailChannel delivery failed', [
                'recipient' => $payload->emailRecipient(),
                'error' => $e->getMessage(),
            ]);

            // Update database notification to failed if provided
            if ($dbNotification) {
                $dbNotification->update([
                    'status' => DeliveryStatus::Failed,
                    'failed_at' => now(),
                ]);
            }

            // Record failed audit trail
            NotificationDelivery::create([
                'notification_id' => $dbNotification?->id,
                'provider' => 'resend',
                'channel' => NotificationChannel::Email,
                'recipient' => $payload->emailRecipient(),
                'status' => DeliveryStatus::Failed,
                'error_message' => $e->getMessage(),
                'attempted_at' => now(),
            ]);

            // Do NOT re-throw. A failed notification email must never break the
            // action that triggered it (checkout, wallet funding, etc.). The
            // failure is logged + recorded above for retry/audit.
        }
    }

    /**
     * Build the email through Laravel's array mailer so the full send pipeline
     * runs, then lift the HTML body + attachment parts (base64 + content_id,
     * the shape Resend's API expects for CID inline images) off the resulting
     * Symfony message. Falls back to a plain render when anything about the
     * pipeline misbehaves - a missing logo must never block the email.
     *
     * @return array{html: string, attachments: array<int, array<string, string>>}
     */
    private function renderWithInlineParts(NotificationPayload $payload): array
    {
        try {
            $mailer = Mail::mailer('array');
            // sendNow: some mailables are queueable, and a queued "render"
            // would dispatch a job instead of producing a message here.
            $mailer->to($payload->emailRecipient())->sendNow($payload->mailable);

            $transport = $mailer->getSymfonyTransport();
            $sent = collect($transport->messages())->last();
            $transport->flush();

            if (! $sent) {
                return ['html' => $this->plainRender($payload), 'attachments' => []];
            }

            $email = $sent->getOriginalMessage();

            $attachments = [];
            foreach ($email->getAttachments() as $part) {
                $attachment = [
                    'filename' => $part->getFilename() ?: 'attachment',
                    'content' => base64_encode($part->getBody()),
                    'content_type' => $part->getMediaType().'/'.$part->getMediaSubtype(),
                ];
                if ($part->hasContentId()) {
                    $attachment['content_id'] = $part->getContentId();
                }
                $attachments[] = $attachment;
            }

            return ['html' => (string) $email->getHtmlBody(), 'attachments' => $attachments];
        } catch (\Throwable $e) {
            Log::warning('EmailChannel pipeline render failed; falling back to plain render.', [
                'mailable' => get_class($payload->mailable),
                'error' => $e->getMessage(),
            ]);

            return ['html' => $this->plainRender($payload), 'attachments' => []];
        }
    }

    /**
     * Last-resort HTML render without the Mailer pipeline (no $message, so no
     * inline images - the layout falls back to the hosted logo URL).
     */
    private function plainRender(NotificationPayload $payload): string
    {
        return $payload->mailable instanceof Renderable
            ? (string) $payload->mailable->render()
            : '';
    }
}

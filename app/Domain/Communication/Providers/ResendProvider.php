<?php

namespace App\Domain\Communication\Providers;

use Illuminate\Contracts\Mail\Mailable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Enhanced Resend provider.
 * Extracts inline CIDs from Laravel Mailables, attaches them, and
 * supports custom From/Reply-To headers based on category routing.
 */
class ResendProvider implements MailProviderInterface
{
    private string $apiKey;

    public function __construct()
    {
        $this->apiKey = config('services.resend.key');
    }

    public function send(
        string $to,
        string $from,
        string $replyTo,
        string $subject,
        Mailable $mailable,
        array $metadata = []
    ): array {
        // Hijack Laravel's rendering using the Array transport hack
        // to extract the compiled HTML and any inline CID images.
        $rendered = $this->renderWithInlineParts($mailable, $to, $from);

        $payload = [
            'from' => $from,
            'to' => $to,
            'reply_to' => $replyTo,
            'subject' => $subject,
            'html' => $rendered['html'],
            'headers' => [
                'X-Entity-Ref-ID' => $metadata['correlation_id'] ?? uniqid(),
            ],
        ];

        if (! empty($rendered['attachments'])) {
            $payload['attachments'] = $rendered['attachments'];
        }

        // Add marketing headers if requested
        if (isset($metadata['is_marketing']) && $metadata['is_marketing']) {
            $unsubscribeUrl = url('/account/notifications');
            $payload['headers']['List-Unsubscribe'] = "<{$unsubscribeUrl}>";
            $payload['headers']['List-Unsubscribe-Post'] = 'List-Unsubscribe=One-Click';
        }

        $response = Http::withToken($this->apiKey)
            ->post('https://api.resend.com/emails', $payload);

        if ($response->successful()) {
            return $response->json();
        }

        Log::error('Resend API Error', [
            'status' => $response->status(),
            'body' => $response->body(),
            'to' => $to,
            'from' => $from,
        ]);

        return [
            'message' => $response->json('message') ?? 'Resend API returned '.$response->status(),
        ];
    }

    /**
     * Renders a Laravel mailable and extracts inline CID attachments for Resend.
     */
    private function renderWithInlineParts(Mailable $mailable, string $to, string $from): array
    {
        // Render through the array mailer so we can capture the raw Symfony
        // Email. Resolved explicitly from the manager — mutating the
        // mail.default config would not affect an already-built mailer.
        $arrayMailer = app('mail.manager')->mailer('array');

        try {
            // Stamp the correct "from" directly onto the Mailable's envelope
            $fromAddress = $from;
            $fromName = null;
            if (preg_match('/^(.*?)\s*<(.*?)>$/', $from, $matches)) {
                $fromName = trim($matches[1]);
                $fromAddress = trim($matches[2]);
            }
            $mailable->from($fromAddress, $fromName);

            $mailable->to($to)->send($arrayMailer);

            $transport = $arrayMailer->getSymfonyTransport();
            $sent = $transport->messages()->last();

            if ($sent === null) {
                throw new \RuntimeException('Mailable rendered no message through the array transport.');
            }

            $email = $sent->getOriginalMessage();

            $attachments = [];

            // Extract inline parts (CIDs)
            foreach ($email->getAttachments() as $attachment) {
                if ($attachment->hasContentId()) {
                    $attachments[] = [
                        'filename' => $attachment->getFilename() ?? 'inline-image.png',
                        'content' => base64_encode($attachment->getBody()),
                        'content_type' => $attachment->getMediaType().'/'.$attachment->getMediaSubtype(),
                        // Required for cid: references in the HTML body to resolve.
                        'content_id' => $attachment->getContentId(),
                    ];
                }
            }

            return [
                'html' => $email->getHtmlBody(),
                'attachments' => $attachments,
            ];

        } finally {
            // Don't let captured messages accumulate on the long-lived
            // queue-worker process.
            $arrayMailer->getSymfonyTransport()->flush();
        }
    }
}

<?php

namespace App\Domain\Communication\Providers;

use App\Domain\Communication\Services\ResendApiThrottle;
use Illuminate\Contracts\Mail\Mailable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Enhanced Resend provider.
 * Extracts inline CIDs from Laravel Mailables, attaches them, and
 * supports custom From/Reply-To headers based on category routing.
 */
class ResendProvider implements MailProviderInterface
{
    private const MAX_HTTP_ATTEMPTS = 3;

    private const MAX_RETRY_DELAY_MILLISECONDS = 5000;

    private string $apiKey;

    private ResendApiThrottle $throttle;

    public function __construct(?ResendApiThrottle $throttle = null)
    {
        $this->apiKey = config('services.resend.key');
        $this->throttle = $throttle ?? ResendApiThrottle::fromConfig();
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

        // Optional mail advertises its way out. A caller that minted a signed
        // one-click link (UnsubscribeLink) gets the full RFC 8058 pair; the
        // legacy marketing flag points at the settings page, which needs a
        // login and so cannot honestly claim one-click.
        if (! empty($metadata['list_unsubscribe'])) {
            $payload['headers']['List-Unsubscribe'] = "<{$metadata['list_unsubscribe']}>";
            $payload['headers']['List-Unsubscribe-Post'] = 'List-Unsubscribe=One-Click';
        } elseif (! empty($metadata['is_marketing'])) {
            $payload['headers']['List-Unsubscribe'] = '<'.route('dashboard.notifications').'>';
        }

        // Account-wide throughput gate (Resend allows 10 req/s). Waits for a
        // slot; throws if none opens, which the job retries with backoff.
        $this->throttle->acquire();

        // A 429 or 5xx is retried in place, honouring Retry-After, so a
        // transient burst never becomes a job failure plus a Sentry event.
        // Other 4xx (bad address, bad payload) are final and not retried.
        $request = Http::withToken($this->apiKey);

        // Resend dedupes on this header for 24h, so if the response to an
        // accepted send is lost and we retry, the recipient still gets one
        // email. Keyed per channel so an email and its in-app twin never clash.
        if (! empty($metadata['idempotency_key'])) {
            $request = $request->withHeader('Idempotency-Key', 'email/'.$metadata['idempotency_key']);
        }

        $response = $request
            ->retry(
                self::MAX_HTTP_ATTEMPTS,
                fn (int $attempt, \Exception $exception): int => $this->retryDelayMilliseconds($attempt, $exception),
                fn (\Exception $exception, PendingRequest $request): bool => $this->isRetryable($exception),
                throw: false,
            )
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

    private function isRetryable(\Exception $exception): bool
    {
        if ($exception instanceof ConnectionException) {
            return true;
        }

        if (! $exception instanceof RequestException) {
            return false;
        }

        $status = $exception->response->status();
        $retryable = $status === 429 || $status >= 500;

        if ($retryable) {
            Log::warning('Resend API transient error, retrying', [
                'status' => $status,
                'retry_after' => $exception->response->header('Retry-After') ?: null,
            ]);
        }

        return $retryable;
    }

    /**
     * Resend's Retry-After (seconds) wins when present; otherwise back off
     * linearly so concurrent workers that all hit 429 together don't all
     * retry together. Capped so an odd Retry-After can never hold a worker
     * past the job timeout; anything longer is left to the job-level backoff.
     */
    private function retryDelayMilliseconds(int $attempt, \Exception $exception): int
    {
        $retryAfterSeconds = $exception instanceof RequestException
            ? (int) $exception->response->header('Retry-After')
            : 0;

        return min(self::MAX_RETRY_DELAY_MILLISECONDS, max($retryAfterSeconds * 1000, 500 * $attempt));
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

<?php

namespace App\Domain\Notification\Providers;

use App\Models\SiteSetting;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ResendProvider implements MailProviderInterface
{
    private readonly string $apiKey;

    private readonly string $fromAddress;

    public function __construct()
    {
        $this->apiKey = config('services.resend.key') ?? '';
        $this->fromAddress = config('services.resend.from_address') ?? 'noreply@rshoprefills.com';
    }

    /**
     * Sender display name, sourced from the admin "Email -> from name" setting
     * so the website name on outgoing mail follows the panel. Falls back to
     * config, then the literal brand. Read per-send (the setting is cached) so
     * a name change applies without restarting the queue worker that holds this
     * singleton.
     */
    private function fromName(): string
    {
        return SiteSetting::get('email.from_name')
            ?: config('services.resend.from_name')
            ?: 'RshopRefills';
    }

    public function send(string $to, string $subject, string $htmlBody, array $headers = [], array $attachments = []): array
    {
        if (empty($this->apiKey) || $this->apiKey === 'testing' || app()->environment('testing')) {
            Log::info('Resend dry-run / sandbox mode. Email intercepted.', [
                'to' => $to,
                'subject' => $subject,
            ]);

            return [
                'id' => 'mock_resend_id_'.uniqid(),
                'status' => 'success',
                'dry_run' => true,
            ];
        }

        $fromName = $this->fromName();

        $body = [
            'from' => "{$fromName} <{$this->fromAddress}>",
            'to' => [$to],
            'subject' => $subject,
            'html' => $htmlBody,
            'headers' => (object) $headers,
        ];

        // Attachments carry inline CID images (brand logo, eSIM QR): Resend
        // renders parts with a content_id wherever the HTML references
        // src="cid:{content_id}".
        if ($attachments !== []) {
            $body['attachments'] = $attachments;
        }

        $response = Http::withToken($this->apiKey)
            ->post('https://api.resend.com/emails', $body);

        if ($response->failed()) {
            Log::error('Resend API call failed', [
                'response' => $response->json(),
                'status' => $response->status(),
            ]);

            throw new \RuntimeException('Resend email delivery failed: '.($response->json()['message'] ?? $response->body()));
        }

        return $response->json();
    }
}

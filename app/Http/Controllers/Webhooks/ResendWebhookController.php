<?php

namespace App\Http\Controllers\Webhooks;

use App\Domain\Communication\Services\BounceManager;
use App\Domain\Communication\Services\CommunicationLogger;
use App\Http\Controllers\Controller;
use App\Models\CommunicationLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Svix\Webhook;

// Resend uses Svix for webhooks

class ResendWebhookController extends Controller
{
    public function __construct(
        private readonly BounceManager $bounceManager,
        private readonly CommunicationLogger $logger
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        $secret = config('services.resend.webhook_secret');

        if (! $secret) {
            Log::warning('Resend webhook secret not configured');

            return response()->json(['error' => 'Webhook secret not configured'], 500);
        }

        $payload = $request->getContent();
        $headers = [
            'svix-id' => $request->header('svix-id'),
            'svix-timestamp' => $request->header('svix-timestamp'),
            'svix-signature' => $request->header('svix-signature'),
        ];

        try {
            $wh = new Webhook($secret);
            $wh->verify($payload, $headers);
        } catch (\Exception $e) {
            Log::warning('Resend webhook signature verification failed', [
                'error' => $e->getMessage(),
                'ip' => $request->ip(),
            ]);

            return response()->json(['error' => 'Invalid signature'], 400);
        }

        $event = $request->json()->all();
        $type = $event['type'] ?? '';
        $data = $event['data'] ?? [];
        $email = $data['to'][0] ?? null;

        if (! $email) {
            return response()->json(['status' => 'ignored']);
        }

        $emailId = $data['email_id'] ?? null;
        $log = $emailId ? CommunicationLog::where('provider_message_id', $emailId)->first() : null;

        match ($type) {
            'email.bounced' => $this->bounceManager->handleBounce(
                email: $email,
                type: 'hard_bounce',
                reason: $data['reason'] ?? 'Bounced',
                provider: 'resend'
            ),
            'email.complained' => $this->bounceManager->handleBounce(
                email: $email,
                type: 'complaint',
                reason: 'User reported as spam',
                provider: 'resend'
            ),
            'email.delivered' => $log ? $this->logger->logDelivered($log) : null,
            'email.opened' => $log ? $this->logger->logOpened($log) : null,
            'email.clicked' => $log ? $this->logger->logClicked($log) : null,
            default => null, // Ignore other events
        };

        return response()->json(['status' => 'success']);
    }
}

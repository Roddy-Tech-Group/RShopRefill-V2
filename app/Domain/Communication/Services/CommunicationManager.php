<?php

namespace App\Domain\Communication\Services;

use App\Domain\Communication\DTOs\CommunicationPayload;
use App\Domain\Communication\Enums\CommunicationCategory;
use App\Domain\Communication\Enums\CommunicationChannel;
use App\Domain\Communication\Enums\DeliveryStatus;
use App\Domain\Communication\Enums\Priority;
use App\Domain\Communication\Jobs\DispatchCommunicationJob;
use App\Models\CommunicationLog;
use App\Models\User;
use Illuminate\Contracts\Mail\Mailable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * The single entry point for ALL outgoing communications across the
 * entire RShopRefills platform.
 *
 * Business logic emits events → listeners call CommunicationManager::send()
 * → the manager resolves channels, preferences, sender, rate limits →
 * queues a DispatchCommunicationJob for async delivery.
 *
 * No business code should call Mail::to(), $user->notify(), or interact
 * with channels directly. Everything flows through here.
 */
class CommunicationManager
{
    public function __construct(
        private readonly ChannelResolver $channelResolver,
        private readonly PreferenceManager $preferenceManager,
        private readonly SenderResolver $senderResolver,
        private readonly CommunicationLogger $logger,
        private readonly BounceManager $bounceManager,
    ) {}

    /**
     * Dispatch a communication to the specified recipient.
     *
     * This method never throws — a notification failure must never break
     * the business action that triggered it. Failures are logged and
     * retried asynchronously.
     *
     * @param  Model  $recipient  User or Admin receiving the communication.
     * @param  CommunicationCategory  $category  Business category for routing.
     * @param  string  $title  Short title for in-app / push.
     * @param  string  $message  Body for in-app / push.
     * @param  Mailable|null  $mailable  Email template (null = no email channel).
     * @param  Priority|null  $priority  Override priority (auto-derived if null).
     * @param  string|null  $correlationId  Business event correlation ID.
     * @param  array  $metadata  Arbitrary context for logs.
     * @param  string|null  $idempotencyKey  Deduplication key.
     * @param  string|null  $overrideEmail  Redirect email channel (for gifting).
     */
    public function send(
        Model $recipient,
        CommunicationCategory $category,
        string $title,
        string $message,
        ?Mailable $mailable = null,
        ?Priority $priority = null,
        ?string $correlationId = null,
        array $metadata = [],
        ?string $idempotencyKey = null,
        ?string $overrideEmail = null,
    ): void {
        try {
            $resolvedPriority = $priority ?? Priority::forCategory($category);

            // Every communication is traceable: callers pass the business
            // event's correlation ID, otherwise one is generated.
            $correlationId = $correlationId ?? 'auto_'.Str::uuid()->toString();

            // 1. Resolve which channels this communication should use
            $channels = $this->channelResolver->resolve(
                recipient: $recipient,
                category: $category,
                hasMailable: $mailable !== null,
                hasOverrideEmail: $overrideEmail !== null,
                preferenceManager: $this->preferenceManager,
            );

            // Filter out email channel if the email address is suppressed
            if (in_array(CommunicationChannel::Email, $channels, true)) {
                $email = $overrideEmail ?? $recipient->email ?? null;
                if ($email && $this->bounceManager->isSuppressed($email)) {
                    $channels = array_filter($channels, fn ($c) => $c !== CommunicationChannel::Email);
                    Log::info('Email delivery suppressed by BounceManager.', [
                        'recipient_id' => $recipient->id ?? null,
                        'email' => $email,
                    ]);
                }
            }

            if (empty($channels)) {
                Log::info('Communication suppressed — all channels filtered.', [
                    'recipient_id' => $recipient->id,
                    'category' => $category->value,
                ]);

                return;
            }

            // 2. Generate idempotency key if not supplied
            $key = $idempotencyKey ?? $this->generateIdempotencyKey(
                $recipient, $category, $mailable, $message
            );

            // 2b. Idempotency guard — the same logical communication must
            // never be dispatched twice. A key already queued/sent within
            // the last 24h means a duplicate trigger; drop it silently.
            $alreadyDispatched = CommunicationLog::query()
                ->where('idempotency_key', $key)
                ->whereIn('status', [
                    DeliveryStatus::Queued->value,
                    DeliveryStatus::Sent->value,
                    DeliveryStatus::Delivered->value,
                ])
                ->where('created_at', '>=', now()->subDay())
                ->exists();

            if ($alreadyDispatched) {
                Log::info('Communication suppressed — duplicate idempotency key.', [
                    'recipient_id' => $recipient->id ?? null,
                    'category' => $category->value,
                    'idempotency_key' => $key,
                ]);

                return;
            }

            // 3. Build the payload
            $payload = new CommunicationPayload(
                recipient: $recipient,
                category: $category,
                title: $title,
                message: $message,
                mailable: $mailable,
                priority: $resolvedPriority,
                channels: $channels,
                correlationId: $correlationId,
                idempotencyKey: $key,
                overrideEmail: $overrideEmail,
                metadata: $metadata,
            );

            // 4. Log the queued state for each channel
            foreach ($channels as $channel) {
                $this->logger->logQueued($payload, $channel);
            }

            // 5. Dispatch to the async delivery job
            DispatchCommunicationJob::dispatch($payload)
                ->onQueue($resolvedPriority->queue());

        } catch (\Throwable $e) {
            // A communication failure must NEVER break the calling code.
            Log::error('CommunicationManager::send() failed to queue.', [
                'recipient_id' => $recipient->id ?? null,
                'category' => $category->value,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
        }
    }

    /**
     * Send to the configured admin notification email.
     * Convenience wrapper — resolves the admin user from config.
     */
    public function sendToAdmin(
        CommunicationCategory $category,
        string $title,
        string $message,
        ?Mailable $mailable = null,
        ?string $correlationId = null,
        array $metadata = [],
    ): void {
        $adminEmail = config('communication.admin_email');

        $adminUser = User::where('email', $adminEmail)->first();

        if (! $adminUser) {
            Log::warning('Admin notification target not found.', [
                'admin_email' => $adminEmail,
                'category' => $category->value,
            ]);

            return;
        }

        $this->send(
            recipient: $adminUser,
            category: $category,
            title: $title,
            message: $message,
            mailable: $mailable,
            correlationId: $correlationId,
            metadata: $metadata,
        );
    }

    /**
     * Generate a deterministic idempotency key when the caller doesn't supply one.
     */
    private function generateIdempotencyKey(
        Model $recipient,
        CommunicationCategory $category,
        ?Mailable $mailable,
        string $message,
    ): string {
        $classKey = $mailable ? get_class($mailable) : 'no_mailable';

        return md5(implode('_', [
            $recipient->id,
            $category->value,
            $classKey,
            substr($message, 0, 100),
            now()->format('Y-m-d-H'),
        ]));
    }
}

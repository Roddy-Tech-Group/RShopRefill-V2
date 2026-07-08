<?php

namespace App\Domain\Notification\Jobs;

use App\Domain\Notification\Channels\DatabaseChannel;
use App\Domain\Notification\Channels\EmailChannel;
use App\Domain\Notification\Channels\WebPushChannel;
use App\Domain\Notification\DTOs\NotificationPayload;
use App\Domain\Notification\Enums\DeliveryStatus;
use App\Domain\Notification\Enums\NotificationChannel;
use App\Domain\Notification\Enums\NotificationPriority;
use App\Models\Notification;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Mail\Mailable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class SendAsynchronousNotificationJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * The number of times the job may be attempted.
     */
    public int $tries = 3;

    /**
     * The number of seconds to wait before retrying the job.
     */
    public array $backoff = [30, 60, 120];

    /**
     * Create a new job instance.
     *
     * @param  array<NotificationChannel>  $channels
     */
    public function __construct(
        private readonly User $user,
        private readonly string $title,
        private readonly string $message,
        private readonly ?Mailable $mailable = null,
        private readonly NotificationPriority $priority = NotificationPriority::Normal,
        private readonly array $metadata = [],
        private readonly array $channels = [NotificationChannel::Database, NotificationChannel::Email],
        private readonly ?string $idempotencyKey = null,
        private readonly ?string $overrideEmail = null
    ) {
        // Set queue based on priority
        $this->queue = match ($priority) {
            NotificationPriority::Critical => 'critical-alerts',
            NotificationPriority::High => 'emails',
            default => 'notifications',
        };
    }

    /**
     * Execute the job.
     */
    public function handle(EmailChannel $emailChannel, DatabaseChannel $databaseChannel, WebPushChannel $webPushChannel): void
    {
        // 1. Idempotency Check
        if ($this->idempotencyKey) {
            $existing = Notification::where('metadata->idempotency_key', $this->idempotencyKey)
                ->where('status', DeliveryStatus::Sent)
                ->exists();

            if ($existing) {
                Log::info('Duplicate notification blocked by idempotency key.', [
                    'key' => $this->idempotencyKey,
                ]);

                return;
            }
        }

        $payload = new NotificationPayload(
            user: $this->user,
            title: $this->title,
            message: $this->message,
            mailable: $this->mailable,
            priority: $this->priority,
            metadata: array_merge($this->metadata, [
                'idempotency_key' => $this->idempotencyKey,
            ]),
            overrideEmail: $this->overrideEmail
        );

        // 2. Dispatch to designated channels
        foreach ($this->channels as $channel) {
            try {
                if ($channel === NotificationChannel::Database) {
                    $databaseChannel->send($payload);
                } elseif ($channel === NotificationChannel::Email) {
                    $emailChannel->send($payload);
                } elseif ($channel === NotificationChannel::Push) {
                    $webPushChannel->send($payload);
                }
            } catch (\Throwable $e) {
                Log::error("Failed to send notification via channel: {$channel->value}", [
                    'user_id' => $this->user->id,
                    'error' => $e->getMessage(),
                ]);

                // Re-throw to trigger backoff retries if it is a critical transport failure
                if ($this->attempts() < $this->tries) {
                    throw $e;
                }
            }
        }
    }
}

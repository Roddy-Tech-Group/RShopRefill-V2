<?php

namespace App\Domain\Notification\Services;

use App\Domain\Notification\Enums\NotificationChannel;
use App\Domain\Notification\Enums\NotificationPriority;
use App\Domain\Notification\Jobs\SendAsynchronousNotificationJob;
use App\Models\User;
use Illuminate\Contracts\Mail\Mailable;
use Illuminate\Support\Facades\Log;

class NotificationDispatcher
{
    public function __construct(
        private readonly NotificationPreferenceService $preferenceService
    ) {}

    /**
     * Centralized portal to dispatch any notification.
     * Evaluates user preferences before dispatching.
     */
    public function dispatch(
        User $user,
        string $title,
        string $message,
        string $category, // order, wallet, security, marketing
        ?Mailable $mailable = null,
        NotificationPriority $priority = NotificationPriority::Normal,
        array $metadata = [],
        ?string $idempotencyKey = null,
        ?string $overrideEmail = null
    ): void {
        $channels = [NotificationChannel::Database]; // In-app is always enabled by default

        // Email check based on user preferences. An override address (e.g. a
        // gift recipient) always emails: the inbox belongs to someone else, so
        // the account holder's preferences must not suppress their delivery.
        if ($overrideEmail !== null || $this->preferenceService->isAllowed($user, $category, 'email')) {
            $channels[] = NotificationChannel::Email;
        } else {
            Log::info('Email notification skipped due to user preference settings.', [
                'user_id' => $user->id,
                'category' => $category,
            ]);
        }

        // Push check based on user preferences
        if ($this->preferenceService->isAllowed($user, $category, 'push')) {
            $channels[] = NotificationChannel::Push;
        }

        // Generate a safety fallback idempotency key if not supplied
        $key = $idempotencyKey ?? md5($user->id.'_'.$category.'_'.get_class($mailable ?? $this).'_'.substr($message, 0, 100));

        // Queue the asynchronous delivery job
        SendAsynchronousNotificationJob::dispatch(
            user: $user,
            title: $title,
            message: $message,
            mailable: $mailable,
            priority: $priority,
            metadata: $metadata,
            channels: $channels,
            idempotencyKey: $key,
            overrideEmail: $overrideEmail
        );
    }
}

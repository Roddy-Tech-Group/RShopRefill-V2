<?php

namespace App\Domain\Notification\DTOs;

use App\Domain\Notification\Enums\NotificationPriority;
use App\Models\User;
use Illuminate\Contracts\Mail\Mailable;

class NotificationPayload
{
    /**
     * $overrideEmail redirects ONLY the email channel to a different inbox
     * (e.g. gift deliveries go to the recipient, not the buyer). Database and
     * push channels still target $user, so the buyer keeps the in-app trail.
     */
    public function __construct(
        public readonly User $user,
        public readonly string $title,
        public readonly string $message,
        public readonly ?Mailable $mailable = null,
        public readonly NotificationPriority $priority = NotificationPriority::Normal,
        public readonly array $metadata = [],
        public readonly ?string $overrideEmail = null
    ) {}

    /** The inbox the email channel should deliver to. */
    public function emailRecipient(): string
    {
        return $this->overrideEmail ?: $this->user->email;
    }
}

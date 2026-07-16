<?php

namespace App\Domain\Security\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class SecurityEventNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public string $eventType,
        public string $details
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Security Alert: ' . $this->eventType)
            ->greeting('Hello ' . $notifiable->name . ',')
            ->line('A security-related change was made to your account:')
            ->line('**Event:** ' . $this->eventType)
            ->line('**Details:** ' . $this->details)
            ->line('If you made this change, no further action is needed.')
            ->line('If you did not make this change, please contact support immediately.');
    }
}

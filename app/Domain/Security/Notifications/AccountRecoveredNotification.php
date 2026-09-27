<?php

namespace App\Domain\Security\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class AccountRecoveredNotification extends Notification
{
    use Queueable;

    public function __construct(private string $ipAddress) {}

    public function via($notifiable): array
    {
        return ['mail'];
    }

    public function toMail($notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Your account has been recovered')
            ->greeting("Hello {$notifiable->name},")
            ->line('Your RshopRefill account has been successfully recovered and Protected Mode has been lifted.')
            ->line('Financial operations are now available.')
            ->line('**Security Tip:** We strongly recommend updating your account password and transaction PIN if you suspect your credentials may have been compromised.')
            ->line('If this was you, no further action is required.')
            ->line('If you did not perform this recovery, please contact support immediately.')
            ->line("IP Address: {$this->ipAddress}");
    }
}

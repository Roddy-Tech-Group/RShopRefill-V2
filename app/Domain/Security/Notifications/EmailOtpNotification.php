<?php

namespace App\Domain\Security\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class EmailOtpNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public string $otp,
        public string $context = 'login'
    ) {
        // Explicit queue: keeps one workload from starving unrelated ones (bulkhead).
        $this->onQueue('comm-critical');
    }

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Your RShopRefill Authentication Code')
            ->greeting('Hello '.$notifiable->name.',')
            ->line($this->context === 'recovery'
                ? 'You are attempting to recover your Protected Mode account.'
                : 'You are attempting to sign in from a new or unrecognized device.')
            ->line('Here is your one-time verification code:')
            ->line('**'.$this->otp.'**')
            ->line('This code will expire in 10 minutes. Do not share this code with anyone.');
    }
}

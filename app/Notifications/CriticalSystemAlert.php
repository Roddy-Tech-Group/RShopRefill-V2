<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class CriticalSystemAlert extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public string $title,
        public string $description,
        public array $context = []
    ) {}

    /**
     * Get the notification's delivery channels.
     *
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        // Add 'slack' if SLACK_WEBHOOK_URL is configured
        $channels = ['mail'];

        if (config('logging.channels.slack.url')) {
            // $channels[] = 'slack';
        }

        return $channels;
    }

    /**
     * Get the mail representation of the notification.
     */
    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)
            ->error()
            ->subject("CRITICAL ALERT: {$this->title}")
            ->greeting('Critical System Alert')
            ->line($this->description);

        if (! empty($this->context)) {
            $mail->line('Context:');
            foreach ($this->context as $key => $value) {
                $valStr = is_array($value) ? json_encode($value) : $value;
                $mail->line("**{$key}:** {$valStr}");
            }
        }

        return $mail;
    }
}

<?php

namespace App\Notifications;

use App\Models\GiftCardTrade;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class TradeStatusUpdated extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public GiftCardTrade $trade,
        public ?string $reason = null
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $statusLabel = $this->trade->status->label();

        $mail = (new MailMessage)
            ->subject('Update on your Gift Card Trade #'.substr($this->trade->uuid, 0, 8))
            ->greeting("Hello {$notifiable->name},")
            ->line("The status of your gift card trade has been updated to: **{$statusLabel}**.");

        if ($this->reason) {
            $mail->line("Admin Note: {$this->reason}");
        }

        return $mail
            ->action('View Trade Details', url('/dashboard/gift-cards/trades/'.$this->trade->id))
            ->line('Thank you for trading with us!');
    }

    public function toArray(object $notifiable): array
    {
        return [
            'title' => 'Trade Status Updated',
            'message' => 'Your trade #'.substr($this->trade->uuid, 0, 8).' is now '.$this->trade->status->label().'.',
            'reason' => $this->reason,
            'url' => '/dashboard/gift-cards/trades/'.$this->trade->id,
            'icon' => 'arrow-path',
        ];
    }
}

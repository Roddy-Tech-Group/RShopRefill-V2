<?php

namespace App\Domain\Security\Notifications;

use App\Models\TrustedDevice;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class NewDeviceDetectedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public TrustedDevice $device
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $lockUrl = route('security.compromise', [
            'device' => $this->device->id,
            'token' => hash_hmac('sha256', $this->device->id, config('app.key'))
        ]);

        return (new MailMessage)
            ->subject('New device signed into your account')
            ->greeting('Hello ' . $notifiable->name . ',')
            ->line('We noticed a new sign-in to your RShopRefill account.')
            ->line('**Device:** ' . $this->device->os . ' / ' . $this->device->browser)
            ->line('**IP Address:** ' . $this->device->ip_address)
            ->line('**Location:** ' . ($this->device->city ?: 'Unknown City') . ', ' . ($this->device->country ?: 'Unknown Country'))
            ->line('**Time:** ' . now()->toDayDateTimeString())
            ->line('If this was you, you can safely ignore this email.')
            ->line('If you did not sign in recently, please secure your account immediately.')
            ->action('NO, SECURE MY ACCOUNT', $lockUrl)
            ->line('Clicking the secure link will instantly log out all devices and freeze financial operations on your account.');
    }
}

<?php

namespace App\Domain\Communication\Enums;

/**
 * Delivery channels supported by the Communication Platform.
 */
enum CommunicationChannel: string
{
    case Email = 'email';
    case InApp = 'in_app';
    case Push = 'push';
    case Sms = 'sms';           // Future
    case WhatsApp = 'whatsapp'; // Future

    /**
     * Channels currently implemented and ready to deliver.
     */
    public static function active(): array
    {
        return [
            self::Email,
            self::InApp,
            self::Push,
        ];
    }

    public function label(): string
    {
        return match ($this) {
            self::Email => 'Email',
            self::InApp => 'In-App',
            self::Push => 'Push Notification',
            self::Sms => 'SMS',
            self::WhatsApp => 'WhatsApp',
        };
    }
}

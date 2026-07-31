<?php

namespace App\Notifications;

use Spatie\Backup\Notifications\Notifiable as SpatieBackupNotifiable;

/**
 * Delivery target for spatie/laravel-backup's failure notifications.
 *
 * Exists so backup alerting has one owned seam rather than being wired directly
 * to a vendor default: Phase 0's P0-3 adds the SEV-tier channel (Slack/Telegram)
 * here, and every backup alert picks it up without touching vendor config.
 */
class BackupNotifiable extends SpatieBackupNotifiable
{
    /**
     * The vendor default returns the configured address verbatim, including the
     * `your@example.com` placeholder it ships with. Silently mailing backup
     * failures into a void is indistinguishable from having no alerting, so an
     * unusable address resolves to nothing and the log/Sentry path carries it.
     *
     * @return string|array<int, string>
     */
    public function routeNotificationForMail(): string|array
    {
        $address = (string) config('backup.notifications.mail.to', '');

        if (! filter_var($address, FILTER_VALIDATE_EMAIL) || str_ends_with($address, '@example.com')) {
            return [];
        }

        return $address;
    }
}

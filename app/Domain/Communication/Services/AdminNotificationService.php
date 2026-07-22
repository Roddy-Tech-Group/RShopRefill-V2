<?php

namespace App\Domain\Communication\Services;

use App\Domain\Communication\Providers\WebPushProvider;
use App\Models\Admin;
use App\Models\AdminNotification;
use Illuminate\Support\Facades\Log;

/**
 * Central entry point for admin-dashboard notifications. Resilient by design —
 * a notification failure must never break the action that triggered it.
 */
class AdminNotificationService
{
    public function __construct(
        private readonly WebPushProvider $webPushProvider,
        private readonly DeviceManager $deviceManager
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function push(string $type, string $title, ?string $message = null, ?string $url = null, array $data = []): void
    {
        try {
            AdminNotification::create([
                'type' => $type,
                'title' => $title,
                'message' => $message,
                'url' => $url,
                'data' => $data ?: null,
            ]);

            $this->sendPushToAdmins($type, $title, $message, $url);
        } catch (\Throwable $e) {
            Log::warning('Admin notification failed: '.$e->getMessage());
        }
    }

    private function sendPushToAdmins(string $type, string $title, ?string $message, ?string $url): void
    {
        $admins = Admin::all()->filter(function (Admin $admin) use ($type) {
            if ($admin->isSuperAdmin()) {
                return true;
            }

            return in_array($type, $admin->allowedNotificationTypes(), true);
        });

        foreach ($admins as $admin) {
            $subscriptions = $this->deviceManager->getSubscriptionsFor($admin);

            if ($subscriptions->isEmpty()) {
                continue;
            }

            $this->webPushProvider->send(
                subscriptions: $subscriptions,
                title: $title,
                body: $message ?? '',
                url: $url ?? config('app.url').'/admin',
                icon: '/icon-192x192.png',
            );
        }
    }
}

<?php

namespace App\Domain\Notification\Services;

use App\Models\PushSubscription;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;

class DeviceManager
{
    /**
     * Get active subscriptions for a given model (User/Admin).
     */
    public function getSubscriptionsFor(Model $subscribable): Collection
    {
        return $subscribable->pushSubscriptions()
            ->where(function ($q) {
                $q->whereNull('expires_at')
                    ->orWhere('expires_at', '>', now());
            })
            ->get();
    }

    /**
     * Register or update a subscription for a model.
     */
    public function subscribe(Model $subscribable, array $subscriptionData): PushSubscription
    {
        $endpoint = $subscriptionData['endpoint'];

        // If another user had this endpoint, they logged out. Re-assign it.
        $existing = PushSubscription::where('endpoint', $endpoint)->first();

        if ($existing) {
            // Re-assign the endpoint to the current owner. subscribable_type/_id
            // are intentionally NOT mass-assignable, so associate() sets them
            // directly — an ->update() silently drops them, leaving the old owner
            // attached, which would route THEIR pushes to this person's device.
            $existing->subscribable()->associate($subscribable);
            $existing->fill([
                'p256dh_key' => $subscriptionData['keys']['p256dh'] ?? '',
                'auth_token' => $subscriptionData['keys']['auth'] ?? '',
                'user_agent' => request()->userAgent(),
                'expires_at' => $subscriptionData['expirationTime'] ?? null,
            ]);
            $existing->save();

            return $existing;
        }

        return $subscribable->pushSubscriptions()->create([
            'endpoint' => $endpoint,
            'p256dh_key' => $subscriptionData['keys']['p256dh'] ?? '',
            'auth_token' => $subscriptionData['keys']['auth'] ?? '',
            'user_agent' => request()->userAgent(),
            'expires_at' => $subscriptionData['expirationTime'] ?? null,
        ]);
    }

    /**
     * Delete a subscription by its unique endpoint.
     */
    public function unsubscribe(Model $subscribable, string $endpoint): bool
    {
        return (bool) $subscribable->pushSubscriptions()
            ->where('endpoint', $endpoint)
            ->delete();
    }

    /**
     * Bulk delete expired endpoints.
     */
    public function deleteSubscriptionsByEndpoint(array $endpoints): void
    {
        PushSubscription::whereIn('endpoint', $endpoints)->delete();
    }
}

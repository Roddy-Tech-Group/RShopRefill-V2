<?php

namespace App\Http\Resources\V2;

use App\Models\Notification;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One in-app notification.
 *
 * `metadata` is not published wholesale. It carries routing hints we add for our own
 * delivery pipeline alongside anything useful, and publishing the lot would turn
 * every internal key into a public field the app might come to depend on. The two
 * keys the app genuinely needs are the ones that let a tap open the right screen.
 *
 * @property Notification $resource
 */
class NotificationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $metadata = (array) ($this->metadata ?? []);

        return [
            'id' => $this->id,
            'type' => $this->type,
            'title' => $this->title,
            'message' => $this->message,
            'priority' => $this->priority,
            'read' => $this->read_at !== null,
            'read_at' => $this->read_at?->toIso8601ZuluString(),
            'created_at' => $this->created_at?->toIso8601ZuluString(),

            // Where a tap should go. An order number rather than an order id, so the
            // app uses the same public identifier the orders endpoints do.
            'target' => [
                'order_number' => $metadata['order_number'] ?? null,
                'url' => $metadata['url'] ?? null,
            ],
        ];
    }
}

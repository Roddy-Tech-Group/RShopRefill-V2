<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Communication\Enums\CommunicationCategory;
use App\Domain\Communication\Enums\DeliveryStatus;
use App\Domain\Communication\Services\CommunicationManager;
use App\Http\Controllers\Controller;
use App\Http\Resources\NotificationResource;
use App\Models\CommunicationLog;
use App\Models\Notification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Admin audit API for the Communication Platform. Reads the in-app feed
 * (notifications) and the unified delivery audit trail (communication_logs).
 */
class NotificationAdminApiController extends Controller
{
    /**
     * Audit list of all in-app notifications in the system.
     */
    public function index(Request $request): JsonResponse
    {
        $notifications = Notification::with('user')
            ->latest()
            ->paginate($request->integer('per_page', 15));

        return response()->json([
            'status' => 'success',
            'data' => NotificationResource::collection($notifications),
            'pagination' => [
                'total' => $notifications->total(),
                'per_page' => $notifications->perPage(),
                'current_page' => $notifications->currentPage(),
                'last_page' => $notifications->lastPage(),
            ],
        ]);
    }

    /**
     * Audit list of delivery attempts and error messages, sourced from the
     * unified communication_logs trail.
     */
    public function deliveries(Request $request): JsonResponse
    {
        $deliveries = CommunicationLog::with('user')
            ->latest()
            ->paginate($request->integer('per_page', 15));

        return response()->json([
            'status' => 'success',
            'data' => $deliveries->getCollection()->map(fn (CommunicationLog $log) => [
                'id' => $log->id,
                'uuid' => $log->uuid,
                'user' => $log->user?->only(['id', 'name', 'email']),
                'mailbox' => $log->mail_domain?->value,
                'category' => $log->category?->value,
                'channel' => $log->channel?->value,
                'recipient' => $log->recipient,
                'sender' => $log->sender,
                'subject' => $log->subject,
                'status' => $log->status?->value,
                'provider' => $log->provider,
                'error_message' => $log->error_message,
                'correlation_id' => $log->correlation_id,
                'queued_at' => $log->queued_at,
                'sent_at' => $log->sent_at,
                'failed_at' => $log->failed_at,
            ]),
            'pagination' => [
                'total' => $deliveries->total(),
                'per_page' => $deliveries->perPage(),
                'current_page' => $deliveries->currentPage(),
                'last_page' => $deliveries->lastPage(),
            ],
        ]);
    }

    /**
     * Re-send a failed communication through the platform. Email templates
     * cannot be reconstructed from the log, so the retry re-delivers the
     * in-app/push content; sent communications are never re-dispatched.
     */
    public function retry(Request $request, string $id): JsonResponse
    {
        $notification = Notification::findOrFail($id);

        if ($notification->status === DeliveryStatus::Sent) {
            return response()->json([
                'status' => 'error',
                'message' => 'Notification has already been successfully sent.',
            ], 400);
        }

        if (! $notification->user) {
            return response()->json([
                'status' => 'error',
                'message' => 'Notification has no recipient to retry against.',
            ], 422);
        }

        app(CommunicationManager::class)->send(
            recipient: $notification->user,
            category: CommunicationCategory::tryFrom($notification->type) ?? CommunicationCategory::ServiceNotice,
            title: $notification->title,
            message: $notification->message,
            correlationId: 'admin_retry_'.$notification->id,
            metadata: ($notification->metadata ?? []) + ['retried_from' => $notification->id],
        );

        return response()->json([
            'status' => 'success',
            'message' => 'Re-queued failed notification for immediate delivery retry.',
        ]);
    }

    /**
     * Expose delivery performance metrics from the unified audit trail.
     */
    public function metrics(Request $request): JsonResponse
    {
        $since = now()->subDays($request->integer('days', 30));

        $logs = CommunicationLog::where('created_at', '>=', $since);

        $byStatus = (clone $logs)
            ->selectRaw('status, count(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        $total = (int) $byStatus->sum();
        $sent = (int) ($byStatus['sent'] ?? 0) + (int) ($byStatus['delivered'] ?? 0);

        return response()->json([
            'status' => 'success',
            'data' => [
                'total' => $total,
                'by_status' => $byStatus,
                'delivery_rate' => $total > 0 ? round(($sent / $total) * 100, 2) : null,
                'by_channel' => (clone $logs)
                    ->selectRaw('channel, count(*) as total')
                    ->groupBy('channel')
                    ->pluck('total', 'channel'),
                'by_mailbox' => (clone $logs)
                    ->selectRaw('mail_domain, count(*) as total')
                    ->groupBy('mail_domain')
                    ->pluck('total', 'mail_domain'),
            ],
        ]);
    }
}

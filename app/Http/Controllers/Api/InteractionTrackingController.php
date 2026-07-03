<?php

namespace App\Http\Controllers\Api;

use App\Domain\Notification\Enums\InteractionType;
use App\Http\Controllers\Controller;
use App\Models\NotificationInteraction;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class InteractionTrackingController extends Controller
{
    public function record(Request $request)
    {
        $request->validate([
            'notification_id' => 'nullable|integer',
            'campaign_id' => 'nullable|integer',
            'type' => 'required|string',
            'channel' => 'required|string',
        ]);

        $typeEnum = InteractionType::tryFrom($request->type);
        if (! $typeEnum) {
            return response()->json(['error' => 'Invalid type'], 400);
        }

        NotificationInteraction::create([
            'user_id' => Auth::id(), // null if unauthenticated push click
            'notification_id' => $request->notification_id,
            'campaign_id' => $request->campaign_id,
            'interaction_type' => $typeEnum,
            'channel' => $request->channel,
            'user_agent' => $request->userAgent(),
            'ip_address' => $request->ip(),
            'metadata' => $request->metadata ?? [],
            'interacted_at' => now(),
        ]);

        return response()->json(['success' => true]);
    }
}

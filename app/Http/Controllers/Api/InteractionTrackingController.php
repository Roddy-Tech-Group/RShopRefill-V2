<?php

namespace App\Http\Controllers\Api;

use App\Domain\Communication\Enums\CommunicationChannel;
use App\Domain\Communication\Enums\InteractionType;
use App\Http\Controllers\Controller;
use App\Models\NotificationCampaign;
use App\Models\NotificationInteraction;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

class InteractionTrackingController extends Controller
{
    public function record(Request $request)
    {
        // This endpoint is public by necessity (a service-worker
        // notificationclick carries no session), so every field is attacker
        // reachable and none may be free text. A scanner posted a time-based
        // SQL-injection probe as `channel`; the insert bound it as data and
        // MySQL refused it for exceeding the column's 20 chars, which turned a
        // rejected probe into a 500 and a Sentry page. Allowlisting the enum
        // makes it a 422 that never touches the database.
        $request->validate([
            'notification_id' => 'nullable|integer',
            'campaign_id' => 'nullable|integer',
            'type' => 'required|string',
            'channel' => ['required', Rule::enum(CommunicationChannel::class)],
            // Stored verbatim into a JSON column, so it must be a structure,
            // not a string, and not an unbounded one.
            'metadata' => 'nullable|array|max:50',
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

        // Roll a click up onto the campaign so the admin Campaigns list reflects
        // it — nothing else increments stats_clicked, so without this it stays 0.
        if ($typeEnum === InteractionType::Clicked && $request->filled('campaign_id')) {
            NotificationCampaign::whereKey($request->campaign_id)->increment('stats_clicked');
        }

        return response()->json(['success' => true]);
    }
}

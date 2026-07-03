<?php

namespace App\Http\Controllers\Api;

use App\Domain\Notification\Services\DeviceManager;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class PushSubscriptionController extends Controller
{
    public function __construct(private readonly DeviceManager $deviceManager) {}

    public function subscribe(Request $request): JsonResponse
    {
        $request->validate([
            'endpoint' => 'required|string',
            'keys.p256dh' => 'required|string',
            'keys.auth' => 'required|string',
        ]);

        $user = Auth::user() ?? Auth::guard('admin')->user();

        if (! $user) {
            return response()->json(['message' => 'Unauthenticated'], 401);
        }

        $this->deviceManager->subscribe($user, $request->all());

        return response()->json(['message' => 'Subscribed']);
    }

    public function unsubscribe(Request $request): JsonResponse
    {
        $request->validate([
            'endpoint' => 'required|string',
        ]);

        $user = Auth::user() ?? Auth::guard('admin')->user();

        if ($user) {
            $this->deviceManager->unsubscribe($user, $request->endpoint);
        }

        return response()->json(['message' => 'Unsubscribed']);
    }
}

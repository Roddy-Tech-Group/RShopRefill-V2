<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\TrustedDevice;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class SecurityController extends Controller
{
    public function revokeDevice(Request $request, TrustedDevice $device)
    {
        if ($device->user_id !== Auth::id()) {
            abort(403, 'Unauthorized access to device.');
        }

        $device->sessions()->update(['revoked_at' => now()]);
        $device->trusted_since = null;
        $device->trust_score = 0;
        $device->save();

        return response()->json(['message' => 'Device revoked successfully.']);
    }

    public function updatePreferences(Request $request)
    {
        $data = $request->validate([
            'email_otp_preference' => 'required|in:never,new_device,always',
        ]);

        $user = Auth::user();
        $user->email_otp_preference = $data['email_otp_preference'];
        $user->save();

        return response()->json(['message' => 'Security preferences updated.']);
    }
}

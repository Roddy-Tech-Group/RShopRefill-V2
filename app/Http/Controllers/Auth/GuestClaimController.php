<?php

namespace App\Http\Controllers\Auth;

use App\Domain\Wallet\Services\TransactionPinService;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\URL;
use Illuminate\Validation\Rules\Password;

/**
 * Claims a guest-checkout shadow account. The guest arrives via the signed
 * link from the claim email (7 days), sets a password + transaction PIN, and
 * the shadow account becomes a real one - with every order they placed as a
 * guest already attached. Routes are signed + throttled; opening the link
 * also proves inbox ownership, so the email is marked verified on claim.
 */
class GuestClaimController extends Controller
{
    public function show(Request $request, User $user)
    {
        if (! $user->is_guest) {
            return redirect()->route('home')
                ->with('status', 'This account is already set up. Please sign in.');
        }

        return view('auth.claim-account', [
            'user' => $user,
            // The POST is signed too; a fresh short-lived signature keeps the
            // form action valid without trusting anything from the client.
            'actionUrl' => URL::temporarySignedRoute('account.claim.store', now()->addHour(), ['user' => $user->id]),
        ]);
    }

    public function store(Request $request, User $user, TransactionPinService $pinService)
    {
        if (! $user->is_guest) {
            return redirect()->route('home')
                ->with('status', 'This account is already set up. Please sign in.');
        }

        $validated = $request->validate([
            'password' => ['required', 'string', 'confirmed', Password::defaults()],
            'pin' => ['required', 'string', 'size:4', 'regex:/^[0-9]+$/', 'confirmed'],
        ], [
            'pin.size' => 'The PIN must be exactly 4 digits.',
            'pin.regex' => 'The PIN can only contain numbers.',
            'pin.confirmed' => 'The PIN confirmation does not match.',
        ]);

        if (! $user->hasTransactionPin()) {
            $pinService->setupPin($user, $validated['pin']);
        }

        $user->forceFill([
            'password' => Hash::make($validated['password']),
            'is_guest' => false,
            // Reaching this page required the signed link from their inbox.
            'email_verified_at' => $user->email_verified_at ?? now(),
        ])->save();

        $request->session()->regenerate();
        Auth::login($user, remember: true);

        return redirect()->route('dashboard.orders')
            ->with('status', 'Welcome! Your account is ready - here are your orders.');
    }
}

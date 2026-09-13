<?php

namespace App\Http\Controllers\Api;

use App\Domain\Communication\Enums\CommunicationCategory;
use App\Domain\Communication\Services\PreferenceManager;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;

/**
 * One-click unsubscribe for optional email (RFC 8058) plus the human version
 * of the same link.
 *
 * POST is what a mail client sends when the reader taps its own Unsubscribe
 * button: no page, no login, just "done". GET is the footer link a person
 * clicks, and shows a small confirmation with the way back to settings. Both
 * are signed URLs, so nobody can unsubscribe anyone but themselves, and both
 * live under /api so the mail client's POST is never asked for a CSRF token.
 *
 * Turning a category off flips its preference group's email switch, the same
 * switch the notification settings page offers. Security, verification and
 * receipts have no group and stay on; the page says so instead of pretending.
 */
class EmailUnsubscribeController extends Controller
{
    public function __invoke(Request $request, User $user, string $category, PreferenceManager $preferences)
    {
        $enum = CommunicationCategory::tryFrom($category);
        $group = $enum?->preferenceGroup();
        $disableable = $enum !== null && $enum->isUserDisableable() && $group !== null;

        if ($disableable) {
            $preferences->updatePreferences($user, ["{$group}_email" => false]);
        }

        if ($request->isMethod('post')) {
            return response($disableable ? 'Unsubscribed' : 'This email cannot be turned off', $disableable ? 200 : 422)
                ->header('Content-Type', 'text/plain');
        }

        return response()->view('email-preferences.unsubscribed', [
            'disableable' => $disableable,
            'label' => $enum?->label() ?? 'these',
            'manageUrl' => route('dashboard.notifications'),
        ]);
    }
}

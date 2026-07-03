<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Notification\Enums\DeliveryStatus;
use App\Domain\Notification\Enums\NotificationChannel;
use App\Domain\Shared\Enums\Currency;
use App\Domain\Shared\Enums\TransactionCategory;
use App\Domain\Wallet\Services\TransactionPinService;
use App\Domain\Wallet\Services\WalletService;
use App\Http\Controllers\Controller;
use App\Mail\AdminDirectMessageMail;
use App\Models\Notification;
use App\Models\NotificationDelivery;
use App\Models\SiteSetting;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Password;
use Illuminate\Validation\Rule;
use Throwable;

/**
 * Admin actions on a customer account: edit profile, ban/unban, hold/release
 * wallet funds. Lives behind the `admin` middleware group.
 */
class AdminCustomerController extends Controller
{
    /**
     * Edit a customer's core profile fields.
     */
    public function update(Request $request, User $user): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user->id)],
            'phone' => ['nullable', 'string', 'max:30'],
            'country' => ['nullable', 'string', Rule::in(array_keys(config('countries.codes', [])))],
            'gender' => ['nullable', 'in:male,female,other'],
        ]);

        $user->update($validated);

        return back()->with('status', 'Customer details updated.');
    }

    /**
     * Set a per-user Rcoin earnings multiplier. 1.00 = standard, 2.00 = 2×
     * earner (influencer / power user), 0.50 = half earnings (flagged user).
     * Applied by RewardEngine on both cashback AND referral credits - the
     * multiplier is the same dial for both. Capped at 10× as a sanity guard.
     */
    public function setRcoinMultiplier(Request $request, User $user): RedirectResponse
    {
        $validated = $request->validate([
            'rcoin_multiplier' => ['required', 'numeric', 'min:0', 'max:10'],
        ]);

        $user->update(['rcoin_multiplier' => $validated['rcoin_multiplier']]);

        return back()->with('status', "Rcoin multiplier set to {$validated['rcoin_multiplier']}×.");
    }

    /**
     * Manually credit or debit any of a customer's wallets (Rcoin, USD, NGN,
     * crypto - anything in Currency enum). Used for goodwill credits, fraud
     * reversals, contest prizes, and refunds the finance team is processing
     * out-of-band. Every adjustment writes a wallet_transactions row via
     * WalletService with category=Adjustment so the admin who did it, when,
     * and why are all auditable.
     *
     * Direction is explicit in the request (no signed amounts) - the UI
     * sends separate Credit / Debit buttons so a typo in either direction
     * isn't possible.
     */
    public function adjustWalletBalance(Request $request, User $user): RedirectResponse
    {
        // Build the allowed-currency list dynamically from the enum so this
        // never goes stale when a new currency is added.
        $allowedCurrencies = array_column(Currency::cases(), 'value');

        $validated = $request->validate([
            'currency' => ['required', 'string', Rule::in($allowedCurrencies)],
            'direction' => ['required', 'in:credit,debit'],
            'amount' => ['required', 'numeric', 'min:0.0001', 'max:1000000'],
            'reason' => ['required', 'string', 'min:3', 'max:280'],
        ]);

        $currencyCase = Currency::from($validated['currency']);
        $walletService = app(WalletService::class);
        $wallet = $walletService->getOrCreateWallet($user, $currencyCase);

        // Rcoin is integer-only; everything else permits decimals. Cast to
        // the right shape so the ledger row stores a clean value.
        $amount = $currencyCase === Currency::RCOIN
            ? (int) $validated['amount']
            : (float) $validated['amount'];

        $adminId = (string) (auth()->guard('admin')->id() ?? 'system');
        $description = "Admin adjustment ({$currencyCase->value}): {$validated['reason']}";
        $metadata = [
            'admin_id' => $adminId,
            'reason' => $validated['reason'],
            'kind' => $validated['direction'],
            'currency' => $currencyCase->value,
        ];

        try {
            if ($validated['direction'] === 'credit') {
                $walletService->credit(
                    wallet: $wallet,
                    amount: $amount,
                    category: TransactionCategory::Adjustment,
                    description: $description,
                    metadata: $metadata,
                );
            } else {
                if ((float) $wallet->balance < $amount) {
                    return back()->withErrors([
                        'amount' => 'Customer only has '.number_format((float) $wallet->balance, $currencyCase === Currency::RCOIN ? 0 : 2)." {$currencyCase->value} - can't debit more than that.",
                    ]);
                }
                $walletService->debit(
                    wallet: $wallet,
                    amount: $amount,
                    category: TransactionCategory::Adjustment,
                    description: $description,
                    metadata: $metadata,
                );
            }
        } catch (Throwable $e) {
            report($e);

            return back()->withErrors(['amount' => 'Adjustment failed. Check the logs.']);
        }

        $verb = $validated['direction'] === 'credit' ? 'Credited' : 'Debited';
        $formatted = $currencyCase === Currency::RCOIN
            ? number_format($amount)
            : number_format($amount, 2);

        return back()->with('status', "{$verb} {$formatted} {$currencyCase->value}.");
    }

    /**
     * Ban or unban the customer. Banning blocks login + access via middleware.
     */
    public function toggleBan(User $user): RedirectResponse
    {
        $banned = $user->banned_at !== null;

        $user->update(['banned_at' => $banned ? null : now()]);

        return back()->with('status', $banned
            ? 'Customer unbanned.'
            : 'Customer banned. They have been blocked from signing in.');
    }

    /**
     * Suspend or lift suspension. Suspension is softer than ban: the customer
     * stays signed in and can use read-only surfaces (dashboard, support), but
     * write actions (checkout, cart, funding) are refused by the
     * `not-suspended` middleware. Lifting suspension also clears any pending
     * review request.
     */
    public function toggleSuspend(Request $request, User $user): RedirectResponse
    {
        $suspended = $user->suspended_at !== null;

        if ($suspended) {
            $user->update([
                'suspended_at' => null,
                'suspension_reason' => null,
                'suspension_review_requested_at' => null,
            ]);

            return back()->with('status', 'Customer suspension lifted.');
        }

        $validated = $request->validate([
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        $user->update([
            'suspended_at' => now(),
            'suspension_reason' => $validated['reason'] ?? null,
            'suspension_review_requested_at' => null,
        ]);

        return back()->with('status', 'Customer suspended. They can still sign in but cannot purchase, fund or check out.');
    }

    /**
     * Manually verify (or unverify) a customer's email. Useful when their
     * inbox is misbehaving or they're locked out of the address. Toggles so
     * the same button can revert if it was clicked by mistake.
     */
    public function toggleEmailVerification(User $user): RedirectResponse
    {
        $verified = $user->email_verified_at !== null;

        $user->update(['email_verified_at' => $verified ? null : now()]);

        return back()->with('status', $verified
            ? 'Email verification cleared.'
            : 'Email manually verified.');
    }

    /**
     * Set the customer's KYC status - `pending` (under review), `verified`, or
     * `rejected`. Bypasses the formal KycSubmission review trail; use this for
     * out-of-band cases (proof held offline, manual fast-track) or to flip a
     * verified user back to pending while you investigate.
     */
    public function setKycStatus(Request $request, User $user): RedirectResponse
    {
        $validated = $request->validate([
            'status' => ['required', Rule::in(['pending', 'verified', 'rejected'])],
        ]);

        $user->update(['kyc_status' => $validated['status']]);

        $label = match ($validated['status']) {
            'verified' => 'verified',
            'pending' => 'marked as under review',
            'rejected' => 'rejected',
        };

        return back()->with('status', 'Customer KYC '.$label.'.');
    }

    /**
     * Hold or release the customer's funds by freezing every wallet. Frozen
     * wallets cannot be debited (guarded in WalletService::debit).
     */
    public function toggleFunds(User $user): RedirectResponse
    {
        // If any wallet is still active we are holding; otherwise releasing.
        $hold = $user->wallets()->where('is_active', true)->exists();

        $user->wallets()->update(['is_active' => ! $hold]);

        return back()->with('status', $hold
            ? 'Customer funds placed on hold.'
            : 'Customer funds released.');
    }

    /**
     * Send a direct message to the customer. The dashboard notification (database
     * row) is the source of truth and saves first; the email is best-effort and
     * its failure is logged but never propagated - a misconfigured mail driver
     * shouldn't 500 the admin's click. The type ('notification' or 'warning')
     * changes the tone + accent in both surfaces.
     */
    public function message(Request $request, User $user): RedirectResponse
    {
        $validated = $request->validate([
            'type' => ['required', 'in:notification,warning'],
            'body' => ['required', 'string', 'min:5', 'max:2000'],
        ]);

        $admin = $request->user('admin');
        $isWarning = $validated['type'] === 'warning';
        $title = $isWarning ? 'Account warning' : 'Message from '.SiteSetting::get('site.name', 'RshopRefills');

        // 1. Dashboard notification - always lands. We write directly to the
        //    custom `notifications` table (App\Models\Notification) to match
        //    the project's DatabaseChannel pattern; Laravel's default Notifiable
        //    targets a different `data` JSON column that this table doesn't have.
        $dbNotification = Notification::create([
            'user_id' => $user->id,
            'type' => AdminDirectMessageMail::class,
            'title' => $title,
            'message' => $validated['body'],
            'channel' => NotificationChannel::Database,
            'status' => DeliveryStatus::Sent,
            'priority' => $isWarning ? 'high' : 'normal',
            'metadata' => [
                'kind' => $validated['type'],
                'admin_name' => $admin?->name,
            ],
            'sent_at' => now(),
        ]);

        NotificationDelivery::create([
            'notification_id' => $dbNotification->id,
            'provider' => 'database',
            'channel' => NotificationChannel::Database,
            'recipient' => (string) $user->id,
            'status' => DeliveryStatus::Sent,
            'response_payload' => ['notification_id' => $dbNotification->id],
            'attempted_at' => now(),
        ]);

        // 2. Email - best-effort. Sending the Mailable directly keeps a transport
        //    failure from rolling back the database row above.
        $emailDelivered = true;
        try {
            Mail::to($user->email)->send(new AdminDirectMessageMail(
                recipient: $user,
                type: $validated['type'],
                body: $validated['body'],
                adminName: $admin?->name,
            ));
        } catch (Throwable $e) {
            $emailDelivered = false;
            Log::warning('Admin direct message: email send failed', [
                'user_id' => $user->id,
                'type' => $validated['type'],
                'error' => $e->getMessage(),
            ]);
        }

        $status = $validated['type'] === 'warning'
            ? 'Warning sent to the customer.'
            : 'Message sent to the customer.';

        if (! $emailDelivered) {
            $status .= ' (Dashboard delivered; email could not be sent - check mail driver.)';
        }

        // Explicit redirect (not back()) so the browser's address bar ends up on
        // the customer detail page rather than the POST endpoint - a refresh
        // after submit would otherwise GET this URL and 405.
        return redirect()->route('admin.customer', $user)->with('status', $status);
    }

    /**
     * Reset a customer's transaction PIN. Clears the PIN entirely (no password
     * or current PIN needed) so the customer is prompted to set a fresh one
     * before their next wallet action. For support cases where a customer is
     * locked out or has forgotten their PIN.
     */
    public function resetTransactionPin(Request $request, User $user): RedirectResponse
    {
        if (! $user->hasTransactionPin()) {
            return redirect()->route('admin.customer', $user)->with('status', "{$user->name} does not have a transaction PIN set.");
        }

        app(TransactionPinService::class)->adminReset($user);

        Log::info('Admin reset customer transaction PIN', [
            'admin_id' => $request->user()?->id,
            'user_id' => $user->id,
        ]);

        return redirect()->route('admin.customer', $user)
            ->with('status', "Transaction PIN reset for {$user->name}. They'll be asked to set a new one.");
    }

    /**
     * Sign in as a customer (impersonation) for support/debugging. Admins
     * authenticate on the separate `admin` guard, so we log the customer into
     * the `web` guard while the admin stays signed in on the admin guard. That
     * keeps switching back clean - the admin session is never touched.
     */
    public function loginAsCustomer(Request $request, User $user): RedirectResponse
    {
        Auth::guard('web')->login($user);

        Log::info('Admin impersonation started', [
            'admin_id' => Auth::guard('admin')->id(),
            'user_id' => $user->id,
        ]);

        return redirect()->route('dashboard')->with('status', "You are now viewing the app as {$user->name}.");
    }

    /**
     * End an impersonation session: log the customer out of the `web` guard and
     * return to admin. Routed OUTSIDE the admin middleware group because the
     * active web user during impersonation is the customer. The admin guard is
     * still authenticated, so the admin lands back in the panel.
     */
    public function leaveImpersonation(Request $request): RedirectResponse
    {
        // Only a real impersonation (admin guard still authenticated) may switch
        // back; otherwise this is just a normal customer and we leave them be.
        if (! Auth::guard('admin')->check()) {
            return redirect()->route('dashboard');
        }

        $userId = Auth::guard('web')->id();
        Auth::guard('web')->logout();

        Log::info('Admin impersonation ended', [
            'admin_id' => Auth::guard('admin')->id(),
            'user_id' => $userId,
        ]);

        return $userId
            ? redirect()->route('admin.customer', $userId)->with('status', 'Returned to your admin account.')
            : redirect()->route('admin.customers');
    }

    /**
     * Email the customer a password reset link. Uses the standard password
     * broker, so the customer sets their own new password - the admin never
     * sees or chooses it.
     */
    public function sendPasswordReset(Request $request, User $user): RedirectResponse
    {
        $status = Password::sendResetLink(['email' => $user->email]);

        Log::info('Admin sent customer password reset link', [
            'admin_id' => $request->user()?->id,
            'user_id' => $user->id,
            'result' => $status,
        ]);

        return redirect()->route('admin.customer', $user)->with(
            'status',
            $status === Password::RESET_LINK_SENT
                ? "Password reset link sent to {$user->email}."
                : 'Could not send the reset link - check the customer has a valid email and the mail driver is configured.'
        );
    }
}

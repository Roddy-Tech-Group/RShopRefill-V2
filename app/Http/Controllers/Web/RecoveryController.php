<?php

namespace App\Http\Controllers\Web;

use App\Domain\PaymentInsights\Enums\ProductLine;
use App\Domain\PaymentInsights\Services\PaymentBackoffManager;
use App\Http\Controllers\Controller;
use App\Models\RecoveryAttempt;
use App\Models\TransactionRecovery;
use Illuminate\Http\Request;

class RecoveryController extends Controller
{
    public function show(string $token)
    {
        $recovery = TransactionRecovery::where('token', $token)
            ->where('expires_at', '>', now())
            ->where('status', 'pending')
            ->with('paymentSession')
            ->firstOrFail();

        // Log the view action
        RecoveryAttempt::create([
            'transaction_recovery_id' => $recovery->id,
            'action' => 'clicked_link',
        ]);

        return view('recovery.show', compact('recovery'));
    }

    public function feedback(Request $request, string $token)
    {
        // Same active-window guard as show/retry: expired or already-resolved
        // recoveries must not be reopened or overwritten by a stray POST.
        $recovery = TransactionRecovery::where('token', $token)
            ->where('expires_at', '>', now())
            ->where('status', 'pending')
            ->firstOrFail();

        $validated = $request->validate([
            'reason' => 'required|string|max:255',
            'details' => 'nullable|string|max:2000',
        ]);

        RecoveryAttempt::create([
            'transaction_recovery_id' => $recovery->id,
            'action' => 'provided_feedback',
            'payload' => $validated,
        ]);

        $recovery->update(['status' => 'feedback_received']);

        return redirect()->route('recovery.thanks');
    }

    public function retry(string $token)
    {
        $recovery = TransactionRecovery::where('token', $token)
            ->where('expires_at', '>', now())
            ->where('status', 'pending')
            ->with('paymentSession')
            ->firstOrFail();

        RecoveryAttempt::create([
            'transaction_recovery_id' => $recovery->id,
            'action' => 'retried_payment',
        ]);

        $recovery->update(['status' => 'recovered']);

        // Clear backoff lock
        /** @var PaymentBackoffManager $backoffManager */
        $backoffManager = app(PaymentBackoffManager::class);

        // Product line mirrors RecordPaymentOutcomeJob: it comes from WHAT was
        // being paid for (the attempt's target), not the session_type - a
        // wallet deposit paid by card has session_type 'card' but is still
        // wallet_funding.
        $productLine = $recovery->paymentSession->paymentAttempt?->order_id
            ? ProductLine::Checkout->value
            : ProductLine::WalletFunding->value;

        $backoffManager->resetBackoff(
            $recovery->user,
            $productLine,
            $recovery->paymentSession->customer_ip,
            $recovery->paymentSession->device_uuid
        );

        // Redirect to the original order status page (it offers retry for unpaid
        // orders) or the dashboard for wallet funding. The order hangs off the
        // session via paymentAttempt (hasOneThrough) — payment_sessions has no
        // order_id column.
        $order = $recovery->paymentSession->order;
        if ($order) {
            return redirect()->route('shop.order', ['orderNumber' => $order->order_number]);
        }

        return redirect()->route('dashboard'); // They can open the funding modal again
    }

    public function thanks()
    {
        return view('recovery.thanks');
    }
}

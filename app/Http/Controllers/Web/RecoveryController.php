<?php

namespace App\Http\Controllers\Web;

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
        $recovery = TransactionRecovery::where('token', $token)->firstOrFail();

        $validated = $request->validate([
            'reason' => 'required|string',
            'details' => 'nullable|string',
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
        $productLine = $recovery->paymentSession->session_type === 'wallet' ? 'wallet_funding' : 'checkout';

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

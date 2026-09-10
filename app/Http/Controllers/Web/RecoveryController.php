<?php

namespace App\Http\Controllers\Web;

use App\Domain\PaymentInsights\Enums\ProductLine;
use App\Domain\PaymentInsights\Enums\RecoveryStatus;
use App\Domain\PaymentInsights\Services\PaymentBackoffManager;
use App\Domain\PaymentInsights\Support\RecoveryCopy;
use App\Http\Controllers\Controller;
use App\Models\RecoveryAttempt;
use App\Models\TransactionRecovery;
use Illuminate\Http\Request;

class RecoveryController extends Controller
{
    public function show(string $token)
    {
        $recovery = $this->openRecovery($token, ['paymentSession']);

        RecoveryAttempt::create([
            'transaction_recovery_id' => $recovery->id,
            'action' => 'clicked_link',
        ]);

        // First click only: the timestamp records when they first engaged, and
        // a revisit must not reset it or downgrade a retry back to a click.
        $recovery->update([
            'clicked_at' => $recovery->clicked_at ?? now(),
            'status' => $recovery->status === RecoveryStatus::Pending
                ? RecoveryStatus::Clicked
                : $recovery->status,
        ]);

        return view('recovery.show', [
            'recovery' => $recovery,
            'copy' => RecoveryCopy::for($recovery->failure_reason_code, $recovery->productLine()),
        ]);
    }

    public function feedback(Request $request, string $token)
    {
        $recovery = $this->openRecovery($token);

        $validated = $request->validate([
            'reason' => 'required|string|max:255',
            'details' => 'nullable|string|max:2000',
        ]);

        RecoveryAttempt::create([
            'transaction_recovery_id' => $recovery->id,
            'action' => 'provided_feedback',
            'payload' => $validated,
        ]);

        $recovery->update(['status' => RecoveryStatus::FeedbackReceived]);

        return redirect()->route('recovery.thanks');
    }

    public function retry(string $token)
    {
        $recovery = $this->openRecovery($token, ['paymentSession']);

        RecoveryAttempt::create([
            'transaction_recovery_id' => $recovery->id,
            'action' => 'retried_payment',
        ]);

        // Retrying, NOT recovered: the customer has gone back to checkout,
        // which is intent, not money. The recovery is marked recovered only
        // when a payment actually confirms (RecordPaymentOutcomeJob), and the
        // link stays live meanwhile so a second attempt is not locked out.
        $recovery->update([
            'status' => RecoveryStatus::Retrying,
            'retried_at' => $recovery->retried_at ?? now(),
        ]);

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

        // Land where the customer can actually pay. The old target was the
        // order page, whose "Complete payment" button only rendered for a
        // pending order, so a declined (Failed) or swept (Cancelled) order was
        // a dead end at the exact moment the email had promised a way back.
        // Checkout keeps the cart until a payment confirms, so it resumes the
        // same purchase; a top-up lands on the wallet with the sheet open and
        // the amount filled in.
        $session = $recovery->paymentSession;

        if ($session->order) {
            return redirect()->route('shop.checkout');
        }

        return redirect()->route('dashboard', [
            'fund' => strtoupper((string) ($session->display_currency ?: $session->currency)),
            'amount' => $session->amount,
        ]);
    }

    public function thanks()
    {
        return view('recovery.thanks');
    }

    /**
     * A recovery the customer can still act on: unexpired, and not already
     * resolved. Shared by every route so the guard cannot drift between them.
     *
     * @param  array<int, string>  $with
     */
    private function openRecovery(string $token, array $with = []): TransactionRecovery
    {
        return TransactionRecovery::where('token', $token)
            ->where('expires_at', '>', now())
            ->whereIn('status', RecoveryStatus::openValues())
            ->with($with)
            ->firstOrFail();
    }
}

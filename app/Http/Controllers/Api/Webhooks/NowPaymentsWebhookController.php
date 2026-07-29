<?php

namespace App\Http\Controllers\Api\Webhooks;

use App\Domain\Payment\Support\NowPaymentsStatus;
use App\Http\Controllers\Controller;
use App\Jobs\VerifyPaymentJob;
use App\Models\PaymentAttempt;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class NowPaymentsWebhookController extends Controller
{
    public function handle(Request $request)
    {
        // Signature verification (key-sorted JSON, HMAC-SHA512, fail-closed on a
        // missing IPN secret) is handled by the VerifyWebhookSignature middleware
        // via NowPaymentsSignature — the request is already authenticated here.
        $payload = $request->all();
        // The /payment flow (what this integration uses) keys every notification
        // by payment_id - which is exactly what the provider stores as
        // gateway_reference. invoice_id only exists for the separate /invoice flow
        // and is absent here, so the old lookup-by-invoice_id never matched and
        // confirmed payments were silently dropped.
        $paymentId = $payload['payment_id'] ?? null;
        $paymentStatus = $payload['payment_status'] ?? null;

        // Redacted log: only the payment id + status, never the full payload.
        Log::info('NowPayments webhook received', ['payment_id' => $paymentId, 'payment_status' => $paymentStatus]);

        if (! $paymentId) {
            return response()->json(['message' => 'Missing payment ID'], 400);
        }

        // Locate attempt by the stored gateway_reference (= NOWPayments payment_id).
        $attempt = PaymentAttempt::where('gateway_reference', (string) $paymentId)->first();

        if (! $attempt) {
            Log::warning("NowPayments webhook: payment attempt not found for payment {$paymentId}");

            return response()->json(['message' => 'Payment attempt not found'], 404);
        }

        $attempt->webhook_payload = $payload;
        $attempt->save();

        // Every status except the purely informational in-flight ones triggers a
        // server-to-server verification, INCLUDING statuses we do not recognise.
        //
        // This used to dispatch on a hardcoded set of four. failed, expired and
        // refunded were acknowledged with a 200 and then dropped, so those attempts
        // sat on Pending indefinitely — the customer saw a spinner, the sweeper had
        // nothing to act on, and support had no signal. NOWPayments also documents
        // statuses in its help centre that are absent from its API reference, so an
        // unknown value must mean "go ask the API", never "assume nothing happened".
        if (NowPaymentsStatus::warrantsVerification($paymentStatus)) {
            VerifyPaymentJob::dispatch($attempt);
        }

        return response()->json(['message' => 'Webhook processed'], 200);
    }
}

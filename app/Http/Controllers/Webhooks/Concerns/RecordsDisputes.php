<?php

namespace App\Http\Controllers\Webhooks\Concerns;

use App\Domain\Risk\DTOs\DisputeNotice;
use App\Models\PaymentWebhook;
use Illuminate\Http\JsonResponse;

/**
 * A gateway's dispute webhook: recorded through DisputeRecorder (the same path
 * the hourly sync takes), the raw webhook row marked with how it went, and
 * Kora, Paystack or Flutterwave told 200 so they stop retrying. A notice with
 * no dispute id is kept on the webhook row for a person to read.
 *
 * Expects the using controller to hold `$disputes` (DisputeRecorder).
 */
trait RecordsDisputes
{
    private function recordDispute(PaymentWebhook $webhook, ?DisputeNotice $notice): JsonResponse
    {
        if ($notice === null) {
            $webhook->update([
                'processed' => true,
                'processing_status' => 'failed',
                'exception_traces' => 'Dispute event without a dispute id; nothing recorded.',
            ]);

            return response()->json(['status' => 'success', 'message' => 'Event acknowledged'], 200);
        }

        $dispute = $this->disputes->record($notice, 'webhook');

        $webhook->update([
            'processed' => true,
            'processing_status' => 'processed',
            'reference' => $dispute->gateway_dispute_id,
        ]);

        return response()->json(['status' => 'success', 'message' => 'Dispute recorded'], 200);
    }
}

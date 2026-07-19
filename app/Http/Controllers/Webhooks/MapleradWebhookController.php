<?php

namespace App\Http\Controllers\Webhooks;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use App\Models\VirtualCard;
use App\Domain\Ledger\Services\LedgerService;

class MapleradWebhookController extends Controller
{
    public function __construct(private LedgerService $ledgerService)
    {
    }

    public function handle(Request $request)
    {
        $payload = $request->json()->all();
        $event = $payload['event'] ?? 'unknown';
        $data = $payload['data'] ?? [];

        Log::info("Maplerad Webhook Received: {$event}", ['id' => $payload['id'] ?? null]);

        try {
            switch ($event) {
                case 'issuing_transaction.created':
                    $this->handleCardTransaction($data);
                    break;
                    
                case 'issuing_card.created':
                    // Card creation async completion if needed
                    break;

                case 'transfer.successful':
                case 'transfer.failed':
                    // Handle wallet withdrawal/transfer status updates
                    break;
                    
                default:
                    Log::info("Unhandled Maplerad event: {$event}");
                    break;
            }

            return response()->json(['status' => 'success']);
        } catch (\Exception $e) {
            Log::error('Maplerad webhook processing failed', [
                'error' => $e->getMessage(),
                'payload' => $payload
            ]);
            // Still return 200 so the provider doesn't infinitely retry unless it's a critical fault
            return response()->json(['status' => 'error', 'message' => 'Internal processing error'], 200);
        }
    }

    private function handleCardTransaction(array $data)
    {
        $cardId = $data['card_id'] ?? null;
        $amount = $data['amount'] ?? 0; // Usually minor units depending on provider
        $currency = $data['currency'] ?? 'USD';
        
        if (!$cardId) return;

        $card = VirtualCard::where('provider_card_id', $cardId)->first();
        if (!$card) {
            Log::warning("Maplerad webhook received for unknown card ID: {$cardId}");
            return;
        }

        // In a real JIT/Prefunded architecture, we would update the local card balance here
        // and optionally post a ledger entry to track the exact merchant spend.
        // For now, we simply log the transaction and assume the provider's balance is source of truth.
        Log::info("Maplerad Virtual Card charged", [
            'card_id' => $card->id,
            'amount' => $amount,
            'merchant' => $data['merchant']['name'] ?? 'Unknown',
        ]);
        
        // Example: $card->decrement('balance', $amount / 100);
    }
}

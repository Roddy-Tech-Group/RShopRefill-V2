<?php

namespace App\Console\Commands;

use App\Domain\GiftCardTrading\Services\TradeReviewService;
use App\Domain\Notification\Services\AdminNotificationService;
use App\Enums\TradeStatus;
use App\Models\Admin;
use App\Models\Payout;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

class CheckStuckBankPayouts extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'payouts:check-stuck';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Check for bank payouts stuck in pending state for too long and fallback to manual processing with gateway verification';

    /**
     * Execute the console command.
     */
    public function handle(TradeReviewService $reviewService)
    {
        $stuckPayouts = Payout::where('status', 'pending')
            ->where('created_at', '<', now()->subMinutes(15))
            ->with('trade')
            ->get();

        if ($stuckPayouts->isEmpty()) {
            $this->info('No stuck payouts found.');

            return;
        }

        $systemAdmin = Admin::first();
        $secretKey = config('services.flutterwave.secret_key');

        foreach ($stuckPayouts as $payout) {
            $this->info("Verifying stuck payout ID: {$payout->id} (Ref: {$payout->reference}) for Trade ID: {$payout->trade_id}");

            // CRITICAL SAFEGUARD: Verify with Flutterwave before reverting to prevent double-payouts
            try {
                $response = Http::timeout(10)
                    ->withToken($secretKey)
                    ->get('https://api.flutterwave.com/v3/transfers', [
                        'reference' => $payout->reference,
                    ]);

                if ($response->successful()) {
                    $data = $response->json('data');

                    // If Flutterwave HAS a record of this transfer, we must be careful!
                    if (! empty($data) && isset($data[0])) {
                        $fwStatus = strtoupper($data[0]['status']);

                        if ($fwStatus === 'SUCCESSFUL') {
                            $this->info('Flutterwave actually succeeded. Updating payout to successful.');
                            // It succeeded! The webhook might have been lost.
                            $payout->update([
                                'status' => 'successful',
                                'gateway_response' => ['note' => 'Recovered via cron check', 'data' => $data[0]],
                            ]);

                            if ($payout->trade) {
                                $reviewService->updateStatus(
                                    $payout->trade,
                                    TradeStatus::Paid,
                                    $systemAdmin,
                                    null,
                                    'Bank transfer completed successfully (recovered by system check).'
                                );
                            }

                            continue; // Move to next payout
                        } elseif (in_array($fwStatus, ['PENDING', 'PROCESSING', 'NEW'])) {
                            $this->info('Flutterwave is still processing this transfer. Skipping fallback to prevent double payout.');

                            // We MUST NOT fallback, otherwise admin might manually pay it while FW is still trying.
                            continue;
                        }
                        // If it's FAILED, we can safely proceed with fallback below.
                    }
                }
            } catch (\Exception $e) {
                $this->error('Failed to verify with Flutterwave: '.$e->getMessage());

                // If we can't verify with the gateway, do NOT blindly fallback, it's too dangerous.
                continue;
            }

            // If we reached here, Flutterwave either returned empty data (never received the request)
            // or returned FAILED. We can safely fallback to manual.
            $this->info("Reverting stuck payout ID: {$payout->id} to manual processing.");

            $payout->update([
                'status' => 'failed',
                'gateway_response' => ['error' => 'Automated timeout reached without gateway success. Falling back to manual.'],
            ]);

            if ($payout->trade) {
                $reviewService->updateStatus(
                    $payout->trade,
                    TradeStatus::Approved,
                    $systemAdmin,
                    null,
                    'Automated bank payout timed out (over 15 mins). Reverted to Approved for manual processing.'
                );

                $payout->trade->messages()->create([
                    'sender_type' => Admin::class,
                    'sender_id' => $systemAdmin->id,
                    'message' => 'Automated bank payout timed out. Reverted to Approved for manual transfer.',
                ]);

                app(AdminNotificationService::class)->push(
                    'payout_reverted',
                    'Automated Payout Reverted',
                    'Trade #'.substr($payout->trade->uuid, 0, 8).' automated payout failed. It has been safely reverted to Approved for manual payment.',
                    route('admin.gift-cards.trades.show', $payout->trade)
                );
            }
        }

        $this->info('Completed checking stuck payouts.');
    }
}

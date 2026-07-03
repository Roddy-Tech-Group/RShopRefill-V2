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

        if (! $systemAdmin) {
            $this->error('No admin available to attribute system actions; aborting stuck-payout check.');

            return;
        }

        foreach ($stuckPayouts as $payout) {
            $this->info("Verifying stuck payout ID: {$payout->id} (Ref: {$payout->reference}) for Trade ID: {$payout->trade_id}");

            // Verify with Flutterwave before touching anything. We must positively
            // identify THIS transfer (by matching reference) and only act on a
            // definitive status - otherwise we risk re-paying a transfer that went through.
            $fwRecord = null;
            try {
                $response = Http::timeout(10)
                    ->withToken($secretKey)
                    ->get('https://api.flutterwave.com/v3/transfers', [
                        'reference' => $payout->reference,
                    ]);

                if ($response->successful()) {
                    // The list endpoint may not filter by reference server-side, so match
                    // it ourselves - never act on some other transfer's record.
                    $fwRecord = collect($response->json('data') ?? [])
                        ->first(fn ($t) => ($t['reference'] ?? null) === $payout->reference);
                }
            } catch (\Exception $e) {
                $this->error('Failed to verify with Flutterwave: '.$e->getMessage());
            }

            // Couldn't positively identify this transfer (empty data, no reference match,
            // or the verify call errored). It may still be in flight or already delivered -
            // reverting now could double-pay, so leave it pending and ask an admin to
            // reconcile by hand. NEVER auto-revert on an unconfirmed lookup.
            if (! $fwRecord) {
                $this->warn("Payout {$payout->id} unconfirmed at gateway; leaving pending for manual reconciliation.");

                app(AdminNotificationService::class)->push(
                    'payout_unconfirmed',
                    'Payout Needs Manual Reconciliation',
                    'Trade #'.substr((string) optional($payout->trade)->uuid, 0, 8).' bank payout has been pending over 15 mins and could not be confirmed with the gateway. Check Flutterwave directly before any manual payment.',
                    $payout->trade ? route('admin.gift-cards.trades.show', $payout->trade) : null
                );

                continue;
            }

            $fwStatus = strtoupper($fwRecord['status'] ?? '');

            if ($fwStatus === 'SUCCESSFUL') {
                $this->info('Flutterwave succeeded (webhook likely lost). Marking payout successful.');
                $payout->update([
                    'status' => 'successful',
                    'gateway_response' => ['note' => 'Recovered via cron check', 'data' => $fwRecord],
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

                continue;
            }

            if (in_array($fwStatus, ['PENDING', 'PROCESSING', 'NEW'], true)) {
                $this->info('Flutterwave still processing this transfer. Skipping to prevent double payout.');

                continue;
            }

            // Only here - a CONFIRMED, reference-matched FAILED transfer - is it safe to
            // mark failed and reopen the trade for retry / manual payment.
            $this->info("Confirmed failed at gateway. Reverting payout ID: {$payout->id} for manual processing.");

            $payout->update([
                'status' => 'failed',
                'gateway_response' => ['note' => 'Gateway-confirmed failed', 'data' => $fwRecord],
            ]);

            if ($payout->trade) {
                $reviewService->updateStatus(
                    $payout->trade,
                    TradeStatus::Approved,
                    $systemAdmin,
                    null,
                    'Bank transfer failed at gateway. Reverted to Approved for retry / manual processing.'
                );

                $payout->trade->messages()->create([
                    'sender_type' => Admin::class,
                    'sender_id' => $systemAdmin->id,
                    'message' => 'Automated bank payout failed at the gateway. Reverted to Approved for manual transfer.',
                ]);

                app(AdminNotificationService::class)->push(
                    'payout_reverted',
                    'Automated Payout Reverted',
                    'Trade #'.substr($payout->trade->uuid, 0, 8).' bank payout failed at the gateway and was reverted to Approved for manual payment.',
                    route('admin.gift-cards.trades.show', $payout->trade)
                );
            }
        }

        $this->info('Completed checking stuck payouts.');
    }
}

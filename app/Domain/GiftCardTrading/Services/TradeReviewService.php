<?php

namespace App\Domain\GiftCardTrading\Services;

use App\Enums\TradeStatus;
use App\Models\Admin;
use App\Models\GiftCardTrade;
use App\Models\TradeAuditLog;
use Illuminate\Support\Facades\DB;

class TradeReviewService
{
    /**
     * Update the trade status with proper audit logging.
     */
    public function updateStatus(GiftCardTrade $trade, TradeStatus $newStatus, Admin $admin, ?string $rejectionReason = null, ?string $internalNote = null): GiftCardTrade
    {
        return DB::transaction(function () use ($trade, $newStatus, $admin, $rejectionReason, $internalNote) {
            $oldStatus = $trade->status;

            if ($oldStatus === $newStatus) {
                return $trade;
            }

            // Simple State Machine validation could go here
            if ($oldStatus === TradeStatus::Paid || $oldStatus === TradeStatus::Rejected) {
                throw new \Exception("Cannot transition from a final state ({$oldStatus->value}).");
            }

            $trade->status = $newStatus;
            $trade->reviewed_by = $admin->id;
            $trade->reviewed_at = now();

            if ($rejectionReason) {
                $trade->rejection_reason = $rejectionReason;
            }

            if ($internalNote) {
                $trade->admin_notes = $trade->admin_notes
                    ? $trade->admin_notes."\n---\n".now()->toDateTimeString()." [{$admin->name}]: ".$internalNote
                    : now()->toDateTimeString()." [{$admin->name}]: ".$internalNote;
            }

            $trade->save();

            TradeAuditLog::create([
                'trade_id' => $trade->id,
                'actor_type' => Admin::class,
                'actor_id' => $admin->id,
                'action' => 'status_changed',
                'previous_status' => $oldStatus->value,
                'new_status' => $newStatus->value,
                'metadata' => [
                    'rejection_reason' => $rejectionReason,
                    'internal_note' => $internalNote,
                ],
            ]);

            // If Approved, orchestrate payout
            if ($newStatus === TradeStatus::Approved) {
                // We typically fire an event here which is picked up by PayoutOrchestrator
                // event(new \App\Events\GiftCardTrading\TradeApproved($trade));
            }

            return $trade;
        });
    }

    /**
     * Mark a trade as Need More Information.
     */
    public function requestMoreInfo(GiftCardTrade $trade, Admin $admin, string $message): GiftCardTrade
    {
        $this->updateStatus($trade, TradeStatus::NeedMoreInfo, $admin, null, 'Requested more info from user');

        // Use Chat service to send the actual message
        app(TradeChatService::class)->sendMessage($trade, $admin, $message);

        return $trade;
    }
}

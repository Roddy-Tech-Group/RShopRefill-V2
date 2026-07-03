<?php

namespace App\Domain\GiftCardTrading\Services;

use App\Models\GiftCardTrade;
use App\Models\TradeAuditLog;
use App\Models\TradeMessage;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

class TradeChatService
{
    /**
     * Send a message on a trade.
     */
    public function sendMessage(GiftCardTrade $trade, Model $sender, string $messageText, ?UploadedFile $attachment = null): TradeMessage
    {
        return DB::transaction(function () use ($trade, $sender, $messageText, $attachment) {

            $attachmentPath = null;
            if ($attachment) {
                $attachmentPath = $attachment->store('gift-cards/chats/'.date('Y/m'), 'public');
            }

            $message = TradeMessage::create([
                'trade_id' => $trade->id,
                'sender_type' => get_class($sender),
                'sender_id' => $sender->id,
                'message' => $messageText,
                'attachment_path' => $attachmentPath,
            ]);

            TradeAuditLog::create([
                'trade_id' => $trade->id,
                'actor_type' => get_class($sender),
                'actor_id' => $sender->id,
                'action' => 'message_sent',
                'metadata' => [
                    'message_id' => $message->id,
                    'has_attachment' => ! is_null($attachmentPath),
                ],
            ]);

            // Fire event so notifications can be sent (e.g., if User sends, notify Admin and vice versa)
            // event(new \App\Events\GiftCardTrading\TradeMessageSent($message));

            return $message;
        });
    }

    /**
     * Mark messages as read.
     */
    public function markAsRead(GiftCardTrade $trade, string $viewerType): void
    {
        TradeMessage::where('trade_id', $trade->id)
            ->where('sender_type', '!=', $viewerType)
            ->whereNull('read_at')
            ->update(['read_at' => now()]);
    }
}

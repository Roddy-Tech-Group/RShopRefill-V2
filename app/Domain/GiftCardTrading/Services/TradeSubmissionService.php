<?php

namespace App\Domain\GiftCardTrading\Services;

use App\Domain\Communication\Enums\CommunicationCategory;
use App\Domain\Communication\Mail\System\AdminNewTradeMail;
use App\Domain\Communication\Services\AdminNotificationService;
use App\Domain\Communication\Services\CommunicationManager;
use App\Enums\TradeStatus;
use App\Models\GiftCardRate;
use App\Models\GiftCardTrade;
use App\Models\TradeAuditLog;
use App\Models\User;
use App\Support\FeatureFlag;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

class TradeSubmissionService
{
    public function __construct(
        protected RateEngine $rateEngine,
        protected FraudDetectionService $fraudService
    ) {}

    /**
     * Submit a new gift card trade.
     *
     * @throws \DomainException when gift card trading is switched off
     */
    public function submitTrade(
        int $userId,
        GiftCardRate $rate,
        float $declaredValue,
        string $payoutMethod,
        ?int $bankAccountId,
        ?string $codePin,
        ?string $whatsappNumber,
        array $images // array of ['file' => UploadedFile, 'type' => 'front'|'back'|'receipt']
    ): GiftCardTrade {
        // Kill switch at the ONE seam every submission funnels through. The
        // flag previously only hid storefront links while this dashboard flow
        // stayed fully open, so trades kept arriving with trading "off".
        // Fails closed (missing row = off), matching the feature: middleware.
        if (! FeatureFlag::on('sell_gift_cards', false)) {
            throw new \DomainException('Gift card trading is not available right now.');
        }

        return DB::transaction(function () use ($userId, $rate, $declaredValue, $payoutMethod, $bankAccountId, $codePin, $whatsappNumber, $images) {

            $calculatedPayout = $this->rateEngine->calculatePayout($rate, $declaredValue);

            $trade = GiftCardTrade::create([
                'user_id' => $userId,
                'rate_id' => $rate->id,
                'payout_method' => $payoutMethod,
                'bank_account_id' => $payoutMethod === 'bank' ? $bankAccountId : null,
                'declared_value' => $declaredValue,
                'calculated_payout' => $calculatedPayout,
                'payout_currency' => $rate->currency,
                'code_pin' => $codePin,
                'whatsapp_number' => $whatsappNumber,
                'status' => TradeStatus::PendingReview,
            ]);

            foreach ($images as $image) {
                $file = $image['file'];
                $hash = $this->fraudService->generateFileHash($file);

                if ($this->fraudService->isDuplicateImage($hash)) {
                    // Flag the trade directly as NeedMoreInfo or let admins see it in UI
                    $trade->update(['admin_notes' => 'SYSTEM FLAG: Duplicate image detected.']);
                }

                $path = $file->store('gift-cards/'.date('Y/m'), 'public');

                $trade->media()->create([
                    'file_path' => $path,
                    'file_hash' => $hash,
                    'type' => $image['type'] ?? 'front',
                ]);
            }

            TradeAuditLog::create([
                'trade_id' => $trade->id,
                'actor_type' => User::class,
                'actor_id' => $userId,
                'action' => 'trade_submitted',
                'new_status' => TradeStatus::PendingReview->value,
            ]);

            // Dispatch push and dashboard notification to all admins
            app(AdminNotificationService::class)->push(
                type: 'new_trade',
                title: 'New Trade Submitted',
                message: "A new trade ({$trade->rate->brand->name}) was submitted by {$trade->user->name}.",
                url: '/admin/gift-cards/trades/'.$trade->id
            );

            // Send Email notification to all admins
            app(CommunicationManager::class)->sendToAdmin(
                category: CommunicationCategory::AdminAlert,
                title: 'New Trade Submitted',
                message: "A new trade ({$trade->rate->brand->name}) was submitted by {$trade->user->name}.",
                mailable: new AdminNewTradeMail($trade),
                correlationId: 'new_trade_'.$trade->id
            );

            // Dispatch events here (e.g., TradeSubmitted)
            // event(new \App\Events\GiftCardTrading\TradeSubmitted($trade));

            return $trade;
        });
    }
}

<?php

namespace App\Domain\Fulfillment\Console;

use App\Domain\Fulfillment\Enums\FulfillmentStatus;
use App\Domain\Fulfillment\Providers\ZenditFulfillmentProvider;
use App\Models\OrderItem;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

/**
 * Re-pull gift-card codes that were delivered by Zendit but never captured into
 * our stored payload - e.g. Apple US eGift cards return the code in
 * receipt.accountId, which normalizeResponse() used to ignore, so the order sat
 * fulfilled with a blank "code appears here" tile.
 *
 * Safety rails:
 *   - Only touches fulfilled Zendit VOUCHER items whose stored payload has no
 *     code (eSIM / top-up items are skipped).
 *   - NEVER touches admin manual fulfillments (fulfillment_reference MANUAL-*)
 *     or pre-dispatch placeholders (PENDING-*).
 *   - Only ever writes fulfillment_payload; it never changes fulfillment_status.
 *   - A Zendit transaction that is actually FAILED is reported, not rewritten -
 *     those need a refund / re-fulfill decision, not a code.
 */
class BackfillVoucherCodesCommand extends Command
{
    protected $signature = 'zendit:backfill-voucher-codes
        {--dry-run : Report what would change without writing anything}
        {--order= : Limit to a single order (order_number or id)}
        {--limit=500 : Maximum fulfilled items to scan}';

    protected $description = 'Re-pull missing gift-card codes from Zendit for fulfilled voucher orders whose stored payload has no code. Skips manually-fulfilled items.';

    public function handle(ZenditFulfillmentProvider $provider): int
    {
        $base = rtrim((string) (config('services.zendit.base_url') ?: 'https://api.zendit.io/v1'), '/');
        $key = (string) config('services.zendit.api_key');
        $dryRun = (bool) $this->option('dry-run');

        $query = OrderItem::query()
            ->with('order:id,order_number')
            ->where('provider_name', 'zendit')
            ->where('fulfillment_status', FulfillmentStatus::Fulfilled->value)
            ->whereNotNull('fulfillment_reference')
            ->where('fulfillment_reference', 'not like', 'MANUAL-%')
            ->where('fulfillment_reference', 'not like', 'PENDING-%');

        if ($order = $this->option('order')) {
            $query->whereHas('order', fn ($q) => $q->where('order_number', $order)->orWhere('id', $order));
        }

        $items = $query->latest('id')->limit((int) $this->option('limit'))->get();

        $this->info(($dryRun ? '[DRY RUN] ' : '')."Scanning {$items->count()} fulfilled Zendit item(s)...");

        $recovered = 0;
        $failed = 0;
        $skipped = 0;
        $notFound = 0;

        foreach ($items as $item) {
            $payload = (array) ($item->fulfillment_payload ?? []);

            // Already has a code, or is an eSIM / top-up (not a gift card) -> leave it.
            if ($this->hasCode($payload) || $this->isEsimOrTopup($payload)) {
                $skipped++;

                continue;
            }

            $ref = (string) $item->fulfillment_reference;
            $resp = Http::withoutVerifying()->withToken($key)->acceptJson()->timeout(15)
                ->get("{$base}/vouchers/purchases/{$ref}");

            if ($resp->status() === 404) {
                $this->warn("  item {$item->id}: transaction {$ref} not found at Zendit");
                $notFound++;

                continue;
            }

            $body = (array) $resp->json();
            $status = strtoupper((string) ($body['status'] ?? ''));

            if (in_array($status, ['FAILED', 'REJECTED', 'CANCELLED'], true)) {
                $reason = $body['error']['code'] ?? 'unknown';
                $this->error("  item {$item->id} (order {$item->order?->order_number}): Zendit {$status} ({$reason}) - NOT a code issue, needs refund/re-fulfill");
                $failed++;

                continue;
            }

            if (! in_array($status, ['DONE', 'COMPLETED', 'SUCCESS'], true)) {
                $this->line("  item {$item->id}: Zendit status {$status} - not complete, skipped");
                $skipped++;

                continue;
            }

            $normalized = $provider->normalizeResponse($body);

            if (! $this->hasCode($normalized)) {
                $this->warn("  item {$item->id}: DONE but no code in the Zendit receipt - inspect manually");
                $skipped++;

                continue;
            }

            $this->info("  item {$item->id} (order {$item->order?->order_number}): code recovered");

            if (! $dryRun) {
                $item->fulfillment_payload = $normalized;
                $item->save();
            }
            $recovered++;
        }

        $this->newLine();
        $this->info(($dryRun ? '[DRY RUN] ' : '')."Done. recovered={$recovered} needs-refund={$failed} skipped={$skipped} not-found={$notFound}");

        if ($failed > 0) {
            $this->warn("{$failed} item(s) are FAILED at Zendit and were left untouched - decide refund or re-fulfill for those.");
        }

        return self::SUCCESS;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function hasCode(array $payload): bool
    {
        foreach (['code', 'voucher_code', 'redeem_code', 'card_number', 'serial', 'activation_code', 'pin', 'epin', 'redemption_url'] as $k) {
            if (! empty($payload[$k]) && is_scalar($payload[$k])) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function isEsimOrTopup(array $payload): bool
    {
        if (! empty($payload['esim']) || ! empty($payload['topup']) || ! empty($payload['topup_recipient_phone'])) {
            return true;
        }

        foreach (['iccid', 'lpa', 'qrcode_url', 'qr_manual_code', 'esim_iccid'] as $k) {
            if (! empty($payload[$k])) {
                return true;
            }
        }

        return false;
    }
}

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Promotes crypto settlement facts out of the metadata JSON blob into real columns.
 *
 * Everything crypto-specific — what the customer was asked to send, what actually
 * arrived, on which chain, to which address — lived only inside
 * payment_attempts.metadata. That is unqueryable and unindexable, which meant we
 * could not answer "which coins do customers actually pay with", could not reconcile
 * our ledger against a gateway settlement export in SQL, and could not find
 * historical overpayments at all.
 *
 * The JSON is left in place and still written; these columns are a queryable
 * projection of it, backfilled below so existing rows are not blind spots.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payment_attempts', function (Blueprint $table) {
            // What we asked for, and what turned up.
            $table->string('pay_currency', 20)->nullable()->after('currency');
            $table->decimal('pay_amount', 30, 12)->nullable()->after('pay_currency');
            $table->decimal('actually_paid', 30, 12)->nullable()->after('pay_amount');

            // Where it was sent. payin_extra_id is the memo/destination tag —
            // without it, payments on tag-based chains cannot be matched at all.
            $table->string('network', 40)->nullable()->after('actually_paid');
            $table->string('pay_address', 255)->nullable()->after('network');
            $table->string('payin_extra_id', 128)->nullable()->after('pay_address');

            // Links a top-up payment back to the original underpaid one.
            $table->string('purchase_id', 64)->nullable()->after('payin_extra_id');

            // What we actually netted, and the gateway's own fee breakdown.
            // outcome_amount is revenue — never use it to decide whether the
            // customer paid enough; that is actually_paid vs pay_amount.
            $table->decimal('outcome_amount', 30, 12)->nullable()->after('purchase_id');
            $table->string('outcome_currency', 20)->nullable()->after('outcome_amount');
            $table->json('gateway_fee')->nullable()->after('outcome_currency');

            // Signed settlement delta, positive when the customer overpaid.
            // Stored rather than derived so surplus is findable in SQL.
            $table->decimal('settlement_delta', 30, 12)->nullable()->after('gateway_fee');

            $table->index('pay_currency');
            $table->index('purchase_id');
        });

        $this->backfill();
    }

    /**
     * Project existing metadata->crypto blobs into the new columns. Chunked so a
     * large table does not load into memory at once.
     */
    private function backfill(): void
    {
        DB::table('payment_attempts')
            ->whereNotNull('metadata')
            ->whereIn('gateway', ['nowpayments', 'crypto'])
            ->orderBy('id')
            ->chunkById(200, function ($rows) {
                foreach ($rows as $row) {
                    $crypto = data_get(json_decode($row->metadata ?? '{}', true), 'crypto');

                    if (! is_array($crypto) || $crypto === []) {
                        continue;
                    }

                    $payAmount = $this->number($crypto['pay_amount'] ?? null);
                    $actuallyPaid = $this->number($crypto['actually_paid'] ?? null);

                    DB::table('payment_attempts')->where('id', $row->id)->update([
                        'pay_currency' => $crypto['pay_currency'] ?? null,
                        'pay_amount' => $payAmount,
                        'actually_paid' => $actuallyPaid,
                        'network' => $crypto['network'] ?? null,
                        'pay_address' => $crypto['pay_address'] ?? null,
                        'payin_extra_id' => $crypto['payin_extra_id'] ?? null,
                        'purchase_id' => $crypto['purchase_id'] ?? null,
                        'outcome_amount' => $this->number($crypto['outcome_amount'] ?? null),
                        'outcome_currency' => $crypto['outcome_currency'] ?? null,
                        'gateway_fee' => isset($crypto['fee']) ? json_encode($crypto['fee']) : null,
                        'settlement_delta' => ($payAmount !== null && $actuallyPaid !== null)
                            ? $actuallyPaid - $payAmount
                            : null,
                    ]);
                }
            });
    }

    private function number(mixed $value): ?float
    {
        return is_numeric($value) ? (float) $value : null;
    }

    public function down(): void
    {
        Schema::table('payment_attempts', function (Blueprint $table) {
            $table->dropIndex(['pay_currency']);
            $table->dropIndex(['purchase_id']);
            $table->dropColumn([
                'pay_currency',
                'pay_amount',
                'actually_paid',
                'network',
                'pay_address',
                'payin_extra_id',
                'purchase_id',
                'outcome_amount',
                'outcome_currency',
                'gateway_fee',
                'settlement_delta',
            ]);
        });
    }
};

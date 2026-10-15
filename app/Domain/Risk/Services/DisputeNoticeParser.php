<?php

namespace App\Domain\Risk\Services;

use App\Domain\Risk\DTOs\DisputeNotice;
use App\Domain\Risk\Enums\DisputeStatus;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Reads each gateway's dispute shapes into a DisputeNotice. Field names come
 * from the gateways' published samples (Flutterwave and Kora chargeback docs,
 * Paystack dispute docs), read 2026-10-12. Every reader is defensive: a field
 * a gateway leaves out reads as null, never as an error, and a notice without
 * a dispute id is no notice at all.
 *
 * Gateway timestamps are UTC; they are converted to the app timezone here, or
 * a deadline would read an hour late (app runs on Africa/Douala).
 */
class DisputeNoticeParser
{
    /**
     * Flutterwave: the chargeback webhook's `data` or one row of
     * GET /v3/chargebacks. The webhook names the payment only by flw_ref; the
     * list adds tx_ref (our reference) and transaction_id (their charge id).
     *
     * Statuses: initiated, pending, accepted, declined, won, lost, reversed.
     * "declined" means we declined the claim and the bank is deciding.
     *
     * @param  array<string, mixed>  $data
     */
    public function flutterwave(array $data): ?DisputeNotice
    {
        $id = $this->text($data['id'] ?? null);

        if ($id === null) {
            return null;
        }

        $gatewayStatus = Str::lower($this->text($data['status'] ?? null) ?? 'initiated');

        return new DisputeNotice(
            gateway: 'flutterwave',
            disputeId: $id,
            paymentReferences: $this->references([$data['tx_ref'] ?? null, $data['transaction_id'] ?? null, $data['flw_ref'] ?? null]),
            amount: $this->amount($data['amount'] ?? null),
            currency: $this->currency($data['currency'] ?? null),
            reason: $this->text($data['comment'] ?? null),
            stage: $this->text($data['stage'] ?? null),
            gatewayStatus: $gatewayStatus,
            status: match ($gatewayStatus) {
                'won', 'reversed' => DisputeStatus::Won,
                'lost', 'accepted' => DisputeStatus::Lost,
                default => DisputeStatus::Open,
            },
            dueAt: $this->time($data['due_date'] ?? ($data['due_datetime'] ?? null)),
            openedAt: $this->time($data['created_at'] ?? ($data['created_datetime'] ?? null)),
            raw: $data,
        );
    }

    /**
     * Kora: the chargeback webhook's `data` (payment under `payment`, amount as
     * chargeback_amount) or one row of the chargeback list (payment_reference,
     * transaction_reference, amount, reason).
     *
     * Statuses: pending, declined, accepted, lost, partial, auto_accepted.
     *
     * @param  array<string, mixed>  $data
     */
    public function korapay(array $data): ?DisputeNotice
    {
        $id = $this->text($data['reference'] ?? null);

        if ($id === null) {
            return null;
        }

        $gatewayStatus = Str::lower($this->text($data['status'] ?? null) ?? 'pending');

        return new DisputeNotice(
            gateway: 'korapay',
            disputeId: $id,
            paymentReferences: $this->references([
                data_get($data, 'payment.reference'),
                $data['payment_reference'] ?? null,
                $data['transaction_reference'] ?? null,
            ]),
            amount: $this->amount($data['chargeback_amount'] ?? ($data['amount'] ?? null)),
            currency: $this->currency($data['currency'] ?? null),
            reason: $this->text($data['chargeback_reason'] ?? ($data['reason'] ?? null)),
            stage: null,
            gatewayStatus: $gatewayStatus,
            status: match ($gatewayStatus) {
                'won', 'reversed' => DisputeStatus::Won,
                'lost', 'accepted', 'auto_accepted', 'partial' => DisputeStatus::Lost,
                default => DisputeStatus::Open,
            },
            dueAt: $this->time($data['deadline'] ?? null),
            openedAt: $this->time($data['created_at'] ?? null),
            raw: $data,
        );
    }

    /**
     * Paystack: the charge.dispute.* webhook's `data`. The payment is under
     * `transaction` (its reference is ours); amounts are in the currency's
     * subunit, like every Paystack amount. Resolved disputes say how in
     * `resolution`: declined means the claim was refused and we kept the money.
     *
     * @param  array<string, mixed>  $data
     */
    public function paystack(array $data): ?DisputeNotice
    {
        $id = $this->text($data['id'] ?? null);

        if ($id === null) {
            return null;
        }

        $gatewayStatus = Str::lower($this->text($data['status'] ?? null) ?? 'pending');
        $resolution = Str::lower((string) $this->text($data['resolution'] ?? null));
        $subunits = $data['refund_amount'] ?? data_get($data, 'transaction.amount');

        return new DisputeNotice(
            gateway: 'paystack',
            disputeId: $id,
            paymentReferences: $this->references([data_get($data, 'transaction.reference'), $data['transaction_reference'] ?? null]),
            amount: ($amount = $this->amount($subunits)) !== null ? round($amount / 100, 2) : null,
            currency: $this->currency($data['currency'] ?? data_get($data, 'transaction.currency')),
            reason: $this->text($data['category'] ?? null),
            stage: null,
            gatewayStatus: $resolution !== '' ? "{$gatewayStatus}:{$resolution}" : $gatewayStatus,
            status: match (true) {
                $gatewayStatus !== 'resolved' => DisputeStatus::Open,
                $resolution === 'declined' => DisputeStatus::Won,
                default => DisputeStatus::Lost,
            },
            dueAt: $this->time($data['dueAt'] ?? ($data['due_at'] ?? null)),
            openedAt: $this->time($data['createdAt'] ?? ($data['created_at'] ?? null)),
            raw: $data,
        );
    }

    /**
     * @param  list<mixed>  $candidates
     * @return list<string>
     */
    private function references(array $candidates): array
    {
        return array_values(array_unique(array_filter(array_map(fn ($c) => $this->text($c), $candidates))));
    }

    private function text(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : Str::limit($value, 250, '');
    }

    private function amount(mixed $value): ?float
    {
        return is_numeric($value) ? (float) $value : null;
    }

    private function currency(mixed $value): ?string
    {
        $value = $this->text($value);

        return $value !== null && preg_match('/^[A-Za-z]{3}$/', $value) ? Str::upper($value) : null;
    }

    private function time(mixed $value): ?Carbon
    {
        $value = $this->text($value);

        if ($value === null) {
            return null;
        }

        try {
            return Carbon::parse($value, 'UTC')->setTimezone(config('app.timezone'));
        } catch (\Throwable) {
            return null;
        }
    }
}

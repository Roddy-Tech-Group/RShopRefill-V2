<?php

namespace App\Http\Resources\V2;

use App\Domain\Payment\Support\CustomerFailureCopy;
use App\Models\PaymentSession;
use App\Support\ApiMoney;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A payment in flight: what is owed, how to pay it, and where it has got to.
 *
 * This is the object the app polls while a customer waits on a spinner, so what it
 * says matters more than almost anything else in the API. Three things are here
 * deliberately.
 *
 * `next_action` tells the app what to DO rather than making it infer that from a
 * status string. A hosted gateway page means open a browser; a wallet payment means
 * ask for the PIN; a crypto address means show it, with the exact coin amount, and
 * wait; a bank transfer means show the account details; a card that wants an OTP
 * means ask for it. Inferring that from provider names and statuses in the app
 * would mean a new provider needs a new app release.
 *
 * `underpayment` exists because a short crypto payment is never auto-fulfilled. The
 * poll is the ONLY channel that can tell a waiting customer their payment came up
 * short; without it they sit watching a confirmation that will never arrive.
 *
 * The failure is published, and only on failure, as a CODE plus the customer
 * sentence for that code. The raw reason the session was failed with is not: it
 * can be a gateway's verbatim text or an exception message, which is ours to read
 * and not the customer's.
 *
 * @property PaymentSession $resource
 */
class PaymentSessionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $attempt = $this->paymentAttempt;

        return [
            'id' => $this->id,
            'status' => $this->status,

            // Historical: this is what the customer is being charged, in the currency
            // the charge was raised in. Never re-converted. The USD figure uses the
            // rate the attempt was raised at, not today's.
            'amount' => ApiMoney::historic(
                (float) $this->amount,
                (string) ($this->currency ?: 'USD'),
                $this->settlementUsd(),
            ),

            'order_number' => $attempt?->order?->order_number,

            'expires_at' => $this->expires_at?->toIso8601ZuluString(),
            'is_expired' => $this->expires_at !== null && $this->expires_at->isPast(),
            'confirmed_at' => $this->confirmed_at?->toIso8601ZuluString(),
            'failed_at' => $this->failed_at?->toIso8601ZuluString(),

            'next_action' => $this->nextAction(),

            'failure' => $this->status === 'failed' ? $this->failure() : null,

            'underpayment' => $attempt !== null && $attempt->isUnderpaid() ? [
                'expected' => ApiMoney::native((float) $attempt->pay_amount, (string) $attempt->pay_currency),
                'received' => ApiMoney::native((float) $attempt->actually_paid, (string) $attempt->pay_currency),
                'shortfall' => ApiMoney::native(abs((float) $attempt->settlement_delta), (string) $attempt->pay_currency),
                // Whether a follow-up payment for the difference can be raised. Without
                // this the customer has paid, received nothing, and has no way to make
                // it up themselves.
                'can_top_up' => $attempt->purchase_id !== null,
            ] : null,
        ];
    }

    /**
     * The charge in settlement USD, at the rate the attempt was raised at.
     */
    private function settlementUsd(): float
    {
        $amount = (float) $this->amount;

        if (strtoupper((string) ($this->currency ?: 'USD')) === 'USD') {
            return round($amount, 4);
        }

        $rate = (float) ($this->paymentAttempt?->exchange_rate_snapshot ?? 0);

        return $rate > 0 ? round($amount / $rate, 4) : round($amount, 4);
    }

    /**
     * Why it failed, in the customer's language and nothing more.
     *
     * @return array{code: string|null, reason: string}
     */
    private function failure(): array
    {
        $payload = is_array($this->payment_payload) ? $this->payment_payload : null;
        $code = CustomerFailureCopy::customerCode(data_get($payload, 'failure_code'));

        return [
            'code' => $code,
            // The sentence for the code. A failure the classifier could not name
            // gets the generic sentence rather than the raw text the session was
            // failed with, which can be a gateway's or an exception's.
            'reason' => CustomerFailureCopy::sentence($code)
                ?? 'This payment could not be completed. Please try another payment method.',
        ];
    }

    /**
     * What the app should do next, named rather than implied.
     *
     * Types and the extra keys each carries:
     *
     *   none              nothing: the session is settled, failed, expired or cancelled
     *   choose_method     a fiat session with no method chosen; POST /pay with one
     *   confirm_with_pin  a wallet payment; POST /pay with the PIN token
     *   open_url          url: a hosted gateway page; open it, come back, verify
     *   send_crypto       address, memo, amount {amount, currency}, network,
     *                     qr_payload: show them and poll
     *   bank_transfer     bank: the account to pay into; show it and poll
     *   enter_otp         the card issuer sent a one-time code; collect it and
     *                     POST /pay again with it
     *   enter_card_pin    the card wants its PIN; collect it and POST /pay again
     *   await_approval    a mobile money push the customer approves on their phone;
     *                     poll
     *   open_inline       inline: the card gateway's configuration for its own
     *                     secure card form; open it, then verify
     *
     * @return array<string, mixed>
     */
    private function nextAction(): array
    {
        if (in_array($this->status, ['confirmed', 'failed', 'expired', 'cancelled'], true)) {
            return ['type' => 'none'];
        }

        $attempt = $this->paymentAttempt;
        $payload = is_array($this->payment_payload) ? $this->payment_payload : [];

        if ($this->session_type === 'wallet') {
            // The PIN authorises the spend. The app collects it, exchanges it for a
            // short-lived token, and authorises this session with that. Checked
            // before any URL: a wallet session still carries the checkout's return
            // link in its payload, and that is not somewhere to send anyone.
            return ['type' => 'confirm_with_pin'];
        }

        if ($this->session_type === 'crypto' || ($payload['pay_address'] ?? null) !== null) {
            $payCurrency = strtoupper((string) ($attempt?->pay_currency ?: ($payload['pay_currency'] ?? '')));
            $exact = $payload['exact_pay_amount'] ?? $attempt?->pay_amount ?? $payload['pay_amount'] ?? null;

            return [
                'type' => 'send_crypto',
                'address' => $attempt?->pay_address ?: ($payload['pay_address'] ?? null),
                'memo' => $attempt?->payin_extra_id ?: ($payload['payin_extra_id'] ?? null),
                // The coin amount, exactly as the gateway quoted it. The session's
                // `amount` is the fiat figure, and sending that many coins is how a
                // customer ends up massively overpaying or, with the decimal moved,
                // underpaying into a payment that is never fulfilled.
                'amount' => $exact !== null && $payCurrency !== ''
                    ? ['amount' => (string) $exact, 'currency' => $payCurrency]
                    : null,
                'network' => $attempt?->network ?: ($payload['network'] ?? null),
                'qr_payload' => $payload['qr_payload'] ?? null,
            ];
        }

        // The card issuer wants something more from the customer before it decides.
        if ($this->status === 'awaiting_customer_action') {
            return match ($payload['action'] ?? null) {
                'otp' => ['type' => 'enter_otp'],
                'pin' => ['type' => 'enter_card_pin'],
                default => ['type' => 'open_url', 'url' => $payload['redirect_url'] ?? $attempt?->payment_url],
            };
        }

        // A bank transfer: the gateway has issued an account to pay into.
        if (is_array($payload['bank_details'] ?? null)) {
            return ['type' => 'bank_transfer', 'bank' => $payload['bank_details']];
        }

        // A mobile money push the customer approves on their handset.
        if ($this->status === 'awaiting_confirmation') {
            return ['type' => 'await_approval'];
        }

        // The card gateway's inline form: the app renders its secure popup from
        // this configuration, and our server never sees card details.
        if (is_array($payload['inline'] ?? null)) {
            return ['type' => 'open_inline', 'inline' => $payload['inline']];
        }

        // A hosted gateway page. The app opens it in a browser and comes back by deep
        // link, then asks us to verify. The payload's redirect_url is only the
        // gateway's once the session is awaiting that redirect; before a method is
        // chosen it can hold the checkout's own return link.
        $url = $this->status === 'awaiting_redirect'
            ? ($payload['redirect_url'] ?? $attempt?->payment_url)
            : $attempt?->payment_url;

        if ($url) {
            return ['type' => 'open_url', 'url' => $url];
        }

        // A fiat session that has not chosen a method yet: the app presents the
        // options and authorises the session with the one the customer picks.
        return ['type' => 'choose_method'];
    }
}

<?php

namespace App\Http\Resources\V2;

use App\Domain\Order\Enums\OrderStatus;
use App\Domain\Payment\Support\PaymentMethodNormaliser;
use App\Models\Order;
use App\Models\OrderItem;
use App\Support\ApiMoney;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A placed order: what was bought, what it cost, and where it got to.
 *
 * Amounts are HISTORICAL and are never re-converted. An order placed in naira in
 * July was charged at July's rate and the customer's bank statement says so;
 * running it through today's rate would produce a figure that matches nothing.
 *
 * `markup_amount` is absent, as it is on the storefront's own resource. It is our
 * margin, not something the customer pays or needs to see.
 *
 * `payment_method` is the customer's word for how they paid (card, wallet, crypto,
 * mobile_money...), never a gateway's name. orders.payment_method stores the
 * gateway chosen at checkout for a card order, and that is not something a
 * customer-facing surface repeats.
 *
 * Three statuses rather than one, because they answer different questions and
 * collapsing them loses information the app needs:
 *   payment_status      did the money arrive
 *   fulfillment_status  did the goods go out
 *   order_status        the overall verdict, which is what to show on a list row
 *
 * @property Order $resource
 */
class OrderResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $currency = (string) ($this->display_currency ?: 'USD');

        if ($this->relationLoaded('items')) {
            // Each line derives its USD figure from the order's rate. Handing the
            // order down here means no line has to load it for itself.
            $this->items->each(fn (OrderItem $item) => $item->setRelation('order', $this->resource));
        }

        return [
            'id' => $this->id,
            // What a customer quotes to support, and what the app should show.
            'order_number' => $this->order_number,

            'subtotal' => ApiMoney::historic((float) $this->subtotal_amount, $currency, $this->usdSubtotal()),
            'total' => ApiMoney::historic((float) $this->total_amount, $currency, $this->usdTotal()),

            'payment_method' => $this->customerPaymentMethod(),
            'payment_method_label' => $this->customerGatewayLabel(),
            'payment_status' => $this->payment_status->value,
            'fulfillment_status' => $this->fulfillment_status->value,
            'order_status' => $this->order_status->value,

            'placed_at' => $this->placed_at?->toIso8601ZuluString(),
            'completed_at' => $this->completed_at?->toIso8601ZuluString(),
            'failed_at' => $this->failed_at?->toIso8601ZuluString(),

            'items' => OrderItemResource::collection($this->whenLoaded('items')),

            // Where to send the customer to finish paying, when there is still
            // something to pay. Only ever the LATEST attempt: an earlier attempt's
            // link may point at a session that has since been cancelled, and
            // sending someone back to that is how a payment lands against a dead
            // order. Null once the order is paid, failed or cancelled: a gateway
            // link on a settled order is a dead link at best.
            'payment' => $this->when(
                $this->relationLoaded('paymentAttempts'),
                fn () => $this->latestPayment(),
            ),
        ];
    }

    /**
     * The method in the customer's words. A card order is stored with the gateway's
     * name in payment_method until the pay step records the specific method, so the
     * paid attempt is asked first and a gateway name is never passed through.
     */
    private function customerPaymentMethod(): string
    {
        $fromAttempt = $this->relationLoaded('paymentAttempts')
            ? $this->paymentAttempts
                ->sortByDesc('created_at')
                ->map(fn ($attempt) => PaymentMethodNormaliser::normalise($attempt->payment_method))
                ->first(fn ($method) => $method !== null)
            : null;

        $method = $fromAttempt ?? PaymentMethodNormaliser::normalise($this->payment_method);

        return match ($method) {
            null, 'flutterwave', 'paystack', 'korapay', 'kora' => 'card',
            default => $method,
        };
    }

    /**
     * @return array{checkout_url: string|null, expires_at: string|null}|null
     */
    private function latestPayment(): ?array
    {
        if (! $this->stillPayable()) {
            return null;
        }

        $attempt = $this->paymentAttempts->sortByDesc('created_at')->first();

        if ($attempt === null) {
            return null;
        }

        return [
            'checkout_url' => $attempt->payment_url,
            'expires_at' => $attempt->expires_at?->toIso8601ZuluString(),
        ];
    }

    private function stillPayable(): bool
    {
        $paymentStatus = $this->payment_status->value;

        return in_array($paymentStatus, ['unpaid', 'pending', 'processing'], true)
            && ! in_array($this->order_status, [OrderStatus::Cancelled, OrderStatus::Failed, OrderStatus::Refunded], true);
    }
}

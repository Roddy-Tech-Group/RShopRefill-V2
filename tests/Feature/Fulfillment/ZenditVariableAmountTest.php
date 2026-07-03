<?php

namespace Tests\Feature\Fulfillment;

use App\Domain\Fulfillment\Providers\ZenditFulfillmentProvider;
use App\Models\Order;
use App\Models\OrderItem;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Variable/custom-amount fulfilment must send Zendit the face value in the
 * OFFER's currency (provider_face_value), not the display-currency display_amount.
 *
 * Regression: a $5 Visa (USD offer) bought in XAF stored display_amount = 2859.90
 * (XAF) and sent 2859.90 x100 = 285990 as USD cents -> Zendit "INVALID_VALUE: value
 * is out of range" -> fail -> auto-refund. The correct value is $5 -> 500 USD cents.
 *
 * Unit-tests the value builder directly: it is the exact piece that broke, and a
 * full Zendit round-trip would need the whole Category/Product/Variant/Order chain
 * for no extra coverage of the calculation.
 */
class ZenditVariableAmountTest extends TestCase
{
    private function priceValue(OrderItem $item): ?array
    {
        $method = new ReflectionMethod(ZenditFulfillmentProvider::class, 'variablePriceValue');
        $method->setAccessible(true);

        return $method->invoke(new ZenditFulfillmentProvider, $item);
    }

    private function variableItem(): OrderItem
    {
        $item = new OrderItem;
        $item->variant_snapshot = [
            'is_variable' => true,
            'metadata' => ['send' => ['currencyDivisor' => 100]],
        ];
        $item->display_amount = 2859.90; // $5 expressed in XAF

        return $item;
    }

    public function test_sends_offer_currency_face_value_not_converted_display_amount(): void
    {
        $item = $this->variableItem();
        $item->provider_face_value = 5.00; // chosen face value in the offer currency (USD)

        $this->assertSame(['type' => 'PRICE', 'value' => 500], $this->priceValue($item));
    }

    public function test_falls_back_to_order_exchange_rate_when_face_value_missing(): void
    {
        // Pre-migration in-flight item: no provider_face_value, back it out via the rate.
        $item = $this->variableItem();
        $item->provider_face_value = null;
        $item->setRelation('order', new Order(['metadata' => ['exchange_rate' => 571.98]]));

        $this->assertSame(['type' => 'PRICE', 'value' => 500], $this->priceValue($item));
    }

    public function test_fixed_denomination_item_sends_no_value(): void
    {
        $item = new OrderItem;
        $item->variant_snapshot = ['is_variable' => false];
        $item->display_amount = 25.00;

        $this->assertNull($this->priceValue($item));
    }
}

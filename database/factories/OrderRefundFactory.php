<?php

namespace Database\Factories;

use App\Models\Order;
use App\Models\OrderRefund;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<OrderRefund>
 */
class OrderRefundFactory extends Factory
{
    protected $model = OrderRefund::class;

    public function definition(): array
    {
        return [
            'order_id' => Order::factory(),
            'kind' => OrderRefund::KIND_CUSTOMER_REFUND,
            'tag' => 'admin-'.Str::uuid(),
            'amount' => 10,
            'currency' => 'USD',
            'revenue_reversed_usd' => 10,
            'allocations' => [],
            'gateway' => 'flutterwave',
            'reason' => 'Customer changed their mind',
            'refunded_at' => now(),
            'supplier_credit_status' => OrderRefund::CREDIT_NOT_APPLICABLE,
        ];
    }

    public function fulfilmentReversal(): static
    {
        return $this->state(fn () => [
            'kind' => OrderRefund::KIND_FULFILMENT_REVERSAL,
            'tag' => 'item-'.Str::uuid(),
            'revenue_reversed_usd' => 0,
        ]);
    }

    public function supplierCreditPending(): static
    {
        return $this->state(fn () => ['supplier_credit_status' => OrderRefund::CREDIT_PENDING]);
    }

    public function supplierCredited(float $usd, ?\DateTimeInterface $at = null): static
    {
        return $this->state(fn () => [
            'supplier_credit_status' => OrderRefund::CREDIT_CREDITED,
            'supplier_credit_usd' => $usd,
            'supplier_credited_at' => $at ?? now(),
        ]);
    }
}

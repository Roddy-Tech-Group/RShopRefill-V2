<?php

namespace App\Models;

use App\Domain\Fulfillment\Enums\FulfillmentStatus;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $order_id
 * @property int $product_id
 * @property int $product_variant_id
 * @property int $category_id
 * @property int $subcategory_id
 * @property string $provider_name
 * @property string|null $provider_offer_id
 * @property array|null $product_snapshot
 * @property array|null $variant_snapshot
 * @property int $quantity
 * @property string $display_currency
 * @property float $display_amount
 * @property float|null $provider_face_value
 * @property float $provider_cost_usd
 * @property float $markup_amount
 * @property float $subtotal_amount
 * @property FulfillmentStatus $fulfillment_status
 * @property string|null $fulfillment_reference
 * @property array|null $fulfillment_payload
 * @property Carbon|null $delivered_at
 * @property Carbon|null $failed_at
 * @property array|null $metadata
 */
class OrderItem extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'order_id',
        'product_id',
        'product_variant_id',
        'category_id',
        'subcategory_id',
        'provider_name',
        'provider_offer_id',
        'product_snapshot',
        'variant_snapshot',
        'quantity',
        'display_currency',
        'display_amount',
        'provider_face_value',
        'provider_cost_usd',
        'markup_amount',
        'subtotal_amount',
        'fulfillment_status',
        'fulfillment_reference',
        'fulfillment_payload',
        'delivered_at',
        'failed_at',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'fulfillment_status' => FulfillmentStatus::class,
            'product_snapshot' => 'array',
            'variant_snapshot' => 'array',
            'quantity' => 'integer',
            'display_amount' => 'decimal:4',
            'provider_face_value' => 'decimal:4',
            'provider_cost_usd' => 'decimal:4',
            'markup_amount' => 'decimal:4',
            'subtotal_amount' => 'decimal:4',
            'fulfillment_payload' => 'array',
            'delivered_at' => 'datetime',
            'failed_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id');
    }

    /**
     * Category at order time. The product_snapshot JSON doesn't include the
     * nested category relation, so anywhere that needs to branch on category
     * slug (e.g. fulfilment provider routing) should rely on this FK instead.
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }
}

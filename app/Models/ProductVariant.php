<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ProductVariant extends Model
{
    use HasFactory;

    protected $fillable = [
        'product_id',
        'subcategory_id',
        'provider_offer_id',
        'sku',
        'currency',
        'face_value',
        'cost_price',
        'retail_price',
        'manual_retail_price_usd',
        'allow_below_cost',
        'min_amount',
        'max_amount',
        'is_variable',
        'is_available',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'face_value' => 'decimal:4',
            'cost_price' => 'decimal:4',
            'retail_price' => 'decimal:4',
            'manual_retail_price_usd' => 'decimal:4',
            'allow_below_cost' => 'boolean',
            'min_amount' => 'decimal:4',
            'max_amount' => 'decimal:4',
            'is_variable' => 'boolean',
            'is_available' => 'boolean',
            'metadata' => 'array',
        ];
    }

    public function coupons()
    {
        return $this->hasMany(Coupon::class);
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * The subcategory this specific offer belongs to. May differ from the
     * parent product's subcategory when a product spans multiple subtypes.
     */
    public function subcategory()
    {
        return $this->belongsTo(Subcategory::class);
    }
}

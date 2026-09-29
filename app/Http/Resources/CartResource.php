<?php

namespace App\Http\Resources;

use App\Domain\Cart\Services\CartPricingService;
use App\Domain\Cart\Services\CartValidationService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Arr;

class CartResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $pricingService = app(CartPricingService::class);
        $validationService = app(CartValidationService::class);

        $totals = $pricingService->calculateCartTotals($this->items);
        $issues = $validationService->validateCart($this->resource);

        return [
            'id' => $this->id,
            'status' => $this->status,
            'items' => CartItemResource::collection($this->whenLoaded('items')),
            // Customer-facing figures only. The pricing service also returns
            // the markup and the supplier cost for internal use; this endpoint
            // is public to anyone holding a guest token.
            'totals' => Arr::only($totals, ['currency', 'subtotal', 'total', 'estimated_rcoin_reward']),
            'issues' => $issues,
            'last_activity_at' => $this->last_activity_at,
        ];
    }
}

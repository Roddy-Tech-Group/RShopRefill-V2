<?php

namespace App\Http\Resources\V2;

use App\Models\Category;
use App\Support\FeatureFlag;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A top-level section of the shop: airtime, data, gift cards, eSIMs, betting.
 *
 * @property Category $resource
 */
class CategoryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'slug' => $this->slug,
            'type' => $this->type,
            'icon' => $this->icon,
            'sort_order' => (int) $this->sort_order,
            // The admin's per-category switch. Off means the website greys the
            // section and refuses to add its products; the app must do the same.
            'is_enabled' => FeatureFlag::categoryEnabled($this->slug),
        ];
    }
}

<?php

namespace App\Http\Resources\V2;

use App\Models\Subcategory;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A grouping inside a category, such as one network or one gift-card brand family.
 *
 * @property Subcategory $resource
 */
class SubcategoryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'slug' => $this->slug,
            'description' => $this->description,
            'icon' => $this->icon,
            'banner_image' => $this->banner_image ? asset($this->banner_image) : null,
            'is_featured' => (bool) $this->is_featured,
            'sort_order' => (int) $this->sort_order,
            'category' => new CategoryResource($this->whenLoaded('category')),
        ];
    }
}

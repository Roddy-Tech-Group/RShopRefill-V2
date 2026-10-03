<?php

namespace App\Http\Controllers\Api\Storefront;

use App\Http\Controllers\Controller;
use App\Http\Resources\ProductResource;
use App\Models\Product;
use Illuminate\Http\Request;

class EsimCatalogController extends Controller
{
    /**
     * Get a list of all eSIM coverage regions (Products).
     * The user's flow requires showing countries/regions first.
     */
    public function countries(Request $request)
    {
        $query = Product::whereHas('category', fn ($q) => $q->where('slug', 'esims'))
            ->whereHas('subcategory', fn ($q) => $q->where('slug', 'data-esim'))
            ->where('is_active', true)
            ->orderBy('name');

        // Optional filtering by region/search
        $query->when($request->queryString('search'), fn ($q, $search) => $q->where('name', 'like', "%{$search}%")
        );

        return ProductResource::collection(
            $query->paginate((int) $request->query('per_page', 50))
        );
    }

    /**
     * Get the specific variants (data plans) for a selected country/region eSIM.
     */
    public function show(string $slug)
    {
        $product = Product::where('slug', $slug)
            ->whereHas('category', fn ($q) => $q->where('slug', 'esims'))
            ->where('is_active', true)
            ->with(['category', 'subcategory', 'variants' => function ($q) {
                // Eager load only available variants
                $q->where('is_available', true)->orderBy('cost_price');
            }])
            ->firstOrFail();

        return new ProductResource($product);
    }
}

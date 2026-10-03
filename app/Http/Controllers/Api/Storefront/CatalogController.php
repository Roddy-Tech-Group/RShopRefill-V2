<?php

namespace App\Http\Controllers\Api\Storefront;

use App\Http\Controllers\Controller;
use App\Http\Resources\CategoryResource;
use App\Http\Resources\ProductResource;
use App\Http\Resources\SubcategoryResource;
use App\Models\Category;
use App\Models\Product;
use App\Models\Subcategory;
use Illuminate\Http\Request;

class CatalogController extends Controller
{
    public function categories()
    {
        $categories = Category::where('is_active', true)
            ->orderBy('sort_order')
            ->get();

        return CategoryResource::collection($categories);
    }

    public function subcategories(Request $request)
    {
        $query = Subcategory::whereHas('category', function ($q) {
            $q->where('is_active', true);
        })
            ->when($request->queryString('category'), fn ($q, $slug) => $q->whereHas('category', fn ($cq) => $cq->where('slug', $slug))
            )
            ->when($request->queryString('is_featured'), fn ($q) => $q->where('is_featured', true))
            ->orderBy('sort_order');

        return SubcategoryResource::collection($query->get());
    }

    public function products(Request $request)
    {
        $query = Product::where('is_active', true)
            ->with(['subcategory', 'variants' => function ($q) {
                $q->where('is_available', true);
            }])
            ->when($request->queryString('country'), fn ($q, $c) => $q->where('country_code', strtoupper($c)))
            ->when($request->queryString('subcategory'), fn ($q, $slug) => $q->whereHas('subcategory', fn ($sq) => $sq->where('slug', $slug))
            )
            ->when($request->queryString('is_featured'), fn ($q) => $q->where('is_featured', true))
            ->when($request->queryString('is_popular'), fn ($q) => $q->where('is_popular', true))
            ->when($request->queryString('search'), fn ($q, $search) => $q->where('name', 'like', "%{$search}%")
            );

        return ProductResource::collection(
            $query->paginate((int) $request->query('per_page', 24))
        );
    }

    public function product(string $slug)
    {
        $product = Product::where('slug', $slug)
            ->where('is_active', true)
            ->with(['category', 'subcategory', 'variants' => function ($q) {
                $q->where('is_available', true);
            }])
            ->firstOrFail();

        return new ProductResource($product);
    }
}

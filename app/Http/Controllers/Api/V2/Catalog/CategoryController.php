<?php

namespace App\Http\Controllers\Api\V2\Catalog;

use App\Http\Controllers\Controller;
use App\Http\Resources\V2\CategoryResource;
use App\Http\Resources\V2\SubcategoryResource;
use App\Models\Category;
use App\Models\Subcategory;
use App\Support\FeatureFlag;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The shop's shape: what sections exist and what is inside them.
 *
 * Not paginated. There are tens of these, not thousands, and the app wants the
 * whole set once to build its navigation. Paging it would cost the app round
 * trips to learn something that fits in one response.
 */
class CategoryController extends Controller
{
    public function categories(): JsonResponse
    {
        $categories = Category::where('is_active', true)
            // The categories the website hides until their feature ships (flights,
            // stays) are hidden here too; the rest stay listed and carry
            // `is_enabled` so a switched-off one is greyed rather than vanished.
            ->whereNotIn('slug', self::hiddenCategorySlugs())
            ->orderBy('sort_order')
            // A stable tiebreaker, so two categories sharing a sort_order do not
            // swap places between requests and make the app's list jump.
            ->orderBy('id')
            ->get();

        return response()->json(['data' => CategoryResource::collection($categories)->resolve()]);
    }

    /**
     * Category slugs the storefront does not show at all right now.
     *
     * @return list<string>
     */
    public static function hiddenCategorySlugs(): array
    {
        return array_values(array_filter(
            FeatureFlag::HIDDEN_WHEN_OFF,
            fn (string $slug) => ! FeatureFlag::categoryVisible($slug),
        ));
    }

    public function subcategories(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'category' => ['nullable', 'string', 'max:255'],
            'featured' => ['nullable', 'boolean'],
        ]);

        $subcategories = Subcategory::query()
            // Inactive and not-yet-shipped categories are hidden wholesale, so
            // their subcategories must not leak out on their own.
            ->whereHas('category', fn ($query) => $query
                ->where('is_active', true)
                ->whereNotIn('slug', self::hiddenCategorySlugs()))
            ->when(
                $validated['category'] ?? null,
                fn ($query, $slug) => $query->whereHas('category', fn ($q) => $q->where('slug', $slug)),
            )
            ->when(
                $request->boolean('featured'),
                fn ($query) => $query->where('is_featured', true),
            )
            ->with('category')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        return response()->json(['data' => SubcategoryResource::collection($subcategories)->resolve()]);
    }
}

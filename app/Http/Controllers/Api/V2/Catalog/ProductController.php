<?php

namespace App\Http\Controllers\Api\V2\Catalog;

use App\Domain\Catalog\Services\ApiCatalogPricer;
use App\Http\Controllers\Controller;
use App\Http\Resources\V2\ProductResource;
use App\Http\Responses\V2\CursorPage;
use App\Models\Product;
use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Browsing and searching the catalogue.
 *
 * Two things here are deliberate and easy to undo by accident.
 *
 * ONLY AVAILABLE VARIANTS are loaded. `is_available` is the single effective flag:
 * ProductVariant forces it false whenever an admin disables the variant, so it
 * already accounts for both the supplier's feed and an admin's override. Filtering
 * on anything else would re-derive a rule that is enforced on the model.
 *
 * EVERY VARIANT IS PRICED before serialisation. The retail_price column is not
 * what checkout charges; the rule chain is. ProductVariantResource throws rather
 * than guess, so forgetting this fails loudly in testing instead of quietly
 * offering free airtime in production.
 */
class ProductController extends Controller
{
    public function __construct(private ApiCatalogPricer $pricer) {}

    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'country' => ['nullable', 'string', 'size:2'],
            'category' => ['nullable', 'string', 'max:255'],
            'subcategory' => ['nullable', 'string', 'max:255'],
            'search' => ['nullable', 'string', 'max:100'],
            'featured' => ['nullable', 'boolean'],
            'popular' => ['nullable', 'boolean'],
            'sort' => ['nullable', 'string', 'in:name,popular,featured'],
            'per_page' => ['nullable', 'integer', 'min:1'],
            'cursor' => ['nullable', 'string', 'max:500'],
        ]);

        $query = Product::where('is_active', true)
            // Categories the website hides outright (unbuilt features) hide their
            // products too. A switched-off but visible category keeps its products
            // listed, with is_available false, exactly as the website greys them.
            ->whereHas('category', fn ($c) => $c->whereNotIn('slug', CategoryController::hiddenCategorySlugs()))
            ->with([
                // `category` is loaded so is_available (the category switch) and the
                // pricing basis do not cost a query per product on a 100-row page.
                'category',
                'subcategory',
                'variants' => fn ($q) => $q->where('is_available', true)->orderBy('face_value')->orderBy('id'),
            ])
            ->when(
                $validated['country'] ?? null,
                fn ($q, $country) => $q->where('country_code', strtoupper($country)),
            )
            ->when(
                $validated['category'] ?? null,
                fn ($q, $slug) => $q->whereHas('category', fn ($c) => $c->where('slug', $slug)),
            )
            ->when(
                $validated['subcategory'] ?? null,
                fn ($q, $slug) => $q->whereHas('subcategory', fn ($s) => $s->where('slug', $slug)),
            )
            ->when(
                $request->boolean('featured'),
                fn ($q) => $q->where('is_featured', true),
            )
            ->when(
                $request->boolean('popular'),
                fn ($q) => $q->where('is_popular', true),
            )
            ->when(
                $validated['search'] ?? null,
                fn ($q, $search) => $this->applySearch($q, $search),
            );

        $this->applySort($query, $validated['sort'] ?? null);

        $page = $query->cursorPaginate(
            CursorPage::perPage($validated['per_page'] ?? null),
            ['*'],
            'cursor',
            CursorPage::cursor($validated['cursor'] ?? null, ['name', 'id', 'is_popular', 'is_featured']),
        );

        // One pass over the page, before anything is serialised.
        $this->pricer->priceProducts($page->items());

        return CursorPage::respond($page, ProductResource::class);
    }

    public function show(string $slug): JsonResponse
    {
        $product = Product::where('slug', $slug)
            ->where('is_active', true)
            ->with([
                'category',
                'subcategory',
                'variants' => fn ($q) => $q->where('is_available', true)->orderBy('face_value')->orderBy('id'),
            ])
            ->firstOrFail();

        $this->pricer->priceVariants($product->variants);

        return response()->json(['data' => (new ProductResource($product))->resolve()]);
    }

    /**
     * Free-text search across the name and the brand key.
     *
     * A LIKE with a leading wildcard cannot use an index, so this is a table scan
     * by design. Fine at the catalogue's current size, and the honest note is that
     * it is the first thing to replace with a full-text index when the catalogue
     * or the traffic grows: the endpoint's contract does not change when it is.
     *
     * The term is escaped, so a customer searching for "100%" gets results for
     * "100%" rather than matching everything.
     *
     * @param  Builder<Product>  $query
     * @return Builder<Product>
     */
    private function applySearch(Builder $query, string $search): Builder
    {
        $term = '%'.addcslashes(trim($search), '%_\\').'%';

        return $query->where(function ($q) use ($term) {
            $q->where('name', 'like', $term)
                ->orWhere('brand_key', 'like', $term);
        });
    }

    /**
     * Ordering, always ending in `id`.
     *
     * The trailing id is not decoration: cursor paging needs the sort to be
     * TOTALLY ordered, or rows that tie on the leading column can be skipped or
     * repeated as the customer scrolls. Every branch here ends in a unique column
     * for exactly that reason.
     *
     * @param  Builder<Product>  $query
     */
    private function applySort(Builder $query, ?string $sort): void
    {
        match ($sort) {
            'popular' => $query->orderByDesc('is_popular')->orderBy('name')->orderBy('id'),
            'featured' => $query->orderByDesc('is_featured')->orderBy('name')->orderBy('id'),
            default => $query->orderBy('name')->orderBy('id'),
        };
    }
}

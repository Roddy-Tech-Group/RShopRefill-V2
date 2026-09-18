<?php

namespace App\Models;

use Database\Factories\ReviewFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * A single customer review surfaced on the homepage carousel.
 *
 * @property int $id
 * @property string $initials
 * @property string $author_name
 * @property string $body
 * @property float $rating
 * @property string $source
 * @property Carbon $reviewed_at
 * @property bool $is_published
 * @property bool $is_customer_submitted
 * @property string|null $category The service line the experience prompt asked about
 * @property int|null $score The prompt's 1-10 answer; `rating` holds it halved for the stars
 * @property bool $is_anonymous Printed without the author's name on customer-facing surfaces
 * @property int $sort_order
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class Review extends Model
{
    /** @use HasFactory<ReviewFactory> */
    use HasFactory;

    protected $fillable = [
        'user_id',
        'order_id',
        'initials',
        'author_name',
        'body',
        'rating',
        'source',
        'reviewed_at',
        'is_published',
        'is_customer_submitted',
        'sort_order',
        'category',
        'score',
        'is_anonymous',
    ];

    protected function casts(): array
    {
        return [
            'reviewed_at' => 'date',
            'is_published' => 'boolean',
            'is_customer_submitted' => 'boolean',
            'is_anonymous' => 'boolean',
            'rating' => 'float',
            'score' => 'integer',
        ];
    }

    /**
     * Reviews that have something to say. The experience prompt makes the
     * comment optional, so a customer can leave a score alone; that still
     * counts toward ratings, but a card with no text has nothing to show, so
     * the carousel and the reviews page take only these.
     */
    public function scopeWithText(Builder $query): Builder
    {
        return $query->whereNotNull('body')->where('body', '!=', '');
    }

    /** The name a customer-facing surface may print. */
    public function displayName(): string
    {
        return $this->is_anonymous ? __('common.anonymous_customer') : $this->author_name;
    }

    /** The initials a customer-facing surface may print: an anonymous review carries none of the author's. */
    public function displayInitials(): string
    {
        return $this->is_anonymous ? mb_strtoupper(mb_substr(__('common.anonymous_customer'), 0, 1)) : $this->initials;
    }

    /**
     * Review platforms we link out to for collection but never render reviews
     * from. Trustpilot and Google host and display their own reviews on their
     * own surfaces; the storefront shows only what our own form collected, so a
     * curated row labelled "Trustpilot" can never be mistaken for a real one
     * pulled from that platform. Compared case-insensitively - editors type the
     * source by hand.
     */
    public const EXTERNAL_SOURCES = ['trustpilot', 'google'];

    public function scopePublished(Builder $query): Builder
    {
        return $query->where('is_published', true);
    }

    /**
     * The only reviews the storefront renders: the ones our own site collected.
     * Admin still lists every row, so entries attributed to an external platform
     * stay editable (and countable) without ever reaching a customer.
     *
     * `is_customer_submitted` is checked FIRST and on its own, because it is the
     * fact while `source` is only a label: the reviews.source column defaults to
     * 'Trustpilot' at the schema level, so anything that writes a review without
     * naming a source picks up a platform label by accident. A review a customer
     * left through our own form is ours however it ended up labelled, and must
     * never be filtered out for carrying the column default.
     */
    public function scopeOwnCollected(Builder $query): Builder
    {
        return $query->where(function (Builder $ownQuery): void {
            $ownQuery
                ->where('is_customer_submitted', true)
                ->orWhereNotIn(DB::raw('LOWER(source)'), self::EXTERNAL_SOURCES);
        });
    }

    /**
     * The exact complement of ownCollected(): rows attributed to an external
     * platform that no customer submitted here. This is precisely the set the
     * storefront hides, so it is the only set that is safe to prune, and having
     * it defined once means a cleanup can never delete more than was hidden.
     */
    public function scopeExternalSourced(Builder $query): Builder
    {
        return $query
            ->where('is_customer_submitted', false)
            ->whereIn(DB::raw('LOWER(source)'), self::EXTERNAL_SOURCES);
    }

    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderByDesc('sort_order')->orderByDesc('reviewed_at');
    }

    /** Customer submissions still awaiting admin approval. */
    public function scopePending(Builder $query): Builder
    {
        return $query->where('is_customer_submitted', true)->where('is_published', false);
    }

    /**
     * Reviews whose order contains a product for the given gift-card brand.
     * One order-page review can roll up under several brands when the customer
     * bought more than one gift card in that order. General reviews (no order)
     * are naturally excluded.
     */
    public function scopeForBrand(Builder $query, string $brandKey): Builder
    {
        return $query->whereHas('order.items.product', function (Builder $itemQuery) use ($brandKey) {
            $itemQuery->where('brand_key', $brandKey);
        });
    }

    /**
     * Average rating and review count per gift-card brand, in ONE query.
     *
     * The listing renders a card per brand and there are ~688 of them, so
     * asking each card for its own rating would be a page of N+1 queries on
     * top of the variant eager-load the price range already needs. This takes
     * the page's brand keys and comes back with everything they need.
     *
     * The inner select is DISTINCT on (brand, review): an order holding two
     * gift cards of the same brand joins its one review twice, which would
     * count it twice and leave the average untouched but the count wrong.
     *
     * Only reviews the storefront would actually show are counted - published,
     * and ours rather than an external platform's - so the number under a
     * brand always matches the reviews readable on its page.
     *
     * @param  iterable<string>  $brandKeys
     * @return array<string, array{average: float, count: int}>
     */
    public static function brandRatings(iterable $brandKeys): array
    {
        $keys = array_values(array_unique(array_filter(
            is_array($brandKeys) ? $brandKeys : iterator_to_array($brandKeys)
        )));

        if ($keys === []) {
            return [];
        }

        $distinct = DB::table('reviews')
            ->join('orders', 'orders.id', '=', 'reviews.order_id')
            ->join('order_items', 'order_items.order_id', '=', 'orders.id')
            ->join('products', 'products.id', '=', 'order_items.product_id')
            ->where('reviews.is_published', true)
            ->where(function ($query): void {
                $query
                    ->where('reviews.is_customer_submitted', true)
                    ->orWhereNotIn(DB::raw('LOWER(reviews.source)'), self::EXTERNAL_SOURCES);
            })
            ->whereIn('products.brand_key', $keys)
            ->distinct()
            ->select('products.brand_key', 'reviews.id', 'reviews.rating');

        return DB::query()
            ->fromSub($distinct, 'brand_reviews')
            ->groupBy('brand_key')
            ->select('brand_key', DB::raw('AVG(rating) as average'), DB::raw('COUNT(*) as total'))
            ->get()
            ->mapWithKeys(fn ($row) => [$row->brand_key => [
                'average' => round((float) $row->average, 1),
                'count' => (int) $row->total,
            ]])
            ->all();
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}

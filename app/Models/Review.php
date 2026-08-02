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
    ];

    protected function casts(): array
    {
        return [
            'reviewed_at' => 'date',
            'is_published' => 'boolean',
            'is_customer_submitted' => 'boolean',
            'rating' => 'float',
        ];
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

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}

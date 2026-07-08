<?php

namespace App\Models;

use Database\Factories\HeroCardFactory;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

/**
 * A homepage hero highlight card (the Apple-style tiles right under the
 * hero), managed from admin > Content > Hero Cards. The card body links to
 * link_url, the Learn more pill to learn_url, and the Buy now pill (shown
 * only when set) to buy_url.
 *
 * @property int $id
 * @property string $title
 * @property string|null $subtitle
 * @property string $image_path Relative to public/assets/, e.g. "hero-cards/apple-x1y2.png"
 * @property int $focal_x Horizontal focal point (0-100) for the square crop
 * @property string|null $link_url
 * @property string|null $learn_url
 * @property string|null $buy_url
 * @property bool $is_enabled
 * @property int $sort_order
 */
class HeroCard extends Model
{
    /** @use HasFactory<HeroCardFactory> */
    use HasFactory;

    protected $fillable = [
        'title',
        'subtitle',
        'image_path',
        'focal_x',
        'link_url',
        'learn_url',
        'buy_url',
        'is_enabled',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'focal_x' => 'integer',
            'is_enabled' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    /**
     * Any write busts the storefront cache so card edits show up immediately
     * instead of waiting out the TTL.
     */
    protected static function booted(): void
    {
        static::saved(fn () => self::bustCache());
        static::deleted(fn () => self::bustCache());
    }

    /**
     * Enabled cards in display order, cached like the hero sections so the
     * homepage does not query on every render. Rides the same fail-safe: a
     * missing table or DB blip returns an empty set instead of a 500.
     *
     * @return Collection<int, self>
     */
    public static function enabledCached(): Collection
    {
        try {
            return Cache::remember(
                'hero.cards.enabled',
                now()->addMinutes(10),
                fn () => self::query()
                    ->where('is_enabled', true)
                    ->orderBy('sort_order')
                    ->orderBy('id')
                    ->get(),
            );
        } catch (\Throwable $e) {
            return new Collection;
        }
    }

    public static function bustCache(): void
    {
        Cache::forget('hero.cards.enabled');
    }

    public function imageUrl(): string
    {
        return asset('assets/'.$this->image_path);
    }

    public function imageExists(): bool
    {
        return $this->image_path !== '' && file_exists(public_path('assets/'.$this->image_path));
    }
}

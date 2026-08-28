<?php

namespace App\Models;

use App\Models\Concerns\HasTranslations;
use Database\Factories\HeroSectionFactory;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

/**
 * A homepage hero slide, managed from admin > Content > Hero Sections.
 *
 * The built-in row (is_builtin) is the designed storefront hero: its headline
 * and CTAs live in the Blade component, so only its media backdrop, enabled
 * flag, and sort order are editable. Custom rows render as full-bleed
 * image/video slides with an optional headline, subtitle, and CTA.
 *
 * @property int $id
 * @property string|null $title
 * @property string|null $subtitle
 * @property string|null $cta_label
 * @property string|null $cta_url
 * @property string|null $media_path Relative to public/assets/, e.g. "hero/summer-x1y2.mp4"
 * @property string|null $media_type "image" or "video"
 * @property bool $is_builtin
 * @property bool $is_enabled
 * @property int $sort_order
 */
class HeroSection extends Model
{
    use HasTranslations;

    /**
     * Prose the admin can translate. The column stays the SOURCE text;
     * a locale with no row here reads straight through to it.
     *
     * @var array<int, string>
     */
    protected array $translatable = ['title', 'subtitle', 'cta_label'];

    /** @use HasFactory<HeroSectionFactory> */
    use HasFactory;

    protected $fillable = [
        'title',
        'subtitle',
        'cta_label',
        'cta_url',
        'media_path',
        'media_type',
        'is_builtin',
        'is_enabled',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'is_builtin' => 'boolean',
            'is_enabled' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    /**
     * Any write busts the storefront cache so hero edits show up immediately
     * instead of waiting out the TTL.
     */
    protected static function booted(): void
    {
        static::saved(fn () => self::bustCache());
        static::deleted(fn () => self::bustCache());
    }

    /**
     * Enabled slides in display order, cached like the hero catalog stats so
     * the homepage does not query on every render.
     *
     * @return Collection<int, self>
     */
    public static function enabledCached(): Collection
    {
        try {
            return Cache::remember(
                'hero.sections.enabled',
                now()->addMinutes(10),
                fn () => self::query()
                    ->where('is_enabled', true)
                    ->orderBy('sort_order')
                    ->orderBy('id')
                    ->get(),
            );
        } catch (\Throwable $e) {
            // The homepage must never 500 over a hero read (table not yet
            // migrated mid-deploy, DB or cache blip). Fall back to the
            // built-in designed hero, mirroring SiteSetting::get(). Nothing
            // is cached, so real data returns as soon as the store recovers.
            return new Collection([new self(['is_builtin' => true, 'is_enabled' => true])]);
        }
    }

    public static function bustCache(): void
    {
        Cache::forget('hero.sections.enabled');
    }

    public function mediaUrl(): ?string
    {
        return $this->media_path ? asset('assets/'.$this->media_path) : null;
    }
}

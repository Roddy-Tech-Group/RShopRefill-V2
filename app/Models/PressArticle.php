<?php

namespace App\Models;

use App\Models\Concerns\HasTranslations;
use Database\Factories\PressArticleFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A newsroom / press release shown at /press and /press/{slug}.
 *
 * @property int $id
 * @property string $slug
 * @property string $category
 * @property string $title
 * @property string $excerpt
 * @property string $image
 * @property string|null $attachment_path
 * @property string|null $attachment_label
 * @property array<int, string> $body
 * @property Carbon $published_at
 * @property bool $is_published
 * @property int $sort_order
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class PressArticle extends Model
{
    use HasTranslations;

    /**
     * Prose the admin can translate. The column stays the SOURCE text;
     * a locale with no row here reads straight through to it.
     *
     * @var array<int, string>
     */
    protected array $translatable = ['title', 'excerpt', 'body', 'attachment_label'];

    /** @use HasFactory<PressArticleFactory> */
    use HasFactory;

    protected $fillable = [
        'slug',
        'category',
        'title',
        'excerpt',
        'image',
        'attachment_path',
        'attachment_label',
        'body',
        'published_at',
        'is_published',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'body' => 'array',
            'published_at' => 'date',
            'is_published' => 'boolean',
        ];
    }

    public function scopePublished(Builder $query): Builder
    {
        return $query->where('is_published', true);
    }

    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderByDesc('sort_order')->orderByDesc('published_at');
    }
}

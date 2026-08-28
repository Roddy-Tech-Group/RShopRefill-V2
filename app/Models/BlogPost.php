<?php

namespace App\Models;

use App\Models\Concerns\HasTranslations;
use Database\Factories\BlogPostFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A single blog article shown at /blog and /blog/{slug}.
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
 * @property string $author
 * @property string|null $author_avatar
 * @property array<int, string>|null $video_urls
 * @property string|null $read_time
 * @property Carbon $published_at
 * @property bool $is_published
 * @property int $sort_order
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class BlogPost extends Model
{
    use HasTranslations;

    /**
     * Prose the admin can translate. The column stays the SOURCE text;
     * a locale with no row here reads straight through to it.
     *
     * @var array<int, string>
     */
    protected array $translatable = ['title', 'excerpt', 'body', 'attachment_label'];

    /** @use HasFactory<BlogPostFactory> */
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
        'author',
        'author_avatar',
        'video_urls',
        'read_time',
        'published_at',
        'is_published',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'body' => 'array',
            'video_urls' => 'array',
            'published_at' => 'date',
            'is_published' => 'boolean',
        ];
    }

    /**
     * Each pasted video URL normalised into an embeddable iframe src where we
     * recognise the provider (YouTube / Vimeo). Unrecognised links get embed=null
     * so the view renders them as a plain "Watch video" link instead.
     *
     * @return array<int, array{url: string, embed: string|null}>
     */
    public function videoEmbeds(): array
    {
        return collect($this->video_urls ?? [])
            ->filter()
            ->map(fn (string $url) => ['url' => $url, 'embed' => self::videoEmbedUrl($url)])
            ->values()
            ->all();
    }

    /**
     * A YouTube/Vimeo watch URL turned into an embeddable player src, or null when
     * the link isn't a provider we can embed (the caller renders a link instead).
     */
    public static function videoEmbedUrl(string $url): ?string
    {
        $url = trim($url);

        // youtu.be/ID, youtube.com/watch?v=ID, /embed/ID, /shorts/ID
        if (preg_match('~(?:youtu\.be/|youtube\.com/(?:watch\?v=|embed/|shorts/|live/))([A-Za-z0-9_-]{11})~', $url, $m)) {
            return 'https://www.youtube.com/embed/'.$m[1];
        }

        // vimeo.com/ID or vimeo.com/video/ID
        if (preg_match('~vimeo\.com/(?:video/)?(\d+)~', $url, $m)) {
            return 'https://player.vimeo.com/video/'.$m[1];
        }

        return null;
    }

    public function scopePublished(Builder $query): Builder
    {
        return $query->where('is_published', true);
    }

    /**
     * Default ordering for index pages: newest first, with `sort_order`
     * available as a manual override when an editor wants to pin a post.
     */
    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderByDesc('sort_order')->orderByDesc('published_at');
    }
}

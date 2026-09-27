<?php

namespace App\Models;

use App\Models\Concerns\HasTranslations;
use Database\Factories\FaqFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A single FAQ entry shown on /help.
 *
 * @property int $id
 * @property string $topic
 * @property string $question
 * @property string $answer
 * @property bool $is_published
 * @property int $sort_order
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class Faq extends Model
{
    use HasTranslations;

    /**
     * Prose the admin can translate. The column stays the SOURCE text;
     * a locale with no row here reads straight through to it.
     *
     * @var array<int, string>
     */
    protected array $translatable = ['topic', 'question', 'answer'];

    /** @use HasFactory<FaqFactory> */
    use HasFactory;

    protected $fillable = [
        'topic',
        'question',
        'answer',
        'is_published',
        'sort_order',
    ];

    protected function casts(): array
    {
        return ['is_published' => 'boolean'];
    }

    public function scopePublished(Builder $query): Builder
    {
        return $query->where('is_published', true);
    }

    /**
     * Default ordering: grouped by topic with `sort_order` as the per-topic
     * tiebreaker. Topics themselves come out alphabetically, which matches the
     * shipped layout closely enough — editors can override with `sort_order`.
     */
    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('topic')->orderBy('sort_order')->orderBy('id');
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * One field of one record, in one language.
 *
 * Written by the admin's translation panel, read through
 * App\Models\Concerns\HasTranslations - nothing should query this table
 * directly in a view, or the per-record memo the trait keeps is bypassed and
 * a listing becomes a query per tile.
 *
 * @property string $translatable_type
 * @property int $translatable_id
 * @property string $locale
 * @property string $field
 * @property string $value
 */
class Translation extends Model
{
    protected $fillable = [
        'translatable_type',
        'translatable_id',
        'locale',
        'field',
        'value',
    ];

    public function translatable(): MorphTo
    {
        return $this->morphTo();
    }
}

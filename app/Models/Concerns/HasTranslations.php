<?php

namespace App\Models\Concerns;

use App\Models\Translation;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Facades\App;

/**
 * Per-locale copy for a model's own text columns.
 *
 * A model lists the fields that carry customer-facing prose:
 *
 *     protected array $translatable = ['title', 'excerpt', 'body'];
 *
 * Reading `$post->title` then returns the current locale's copy when one
 * exists, and the column's own value when it does not. Nothing at the call
 * site changes - views, feeds and search keep reading the attribute they
 * always read, which is what makes this safe to add to a live model.
 *
 * The column is the SOURCE, never a translation: an empty translations table
 * is a site in its source language, not a site of blank strings. That also
 * means a half-translated record degrades field by field rather than all at
 * once.
 *
 * Reads are memoized per instance, so rendering a record that shows five
 * translated fields is one query, not five. `withTranslations()` extends that
 * to a collection - use it on any listing, or a 40-tile page pays 40 queries.
 */
trait HasTranslations
{
    /** @var array<string, string>|null Locale-keyed cache: [locale => [field => value]] */
    private ?array $translationMemo = null;

    public function translations(): MorphMany
    {
        return $this->morphMany(Translation::class, 'translatable');
    }

    /**
     * The fields this model translates. Declared as `protected array
     * $translatable` on the model; empty here so the trait is inert until a
     * model opts in.
     *
     * @return array<int, string>
     */
    public function translatableFields(): array
    {
        return property_exists($this, 'translatable') ? $this->translatable : [];
    }

    /**
     * Translated value for the current locale, or null when there is none.
     * Never falls back here - getAttributeValue() owns the fallback, so the
     * source column stays the single answer to "what if it is missing".
     */
    public function translate(string $field, ?string $locale = null): ?string
    {
        $locale = $locale ?: App::getLocale();

        if ($this->translationMemo === null) {
            // relationLoaded so an eager-loaded collection (withTranslations)
            // costs nothing extra; otherwise this is the one query per record.
            $rows = $this->relationLoaded('translations')
                ? $this->getRelation('translations')
                : $this->translations()->get(['locale', 'field', 'value']);

            $memo = [];
            foreach ($rows as $row) {
                $memo[$row->locale][$row->field] = $row->value;
            }
            $this->translationMemo = $memo;
        }

        $value = $this->translationMemo[$locale][$field] ?? null;

        // A blank translation is not a translation. Admins clear a field to
        // mean "use the original", and an empty string would otherwise blank
        // the copy on the site.
        return is_string($value) && trim($value) !== '' ? $value : null;
    }

    /** Store (or clear) one field's copy for one locale. */
    public function setTranslation(string $field, string $locale, ?string $value): void
    {
        if ($value === null || trim($value) === '') {
            $this->translations()->where('locale', $locale)->where('field', $field)->delete();
        } else {
            $this->translations()->updateOrCreate(
                ['locale' => $locale, 'field' => $field],
                ['value' => $value],
            );
        }

        $this->translationMemo = null;
        $this->unsetRelation('translations');
    }

    /**
     * Intercepts reads of the translatable columns. Everything else goes
     * straight through to Eloquent.
     */
    public function getAttributeValue($key)
    {
        $value = parent::getAttributeValue($key);

        if (! in_array($key, $this->translatableFields(), true)) {
            return $value;
        }

        // The source locale is the column itself - no lookup, no query.
        if (App::getLocale() === config('localization.default', 'en')) {
            return $value;
        }

        return $this->translate($key) ?? $value;
    }

    /**
     * Eager-load translations for a listing. One query for the whole
     * collection rather than one per record.
     */
    public function scopeWithTranslations($query, ?string $locale = null)
    {
        $locale = $locale ?: App::getLocale();

        return $query->with(['translations' => fn ($q) => $q->where('locale', $locale)]);
    }
}

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-locale copy for database-backed content.
 *
 * Lang files cover the interface; they cannot cover what the admin types -
 * product names and descriptions, blog and press bodies, FAQ answers, hero
 * copy. Those render straight from their own table in whatever language they
 * were entered, so a French visitor saw an English catalogue on a French page.
 *
 * One row per (model, field, locale). A side table rather than per-locale
 * columns on each model: adding a language is then a data change, not a
 * migration across eight tables, and a model with no translation simply has
 * no rows - which is exactly the fallback-to-source behaviour we want.
 *
 * The source language is NOT stored here. Whatever the column already holds
 * is the source, so this table only ever adds languages and can be emptied
 * without losing content.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('translations', function (Blueprint $table) {
            $table->id();

            // Morph pair, indexed together: every read is "this record's
            // translations", never a scan by type alone.
            $table->string('translatable_type');
            $table->unsignedBigInteger('translatable_id');

            // Short: an ISO code ('fr'), or a tagged one ('pt-BR') at the
            // longest. config/localization.php is the authority on which are
            // accepted; the column only has to hold them.
            $table->string('locale', 10);

            // The model attribute this translates, e.g. 'name', 'body'.
            $table->string('field', 64);

            // text, not string: bodies are articles. MEDIUMTEXT would be the
            // next step if a body ever passes 64KB, which none currently do.
            $table->text('value');

            $table->timestamps();

            // One translation per field per locale - the constraint that makes
            // updateOrCreate safe under concurrent admin saves.
            $table->unique(
                ['translatable_type', 'translatable_id', 'locale', 'field'],
                'translations_unique_field_per_locale'
            );

            // The hot path: load every translated field for one record in one
            // query, for the locale being rendered.
            $table->index(
                ['translatable_type', 'translatable_id', 'locale'],
                'translations_record_locale_index'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('translations');
    }
};

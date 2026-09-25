<?php

namespace Database\Seeders;

use App\Models\Category;
use App\Models\Subcategory;
use App\Models\Translation;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\File;

/**
 * Per-locale names for the categories and subcategories tables.
 *
 * Both models already carry HasTranslations on `name`; this fills the table
 * behind them. Each locale is one file under
 * database/seeders/data/category_translations, the same layout the FAQ
 * translations use.
 *
 * Rows are keyed by slug, not id: ids differ between this database and
 * production. A slug the database does not have is reported and skipped, so a
 * renamed supplier subcategory fails loudly rather than attaching its name to
 * the wrong row.
 *
 * Idempotent - re-running updates in place. The English column is never
 * written; it is the source the trait falls back to.
 */
class CategoryTranslationsSeeder extends Seeder
{
    public function run(): void
    {
        $dir = database_path('seeders/data/category_translations');

        if (! File::isDirectory($dir)) {
            $this->command?->warn('No category translation data directory; nothing to seed.');

            return;
        }

        $categories = Category::all()->keyBy('slug');
        $subcategories = Subcategory::all()->keyBy('slug');

        $written = 0;
        $missing = [];

        foreach (File::files($dir) as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $locale = $file->getFilenameWithoutExtension();
            $data = require $file->getPathname();

            foreach (['categories' => $categories, 'subcategories' => $subcategories] as $group => $records) {
                foreach ($data[$group] ?? [] as $slug => $name) {
                    $record = $records[$slug] ?? null;

                    if (! $record) {
                        $missing[] = "{$locale}: {$group}.{$slug}";

                        continue;
                    }

                    $this->write($record, $locale, $name);
                    $written++;
                }
            }
        }

        $this->command?->info("Category translations written: {$written}");

        foreach (array_slice($missing, 0, 10) as $slug) {
            $this->command?->warn('No row matched: '.$slug);
        }

        if (count($missing) > 10) {
            $this->command?->warn('...and '.(count($missing) - 10).' more unmatched.');
        }
    }

    private function write(Model $record, string $locale, string $name): void
    {
        Translation::updateOrCreate(
            [
                'translatable_type' => $record->getMorphClass(),
                'translatable_id' => $record->getKey(),
                'locale' => $locale,
                'field' => 'name',
            ],
            ['value' => $name],
        );
    }
}

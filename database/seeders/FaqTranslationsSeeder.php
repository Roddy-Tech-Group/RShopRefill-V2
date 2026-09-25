<?php

namespace Database\Seeders;

use App\Models\Faq;
use App\Models\Translation;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\File;

/**
 * Per-locale copy for the FAQ entries.
 *
 * The Faq model already carries HasTranslations; this fills the table behind
 * it. Each locale is one file under database/seeders/data/faq_translations,
 * so a language can be corrected or added without touching the others and a
 * reviewer reads one file per language rather than a 500-row diff.
 *
 * Rows are keyed by "topic||question" in ENGLISH, not by id: ids differ
 * between this database and production, and a mismatched id would attach a
 * French answer to an unrelated question rather than fail loudly.
 *
 * Idempotent - re-running updates in place. The English columns are never
 * written; they are the source the trait falls back to.
 */
class FaqTranslationsSeeder extends Seeder
{
    public function run(): void
    {
        $dir = database_path('seeders/data/faq_translations');

        if (! File::isDirectory($dir)) {
            $this->command?->warn('No FAQ translation data directory; nothing to seed.');

            return;
        }

        /** @var array<string, Faq> $byKey */
        $byKey = Faq::all()->keyBy(fn (Faq $faq) => $this->key($faq->getRawOriginal('topic'), $faq->getRawOriginal('question')));

        $written = 0;
        $missing = [];

        foreach (File::files($dir) as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $locale = $file->getFilenameWithoutExtension();
            $data = require $file->getPathname();

            $topics = $data['topics'] ?? [];

            foreach ($data['faqs'] ?? [] as $englishKey => $fields) {
                $faq = $byKey[$englishKey] ?? null;

                if (! $faq) {
                    $missing[] = $locale.': '.$englishKey;

                    continue;
                }

                $rows = $fields;

                // The topic is shared by every entry under it, so it is
                // translated once per locale rather than repeated per row.
                $englishTopic = $faq->getRawOriginal('topic');

                if (isset($topics[$englishTopic])) {
                    $rows['topic'] = $topics[$englishTopic];
                }

                foreach ($rows as $field => $value) {
                    if ($value === null || $value === '') {
                        continue;
                    }

                    Translation::updateOrCreate(
                        [
                            'translatable_type' => $faq->getMorphClass(),
                            'translatable_id' => $faq->getKey(),
                            'locale' => $locale,
                            'field' => $field,
                        ],
                        ['value' => $value],
                    );

                    $written++;
                }
            }
        }

        $this->command?->info("FAQ translations written: {$written}");

        foreach (array_slice($missing, 0, 10) as $m) {
            $this->command?->warn('No FAQ matched: '.$m);
        }

        if (count($missing) > 10) {
            $this->command?->warn('...and '.(count($missing) - 10).' more unmatched.');
        }
    }

    private function key(?string $topic, ?string $question): string
    {
        return trim((string) $topic).'||'.trim((string) $question);
    }
}

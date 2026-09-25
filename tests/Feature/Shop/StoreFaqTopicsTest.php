<?php

namespace Tests\Feature\Shop;

use App\Models\Faq;
use Database\Seeders\BillFaqSeeder;
use Database\Seeders\FaqTranslationsSeeder;
use Database\Seeders\TopupFaqSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * The mobile top-up and bill payment stores each answer their own questions.
 *
 * Both pages shipped with no FAQ at all, while the gift card and eSIM stores
 * had one. The questions live in the faqs table like every other topic, so the
 * admin FAQ editor stays the one place they are written and /faq picks them up
 * without the pages knowing about it.
 */
class StoreFaqTopicsTest extends TestCase
{
    use RefreshDatabase;

    private const TOPICS = ['Mobile Top-Ups', 'Bill Payments'];

    private function seedFaqs(): void
    {
        $this->seed(TopupFaqSeeder::class);
        $this->seed(BillFaqSeeder::class);
    }

    public function test_the_topup_store_renders_its_own_faq(): void
    {
        $this->seedFaqs();

        $page = $this->withoutVite()->get('/topups')->assertOk()->getContent();

        $this->assertStringContainsString('topups-faq-heading', $page);
        $this->assertStringContainsString('What is a mobile top-up?', $page);
        $this->assertStringContainsString('Can I earn Rcoin on mobile top-ups?', $page);

        // The other stores' topics must not leak into this one.
        $this->assertStringNotContainsString('What is a bill payment?', $page);
        $this->assertStringNotContainsString('What is a digital gift card?', $page);
    }

    public function test_the_bills_store_renders_its_own_faq(): void
    {
        $this->seedFaqs();

        $page = $this->withoutVite()->get('/bills')->assertOk()->getContent();

        $this->assertStringContainsString('bills-faq-heading', $page);
        $this->assertStringContainsString('What is a bill payment?', $page);
        $this->assertStringContainsString('Can I earn Rcoin on bill payments?', $page);

        $this->assertStringNotContainsString('What is a mobile top-up?', $page);
    }

    /** Each store carries a full set, not the two or three the detail page has. */
    public function test_each_store_topic_carries_a_full_set_of_published_questions(): void
    {
        $this->seedFaqs();

        foreach (self::TOPICS as $topic) {
            $this->assertSame(
                12,
                Faq::query()->published()->where('topic', $topic)->count(),
                "{$topic} should carry twelve published questions"
            );
        }
    }

    /** Re-running a seeder updates in place; it must not duplicate the topic. */
    public function test_the_seeders_are_idempotent(): void
    {
        $this->seedFaqs();
        $this->seedFaqs();

        foreach (self::TOPICS as $topic) {
            $this->assertSame(12, Faq::query()->where('topic', $topic)->count());
        }
    }

    /** Both seeders run with the rest, or a fresh database ships without them. */
    public function test_both_seeders_are_registered_with_the_database_seeder(): void
    {
        $seeder = File::get(database_path('seeders/DatabaseSeeder.php'));

        $this->assertStringContainsString('TopupFaqSeeder::class', $seeder);
        $this->assertStringContainsString('BillFaqSeeder::class', $seeder);
    }

    /**
     * Every question needs copy in every locale that ships one.
     *
     * A locale file half-filled with the new topics is the failure this catches:
     * the trait falls back to English silently, so a missing entry shows up as
     * an English question in a French page rather than as an error.
     */
    public function test_every_new_question_is_translated_in_every_locale(): void
    {
        $this->seedFaqs();
        $this->seed(FaqTranslationsSeeder::class);

        $english = Faq::query()
            ->whereIn('topic', self::TOPICS)
            ->get()
            ->map(fn (Faq $faq) => $faq->getRawOriginal('topic').'||'.$faq->getRawOriginal('question'));

        $this->assertCount(24, $english);

        foreach (File::files(database_path('seeders/data/faq_translations')) as $file) {
            $locale = $file->getFilenameWithoutExtension();
            $data = require $file->getPathname();

            foreach (self::TOPICS as $topic) {
                $this->assertArrayHasKey(
                    $topic,
                    $data['topics'] ?? [],
                    "{$locale} is missing a translated name for the {$topic} topic"
                );
            }

            foreach ($english as $key) {
                $this->assertArrayHasKey(
                    $key,
                    $data['faqs'] ?? [],
                    "{$locale} is missing copy for \"{$key}\""
                );

                $entry = $data['faqs'][$key];

                $this->assertNotEmpty($entry['question'] ?? null, "{$locale}: empty question for \"{$key}\"");
                $this->assertNotEmpty($entry['answer'] ?? null, "{$locale}: empty answer for \"{$key}\"");
            }
        }
    }
}

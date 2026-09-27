<?php

namespace Tests\Feature\Localization;

use App\Models\Faq;
use App\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The FAQ reaches a French visitor in French.
 *
 * Three separate things kept it English: the /faq page wrote its heading
 * straight into the blade, the homepage FAQ cached its rows under one key for
 * every language, and the Rcoin filter matched on the displayed topic, which
 * stops matching the moment that topic is translated.
 */
class FaqTranslationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
    }

    private function translatedFaq(array $attributes, array $french): Faq
    {
        $faq = Faq::factory()->create($attributes);

        foreach ($french as $field => $value) {
            $faq->setTranslation($field, 'fr', $value);
        }

        return $faq;
    }

    public function test_the_faq_page_copy_renders_in_french(): void
    {
        $this->withSession(['locale' => 'fr'])
            ->get(route('shop.faq'))
            ->assertOk()
            ->assertSee(__('support.faq_heading', [], 'fr'))
            ->assertSee(__('support.faq_subheading', [], 'fr'))
            ->assertSee(__('support.faq_help_center_link', [], 'fr'))
            ->assertSee(__('support.faq_contact_team_link', [], 'fr'))
            ->assertDontSee('Everything you need to know')
            ->assertDontSee('Still need help?');
    }

    /** The English source reads as it did before extraction, links intact. */
    public function test_the_faq_page_is_unchanged_in_english(): void
    {
        $this->get(route('shop.faq'))
            ->assertOk()
            ->assertSee('Everything you need to know')
            ->assertSee('Frequently asked questions')
            ->assertSee('Still need help? Visit our', false)
            ->assertSee('Help Center')
            ->assertSee('contact our team')
            ->assertSee('href="'.route('shop.help').'"', false)
            ->assertSee('href="'.route('shop.contact').'"', false);
    }

    public function test_a_translated_question_renders_in_french_on_the_faq_page(): void
    {
        $this->translatedFaq(
            ['topic' => 'Orders & Delivery', 'question' => 'How fast is delivery?', 'answer' => 'Most orders arrive instantly.'],
            ['topic' => 'Commandes et livraison', 'question' => 'Quel est le délai de livraison ?', 'answer' => 'La plupart des commandes arrivent instantanément.'],
        );

        $this->withSession(['locale' => 'fr'])
            ->get(route('shop.faq'))
            ->assertOk()
            ->assertSee('Commandes et livraison')
            ->assertSee('Quel est le délai de livraison ?')
            ->assertSee('La plupart des commandes arrivent instantanément.')
            ->assertDontSee('How fast is delivery?');
    }

    /**
     * The regression itself. English renders first and fills the cache; the
     * French request that follows must not be handed those English rows.
     */
    public function test_the_homepage_faq_cache_is_kept_per_language(): void
    {
        $this->translatedFaq(
            ['topic' => 'General', 'question' => 'What can I buy here?', 'answer' => 'Gift cards, eSIMs, top-ups and bill payments.'],
            ['question' => 'Que puis-je acheter ici ?', 'answer' => 'Des cartes cadeaux, des eSIM, des recharges et des paiements de factures.'],
        );

        $this->get('/')
            ->assertOk()
            ->assertSee('What can I buy here?');

        $this->withSession(['locale' => 'fr'])
            ->get('/')
            ->assertOk()
            ->assertSee('Que puis-je acheter ici ?')
            ->assertDontSee('What can I buy here?');
    }

    public function test_reward_questions_stay_hidden_when_their_topic_is_translated(): void
    {
        Setting::set('rcoin_enabled', false, 'boolean');

        $this->rewardsFaq();

        $this->withSession(['locale' => 'fr'])
            ->get(route('shop.faq'))
            ->assertOk()
            ->assertDontSee('Programme de fidélité')
            ->assertDontSee('Comment cumuler des points de fidélité ?')
            ->assertDontSee('How do I collect loyalty points?');
    }

    /** The control for the test above: with rewards on, the question shows. */
    public function test_reward_questions_show_in_french_when_rewards_are_on(): void
    {
        Setting::set('rcoin_enabled', true, 'boolean');

        $this->rewardsFaq();

        $this->withSession(['locale' => 'fr'])
            ->get(route('shop.faq'))
            ->assertOk()
            ->assertSee('Programme de fidélité')
            ->assertSee('Comment cumuler des points de fidélité ?');
    }

    /**
     * A rewards question whose French topic contains neither "reward" nor
     * "rcoin", which is exactly the shape that slipped past the old filter.
     */
    private function rewardsFaq(): Faq
    {
        return $this->translatedFaq(
            ['topic' => 'Rewards', 'question' => 'How do I collect loyalty points?', 'answer' => 'Every order earns points.'],
            ['topic' => 'Programme de fidélité', 'question' => 'Comment cumuler des points de fidélité ?', 'answer' => 'Chaque commande rapporte des points.'],
        );
    }

    /** Both languages define the same support keys, or French silently falls back. */
    public function test_english_and_french_define_the_same_support_keys(): void
    {
        $en = require lang_path('en/support.php');
        $fr = require lang_path('fr/support.php');

        $this->assertSame(array_keys($en), array_keys($fr));
        $this->assertEmpty(array_filter($fr, fn ($v) => $v === ''));
    }
}

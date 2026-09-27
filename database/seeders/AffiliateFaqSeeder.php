<?php

namespace Database\Seeders;

use App\Models\Faq;
use Illuminate\Database\Seeder;

/**
 * The partner page's own FAQ, written by the business.
 *
 * It lives in the faqs table for the same reason the store FAQs do: the admin
 * FAQ editor stays the one place these are written, /faq and the partner page
 * can never drift apart, and changing a line does not need a deploy. The page
 * pulls the "Influencers and Affiliates" topic through <x-home.faq>.
 *
 * Nothing here states a rate, a commission or a response time: those are
 * business terms nobody has set yet, and an answer that states one is making
 * an offer.
 *
 * Idempotent: each row is matched on topic + question, so re-running this
 * updates the answer instead of duplicating the entry.
 */
class AffiliateFaqSeeder extends Seeder
{
    private const TOPIC = 'Influencers and Affiliates';

    public function run(): void
    {
        $faqs = [
            ['Who can apply to the partnership program?', 'Anyone with an audience that trusts their recommendations: creators and influencers on any platform, bloggers and publishers, newsletter writers, and people who run messaging groups or community networks. There is no minimum audience size.'],
            ['Does it cost anything to join?', 'No. Applying is free and commits you to nothing. You choose what to share and when.'],
            ['What can I share with my audience?', 'Everything in the store: gift cards for games, apps, shopping and entertainment, travel eSIMs, mobile airtime and data top-ups, and bill payments. All of it is digital and delivered online right after payment.'],
            ['Do I need an account to apply?', 'No. The application form on this page is enough. If you already shop with us, you can also apply from the Partnership program tab in your dashboard.'],
            ['What happens after I apply?', 'Our team reads every application and replies by email to the address you gave us. If we need more detail about your channel or your audience, we will ask.'],
            ['How are the terms of a partnership decided?', 'Terms are agreed between you and our team once we have reviewed your application and understood your audience. The application form starts that conversation; it is not an offer.'],
            ['My audience is small. Should I still apply?', 'Yes. A few hundred people who act on your recommendations are worth more than a large audience that does not. Tell us about engagement, not just numbers.'],
            ['Can I focus on a specific product or country?', 'Yes. Tell us in the message which products, brands or countries fit your audience and we will take that into account.'],
        ];

        foreach ($faqs as $i => [$question, $answer]) {
            Faq::updateOrCreate(
                ['topic' => self::TOPIC, 'question' => $question],
                [
                    'answer' => $answer,
                    'is_published' => true,
                    'sort_order' => $i,
                ],
            );
        }
    }
}

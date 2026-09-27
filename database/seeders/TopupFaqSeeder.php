<?php

namespace Database\Seeders;

use App\Models\Faq;
use Illuminate\Database\Seeder;

/**
 * The mobile top-up store's own FAQ, written by the business.
 *
 * It lives in the faqs table rather than in the page, for the same reason the
 * gift card, eSIM and homepage FAQs do: the admin FAQ editor stays the one
 * place these are written, /faq and the store page can never drift apart, and
 * changing a line does not need a deploy. The page pulls the "Mobile Top-Ups"
 * topic through <x-home.faq>.
 *
 * The brand detail page already answers three questions from the per-locale products lang files
 * (speed, which numbers, what happens on failure). Those are per-operator and
 * take the brand and country as arguments; these are the store-wide questions a
 * shopper asks before they have picked an operator at all.
 *
 * Idempotent: each row is matched on topic + question, so re-running this
 * updates the answer instead of duplicating the entry.
 */
class TopupFaqSeeder extends Seeder
{
    private const TOPIC = 'Mobile Top-Ups';

    public function run(): void
    {
        $faqs = [
            ['What is a mobile top-up?', 'A mobile top-up adds airtime, credit or a data bundle to a prepaid mobile number. You choose the network, enter the number and pick an amount, and the credit is sent straight to that line.'],
            ['How quickly will the top-up arrive?', 'Most top-ups reach the number within a few minutes of your payment being confirmed. Delivery depends on the mobile network processing the request, so busy periods can add a short delay.'],
            ['Which networks and countries can I top up?', 'The store lists every network currently available for the country you have selected. Use the country filter to see what can be topped up where, since coverage differs by region and changes as networks are added.'],
            ['What number should I enter?', 'Enter the full mobile number exactly as it is dialled locally, including the country code when asked for it. The number must be an active prepaid line on the network you selected.'],
            ['Can I top up a number in another country?', 'Yes. Pick the destination country first, then the network that serves that number. The credit arrives in the local currency of that country, whatever currency you pay in.'],
            ['Can I change the number or amount after paying?', 'No. Once a top-up has been sent to a number it cannot be recalled, redirected or resized. Check the number digit by digit before you confirm payment.'],
            ['What happens if I enter the wrong number?', 'The credit goes to whoever owns that number and cannot be reversed. RShopRefill cannot recover a top-up sent to a valid number that was not yours. Always confirm the number before paying.'],
            ['What payment methods can I use?', 'RShopRefill supports available payment methods such as mobile money, bank transfers, cards, cryptocurrency and eligible wallet balances. Available options may vary by country and currency.'],
            ['What should I do if my top-up has not arrived?', 'Check the status of your order from the Orders page and confirm the number you entered. If payment succeeded but the network did not deliver, contact RShopRefill Support with your order reference. Failed eligible orders may be automatically refunded.'],
            ['Can a completed top-up be refunded?', 'Top-ups are final once the network has credited the number. Refunds may be considered when an order fails or no credit is delivered at all. A top-up delivered to the number you entered cannot be returned.'],
            ['Can I top up someone else\'s number?', 'Yes. You can send a top-up to any active prepaid number on a supported network, including numbers in other countries. You only need the number itself.'],
            ['Can I earn Rcoin on mobile top-ups?', 'Eligible top-up purchases may earn Rcoin after the order is successfully completed. The amount and eligibility will be displayed during your purchase or in the Rcoin Rewards section.'],
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
